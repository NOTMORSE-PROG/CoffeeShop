<?php
/**
 * The dashboard's numbers and live queue.
 *
 * Shared by index.php and api/queue.php on purpose: the first paint and the
 * fifteen-second poll have to describe the queue in exactly the same shape,
 * otherwise the page quietly drifts away from the server.
 */

declare(strict_types=1);

require_once __DIR__ . '/layout.php';

/** The four tiles across the top of the dashboard. */
function dashboard_stats(): array
{
    $todayOrders = (int) db_value(
        'SELECT COUNT(*) FROM orders WHERE DATE(placed_at) = CURDATE()'
    );

    // Cancelled orders never counted as money taken.
    $todayRevenue = (float) db_value(
        "SELECT COALESCE(SUM(total), 0) FROM orders
         WHERE DATE(placed_at) = CURDATE() AND status <> 'cancelled'"
    );

    $pending = (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'pending'");

    $lowStock = (int) db_value(
        'SELECT COUNT(*) FROM inventory_items WHERE is_active = 1 AND stock_qty <= reorder_level'
    );

    return [
        'today_orders'    => $todayOrders,
        'today_revenue'   => $todayRevenue,
        'today_revenue_display' => peso($todayRevenue),
        'pending_count'   => $pending,
        'low_stock_count' => $lowStock,
    ];
}

/** One queue row, flattened for both the server render and the JSON poll. */
function queue_row(array $order): array
{
    $status = (string) $order['status'];
    $type   = (string) $order['order_type'];

    $next = [];
    foreach (allowed_next_statuses($status, $type) as $candidate) {
        $next[] = ['status' => $candidate, 'label' => status_label($candidate)];
    }

    return [
        'id'             => (int) $order['id'],
        'order_ref'      => (string) $order['order_ref'],
        'customer_name'  => (string) $order['customer_name'],
        'customer_phone' => format_ph_mobile((string) $order['customer_phone']),
        'status'         => $status,
        'status_label'   => status_label($status),
        'order_type'     => $type,
        'type_label'     => $type === 'delivery' ? 'Delivery' : 'Pickup',
        'payment_method' => (string) $order['payment_method'],
        'payment_status' => (string) $order['payment_status'],
        'item_count'     => (int) ($order['item_count'] ?? 0),
        'total'          => (float) $order['total'],
        'total_display'  => peso($order['total']),
        'placed_at'      => date('g:i A', (int) strtotime((string) $order['placed_at'])),
        'waiting'        => time_ago((string) $order['placed_at']),
        'view_url'       => admin_url('order-view.php') . '?id=' . (int) $order['id'],
        'next_statuses'  => $next,
    ];
}

/** Everything the dashboard needs, in one payload. */
function queue_payload(): array
{
    $rows = [];

    foreach (order_queue() as $order) {
        $rows[] = queue_row($order);
    }

    return [
        'ok'           => true,
        'generated_at' => date('c'),
        'stats'        => dashboard_stats(),
        'orders'       => $rows,
    ];
}
