<?php
/**
 * Admin authentication.
 *
 * Password hashing with bcrypt, brute-force throttling per username and per
 * IP, session fixation protection, idle and absolute session timeouts, and a
 * forced password change on first sign-in.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';

/** Hash a new password. */
function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

/**
 * The password rules, in one place.
 *
 * Both the server check below and the live checklist in the browser read
 * this, so what a person is told while typing cannot drift away from what
 * is actually enforced on submit.
 */
function password_policy_rules(): array
{
    return [
        'min_length' => PASSWORD_MIN_LENGTH,
        'max_length' => 200,
        'common'     => ['password', '12345678', 'qwerty', 'ourcoffee', 'admin123', 'letmein'],
    ];
}

/**
 * Check a password against the policy. Returns an error string, or null when
 * it is acceptable.
 *
 * Length does more for real-world resistance than forcing four character
 * classes, so the rule is a solid minimum length plus a mixed-content check.
 */
function password_policy_error(string $plain): ?string
{
    $rules = password_policy_rules();

    if (strlen($plain) < $rules['min_length']) {
        return 'Password must be at least ' . $rules['min_length'] . ' characters long.';
    }

    if (strlen($plain) > $rules['max_length']) {
        return 'Password is too long.';
    }

    if (!preg_match('/[A-Za-z]/', $plain) || !preg_match('/[0-9]/', $plain)) {
        return 'Password must contain at least one letter and one number.';
    }

    foreach ($rules['common'] as $bad) {
        if (stripos($plain, $bad) !== false) {
            return 'That password is too easy to guess. Please choose another.';
        }
    }

    return null;
}

/** How many failed attempts happened recently for this username or IP. */
function recent_failed_attempts(string $username, string $ip): int
{
    $since = date('Y-m-d H:i:s', time() - (LOGIN_LOCKOUT_MINUTES * 60));

    return (int) db_value(
        'SELECT COUNT(*) FROM login_attempts
         WHERE successful = 0 AND created_at > ? AND (username = ? OR ip_address = ?)',
        [$since, $username, $ip]
    );
}

/** Record an attempt, successful or not. */
function record_login_attempt(string $username, bool $successful): void
{
    db_query(
        'INSERT INTO login_attempts (username, ip_address, successful, user_agent)
         VALUES (?, ?, ?, ?)',
        [mb_substr($username, 0, 50), client_ip(), $successful ? 1 : 0, client_user_agent()]
    );
}

/** Whether this username or IP is currently throttled. */
function is_throttled(string $username): bool
{
    return recent_failed_attempts($username, client_ip()) >= LOGIN_MAX_ATTEMPTS;
}

/**
 * Attempt a sign-in.
 *
 * Returns ['ok' => true] or ['ok' => false, 'error' => '...'].
 *
 * The error message is intentionally the same whether the username does not
 * exist or the password was wrong, so the form cannot be used to discover
 * which accounts are real.
 */
function attempt_login(string $username, string $password): array
{
    $generic = 'Incorrect username or password.';

    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => $generic];
    }

    if (is_throttled($username)) {
        audit('auth.locked_out', 'admin_user', null,
            'Throttled sign-in attempt for ' . $username, null, null, null, $username);

        return [
            'ok'    => false,
            'error' => 'Too many failed attempts. Please wait '
                       . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.',
        ];
    }

    $user = db_one(
        'SELECT id, username, full_name, password_hash, role, is_active,
                must_change_password, locked_until
         FROM admin_users WHERE username = ? LIMIT 1',
        [$username]
    );

    // Always run a hash comparison, even with no user, so that a missing
    // account and a wrong password take about the same amount of time.
    $hash = $user['password_hash']
        ?? '$2y$10$usesomesillystringfoxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    $passwordOk = password_verify($password, $hash);

    if (!$user || !$passwordOk) {
        record_login_attempt($username, false);
        audit('auth.login_failed', 'admin_user', $user['id'] ?? null,
            'Failed sign-in for ' . $username, null, null, null, $username);

        return ['ok' => false, 'error' => $generic];
    }

    if ((int) $user['is_active'] !== 1) {
        record_login_attempt($username, false);

        return ['ok' => false, 'error' => 'This account has been deactivated.'];
    }

    if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
        record_login_attempt($username, false);

        return ['ok' => false, 'error' => 'This account is temporarily locked.'];
    }

    // Upgrade the stored hash if PHP's default cost or algorithm has moved on.
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        db_query('UPDATE admin_users SET password_hash = ? WHERE id = ?',
            [hash_password($password), $user['id']]);
    }

    record_login_attempt($username, true);
    establish_session($user);

    db_query(
        'UPDATE admin_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
        [client_ip(), $user['id']]
    );

    audit('auth.login', 'admin_user', $user['id'], $user['username'] . ' signed in');

    return ['ok' => true];
}

