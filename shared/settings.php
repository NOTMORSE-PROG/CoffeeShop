<?php
/**
 * Shop settings, read from the database so the owner can change them from
 * the admin site without anyone editing code.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** All settings as a key => value map, loaded once per request. */
function all_settings(bool $refresh = false): array
{
    static $cache = null;

    if ($cache === null || $refresh) {
        $cache = [];
        foreach (db_all('SELECT `key`, `value` FROM settings') as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }

    return $cache;
}

/** A single setting, with a fallback. */
function setting(string $key, mixed $default = null): mixed
{
    $settings = all_settings();

    return $settings[$key] ?? $default;
}

/** A setting read as a boolean flag. */
function setting_bool(string $key, bool $default = false): bool
{
    $value = setting($key);

    if ($value === null) {
        return $default;
    }

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

/** A setting read as a number. */
function setting_float(string $key, float $default = 0.0): float
{
    $value = setting($key);

    return $value === null ? $default : (float) $value;
}

/**
 * Write a setting. Only keys that already exist may be written, which stops
 * an unexpected form field from creating arbitrary rows.
 */
function set_setting(string $key, string $value): bool
{
    $exists = db_value('SELECT COUNT(*) FROM settings WHERE `key` = ?', [$key]);

    if ((int) $exists === 0) {
        return false;
    }

    db_query('UPDATE settings SET `value` = ? WHERE `key` = ?', [$value, $key]);
    all_settings(true);

    return true;
}

/**
 * Whether the shop is currently taking orders: the master switch is on and
 * the current time falls inside opening hours.
 */
function shop_is_open(): bool
{
    if (!setting_bool('accepting_orders', true)) {
        return false;
    }

    $open  = (string) setting('shop_open_time', '00:00');
    $close = (string) setting('shop_close_time', '23:59');
    $now   = date('H:i');

    return $now >= $open && $now <= $close;
}

/** A short sentence explaining why ordering is unavailable, or null when it is. */
function shop_closed_reason(): ?string
{
    if (!setting_bool('accepting_orders', true)) {
        return 'We are not taking online orders at the moment. Please check back shortly.';
    }

    if (!shop_is_open()) {
        return sprintf(
            'We are closed right now. Ordering opens daily from %s to %s.',
            date('g:i A', (int) strtotime((string) setting('shop_open_time', '07:00'))),
            date('g:i A', (int) strtotime((string) setting('shop_close_time', '20:00')))
        );
    }

    return null;
}

/**
 * The delivery fee for a city.
 *
 * This is a flat fee the owner sets, with one free city. There is deliberately
 * no distance calculation: automatic distance-based fees are out of scope.
 */
function delivery_fee_for_city(?string $city): float
{
    if ($city === null || $city === '') {
        return setting_float('delivery_fee', 0.0);
    }

    $freeCity = strtolower(trim((string) setting('free_delivery_city', '')));

    if ($freeCity !== '' && strtolower(trim($city)) === $freeCity) {
        return 0.0;
    }

    return setting_float('delivery_fee', 0.0);
}

/** Whether customers may choose delivery at all. */
function delivery_available(): bool
{
    return setting_bool('delivery_enabled', true);
}
