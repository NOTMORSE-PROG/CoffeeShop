<?php
/**
 * Security mechanisms shared by both sites.
 *
 * Covers: hardened session startup, CSRF tokens, response headers, and
 * input validation helpers.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

/**
 * Whether the current request arrived over HTTPS.
 * Shared hosts commonly terminate TLS upstream, hence the forwarded check.
 */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * Start a session with cookie flags that matter.
 *
 * httponly  keeps JavaScript away from the session cookie, so an XSS bug
 *           cannot simply read it and hand the admin session to an attacker.
 * samesite  Lax stops the cookie riding along on cross-site form posts.
 * secure    set only when actually on HTTPS, otherwise local XAMPP breaks.
 */
function start_session(string $name = SESSION_NAME): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name($name);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // The session id must come from the cookie, never from the URL.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_start();
}

/**
 * Security response headers.
 *
 * The CSP is deliberately strict: no inline scripts, nothing loaded from a
 * third-party origin. Chart.js is vendored locally under assets/vendor for
 * exactly this reason, which also means the demo still works offline.
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self'; "
        . "img-src 'self' data:; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "object-src 'none'"
    );

    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Admin pages must never be cached by a shared browser.
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

/**
 * The token for this session, created on first use.
 * One token per session is enough here and keeps multi-tab use working.
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_session();
    }

    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

/** A hidden input carrying the token, for use inside a form. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Constant-time check of a submitted token. */
function csrf_valid(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }

    $expected = $_SESSION['_csrf'] ?? '';

    if ($expected === '' || $token === null || $token === '') {
        return false;
    }

    return hash_equals($expected, $token);
}

/**
 * Guard a state-changing request. Call this at the top of every POST handler.
 * Rejects the request outright rather than trying to recover.
 */
function require_post_with_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed.');
    }

    if (!csrf_valid($_POST['_csrf'] ?? null)) {
        write_log('security.log', sprintf(
            'CSRF rejected: ip=%s uri=%s',
            client_ip(),
            $_SERVER['REQUEST_URI'] ?? '?'
        ));

        http_response_code(419);
        exit('Your session expired or the form was not submitted correctly. Please go back and try again.');
    }
}

// ---------------------------------------------------------------------------
// Input validation
// ---------------------------------------------------------------------------

/**
 * Validate a customer-supplied name.
 * Letters, spaces, apostrophes, hyphens and dots only.
 */
function valid_person_name(string $name): bool
{
    $length = mb_strlen($name);

    if ($length < 2 || $length > 120) {
        return false;
    }

    return (bool) preg_match("/^[\p{L}\p{M}' .\-]+$/u", $name);
}

/**
 * Collapse whitespace and strip control characters from free text before it
 * is stored. Output escaping still happens separately at render time.
 */
function clean_text(string $text, int $maxLength): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';

    return mb_substr(trim($text), 0, $maxLength);
}
