<?php
/**
 * Shared helpers used by both sites.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Escape for HTML output. Every piece of user-supplied text that reaches a
 * page goes through this. Short name because it is used constantly in views.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format a peso amount for display. */
function peso(float|string|int $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/**
 * Format a peso amount for an SMS.
 *
 * The peso sign is not in the GSM-7 alphabet. A single non-GSM character
 * forces the whole message into UCS-2, which drops the per-credit limit from
 * 160 characters to 70 and can silently double what each order costs to
 * notify. Spelling it "PHP" keeps every message on one credit.
 */
function peso_sms(float|string|int $amount): string
{
    return 'PHP ' . number_format((float) $amount, 2);
}

/**
 * Reduce text to the GSM-7 range so a message stays one credit.
 * Replaces the characters that realistically show up in a name or a drink.
 */
function gsm7_safe(string $text): string
{
    $replacements = [
        "\u{20B1}" => 'PHP ',   // peso sign
        "\u{2018}" => "'",      // curly quotes
        "\u{2019}" => "'",
        "\u{201C}" => '"',
        "\u{201D}" => '"',
        "\u{2013}" => '-',      // en dash
        "\u{2014}" => '-',      // em dash
        "\u{2026}" => '...',    // ellipsis
        "\u{00A0}" => ' ',      // non-breaking space
    ];

    $text = strtr($text, $replacements);

    // Anything still outside printable ASCII is transliterated where
    // possible and dropped otherwise.
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);

    if ($converted !== false) {
        $text = $converted;
    }

    return preg_replace('/[^\x20-\x7E\r\n]/', '', $text) ?? $text;
}

/**
 * Append a cache-busting stamp to a stylesheet or script.
 *
 * Apache serves everything under assets/ with a long expiry, which is right
 * for a drink illustration but wrong for CSS and JS: after an update the
 * browser keeps running the old copy and the change appears not to have
 * happened. Stamping the URL with the file's modified time means a changed
 * file is a new URL, while an unchanged one still comes from cache.
 */
function asset_version(string $path): string
{
    static $stamps = [];

    if (!array_key_exists($path, $stamps)) {
        $full = APP_ROOT . '/assets/' . ltrim($path, '/');
        $time = is_file($full) ? filemtime($full) : false;
        $stamps[$path] = $time === false ? '' : (string) $time;
    }

    return $stamps[$path];
}

/** Send a redirect and stop. */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Read a trimmed string from POST. */
function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/** Read an integer from GET. */
function get_int(string $key, int $default = 0): int
{
    return filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT) ?? $default;
}

/** Read a trimmed string from GET. */
function get_string(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/**
 * Normalise a Philippine mobile number to the 639XXXXXXXXX form Semaphore
 * expects. Returns null when the number is not a valid PH mobile number.
 *
 * Accepts 09171234567, 639171234567, +639171234567 and spaced or dashed
 * variants of each.
 */
function normalize_ph_mobile(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    if (str_starts_with($digits, '09') && strlen($digits) === 11) {
        return '63' . substr($digits, 1);
    }

    if (str_starts_with($digits, '639') && strlen($digits) === 12) {
        return $digits;
    }

    if (str_starts_with($digits, '9') && strlen($digits) === 10) {
        return '63' . $digits;
    }

    return null;
}

/** Display a mobile number back to the customer in the familiar 09XX form. */
function format_ph_mobile(string $normalized): string
{
    if (str_starts_with($normalized, '63') && strlen($normalized) === 12) {
        return '0' . substr($normalized, 2);
    }

    return $normalized;
}

/** Build a URL-safe slug. */
function slugify(string $text): string
{
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
    $text = trim($text, '-');

    return strtolower($text) ?: 'item';
}

/**
 * Generate the next order reference in the ORD-YYYYMMDD-NNNN form the team
 * used in their own draft design.
 */
function generate_order_ref(): string
{
    $date     = date('Ymd');
    $sequence = random_int(1000, 9999);

    return sprintf('ORD-%s-%04d', $date, $sequence);
}

/** The visitor's IP, as far as the server can tell. */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** The visitor's user agent, truncated to fit the column. */
function client_user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

// ---------------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------------

/** Queue a message to show on the next page load. */
function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Take and clear all queued messages. */
function take_flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }

    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return $flashes;
}

// ---------------------------------------------------------------------------
// Order status presentation
// ---------------------------------------------------------------------------

const ORDER_STATUSES = [
    'pending'          => 'Order Received',
    'preparing'        => 'Preparing',
    'ready'            => 'Ready for Pickup',
    'out_for_delivery' => 'Out for Delivery',
    'completed'        => 'Completed',
    'cancelled'        => 'Cancelled',
];

/**
 * Human label for a status value.
 *
 * `ready` reads differently depending on fulfilment: a pickup order is ready
 * for pickup, a delivery order is simply ready to go out.
 */
function status_label(string $status, ?string $orderType = null): string
{
    if ($status === 'ready' && $orderType === 'delivery') {
        return 'Ready to Send';
    }

    return ORDER_STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * The statuses an order may legally move to from where it is now.
 *
 * Pickup orders never reach out_for_delivery, and completed or cancelled
 * orders are terminal.
 */
function allowed_next_statuses(string $current, string $orderType): array
{
    $flow = match ($current) {
        'pending'          => ['preparing', 'cancelled'],
        'preparing'        => $orderType === 'delivery'
                              ? ['ready', 'cancelled']
                              : ['ready', 'cancelled'],
        'ready'            => $orderType === 'delivery'
                              ? ['out_for_delivery', 'completed', 'cancelled']
                              : ['completed', 'cancelled'],
        'out_for_delivery' => ['completed', 'cancelled'],
        default            => [],
    };

    return $flow;
}

/** Whether a status change is permitted. */
function can_transition(string $from, string $to, string $orderType): bool
{
    return in_array($to, allowed_next_statuses($from, $orderType), true);
}

/** Render a JSON response and stop. */
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Append a line to a log file under storage/logs. */
function write_log(string $file, string $message): void
{
    if (!is_dir(LOG_PATH)) {
        @mkdir(LOG_PATH, 0775, true);
    }

    @file_put_contents(
        LOG_PATH . '/' . basename($file),
        sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $message, PHP_EOL),
        FILE_APPEND | LOCK_EX
    );
}
