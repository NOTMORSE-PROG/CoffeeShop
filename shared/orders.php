<?php
/**
 * Order handling: placement, status transitions, and stock movement.
 *
 * Prices are always recalculated on the server from the database. Nothing the
 * browser sends about money is trusted, because the cart lives in the
 * customer's own session and a total posted from a form can be edited.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/audit.php';

/**
 * Price a cart against current database prices.
 *
 * The cart is a list of ['product_id' => int, 'quantity' => int,
 * 'option_ids' => int[]].
 *
 * @return array{items: array, subtotal: float, errors: string[]}
 */
function price_cart(array $cart): array
{
    $items    = [];
    $errors   = [];
    $subtotal = 0.0;

    foreach ($cart as $line) {
        $productId = (int) ($line['product_id'] ?? 0);
        $quantity  = max(1, min(50, (int) ($line['quantity'] ?? 1)));

        $product = db_one(
            'SELECT id, name, price, is_available FROM products WHERE id = ? LIMIT 1',
            [$productId]
        );

        if ($product === null) {
            $errors[] = 'One of the items in your cart is no longer on the menu.';
            continue;
        }

        if ((int) $product['is_available'] !== 1) {
            $errors[] = $product['name'] . ' is currently unavailable.';
            continue;
        }

        // Resolve the chosen options, again from the database, not from the post.
        $optionIds     = array_values(array_unique(array_map('intval', $line['option_ids'] ?? [])));
        $chosenOptions = [];
        $optionsTotal  = 0.0;

        if ($optionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($optionIds), '?'));

            $rows = db_all(
                "SELECT o.id, o.name, o.price_delta, g.name AS group_name
                 FROM options o
                 JOIN option_groups g ON g.id = o.group_id
                 JOIN product_option_groups pog
                      ON pog.group_id = g.id AND pog.product_id = ?
                 WHERE o.id IN ($placeholders)",
                array_merge([$productId], $optionIds)
            );

            foreach ($rows as $row) {
                $chosenOptions[] = [
                    'group_name'  => $row['group_name'],
                    'option_name' => $row['name'],
                    'price_delta' => (float) $row['price_delta'],
                ];
                $optionsTotal += (float) $row['price_delta'];
            }
        }

        $unitPrice = (float) $product['price'];
        $lineTotal = ($unitPrice + $optionsTotal) * $quantity;
        $subtotal += $lineTotal;

        $items[] = [
            'product_id'    => (int) $product['id'],
            'product_name'  => $product['name'],
            'unit_price'    => $unitPrice,
            'quantity'      => $quantity,
            'options'       => $chosenOptions,
            'options_total' => $optionsTotal,
            'line_total'    => $lineTotal,
        ];
    }

    return [
        'items'    => $items,
        'subtotal' => round($subtotal, 2),
        'errors'   => $errors,
    ];
}

/**
 * Create an order.
 *
 * Everything happens inside one transaction, so a failure partway through
 * cannot leave an order with half its items.
 *
 * @return array{ok: bool, order_id?: int, order_ref?: string, errors?: string[]}
 */
