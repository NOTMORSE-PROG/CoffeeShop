<?php
/**
 * Set the owner's password.
 *
 * The seed no longer ships a working password. It used to, and the password
 * was printed in the README so the team could sign in, which meant anyone who
 * found the repository could sign in to any copy of the system that had been
 * put online. This replaces that: the person installing picks the password,
 * and it is never written down anywhere public.
 *
 * Run it from the project root after loading the database:
 *
 *     php database/set-owner-password.php
 *
 * It asks for the password twice and prints nothing back. To set a different
 * account, pass the username:
 *
 *     php database/set-owner-password.php manager
 *
 * The admin can change it afterwards from Accounts in the admin site; this is
 * only here so there is a first password to sign in with.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../shared/auth.php';

/**
 * Read a line without echoing it, so the password does not sit on screen or
 * in the scrollback. Windows has no stty, so there it falls back to a visible
 * prompt and says so rather than pretending to hide anything.
 */
function read_secret(string $prompt): string
{
    $hidden = false;

    if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
        $hidden = shell_exec('stty -echo 2>/dev/null') !== null;
    }

    fwrite(STDOUT, $prompt . ($hidden ? '' : ' (visible on this platform)') . ': ');

    $line = fgets(STDIN);

    if ($hidden) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }

    return $line === false ? '' : rtrim($line, "\r\n");
}

$username = $argv[1] ?? 'owner';

$user = db_one('SELECT id, username, full_name FROM admin_users WHERE username = ? LIMIT 1', [$username]);

if ($user === null) {
    fwrite(STDERR, "No account named '$username'. Load database/install.sql first.\n");
    exit(1);
}

fwrite(STDOUT, "Setting the password for '{$user['username']}' ({$user['full_name']}).\n\n");

$first = read_secret('New password');

$policyError = password_policy_error($first);

if ($policyError !== null) {
    fwrite(STDERR, "\n$policyError\n");
    exit(1);
}

$second = read_secret('Repeat it');

if (!hash_equals($first, $second)) {
    fwrite(STDERR, "\nThose did not match. Nothing was changed.\n");
    exit(1);
}

db_query(
    'UPDATE admin_users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
    [hash_password($first), (int) $user['id']]
);

fwrite(STDOUT, "\nDone. Sign in as '{$user['username']}' with the password you just set.\n");
