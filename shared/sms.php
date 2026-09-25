<?php
/**
 * SMS order status notification.
 *
 * Two ways out, chosen in Settings.
 *
 * 1. phone     The shop's own handset drains a queue. Nothing is sent from
 *              here: a message is written as `queued` and the handset asks
 *              for work on its own schedule. This is the only arrangement
 *              that works on free hosting, where the web server has no route
 *              to a phone behind a home router or on mobile data, and where
 *              outbound calls to third parties are often blocked outright.
 *              It costs nothing per message beyond the owner's own plan.
 *
 * 2. semaphore The paid API, sent immediately. Kept as the fallback for when
 *              the shop would rather not depend on a handset being awake.
 *
 * Whichever is in use, two rules hold. Every attempt is recorded in sms_log,
 * so the spend and the failures are visible rather than a surprise. And a
 * messaging problem never fails an order: if nothing can be sent, the order
 * still stands and the customer can follow it on the tracking page.
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
    $total = peso_sms($order['total']);
    $shop  = (string) setting('shop_name', 'Our Coffee Shop');

    $message = match ($status) {
        'pending' => sprintf(
            '%s: Hi %s, we got your order %s. Total %s. We will text you when it is being prepared.',
            $shop, $name, $ref, $total
        ),
        'preparing' => sprintf(
            '%s: Your order %s is being prepared now. We will text you again once it is ready.',
            $shop, $ref
        ),
        'ready' => $order['order_type'] === 'delivery'
            ? sprintf('%s: Your order %s is ready and will be sent out shortly.', $shop, $ref)
            : sprintf('%s: Your order %s is ready for pickup. See you at the shop.', $shop, $ref),
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
        default => sprintf('%s: Your order %s is now %s.', $shop, $ref,
                      status_label($status, $order['order_type'] ?? null)),
    };

    // Strip anything outside GSM-7 and keep the message to a single credit.
    // A shop name or a customer name with an accent would otherwise push the
    // whole message into UCS-2, where one credit covers only 70 characters.
    $message = gsm7_safe($message);

    return mb_strlen($message) > 160 ? mb_substr($message, 0, 157) . '...' : $message;
}

/** Whether the owner has SMS switched on for this particular status. */
function sms_enabled_for_status(string $status): bool
{
    if (!setting_bool('sms_enabled', true)) {
        return false;
    }

    return setting_bool('sms_on_' . $status, false);
}

/** Which way messages go out: phone, semaphore, or off. */
function sms_provider(): string
{
    $provider = strtolower(trim((string) setting('sms_provider', 'phone')));

    return in_array($provider, ['phone', 'semaphore', 'off'], true) ? $provider : 'off';
}

/**
 * Handle the notification for an order status.
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

    // Dry run wins over everything, so the team can rehearse the whole flow
    // without a handset and without an API key.
    if (SMS_DRY_RUN) {
        write_log('sms.log', sprintf('DRY RUN to %s: %s', $phone, $message));

        return sms_log_row($order, $status, $message, 'sent', 'dry-run',
            'Dry run. Nothing was actually sent.', $phone);
    }

    return match (sms_provider()) {
        'phone'     => sms_queue_for_handset($order, $status, $message, $phone),
        'semaphore' => sms_send_via_semaphore($order, $status, $message, $phone),
        default     => sms_log_row($order, $status, $message, 'skipped', null,
                           'Sending is switched off in Settings.', $phone),
    };
}

/**
 * Put a message on the queue for the shop handset to collect.
 *
 * A status text has a short shelf life, so each one carries an expiry.
 * Telling someone their order is being prepared an hour after the fact is
 * worse than saying nothing at all.
 */
function sms_queue_for_handset(array $order, string $status, string $message, string $phone): ?int
{
    $ttl = max(5, (int) setting('sms_ttl_minutes', 45));

    try {
        return db_insert(
            'INSERT INTO sms_log
                (order_id, phone, message, trigger_status, status, provider, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $order['id'] ?? null,
                $phone,
                mb_substr($message, 0, 640),
                $status,
                'queued',
                'phone',
                date('Y-m-d H:i:s', time() + ($ttl * 60)),
            ]
        );
    } catch (Throwable $e) {
        error_log('Could not queue an SMS: ' . $e->getMessage());

        return null;
    }
}

/** Send immediately through the paid API. */
function sms_send_via_semaphore(array $order, string $status, string $message, string $phone): ?int
{
    if (SEMAPHORE_API_KEY === '') {
        return sms_log_row($order, $status, $message, 'failed', null,
            'No Semaphore API key is configured.', $phone);
    }

    $result = semaphore_send($phone, $message);

    $id = sms_log_row(
        $order,
        $status,
        $message,
        $result['ok'] ? 'sent' : 'failed',
        $result['message_id'],
        $result['error'],
        $phone
    );

    if ($result['ok'] && $id !== null) {
        db_query('UPDATE sms_log SET sent_at = NOW() WHERE id = ?', [$id]);
    }

    return $id;
}

// ---------------------------------------------------------------------------
// The queue, as the handset sees it
// ---------------------------------------------------------------------------

/**
 * Hand the next batch of messages to a handset.
 *
 * Claiming is what stops one handset retrying, or two handsets running at
 * once, from sending the same text twice. A claim that is never confirmed
 * goes stale after a timeout and the message returns to the queue.
 *
 * @return array<int, array{id: int, to: string, message: string}>
 */