function place_order(array $cart, array $customer): array
{
    $closedReason = shop_closed_reason();
    if ($closedReason !== null) {
        return ['ok' => false, 'errors' => [$closedReason]];
    }

    $priced = price_cart($cart);

    if ($priced['items'] === []) {
        return [
            'ok'     => false,
            'errors' => $priced['errors'] ?: ['Your cart is empty.'],
        ];
    }

    if ($priced['errors'] !== []) {
        return ['ok' => false, 'errors' => $priced['errors']];
    }

    $orderType = ($customer['order_type'] ?? 'pickup') === 'delivery' ? 'delivery' : 'pickup';

    if ($orderType === 'delivery' && !delivery_available()) {
        return ['ok' => false, 'errors' => ['Delivery is not available at the moment.']];
    }

    $deliveryCity    = $orderType === 'delivery' ? ($customer['delivery_city'] ?? null) : null;
    $deliveryAddress = $orderType === 'delivery' ? ($customer['delivery_address'] ?? null) : null;

    if ($orderType === 'delivery' && ($deliveryAddress === null || trim($deliveryAddress) === '')) {
        return ['ok' => false, 'errors' => ['Please give a delivery address.']];
    }

    $deliveryFee = $orderType === 'delivery' ? delivery_fee_for_city($deliveryCity) : 0.0;
    $subtotal    = $priced['subtotal'];
    $total       = round($subtotal + $deliveryFee, 2);

    $paymentMethod = ($customer['payment_method'] ?? 'cash') === 'gcash' ? 'gcash' : 'cash';

    if ($paymentMethod === 'gcash' && !setting_bool('payment_gcash_enabled', true)) {
        return ['ok' => false, 'errors' => ['GCash is not available at the moment.']];
    }
    if ($paymentMethod === 'cash' && !setting_bool('payment_cash_enabled', true)) {
        return ['ok' => false, 'errors' => ['Payment at the counter is not available at the moment.']];
    }

    $phone = normalize_ph_mobile((string) ($customer['phone'] ?? ''));

    if ($phone === null) {
        return ['ok' => false, 'errors' => ['Please give a valid Philippine mobile number, for example 09171234567.']];
    }

    try {
        $result = db_transaction(function () use (
            $priced, $customer, $orderType, $deliveryAddress, $deliveryCity,
            $deliveryFee, $subtotal, $total, $paymentMethod, $phone
        ) {
            $customerId = upsert_customer((string) $customer['name'], $phone, $customer['email'] ?? null);

            // Retry on the very unlikely chance of a reference collision.
            $orderRef = null;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = generate_order_ref();
                $taken = db_value('SELECT COUNT(*) FROM orders WHERE order_ref = ?', [$candidate]);

                if ((int) $taken === 0) {
                    $orderRef = $candidate;
                    break;
                }
            }

            if ($orderRef === null) {
                throw new RuntimeException('Could not generate a unique order reference.');
            }

            $orderId = db_insert(
                'INSERT INTO orders
                    (order_ref, customer_id, customer_name, customer_phone, order_type,
                     delivery_address, delivery_city, payment_method, payment_status,
                     status, special_instructions, subtotal, delivery_fee, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderRef,
                    $customerId,
                    $customer['name'],
                    $phone,
                    $orderType,
                    $deliveryAddress,
                    $deliveryCity,
                    $paymentMethod,
                    'unpaid',
                    'pending',
                    $customer['special_instructions'] ?? null,
                    $subtotal,
                    $deliveryFee,
                    $total,
                ]
            );

            foreach ($priced['items'] as $item) {
                $orderItemId = db_insert(
                    'INSERT INTO order_items
                        (order_id, product_id, product_name, unit_price, quantity, options_total, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $orderId,
                        $item['product_id'],
                        $item['product_name'],
                        $item['unit_price'],
                        $item['quantity'],
                        $item['options_total'],
                        $item['line_total'],
                    ]
                );

                foreach ($item['options'] as $option) {
                    db_query(
                        'INSERT INTO order_item_options (order_item_id, group_name, option_name, price_delta)
                         VALUES (?, ?, ?, ?)',
                        [$orderItemId, $option['group_name'], $option['option_name'], $option['price_delta']]
                    );
                }
            }

            db_query(
                'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note)
                 VALUES (?, NULL, ?, NULL, ?)',
                [$orderId, 'pending', 'Order placed by the customer']
            );

            return ['order_id' => $orderId, 'order_ref' => $orderRef];
        });
    } catch (Throwable $e) {
        error_log('Order placement failed: ' . $e->getMessage());

        return ['ok' => false, 'errors' => ['We could not save your order. Please try again.']];
    }

    // Outside the transaction: a slow or failing SMS must not hold a lock.
    $order = find_order($result['order_id']);
    if ($order !== null) {
        send_order_sms($order, 'pending');
    }

    audit(
        'order.placed',
        'order',
        $result['order_ref'],
        sprintf('Order %s placed for %s', $result['order_ref'], peso($total)),
        null,
        ['total' => $total, 'order_type' => $orderType, 'payment_method' => $paymentMethod],
        null,
        'customer'
    );

    return [
        'ok'        => true,
        'order_id'  => $result['order_id'],
        'order_ref' => $result['order_ref'],
    ];
}

