<?php
/**
 * Central configuration.
 *
 * Real values live in the .env file at the project root, which is never
 * committed. Copy .env.example to .env and fill it in before running.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/**
 * Minimal .env reader. There is no Composer dependency in this project on
 * purpose: the paper lists plain PHP, and the team needs to be able to drop
 * this on any shared host without running a build step.
 */
function load_env(string $path): void
{
    static $loaded = false;
    if ($loaded || !is_readable($path)) {
        $loaded = true;
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip one matching pair of surrounding quotes
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last  = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $_ENV[$key] = $value;
    }

    $loaded = true;
}

load_env(APP_ROOT . '/.env');

/**
 * Read a configuration value, falling back to a default.
 */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return match (strtolower((string) $value)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $value,
    };
}

// --- Application -----------------------------------------------------------
define('APP_NAME',  env('APP_NAME', 'Our Coffee Shop'));
define('APP_ENV',   env('APP_ENV', 'local'));
define('APP_DEBUG', (bool) env('APP_DEBUG', APP_ENV === 'local'));

define('CUSTOMER_URL', rtrim((string) env('CUSTOMER_URL', 'http://localhost/CoffeeShop/customer-site'), '/'));
define('ADMIN_URL',    rtrim((string) env('ADMIN_URL', 'http://localhost/CoffeeShop/admin-site'), '/'));

/**
 * Where the shared assets folder is served from.
 *
 * Normally the pages work this out from their own path. That breaks when a
 * rewrite serves the customer site from the address root: the script still
 * lives in customer-site/, so anything derived from its path points into a
 * folder the browser never sees. Setting this pins it.
 *
 * Leave it empty locally, where the script path is the truth.
 */
define('ASSETS_URL', rtrim((string) env('ASSETS_URL', ''), '/'));

/**
 * The public path the customer site answers on.
 *
 * Taken from CUSTOMER_URL rather than configured separately, because two
 * settings that describe the same thing eventually disagree. A rewrite can
 * make the script path differ from the public path, and this is the truth.
 *
 * Note the path for a site at the domain root is an empty string, which is
 * correct and must not be confused with "not configured".
 */
define('CUSTOMER_BASE_PATH', rtrim((string) (parse_url(CUSTOMER_URL, PHP_URL_PATH) ?? ''), '/'));

// --- Database --------------------------------------------------------------
define('DB_HOST',    env('DB_HOST', '127.0.0.1'));
define('DB_PORT',    (int) env('DB_PORT', 3306));
define('DB_NAME',    env('DB_NAME', 'our_coffee_shop'));
define('DB_USER',    env('DB_USER', 'root'));
define('DB_PASS',    (string) env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// --- Semaphore SMS ---------------------------------------------------------
define('SEMAPHORE_API_KEY',  (string) env('SEMAPHORE_API_KEY', ''));
define('SEMAPHORE_ENDPOINT', 'https://api.semaphore.co/api/v4/messages');

/**
 * When true, SMS is written to the log and to storage/logs/sms.log but never
 * actually sent. This is how the team demos the flow without burning credits.
 */
define('SMS_DRY_RUN', (bool) env('SMS_DRY_RUN', true));

// --- Security --------------------------------------------------------------
define('SESSION_NAME',        'ourcoffee_admin');
define('SESSION_IDLE_TIMEOUT', (int) env('SESSION_IDLE_TIMEOUT', 1800));  // 30 minutes
define('SESSION_ABSOLUTE_TIMEOUT', (int) env('SESSION_ABSOLUTE_TIMEOUT', 43200)); // 12 hours
define('LOGIN_MAX_ATTEMPTS',  (int) env('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_MINUTES', (int) env('LOGIN_LOCKOUT_MINUTES', 15));
define('PASSWORD_MIN_LENGTH', 10);

// --- Storage ---------------------------------------------------------------
define('STORAGE_PATH', APP_ROOT . '/storage');
define('LOG_PATH',     STORAGE_PATH . '/logs');

// --- Error reporting -------------------------------------------------------
// Errors are never printed to the browser outside local development. A stack
// trace on a live page is an information-disclosure problem.
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/php-error.log');

date_default_timezone_set('Asia/Manila');
