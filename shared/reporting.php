<?php
/** Sales use completed orders, dated by fulfilment in the shop timezone. */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function report_date_range(string $from, string $to): array
{
    $today = new DateTimeImmutable('today');
    $parse = static function (string $value): ?DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    };
    $start = $parse($from) ?? $today;
    $end = $parse($to) ?? $today;
    if ($start > $end) [$start, $end] = [$end, $start];
    $end = min($end, $today);
    $start = min($start, $end);
    $start = max($start, $end->modify('-365 days'));
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function daily_sales(string $from, string $to): array
{
    $rows = db_all("SELECT DATE(COALESCE(completed_at, placed_at)) AS day,
        COUNT(*) AS orders, SUM(total) AS revenue
        FROM orders WHERE status = 'completed'
        AND COALESCE(completed_at, placed_at) >= ? AND COALESCE(completed_at, placed_at) < ?
        GROUP BY DATE(COALESCE(completed_at, placed_at)) ORDER BY day",
        [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
    $byDay = array_column($rows, null, 'day');
    $days = [];
    for ($day = new DateTimeImmutable($from); $day <= new DateTimeImmutable($to); $day = $day->modify('+1 day')) {
        $key = $day->format('Y-m-d');
        $days[] = $byDay[$key] ?? ['day' => $key, 'orders' => 0, 'revenue' => '0.00'];
    }
    return $days;
}

function best_selling_products(string $from, string $to, int $limit = 5): array
{
    $limit = max(1, min(10, $limit));
    return db_all("SELECT oi.product_id, MAX(oi.product_name) AS product_name,
        SUM(oi.quantity) AS quantity, SUM(oi.line_total) AS revenue
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE o.status = 'completed'
        AND COALESCE(o.completed_at, o.placed_at) >= ? AND COALESCE(o.completed_at, o.placed_at) < ?
        GROUP BY oi.product_id, CASE WHEN oi.product_id IS NULL THEN oi.product_name ELSE '' END
        ORDER BY quantity DESC, revenue DESC, product_name ASC, oi.product_id ASC LIMIT " . $limit,
        [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
}
