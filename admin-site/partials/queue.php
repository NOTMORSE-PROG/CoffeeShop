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

    // A sale is fulfilled: pending and cancelled orders are not sales.
    $todayRevenue = (float) db_value(
        "SELECT COALESCE(SUM(total), 0) FROM orders
         WHERE DATE(COALESCE(completed_at, placed_at)) = CURDATE() AND status = 'completed'"
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
        $next[] = ['status' => $candidate, 'label' => order_action_label($candidate, $type)];
    }

    return [
        'id'             => (int) $order['id'],
        'order_ref'      => (string) $order['order_ref'],
        'customer_name'  => (string) $order['customer_name'],
        'customer_phone' => format_ph_mobile((string) $order['customer_phone']),
        'status'         => $status,
        'status_label'   => status_label($status, $type),
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
    $data = dashboard_orders();
    return [
        'ok' => true,
        'generated_at' => date('c'),
        'stats' => dashboard_stats(),
        'orders' => array_map('queue_row', $data['orders']),
        'pagination' => ['page' => $data['page'], 'pages' => $data['pages'], 'total' => $data['total']],
        'categories' => $data['categories'],
    ];
}

/** Validated filters shared by initial rendering and live polling. */
function dashboard_orders(): array
{
    $filters = [];
    $where = [];
    $params = [];
    foreach (['status' => array_merge(['active'], array_keys(ORDER_STATUSES)),
              'order_type' => ['pickup', 'delivery'],
              'payment_method' => array_merge(['cash'], array_keys(shop_wallets())),
              'payment_status' => ['paid', 'unpaid']] as $key => $allowed) {
        $value = get_string($key, $key === 'status' ? 'active' : '');
        $filters[$key] = in_array($value, $allowed, true) ? $value : '';
        if ($filters[$key] === 'active' && $key === 'status') {
            $where[] = "o.status IN ('pending', 'preparing', 'ready', 'out_for_delivery')";
        } elseif ($filters[$key] !== '') {
            $where[] = 'o.' . $key . ' = ?';
            $params[] = $filters[$key];
        }
    }
    $filters['q'] = mb_substr(get_string('q'), 0, 80);
    if ($filters['q'] !== '') {
        $where[] = '(o.order_ref LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.customer_phone = ?)';
        $term = '%' . $filters['q'] . '%';
        array_push($params, $term, $term, $term, normalize_ph_mobile($filters['q']) ?? $filters['q']);
    }
    $filters['sort'] = get_string('sort') === 'oldest' ? 'oldest' : 'recent';
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int) db_value('SELECT COUNT(*) FROM orders o' . $clause, $params);
    $pages = max(1, (int) ceil($total / 10));
    $page = min($pages, max(1, get_int('page', 1)));
    $direction = $filters['sort'] === 'oldest' ? 'ASC' : 'DESC';
    $orders = db_all('SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
        FROM orders o' . $clause . " ORDER BY o.placed_at $direction, o.id $direction LIMIT 10 OFFSET " . (($page - 1) * 10), $params);
    $categories = array_fill_keys(array_keys(ORDER_STATUSES), 0);
    foreach (db_all('SELECT status, COUNT(*) AS total FROM orders GROUP BY status') as $row) {
        $categories[$row['status']] = (int) $row['total'];
    }
    return compact('orders', 'filters', 'total', 'pages', 'page', 'categories');
}
