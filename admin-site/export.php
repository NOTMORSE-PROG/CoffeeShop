<?php
/**
 * Sales export as CSV, for a date range.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_login();

$from = get_string('from');
$to   = get_string('to');

if ($from === '' || strtotime($from) === false) {
    $from = date('Y-m-d', strtotime('-13 days'));
}

if ($to === '' || strtotime($to) === false) {
    $to = date('Y-m-d');
}

if (strtotime($from) > strtotime($to)) {
    [$from, $to] = [$to, $from];
}

$orders = db_all(
    "SELECT o.*,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count,
            (SELECT GROUP_CONCAT(CONCAT(oi.quantity, 'x ', oi.product_name) SEPARATOR '; ')
             FROM order_items oi WHERE oi.order_id = o.id) AS item_list
     FROM orders o
     WHERE o.placed_at BETWEEN ? AND ?
     ORDER BY o.placed_at ASC, o.id ASC",
    [$from . ' 00:00:00', $to . ' 23:59:59']
);

/**
 * A spreadsheet treats a leading =, +, - or @ as the start of a formula, so a
 * customer name beginning with one of those gets an apostrophe in front of it.
 */
function csv_cell(?string $value): string
{
    $value = (string) $value;

    if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
        return "'" . $value;
    }

    return $value;
}

$filename = sprintf('ourcoffee-sales-%s-to-%s.csv', $from, $to);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'wb');

// Excel needs the byte order mark to read the file as UTF-8.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Reference', 'Placed at', 'Customer', 'Mobile', 'Order type',
    'Delivery address', 'Delivery city', 'Items', 'Item detail',
    'Payment method', 'Payment status', 'Payment reference',
    'Status', 'Subtotal', 'Delivery fee', 'Total', 'Completed at',
]);

$revenue = 0.0;

foreach ($orders as $order) {
    if ($order['status'] !== 'cancelled') {
        $revenue += (float) $order['total'];
    }

    fputcsv($out, [
        csv_cell((string) $order['order_ref']),
        date('Y-m-d H:i:s', (int) strtotime((string) $order['placed_at'])),
        csv_cell((string) $order['customer_name']),
        csv_cell(format_ph_mobile((string) $order['customer_phone'])),
        $order['order_type'] === 'delivery' ? 'Delivery' : 'Pickup',
        csv_cell((string) ($order['delivery_address'] ?? '')),
        csv_cell((string) ($order['delivery_city'] ?? '')),
        (int) $order['item_count'],
        csv_cell((string) ($order['item_list'] ?? '')),
        $order['payment_method'] === 'gcash' ? 'GCash' : 'Cash',
        $order['payment_status'] === 'paid' ? 'Paid' : 'Unpaid',
        csv_cell((string) ($order['payment_reference'] ?? '')),
        status_label((string) $order['status']),
        number_format((float) $order['subtotal'], 2, '.', ''),
        number_format((float) $order['delivery_fee'], 2, '.', ''),
        number_format((float) $order['total'], 2, '.', ''),
        $order['completed_at'] === null
            ? ''
            : date('Y-m-d H:i:s', (int) strtotime((string) $order['completed_at'])),
    ]);
}

fputcsv($out, []);
fputcsv($out, ['Orders', count($orders)]);
fputcsv($out, ['Revenue excluding cancelled', number_format($revenue, 2, '.', '')]);
fputcsv($out, ['Range', $from . ' to ' . $to]);
fputcsv($out, ['Exported', date('Y-m-d H:i:s')]);

fclose($out);
exit;
