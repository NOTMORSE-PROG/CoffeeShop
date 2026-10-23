<?php
declare(strict_types=1);
if (!function_exists('is_owner') || !is_owner()) {
    http_response_code(403);
    exit('You do not have permission to open this page.');
}
require_once __DIR__ . '/../../shared/reporting.php';
// --- Date range ------------------------------------------------------------
// Sales open on the current shop day; other periods are selected explicitly.

$today = date('Y-m-d');
[$from, $to] = report_date_range(get_string('from'), get_string('to'));

$rangeStart = $from . ' 00:00:00';
$rangeEnd   = $to . ' 23:59:59';
$range      = [$rangeStart, $rangeEnd];

// --- Headline numbers ------------------------------------------------------

$totals = db_one(
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END), 0) AS revenue,
            SUM(status = 'completed') AS completed,
            SUM(status = 'cancelled') AS cancelled
     FROM orders WHERE placed_at BETWEEN ? AND ?",
    $range
);

$orderCount = (int) ($totals['orders'] ?? 0);
$revenue    = (float) ($totals['revenue'] ?? 0);
$completed  = (int) ($totals['completed'] ?? 0);
$cancelled  = (int) ($totals['cancelled'] ?? 0);
$average    = $orderCount > 0 ? $revenue / max(1, $orderCount - $cancelled) : 0.0;

// --- Revenue per day, with the quiet days filled in ------------------------

$salesDays = daily_sales($from, $to);
$revenue = array_sum(array_column($salesDays, 'revenue'));
$salesCount = array_sum(array_column($salesDays, 'orders'));
$average = $salesCount > 0 ? $revenue / $salesCount : 0;
$revenueLabels = array_map(static fn ($row) => date('j M', strtotime($row['day'])), $salesDays);
$revenueValues = array_map(static fn ($row) => (float) $row['revenue'], $salesDays);
$ordersValues = array_map(static fn ($row) => (int) $row['orders'], $salesDays);

// --- Orders by status ------------------------------------------------------

$statusRows = db_all(
    'SELECT status, COUNT(*) AS total FROM orders
     WHERE placed_at BETWEEN ? AND ? GROUP BY status',
    $range
);

$statusCounts = [];
foreach ($statusRows as $row) {
    $statusCounts[(string) $row['status']] = (int) $row['total'];
}

$statusLabels = [];
$statusValues = [];
$statusKeys   = [];

foreach (ORDER_STATUSES as $key => $label) {
    if (($statusCounts[$key] ?? 0) === 0) {
        continue;
    }

    $statusKeys[]   = $key;
    $statusLabels[] = $label;
    $statusValues[] = $statusCounts[$key];
}

// --- Top sellers -----------------------------------------------------------

$topRows = best_selling_products($from, $to, 10);

$topLabels = [];
$topValues = [];

foreach ($topRows as $row) {
    $topLabels[] = (string) $row['product_name'];
    $topValues[] = (int) $row['quantity'];
}

// --- Orders by hour --------------------------------------------------------

$hourRows = db_all(
    'SELECT HOUR(placed_at) AS hour, COUNT(*) AS total FROM orders
     WHERE placed_at BETWEEN ? AND ? GROUP BY HOUR(placed_at)',
    $range
);

$hourCounts = array_fill(0, 24, 0);
foreach ($hourRows as $row) {
    $hourCounts[(int) $row['hour']] = (int) $row['total'];
}

$hourLabels = [];
for ($hour = 0; $hour < 24; $hour++) {
    $hourLabels[] = date('ga', mktime($hour, 0, 0, 1, 1, 2000));
}

// --- SMS usage -------------------------------------------------------------

$smsCounts = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'queued' => 0];

foreach (sms_usage_summary($from, $to) as $row) {
    $key = (string) $row['status'];

    if (array_key_exists($key, $smsCounts)) {
        $smsCounts[$key] = (int) $row['total'];
    }
}

$smsTotal = array_sum($smsCounts);

// --- Payload for the charts ------------------------------------------------