function sms_claim_batch(string $deviceId): array
{
    $batch   = max(1, min(20, (int) setting('sms_batch_size', 5)));
    $timeout = max(30, (int) setting('sms_claim_timeout_seconds', 120));
    $maxTry  = max(1, (int) setting('sms_max_attempts', 3));

    return db_transaction(static function () use ($batch, $timeout, $maxTry, $deviceId): array {
        // Abandon anything past its shelf life before handing work out.
        db_query(
            "UPDATE sms_log
             SET status = 'failed',
                 error_message = 'Expired before the handset collected it'
             WHERE status = 'queued' AND expires_at IS NOT NULL AND expires_at < NOW()"
        );

        // And anything handed over too many times without a confirmation.
        db_query(
            "UPDATE sms_log
             SET status = 'failed',
                 error_message = 'Given to the handset too many times without confirmation'
             WHERE status = 'queued' AND attempts >= ?",
            [$maxTry]
        );

        // LIMIT cannot be bound, so the value is clamped to an int above.
        $rows = db_all(
            "SELECT id, phone, message
             FROM sms_log
             WHERE status = 'queued'
               AND (claimed_at IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
             ORDER BY id ASC
             LIMIT " . $batch . "
             FOR UPDATE",
            [$timeout]
        );

        if ($rows === []) {
            return [];
        }

        $ids = array_map('intval', array_column($rows, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        db_query(
            "UPDATE sms_log
             SET claimed_at = NOW(), attempts = attempts + 1, device_id = ?
             WHERE id IN ($placeholders)",
            array_merge([mb_substr($deviceId, 0, 64)], $ids)
        );

        return array_map(
            static fn (array $row): array => [
                'id'      => (int) $row['id'],
                'to'      => (string) $row['phone'],
                'message' => (string) $row['message'],
            ],
            $rows
        );
    });
}

/**
 * Record what the handset did with a batch.
 *
 * @param array<int, array{id: int, ok: bool, error?: string}> $results
 * @return array{sent: int, failed: int, ignored: int}
 */
function sms_record_results(array $results, string $deviceId): array
{
    $sent = 0;
    $failed = 0;
    $ignored = 0;

    foreach ($results as $result) {
        $id = (int) ($result['id'] ?? 0);

        if ($id <= 0) {
            $ignored++;
            continue;
        }

        // Only a message still on the queue may be closed off, so a replayed
        // or malformed report cannot rewrite history that is already settled.
        $row = db_one(
            "SELECT id FROM sms_log WHERE id = ? AND status = 'queued' LIMIT 1",
            [$id]
        );

        if ($row === null) {
            $ignored++;
            continue;
        }

        if (!empty($result['ok'])) {
            db_query(
                "UPDATE sms_log
                 SET status = 'sent', sent_at = NOW(), device_id = ?, error_message = NULL
                 WHERE id = ?",
                [mb_substr($deviceId, 0, 64), $id]
            );
            $sent++;
        } else {
            db_query(
                "UPDATE sms_log
                 SET status = 'failed', device_id = ?, error_message = ?
                 WHERE id = ?",
                [
                    mb_substr($deviceId, 0, 64),
                    mb_substr((string) ($result['error'] ?? 'The handset could not send it.'), 0, 255),
                    $id,
                ]
            );
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'ignored' => $ignored];
}

/** How the queue currently looks, for the admin. */
function sms_queue_summary(): array
{
    $row = db_one(
        "SELECT
            SUM(status = 'queued') AS queued,
            SUM(status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)) AS failed_today,
            SUM(status = 'sent'   AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)) AS sent_today,
            MIN(CASE WHEN status = 'queued' THEN created_at END) AS oldest_queued
         FROM sms_log"
    );

    return [
        'queued'        => (int) ($row['queued'] ?? 0),
        'failed_today'  => (int) ($row['failed_today'] ?? 0),
        'sent_today'    => (int) ($row['sent_today'] ?? 0),
        'oldest_queued' => $row['oldest_queued'] ?? null,
    ];
}

/** Note that the handset checked in. */
function sms_touch_device(string $deviceId): void
{
    set_setting('sms_device_last_seen', date('Y-m-d H:i:s'));
    set_setting('sms_device_last_ip', client_ip());

    if (trim((string) setting('sms_device_name', '')) === '') {
        set_setting('sms_device_name', mb_substr($deviceId, 0, 64));
    }
}

/**
 * Check the token a handset presented.
 *
 * Constant-time, so the endpoint cannot be used to guess the token one
 * character at a time by measuring how long a rejection takes.
 */
function sms_token_valid(?string $presented): bool
{
    $expected = trim((string) setting('sms_device_token', ''));

    if ($expected === '' || $presented === null || trim($presented) === '') {
        return false;
    }

    return hash_equals($expected, trim($presented));
}

/** A fresh device token. Shown once, then it lives in Settings. */
function sms_generate_device_token(): string
{
    return bin2hex(random_bytes(24));
}

// ---------------------------------------------------------------------------
// Semaphore
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Logging and reporting
// ---------------------------------------------------------------------------

/** Write one row to sms_log and return its id. */
function sms_log_row(
    array   $order,
    string  $status,
    string  $message,
    string  $result,
    ?string $providerMessageId = null,
    ?string $error = null,
    ?string $phone = null
): ?int {
    try {
        return db_insert(
            'INSERT INTO sms_log
                (order_id, phone, message, trigger_status, status, provider, provider_message_id, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $order['id'] ?? null,
                $phone ?? (string) $order['customer_phone'],
                mb_substr($message, 0, 640),
                $status,
                $result,
                SMS_DRY_RUN ? 'dry-run' : sms_provider(),
                $providerMessageId,
                $error === null ? null : mb_substr($error, 0, 255),
            ]
        );
    } catch (Throwable $e) {
        error_log('Could not write sms_log row: ' . $e->getMessage());

        return null;
    }
}

/** Every SMS for one order, oldest first. */
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