/** Put the signed-in user into the session, with a fresh session id. */
function establish_session(array $user): void
{
    start_session();

    // A new id on privilege change defeats session fixation.
    session_regenerate_id(true);

    $_SESSION['admin_id']        = (int) $user['id'];
    $_SESSION['admin_username']  = $user['username'];
    $_SESSION['admin_name']      = $user['full_name'];
    $_SESSION['admin_role']      = $user['role'];
    $_SESSION['must_change_password'] = (int) $user['must_change_password'] === 1;
    $_SESSION['login_time']      = time();
    $_SESSION['last_activity']   = time();

    // Bound to the browser, so a stolen cookie alone is less useful.
    $_SESSION['ua_fingerprint']  = hash('sha256', client_user_agent());
}

/** Whether someone is signed in on this request. */
function is_logged_in(): bool
{
    start_session();

    if (empty($_SESSION['admin_id'])) {
        return false;
    }

    if (($_SESSION['ua_fingerprint'] ?? '') !== hash('sha256', client_user_agent())) {
        logout('Your session did not match this browser.');
    }

    if (time() - (int) ($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_TIMEOUT) {
        logout('You were signed out after a period of inactivity.');
    }

    if (time() - (int) ($_SESSION['login_time'] ?? 0) > SESSION_ABSOLUTE_TIMEOUT) {
        logout('Your session expired. Please sign in again.');
    }

    $_SESSION['last_activity'] = time();

    return true;
}

/** The signed-in user's row, or null. */
function current_admin(): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    static $cached = null;

    if ($cached === null) {
        $cached = db_one(
            'SELECT id, username, full_name, email, role, is_active,
                    must_change_password, last_login_at
             FROM admin_users WHERE id = ? LIMIT 1',
            [$_SESSION['admin_id']]
        );

        // Deactivated mid-session, so drop them immediately.
        if ($cached === null || (int) $cached['is_active'] !== 1) {
            logout('This account is no longer active.');
        }
    }

    return $cached;
}

/** Whether the signed-in user is the owner. */
function is_owner(): bool
{
    return ($_SESSION['admin_role'] ?? '') === 'owner';
}

/**
 * Gate a page. Sends the visitor to the login screen when not signed in, and
 * to the password screen when a password change is still outstanding.
 */
function require_login(string $loginUrl = 'login.php'): void
{
    if (!is_logged_in()) {
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect($loginUrl);
    }

    $onPasswordPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'change-password.php';

    if (!empty($_SESSION['must_change_password']) && !$onPasswordPage) {
        redirect('change-password.php');
    }
}

/** Gate a page to owners only. */
function require_owner(): void
{
    require_login();

    if (!is_owner()) {
        http_response_code(403);
        exit('You do not have permission to open this page.');
    }
}

/** Sign out and return to the login screen. */
function logout(?string $message = null): never
{
    start_session();

    if (!empty($_SESSION['admin_id'])) {
        audit('auth.logout', 'admin_user', $_SESSION['admin_id'],
            ($_SESSION['admin_username'] ?? 'someone') . ' signed out');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();

    // A fresh session just to carry the goodbye message across the redirect.
    start_session();
    session_regenerate_id(true);

    if ($message !== null) {
        flash('info', $message);
    }

    redirect('login.php');
}

/** Change the signed-in user's password after checking the current one. */
function change_password(int $adminId, string $current, string $new, string $confirm): array
{
    if ($new !== $confirm) {
        return ['ok' => false, 'error' => 'The two new passwords do not match.'];
    }

    $policyError = password_policy_error($new);
    if ($policyError !== null) {
        return ['ok' => false, 'error' => $policyError];
    }

    $user = db_one('SELECT id, username, password_hash FROM admin_users WHERE id = ? LIMIT 1', [$adminId]);

    if ($user === null || !password_verify($current, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Your current password is not correct.'];
    }

    if (password_verify($new, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Please choose a password you have not used here before.'];
    }

    db_query(
        'UPDATE admin_users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
        [hash_password($new), $adminId]
    );

    $_SESSION['must_change_password'] = false;
    session_regenerate_id(true);

    audit('auth.password_changed', 'admin_user', $adminId, $user['username'] . ' changed their password');

    return ['ok' => true];
}