$chartData = [
    'revenue' => [
        'labels'  => $revenueLabels,
        'revenue' => $revenueValues,
        'orders'  => $ordersValues,
    ],
    'status' => [
        'labels' => $statusLabels,
        'keys'   => $statusKeys,
        'values' => $statusValues,
    ],
    'top' => [
        'labels' => $topLabels,
        'values' => $topValues,
    ],
    'hours' => [
        'labels' => $hourLabels,
        'values' => array_values($hourCounts),
    ],
    'sms' => [
        'labels' => array_map('sms_status_label', ['sent', 'failed', 'skipped', 'queued']),
        'keys'   => ['sent', 'failed', 'skipped', 'queued'],
        'values' => array_values($smsCounts),
    ],
];

$exportQuery = http_build_query(['from' => $from, 'to' => $to]);

?>

<header class="analytics-heading"><div><p class="eyebrow">Sales overview</p><h2><?= $from === $today && $to === $today ? "Today's sales" : 'Sales for selected dates' ?></h2><p class="small subtle"><?= e(date('D, j M Y', strtotime($from))) ?><?= $from !== $to ? ' to ' . e(date('D, j M Y', strtotime($to))) : '' ?> &middot; Completed orders only</p></div></header>
<details class="card analytics-date-control"<?= $from !== $today || $to !== $today ? ' open' : '' ?>>
<summary>Change dates <span class="small subtle"><?= e($from === $today && $to === $today ? 'Today' : $from . ' to ' . $to) ?></span></summary>
<form class="filter-bar analytics-range" method="get" action="<?= e(admin_url('index.php')) ?>" data-no-guard>
  <input type="hidden" name="view" value="sales">
  <div class="filter-grid filter-grid-sm">
    <div class="field">
      <label class="label" for="from">From</label>
      <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>" max="<?= e($today) ?>">
    </div>

    <div class="field">
      <label class="label" for="to">To</label>
      <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>" max="<?= e($today) ?>">
    </div>
  </div>

  <div class="filter-actions">
    <button type="submit" class="btn btn-sm">Apply range</button>
    <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('index.php')) ?>?view=sales">Today</a>
    <span class="grow"></span>
    <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('export.php')) ?>?<?= e($exportQuery) ?>">
      <?= admin_icon('icon-receipt', 'icon-sm') ?> Export sales CSV
    </a>
  </div>
</form>
</details>

<section class="stat-grid" aria-label="Totals for the selected range">
  <div class="card stat">
    <p class="stat-label">Orders placed</p>
    <p class="stat-value tabular"><?= number_format($orderCount) ?></p>
    <p class="stat-note">Placed in this range; <?= number_format($cancelled) ?> cancelled</p>
  </div>

  <div class="card stat">
    <p class="stat-label">Sales</p>
    <p class="stat-value tabular"><?= peso($revenue) ?></p>
    <p class="stat-note">Completed orders, by completion date</p>
  </div>

  <div class="card stat">
    <p class="stat-label">Average order</p>
    <p class="stat-value tabular"><?= peso($average) ?></p>
    <p class="stat-note">Across completed sales in this range</p>
  </div>

  <div class="card stat">
    <p class="stat-label">Completed orders</p>
    <p class="stat-value tabular"><?= number_format($salesCount) ?></p>
    <p class="stat-note">Completed in this date range</p>
  </div>
</section>

<?php
/*
 * The charts answer three different questions, so they are three tabs rather
 * than one column five cards tall. The range picker and the four figures above
 * stay put, because they are the summary every tab is read against.
 */