/** Find or create the customer record for a mobile number. */
function upsert_customer(string $name, string $phone, ?string $email = null): int
{
    $existing = db_one('SELECT id FROM customers WHERE phone = ? LIMIT 1', [$phone]);

    if ($existing !== null) {
        db_query(
            'UPDATE customers SET name = ?, order_count = order_count + 1 WHERE id = ?',
            [$name, $existing['id']]
        );

        return (int) $existing['id'];
    }

    return db_insert(
        'INSERT INTO customers (name, phone, email, order_count) VALUES (?, ?, ?, 1)',
        [$name, $phone, $email]
    );
}

/** One order with its items and chosen options. */
function find_order(int $orderId): ?array
{
    $order = db_one('SELECT * FROM orders WHERE id = ? LIMIT 1', [$orderId]);

    if ($order === null) {
        return null;
    }

    return hydrate_order($order);
}

/**
 * Look an order up for a customer, by reference plus mobile number.
 *
 * Both are required on purpose. A reference alone is guessable, and letting
 * anyone read any order by reference would leak names and addresses.
 */
function find_order_for_customer(string $orderRef, string $phone): ?array
{
    $normalized = normalize_ph_mobile($phone);

    if ($normalized === null) {
        return null;
    }

    $order = db_one(
        'SELECT * FROM orders WHERE order_ref = ? AND customer_phone = ? LIMIT 1',
        [strtoupper(trim($orderRef)), $normalized]
    );

    return $order === null ? null : hydrate_order($order);
}

/** Attach items, options and status history to an order row. */
function hydrate_order(array $order): array
{
    $orderId = (int) $order['id'];

    $items = db_all(
        'SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC',
        [$orderId]
    );

    foreach ($items as $index => $item) {
        $items[$index]['options'] = db_all(
            'SELECT group_name, option_name, price_delta
             FROM order_item_options WHERE order_item_id = ? ORDER BY id ASC',
            [$item['id']]
        );
    }

    $order['items']   = $items;
    $order['history'] = db_all(
        'SELECT h.*, u.full_name AS changed_by_name
         FROM order_status_history h
         LEFT JOIN admin_users u ON u.id = h.changed_by
         WHERE h.order_id = ? ORDER BY h.created_at ASC, h.id ASC',
        [$orderId]
    );

    return $order;
}

/**
 * Move an order to a new status.
 *
 * Refuses transitions that are not allowed by the flow, which keeps the order
 * queue honest even if a form is replayed or a button is double-clicked.
 *
 * @return array{ok: bool, error?: string}
 */
function change_order_status(int $orderId, string $newStatus, ?int $adminId, ?string $note = null): array
{
    if (!array_key_exists($newStatus, ORDER_STATUSES)) {
        return ['ok' => false, 'error' => 'That is not a valid status.'];
    }

    $order = db_one('SELECT * FROM orders WHERE id = ? LIMIT 1', [$orderId]);

    if ($order === null) {
        return ['ok' => false, 'error' => 'That order no longer exists.'];
    }

    $current = (string) $order['status'];

    if ($current === $newStatus) {
        return ['ok' => false, 'error' => 'The order is already ' . status_label($newStatus) . '.'];
    }

    if (!can_transition($current, $newStatus, (string) $order['order_type'])) {
        return [
            'ok'    => false,
            'error' => sprintf(
                'An order that is %s cannot be moved to %s.',
                status_label($current),
                status_label($newStatus)
            ),
        ];
    }

    try {
        db_transaction(function () use ($order, $orderId, $current, $newStatus, $adminId, $note) {
            $completedAt = in_array($newStatus, ['completed', 'cancelled'], true) ? date('Y-m-d H:i:s') : null;

            db_query(
                'UPDATE orders SET status = ?, completed_at = ?, cancel_reason = ? WHERE id = ?',
                [
                    $newStatus,
                    $completedAt,
                    $newStatus === 'cancelled' ? $note : $order['cancel_reason'],
                    $orderId,
                ]
            );

            db_query(
                'INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, note)
                 VALUES (?, ?, ?, ?, ?)',
                [$orderId, $current, $newStatus, $adminId, $note]
            );

            // Stock comes off once, when the order is accepted for preparation.
            if ($newStatus === 'preparing' && (int) $order['stock_deducted'] === 0) {
                deduct_stock_for_order($orderId);
                db_query('UPDATE orders SET stock_deducted = 1 WHERE id = ?', [$orderId]);
            }

            // Cancelling something already deducted puts the stock back.
            if ($newStatus === 'cancelled' && (int) $order['stock_deducted'] === 1) {
                restore_stock_for_order($orderId);
                db_query('UPDATE orders SET stock_deducted = 0 WHERE id = ?', [$orderId]);
            }
        });
    } catch (Throwable $e) {
        error_log('Status change failed for order ' . $orderId . ': ' . $e->getMessage());

        return ['ok' => false, 'error' => 'The status could not be saved. Please try again.'];
    }

    audit(
        $newStatus === 'cancelled' ? 'order.cancelled' : 'order.status_changed',
        'order',
        $order['order_ref'],
        sprintf('Order %s: %s to %s', $order['order_ref'], status_label($current), status_label($newStatus)),
        ['status' => $current],
        ['status' => $newStatus, 'note' => $note],
        $adminId
    );

    $fresh = find_order($orderId);
    if ($fresh !== null) {
        send_order_sms($fresh, $newStatus);
    }

    return ['ok' => true];
}

