<?php
/**
 * The endpoint the shop handset talks to.
 *
 * The handset asks for work and reports back what it did. It is the phone
 * that reaches out, never the server, because on free hosting there is no
 * route from the web server to a phone behind a home router or on mobile
 * data, and outbound calls to third parties are often blocked outright.
 *
 * This is a machine endpoint, so it authenticates with a bearer token rather
 * than an admin session. That token is the only thing standing between the
 * public internet and the shop's message queue, so:
 *
 *   - it is compared in constant time
 *   - failures are throttled per IP, to make guessing impractical
 *   - a rejection says nothing about why
 *   - nothing but queued messages is ever returned
 *
 * Usage, with the token from Settings:
 *
 *   GET  ...?action=pull&device=shop-phone
 *        Authorization: Bearer <token>
 *        -> { "ok": true, "messages": [ { "id": 12, "to": "639...", "message": "..." } ] }
 *
 *   POST ...?action=report&device=shop-phone
 *        Authorization: Bearer <token>
 *        Content-Type: application/json
 *        { "results": [ { "id": 12, "ok": true }, { "id": 13, "ok": false, "error": "no signal" } ] }
 *        -> { "ok": true, "sent": 1, "failed": 1, "ignored": 0 }
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/security.php';
require_once dirname(__DIR__, 2) . '/shared/sms.php';

// No session: this is a machine caller, and a session cookie would only be
// something else to leak.
send_security_headers();
header('Content-Type: application/json; charset=utf-8');

// Some gateway apps cannot set an Authorization header, so a query parameter
// is accepted too. It is the weaker option because it lands in server logs,
// and the setup notes say so.
function presented_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (is_string($header) && preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1) {
        return trim($m[1]);
    }

    $query = $_GET['token'] ?? null;

    return is_string($query) && $query !== '' ? $query : null;
}

/** Stop and say no, without hinting at why. */
function deny(int $code = 401): never
{
    throttle_record('sms_gateway', false);

    json_response(['ok' => false, 'error' => 'Not authorised.'], $code);
}

// Guessing the token has to be expensive. Legitimate polling never fails, so
// a burst of failures from one address is not a normal caller.
if (throttle_exceeded('sms_gateway', 20, 15)) {
    json_response(['ok' => false, 'error' => 'Too many attempts. Try again later.'], 429);
}

if (!sms_token_valid(presented_token())) {
    deny();
}

// The handset names itself so the admin can tell which one is connected, and
// so a message can be traced to the phone that sent it.
$deviceId = trim((string) ($_GET['device'] ?? ''));
$deviceId = $deviceId !== '' ? preg_replace('/[^A-Za-z0-9._-]/', '', $deviceId) : 'handset';
$deviceId = mb_substr((string) $deviceId, 0, 64) ?: 'handset';

if (sms_provider() !== 'phone') {
    json_response([
        'ok'      => false,
        'error'   => 'The shop is not set to send through a handset right now.',
        'provider' => sms_provider(),
    ], 409);
}

sms_touch_device($deviceId);

$action = $_GET['action'] ?? 'pull';

// --- Collect work -----------------------------------------------------------
if ($action === 'pull') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_response(['ok' => false, 'error' => 'Use GET to collect messages.'], 405);
    }

    $messages = sms_claim_batch($deviceId);

    json_response([
        'ok'       => true,
        'device'   => $deviceId,
        'count'    => count($messages),
        'messages' => $messages,
    ]);
}

// --- Report what happened ----------------------------------------------------
if ($action === 'report') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['ok' => false, 'error' => 'Use POST to report results.'], 405);
    }

    $raw = file_get_contents('php://input');

    // Accept a JSON body, or a plain form post for the simpler gateway apps.
    $payload = json_decode((string) $raw, true);

    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $results = $payload['results'] ?? null;

    // A single-result shorthand, since some apps can only post flat fields.
    if ($results === null && isset($payload['id'])) {
        $results = [[
            'id'    => $payload['id'],
            'ok'    => filter_var($payload['ok'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'error' => $payload['error'] ?? null,
        ]];
    }

    if (!is_array($results)) {
        json_response(['ok' => false, 'error' => 'Send a results array.'], 400);
    }

    if (count($results) > 50) {
        json_response(['ok' => false, 'error' => 'Too many results in one report.'], 400);
    }

    // Normalise before anything touches the database.
    $clean = [];

    foreach ($results as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $clean[] = [
            'id'    => (int) ($entry['id'] ?? 0),
            'ok'    => filter_var($entry['ok'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'error' => isset($entry['error']) ? (string) $entry['error'] : null,
        ];
    }

    $outcome = sms_record_results($clean, $deviceId);

    json_response([
        'ok'      => true,
        'device'  => $deviceId,
        'sent'    => $outcome['sent'],
        'failed'  => $outcome['failed'],
        'ignored' => $outcome['ignored'],
    ]);
}

// --- A health check the owner can open in a browser ---------------------------
if ($action === 'status') {
    $summary = sms_queue_summary();

    json_response([
        'ok'        => true,
        'device'    => $deviceId,
        'provider'  => sms_provider(),
        'queued'    => $summary['queued'],
        'sent_today'   => $summary['sent_today'],
        'failed_today' => $summary['failed_today'],
    ]);
}

json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