?>
<div class="tabs" data-tabs>
  <div class="tablist" role="tablist" aria-label="Analytics sections">
    <button type="button" class="tab" role="tab" id="tab-sales-tab" aria-controls="tab-sales"
            aria-selected="true" tabindex="0">Sales overview</button>
    <button type="button" class="tab" role="tab" id="tab-orders-tab" aria-controls="tab-orders"
            aria-selected="false" tabindex="-1">Order trends</button>
    <button type="button" class="tab" role="tab" id="tab-sms-tab" aria-controls="tab-sms"
            aria-selected="false" tabindex="-1">SMS activity</button>
  </div>

  <div class="tabpanel chart-grid" role="tabpanel" id="tab-sales" aria-labelledby="tab-sales-tab">

  <section class="card">
    <div class="card-header">
      <h2>Sales per day</h2>
      <?php if ($orderCount > 0): ?>
        <span class="small subtle"><?= count($revenueLabels) ?> days</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($salesCount === 0): ?>
        <div class="empty"><p class="mb-0">No completed sales in this range.</p></div>
      <?php else: ?>
        <div class="chart-box">
          <canvas id="chart-revenue" role="img"
                  aria-label="Completed sales per day across the selected range"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <h2><span aria-hidden="true">&#9733;</span> Best sellers</h2>
      <?php if ($topValues !== []): ?>
        <span class="small subtle">Top <?= count($topLabels) ?> by items sold</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($topValues === []): ?>
        <div class="empty"><p class="mb-0">Nothing has been sold in this range yet.</p></div>
      <?php else: ?>
        <div class="chart-box chart-box-tall">
          <canvas id="chart-top" role="img" aria-label="The ten best selling items by quantity"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </section>
  <details class="card chart-wide daily-sales-details">
    <summary>Sales breakdown <span class="small subtle"><?= count($salesDays) ?> day<?= count($salesDays) === 1 ? '' : 's' ?> &middot; <?= peso($revenue) ?> total</span></summary>
    <div class="table-wrap"><table class="table">
      <thead><tr><th scope="col">Date</th><th scope="col">Completed orders</th><th scope="col">Sales</th></tr></thead>
      <tbody><?php foreach (array_reverse($salesDays) as $day): ?>
        <tr><td><?= e(date('D, j M Y', strtotime($day['day']))) ?></td><td><?= (int) $day['orders'] ?></td><td class="tabular"><?= peso($day['revenue']) ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot><tr><th scope="row">Total</th><td><?= (int) $salesCount ?></td><td class="tabular"><?= peso($revenue) ?></td></tr></tfoot>
    </table></div>
  </details>

  </div>

  <div class="tabpanel chart-grid" role="tabpanel" id="tab-orders" aria-labelledby="tab-orders-tab" hidden>

  <section class="card">
    <div class="card-header"><h2>Orders by status</h2></div>
    <div class="card-body">
      <?php if ($statusValues === []): ?>
        <div class="empty"><p class="mb-0">No orders in this range.</p></div>
      <?php else: ?>
        <div class="chart-box">
          <canvas id="chart-status" role="img" aria-label="Share of orders in each status"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h2>Orders by hour</h2></div>
    <div class="card-body">
      <?php if ($orderCount === 0): ?>
        <div class="empty"><p class="mb-0">No orders in this range.</p></div>
      <?php else: ?>
        <div class="chart-box">
          <canvas id="chart-hours" role="img" aria-label="Number of orders placed in each hour of the day"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </section>



  </div>

  <div class="tabpanel chart-grid" role="tabpanel" id="tab-sms" aria-labelledby="tab-sms-tab" hidden>

  <section class="card">
    <div class="card-header">
      <h2>SMS usage</h2>
      <span class="small subtle"><?= number_format($smsTotal) ?> messages</span>
    </div>
    <div class="card-body">
      <?php if ($smsTotal === 0): ?>
        <div class="empty"><p class="mb-0">No messages in this range.</p></div>
      <?php else: ?>
        <div class="chart-box">
          <canvas id="chart-sms" role="img" aria-label="SMS notifications by result"></canvas>
        </div>
        <p class="hint mb-0">
          Skipped messages are statuses the owner has switched off in Settings, so they cost nothing.
        </p>
      <?php endif; ?>
    </div>
  </section>

  </div>
</div>

<script type="application/json" id="analytics-data"><?=
    json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
?></script>