/** Take the recipe quantities for an order off the inventory. */
function deduct_stock_for_order(int $orderId): void
{
    db_query(
        'UPDATE inventory_items inv
         JOIN (
             SELECT pi.inventory_item_id AS item_id,
                    SUM(pi.qty_per_unit * oi.quantity) AS used
             FROM order_items oi
             JOIN product_ingredients pi ON pi.product_id = oi.product_id
             WHERE oi.order_id = ?
             GROUP BY pi.inventory_item_id
         ) AS usage_rows ON usage_rows.item_id = inv.id
         SET inv.stock_qty = GREATEST(0, inv.stock_qty - usage_rows.used)',
        [$orderId]
    );
}

/** Put the recipe quantities for a cancelled order back. */
function restore_stock_for_order(int $orderId): void
{
    db_query(
        'UPDATE inventory_items inv
         JOIN (
             SELECT pi.inventory_item_id AS item_id,
                    SUM(pi.qty_per_unit * oi.quantity) AS used
             FROM order_items oi
             JOIN product_ingredients pi ON pi.product_id = oi.product_id
             WHERE oi.order_id = ?
             GROUP BY pi.inventory_item_id
         ) AS usage_rows ON usage_rows.item_id = inv.id
         SET inv.stock_qty = inv.stock_qty + usage_rows.used',
        [$orderId]
    );
}

/**
 * Mark a GCash payment as received.
 *
 * This is a manual step by design. GCash here is a static QR the customer
 * scans, and the admin confirms the transfer landed. There is no merchant
 * API and no automatic reconciliation.
 */
function verify_payment(int $orderId, int $adminId, ?string $reference = null): array
{
    $order = db_one('SELECT id, order_ref, payment_status FROM orders WHERE id = ? LIMIT 1', [$orderId]);

    if ($order === null) {
        return ['ok' => false, 'error' => 'That order no longer exists.'];
    }

    if ($order['payment_status'] === 'paid') {
        return ['ok' => false, 'error' => 'This order is already marked as paid.'];
    }

    db_query(
        'UPDATE orders
         SET payment_status = ?, payment_reference = ?, payment_verified_by = ?, payment_verified_at = NOW()
         WHERE id = ?',
        ['paid', $reference, $adminId, $orderId]
    );

    audit(
        'order.payment_verified',
        'order',
        $order['order_ref'],
        'Payment marked as received for ' . $order['order_ref'],
        ['payment_status' => 'unpaid'],
        ['payment_status' => 'paid', 'reference' => $reference],
        $adminId
    );

    return ['ok' => true];
}

/**
 * The order queue for the admin dashboard.
 * Open orders first and oldest first, so the queue reads top to bottom.
 */
function order_queue(array $statuses = ['pending', 'preparing', 'ready', 'out_for_delivery']): array
{
    if ($statuses === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    return db_all(
        "SELECT o.*,
                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
         FROM orders o
         WHERE o.status IN ($placeholders)
         ORDER BY FIELD(o.status, 'pending', 'preparing', 'ready', 'out_for_delivery'),
                  o.placed_at ASC",
        $statuses
    );
}
