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

        /*
         * filemtime() is answered from the stat cache, which can be well over
         * a minute stale. A stale stamp here is worse than no stamp at all:
         * the URL stays the same after the file has changed, so browsers keep
         * the copy they already have and the change appears not to have
         * happened. That bites hardest straight after a deploy.
         *
         * Cleared for this one path only, and only once per file per request,
         * because $stamps remembers the answer either way.
         */
        clearstatcache(true, $full);

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

/**
 * Read an integer from GET.
 *
 * filter_input() answers three different ways: the integer, null when the key
 * is absent, and false when it is present but not a number. Only null is
 * caught by ??, so ?id=abc used to return false and the int return type then
 * made that a fatal error. Anything that is not an integer is the default.
 */
function get_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);

    return is_int($value) ? $value : $default;
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
 * Accepts 09171234567, 639171234567, +639171234567, the bare ten digits, and
 * spaced or dashed variants of each. Numbers beginning 08 are mobile too -
 * DITO's whole range is 0895 to 0898 - so the leading digit is not assumed.
 */
function normalize_ph_mobile(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    /*
     * Take the country code off, however it was written. The length test is
     * what stops a local number that happens to begin 63 losing its first two
     * digits: a subscriber number is ten, so anything longer still has a
     * country code on the front.
     */
    if (str_starts_with($digits, '63') && strlen($digits) > 10) {
        $digits = substr($digits, 2);
    }

    // Then the trunk zero, which survives +63 being typed in front of a
    // number already written the local way.
    if (str_starts_with($digits, '0') && strlen($digits) === 11) {
        $digits = substr($digits, 1);
    }

    if (strlen($digits) !== 10) {
        return null;
    }

    return plausible_ph_subscriber($digits) ? '63' . $digits : null;
}

/**
 * The network prefixes Philippine operators actually use, as the three digits
 * after the leading zero - so 0917 is '917'.
 *
 * ADDING TO THIS LIST: when a customer is wrongly turned away, put their
 * prefix here. That is the whole maintenance story. An allocation we have not
 * heard about is the one way this check can hurt, so the list errs towards
 * letting things through: everything below is or has been in service, and
 * where a range is split between brands it is listed once.
 *
 * What it exists to catch is the 090x block and similar, which no network has
 * ever been given - the kind of number that is accepted, queued, and then
 * fails silently on the handset.
 */
function ph_mobile_prefixes(): array
{
    static $prefixes = null;

    if ($prefixes !== null) {
        return $prefixes;
    }

    /*
     * Grouped as the networks publish them. The leading zero is dropped, so
     * 0917 is '917'. The 08 group is not a typo: Smart holds 0811 and 0813,
     * Globe 0817, and DITO the whole 0895 to 0898 range.
     */
    $list = [
        // Globe, TM, GOMO, and the brands that ride on Globe - ABS-CBN
        // Mobile on 0937 and Cherry Prepaid on 0996.
        '817', '904', '905', '906', '915', '916', '917', '926', '927', '935',
        '936', '937', '945', '953', '954', '955', '956', '957', '958', '959',
        '965', '966', '967', '975', '976', '977', '978', '979', '995', '996',
        '997',

        // Smart and TNT
        '811', '813', '907', '908', '909', '910', '912', '913', '914', '918',
        '919', '920', '921', '928', '929', '930', '938', '939', '940', '946',
        '947', '948', '949', '950', '951', '961', '963', '968', '969', '970',
        '981', '989', '998', '999',

        // Sun, now part of Smart but still its own ranges
        '922', '923', '924', '925', '931', '932', '933', '934', '941', '942',
        '943', '944', '973', '974',

        // DITO. 0992 is listed by both Smart and DITO, so it appears here once.
        '895', '896', '897', '898', '991', '992', '993', '994',
    ];

    $prefixes = array_fill_keys($list, true);

    return $prefixes;
}

/**
 * A last sanity check on the nine digits after the leading 9.
 *
 * Shape alone let obvious nonsense through: 09999999999 and 09000000000 are
 * the right length and start correctly, so they normalised happily and the
 * shop only found out when the text bounced. This refuses a number that is
 * one digit repeated, and one whose subscriber part is all zeroes.
 *
 * Deliberately not a list of network prefixes. Those change whenever a
 * carrier is allocated a new range, and a shop losing a real customer to a
 * stale list is worse than letting an improbable number through.
 */
function plausible_ph_subscriber(string $tenDigits): bool
{
    if (strlen($tenDigits) !== 10) {
        return false;
    }

    if (preg_match('/^(\d)\1{9}$/', $tenDigits) === 1) {
        return false;
    }

    if (substr($tenDigits, 1) === '000000000') {
        return false;
    }

    // The three digits that identify the network: 9171575437 gives '917',
    // and a DITO number 8951234567 gives '895'.
    return isset(ph_mobile_prefixes()[substr($tenDigits, 0, 3)]);
}

/** The ten digits the number field holds, from whatever form is stored. */
function ph_subscriber_digits(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    if (str_starts_with($digits, '63') && strlen($digits) === 12) {
        return substr($digits, 2);
    }

    if (str_starts_with($digits, '0') && strlen($digits) === 11) {
        return substr($digits, 1);
    }

    return $digits;
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
        'preparing'        => ['ready', 'cancelled'],
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
