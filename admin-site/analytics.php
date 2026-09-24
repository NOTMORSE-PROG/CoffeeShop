<?php
/**
 * Analytics.
 *
 * Chart.js is vendored under assets/vendor so the demo works with the network
 * off, and the figures are handed to it through a JSON block rather than an
 * inline script, because the Content-Security-Policy allows neither inline
 * script nor a third-party origin.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_login();

// --- Date range ------------------------------------------------------------
// Defaults to the last fourteen days, which is the window the dashboard
// conversation kept coming back to.

$today = date('Y-m-d');

$from = get_string('from');
$to   = get_string('to');

if ($from === '' || strtotime($from) === false) {
    $from = date('Y-m-d', strtotime('-13 days'));
}

if ($to === '' || strtotime($to) === false) {
    $to = $today;
}

if (strtotime($from) > strtotime($to)) {
    [$from, $to] = [$to, $from];
}

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

$revenueRows = db_all(
    "SELECT DATE(placed_at) AS day,
            COUNT(*) AS orders,
            COALESCE(SUM(total), 0) AS revenue
     FROM orders
     WHERE placed_at BETWEEN ? AND ? AND status <> 'cancelled'
     GROUP BY DATE(placed_at)
     ORDER BY day ASC",
    $range
);

$byDay = [];
foreach ($revenueRows as $row) {
    $byDay[(string) $row['day']] = $row;
}

$revenueLabels = [];
$revenueValues = [];
$ordersValues  = [];

$cursor = strtotime($from);
$end    = strtotime($to);

while ($cursor <= $end) {
    $key = date('Y-m-d', $cursor);

    $revenueLabels[] = date('j M', $cursor);
    $revenueValues[] = round((float) ($byDay[$key]['revenue'] ?? 0), 2);
    $ordersValues[]  = (int) ($byDay[$key]['orders'] ?? 0);

    $cursor = strtotime('+1 day', $cursor);
}

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

$topRows = db_all(
    "SELECT oi.product_name,
            SUM(oi.quantity)   AS quantity,
            SUM(oi.line_total) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.placed_at BETWEEN ? AND ? AND o.status <> 'cancelled'
     GROUP BY oi.product_name
     ORDER BY quantity DESC, revenue DESC
     LIMIT 10",
    $range
);

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
        'labels' => ['Sent', 'Failed', 'Skipped', 'Queued'],
        'keys'   => ['sent', 'failed', 'skipped', 'queued'],
        'values' => array_values($smsCounts),
    ],
];

$exportQuery = http_build_query(['from' => $from, 'to' => $to]);

admin_head('Analytics', ['scripts' => [admin_asset('vendor/chart.min.js')]]);
admin_header(
    'Analytics',
    'From ' . date('j F Y', (int) strtotime($from)) . ' to ' . date('j F Y', (int) strtotime($to)) . '.'
);
?>

<form class="card filter-bar" method="get" action="<?= e(admin_url('analytics.php')) ?>" data-no-guard>
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
    <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('analytics.php')) ?>">Last 14 days</a>
    <span class="grow"></span>
    <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('export.php')) ?>?<?= e($exportQuery) ?>">
      <?= admin_icon('icon-receipt', 'icon-sm') ?> Download CSV
    </a>
  </div>
</form>

<section class="stat-grid" aria-label="Totals for the selected range">
  <div class="card stat">
    <p class="stat-label">Orders</p>
    <p class="stat-value tabular"><?= number_format($orderCount) ?></p>
    <p class="stat-note"><?= number_format($cancelled) ?> cancelled</p>
  </div>

  <div class="card stat">
    <p class="stat-label">Revenue</p>
    <p class="stat-value tabular"><?= peso($revenue) ?></p>
    <p class="stat-note">Cancelled orders excluded</p>
  </div>

  <div class="card stat">
    <p class="stat-label">Average order</p>
    <p class="stat-value tabular"><?= peso($average) ?></p>
    <p class="stat-note">Across orders that were not cancelled</p>
  </div>

  <div class="card stat">
    <p class="stat-label">Completed</p>
    <p class="stat-value tabular"><?= number_format($completed) ?></p>
    <p class="stat-note">
      <?= $orderCount > 0 ? number_format($completed / $orderCount * 100, 1) : '0.0' ?>% of all orders
    </p>
  </div>
</section>

<div class="chart-grid">

  <section class="card chart-wide">
    <div class="card-header">
      <h2>Revenue</h2>
      <span class="small subtle"><?= count($revenueLabels) ?> days</span>
    </div>
    <div class="card-body">
      <div class="chart-box">
        <canvas id="chart-revenue" role="img"
                aria-label="Revenue per day across the selected range"></canvas>
      </div>
    </div>
  </section>

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
      <div class="chart-box">
        <canvas id="chart-hours" role="img" aria-label="Number of orders placed in each hour of the day"></canvas>
      </div>
    </div>
  </section>

  <section class="card chart-wide">
    <div class="card-header">
      <h2>Best sellers</h2>
      <span class="small subtle">Top <?= count($topLabels) ?> by cups sold</span>
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

<script type="application/json" id="analytics-data"><?=
    json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)
?></script>

<?php admin_footer(); ?>
