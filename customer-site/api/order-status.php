<?php
/**
 * Order status as JSON, for the tracking page to poll.
 *
 * Requires both the reference and the matching mobile number, exactly like
 * the tracking page itself, so this endpoint cannot be walked to enumerate
 * orders.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/security.php';
require_once dirname(__DIR__, 2) . '/shared/orders.php';

start_session('ourcoffee_shop');
send_security_headers();

$ref   = strtoupper(get_string('ref'));
$phone = get_string('phone');

if ($ref === '' || $phone === '') {
    json_response(['error' => 'Missing reference or number.'], 400);
}

// The tracking page polls this, so the allowance is higher than the form's,
// but it still cannot be used to walk through order references.
if (throttle_exceeded('order_status_api', 120, 15)) {
    json_response(['error' => 'Too many requests.'], 429);
}

$order = find_order_for_customer($ref, $phone);

if ($order === null) {
    throttle_record('order_status_api', false);
    json_response(['error' => 'Not found.'], 404);
}

json_response([
    'order_ref'    => $order['order_ref'],
    'status'       => $order['status'],
    'status_label' => status_label((string) $order['status'], (string) $order['order_type']),
    'payment_status' => $order['payment_status'],
    'updated_at'   => $order['updated_at'],
]);
