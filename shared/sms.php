<?php
/**
 * SMS order status notification, via the Semaphore API.
 *
 * Two things matter here beyond simply sending a message.
 *
 * 1. Credits cost money. Every send is logged to sms_log so the team can show
 *    the panel exactly what went out and what it cost, and each status has its
 *    own on/off switch in settings so the owner can trim spend.
 *
 * 2. A failed SMS must never fail an order. If Semaphore is down or out of
 *    credits, the order still goes through and the customer can still follow
 *    it on the order tracking page. That tracking page is the fallback the
 *    developer recommended in the documentation review.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';

/**
 * The message text for a status change.
 * Kept under 160 characters so each one stays a single credit.
 */
function sms_message_for(string $status, array $order): string
{
    $ref   = $order['order_ref'];
    $name  = explode(' ', trim((string) $order['customer_name']))[0] ?: 'there';
    $total = peso($order['total']);
    $shop  = (string) setting('shop_name', 'Our Coffee Shop');

    return match ($status) {
        'pending' => sprintf(
            '%s: Hi %s, we got your order %s. Total %s. We will text you when it is being prepared.',
            $shop, $name, $ref, $total
        ),
        'preparing' => sprintf(
            '%s: Your order %s is being prepared now. We will text you again once it is ready.',
            $shop, $ref
        ),
        'ready' => sprintf(
            '%s: Your order %s is ready for pickup. See you at the shop.',
            $shop, $ref
        ),
        'out_for_delivery' => sprintf(
            '%s: Your order %s is on its way to you. Please keep your phone nearby.',
            $shop, $ref
        ),
        'completed' => sprintf(
            '%s: Order %s is complete. Thank you, and enjoy.',
            $shop, $ref
        ),
        'cancelled' => sprintf(
            '%s: Your order %s was cancelled. Please contact the shop if this was not expected.',
            $shop, $ref
        ),
        default => sprintf('%s: Your order %s is now %s.', $shop, $ref, status_label($status)),
    };
}

/** Whether the owner has SMS switched on for this particular status. */
function sms_enabled_for_status(string $status): bool
{
    if (!setting_bool('sms_enabled', true)) {
        return false;
    }

    return setting_bool('sms_on_' . $status, false);
}

/**
 * Send the notification for an order status.
 *
 * Returns the sms_log row id. Never throws: a messaging problem must not
 * break order handling.
 */
function send_order_sms(array $order, string $status, bool $force = false): ?int
{
    if (!$force && !sms_enabled_for_status($status)) {
        return sms_log_row($order, $status, sms_message_for($status, $order), 'skipped',
            null, 'Notifications are switched off for this status.');
    }

    $phone = normalize_ph_mobile((string) $order['customer_phone']);

    if ($phone === null) {
        return sms_log_row($order, $status, sms_message_for($status, $order), 'failed',
            null, 'The stored mobile number is not a valid Philippine number.');
    }

    $message = sms_message_for($status, $order);

    // Dry run: log it, do not spend a credit. This is the demo mode.
    if (SMS_DRY_RUN) {
        write_log('sms.log', sprintf('DRY RUN to %s: %s', $phone, $message));

        return sms_log_row($order, $status, $message, 'sent', 'dry-run',
            'Dry run. No credit was used.');
    }

    if (SEMAPHORE_API_KEY === '') {
        return sms_log_row($order, $status, $message, 'failed', null,
            'No Semaphore API key is configured.');
    }

    $result = semaphore_send($phone, $message);

    return sms_log_row(
        $order,
        $status,
        $message,
        $result['ok'] ? 'sent' : 'failed',
        $result['message_id'],
        $result['error']
    );
}

/**
 * Post a message to Semaphore.
 *
 * @return array{ok: bool, message_id: ?string, error: ?string}
 */
function semaphore_send(string $phone, string $message): array
{
    $payload = [
        'apikey'  => SEMAPHORE_API_KEY,
        'number'  => $phone,
        'message' => $message,
    ];

    $senderName = trim((string) setting('sms_sender_name', ''));
    if ($senderName !== '') {
        $payload['sendername'] = $senderName;
    }

    $ch = curl_init(SEMAPHORE_ENDPOINT);

    if ($ch === false) {
        return ['ok' => false, 'message_id' => null, 'error' => 'Could not start the request.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return [
            'ok'         => false,
            'message_id' => null,
            'error'      => mb_substr('Network error: ' . $error, 0, 255),
        ];
    }

    $decoded = json_decode((string) $body, true);

    if ($code < 200 || $code >= 300) {
        return [
            'ok'         => false,
            'message_id' => null,
            'error'      => mb_substr('Semaphore returned HTTP ' . $code . ': ' . (string) $body, 0, 255),
        ];
    }

    // A successful send comes back as a list of message objects.
    $first = is_array($decoded) ? ($decoded[0] ?? $decoded) : null;

    if (!is_array($first) || !isset($first['message_id'])) {
        return [
            'ok'         => false,
            'message_id' => null,
            'error'      => mb_substr('Unexpected response: ' . (string) $body, 0, 255),
        ];
    }

    return [
        'ok'         => true,
        'message_id' => (string) $first['message_id'],
        'error'      => null,
    ];
}

/** Write one row to sms_log and return its id. */
function sms_log_row(
    array   $order,
    string  $status,
    string  $message,
    string  $result,
    ?string $providerMessageId = null,
    ?string $error = null
): ?int {
    try {
        return db_insert(
            'INSERT INTO sms_log
                (order_id, phone, message, trigger_status, status, provider, provider_message_id, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $order['id'] ?? null,
                (string) $order['customer_phone'],
                mb_substr($message, 0, 640),
                $status,
                $result,
                'semaphore',
                $providerMessageId,
                $error === null ? null : mb_substr($error, 0, 255),
            ]
        );
    } catch (Throwable $e) {
        error_log('Could not write sms_log row: ' . $e->getMessage());

        return null;
    }
}

/** Every SMS sent for one order, oldest first. */
function sms_history_for_order(int $orderId): array
{
    return db_all(
        'SELECT * FROM sms_log WHERE order_id = ? ORDER BY created_at ASC, id ASC',
        [$orderId]
    );
}

/** Counts by result for a date range, used on the analytics page. */
function sms_usage_summary(string $from, string $to): array
{
    return db_all(
        'SELECT status, COUNT(*) AS total
         FROM sms_log
         WHERE created_at BETWEEN ? AND ?
         GROUP BY status',
        [$from . ' 00:00:00', $to . ' 23:59:59']
    );
}
