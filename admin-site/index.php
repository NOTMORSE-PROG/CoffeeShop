<?php
/**
 * Dashboard: today's numbers and the live order queue.
 *
 * The queue polls api/queue.php every fifteen seconds, so an order placed on
 * the customer site shows up here on its own.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/queue.php';

start_session();
send_security_headers();
require_login();

if (!is_owner()) {
    redirect('orders.php');
}

$admin = current_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $orderId   = (int) ($_POST['order_id'] ?? 0);
    $newStatus = post_string('status');

    $note = $newStatus === 'cancelled' ? 'Cancelled from the order queue' : null;

    // Read the fulfilment type before the change, so the confirmation message
    // says "Ready to Send" for a delivery rather than "Ready for Pickup".
    $orderType = (string) (db_value(
        'SELECT order_type FROM orders WHERE id = ?',
        [$orderId]
    ) ?? 'pickup');

    // change_order_status() checks the transition, moves stock, writes the
    // history row, sends the SMS and writes the audit entry.
    $result = change_order_status($orderId, $newStatus, (int) $admin['id'], $note);

    if ($result['ok']) {
        flash('success', 'Order moved to ' . status_label($newStatus, $orderType) . '.');
    } else {
        flash('error', (string) $result['error']);
    }

    redirect('index.php' . ($_GET === [] ? '' : '?' . http_build_query($_GET)));
}

$stats = dashboard_stats();
$queueData = dashboard_orders();
$queue = $queueData['orders'];
$filters = $queueData['filters'];
$queueQuery = http_build_query(array_merge($filters, ['page' => $queueData['page']]));
$queueAction = admin_url('index.php') . '?' . $queueQuery;

admin_head('Dashboard');
admin_header('Dashboard', 'Good ' . (date('G') < 12 ? 'morning' : (date('G') < 18 ? 'afternoon' : 'evening'))
    . ', ' . (string) $admin['full_name'] . '.');
?>

<section class="stat-grid" aria-label="Today at a glance">
  <div class="card stat" data-stat="today_orders">
    <p class="stat-label">Orders today</p>
    <p class="stat-value tabular" data-stat-value><?= (int) $stats['today_orders'] ?></p>
    <p class="stat-note"><?= e(date('l, j F')) ?></p>
  </div>

  <div class="card stat" data-stat="today_revenue">
    <p class="stat-label">Revenue today</p>
    <p class="stat-value tabular" data-stat-value><?= peso($stats['today_revenue']) ?></p>
    <p class="stat-note">Cancelled orders are not counted</p>
  </div>

  <div class="card stat<?= $stats['pending_count'] > 0 ? ' stat-alert' : '' ?>" data-stat="pending_count">
    <p class="stat-label">Waiting to be accepted</p>
    <p class="stat-value tabular" data-stat-value><?= (int) $stats['pending_count'] ?></p>
    <p class="stat-note"><a href="<?= e(admin_url('orders.php')) ?>?status=pending">See pending orders</a></p>
  </div>

  <div class="card stat<?= $stats['low_stock_count'] > 0 ? ' stat-warn' : '' ?>" data-stat="low_stock_count">
    <p class="stat-label">Items low on stock</p>
    <p class="stat-value tabular" data-stat-value><?= (int) $stats['low_stock_count'] ?></p>
    <p class="stat-note"><a href="<?= e(admin_url('inventory.php')) ?>">Open inventory</a></p>
  </div>
</section>

<form class="card filter-bar" method="get" action="<?= e(admin_url('index.php')) ?>" data-no-guard>
  <div class="filter-grid">
    <div class="field"><label class="label" for="queue-search">Search orders</label>
      <input class="input" id="queue-search" type="search" name="q" maxlength="80" value="<?= e($filters['q']) ?>" placeholder="Reference, name or number"></div>
    <?php foreach ([
        'status' => ['Category', array_replace(array_merge(['active' => 'Active orders'], ORDER_STATUSES), ['ready' => 'Ready'])],
        'order_type' => ['Fulfilment', ['pickup' => 'Pickup', 'delivery' => 'Delivery']],
        'payment_method' => ['Payment method', array_merge(['cash' => 'Cash'], array_map(static fn ($wallet) => $wallet['label'], shop_wallets()))],
        'payment_status' => ['Payment status', ['paid' => 'Paid', 'unpaid' => 'Unpaid']],
        'sort' => ['Sort by', ['recent' => 'Newest first', 'oldest' => 'Oldest first']],
    ] as $key => [$label, $choices]): ?>
    <div class="field"><label class="label" for="queue-<?= e($key) ?>"><?= e($label) ?></label>
      <select class="select" id="queue-<?= e($key) ?>" name="<?= e($key) ?>">
        <?php if ($key !== 'sort'): ?><option value="">All</option><?php endif; ?>
        <?php foreach ($choices as $value => $text): ?>
          <option value="<?= e($value) ?>"<?= $filters[$key] === $value ? ' selected' : '' ?>><?= e($text) ?></option>
        <?php endforeach; ?>
      </select></div>
    <?php endforeach; ?>
  </div>
  <div class="filter-actions"><button class="btn btn-sm" type="submit">Apply filters</button>
    <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('index.php')) ?>">Reset</a></div>
</form>
<nav class="filter-actions" aria-label="Order categories">
  <?php foreach (array_replace(ORDER_STATUSES, ['ready' => 'Ready']) as $value => $label): ?>
    <a class="btn btn-sm<?= $filters['status'] === $value ? '' : ' btn-secondary' ?>" href="<?= e(admin_url('index.php') . '?' . http_build_query(array_merge($filters, ['status' => $value]))) ?>">
      <?= e($label) ?> (<span data-category="<?= e($value) ?>"><?= (int) $queueData['categories'][$value] ?></span>)
    </a>
  <?php endforeach; ?>
</nav>

<section class="card queue-card" id="queue-live" data-live-region>
  <div class="card-header">
    <h2>Order queue</h2>
    <p class="small subtle mb-0" data-queue-updated>Updating every 15 seconds</p>
  </div>

  <div class="card-body">
    <ul class="queue" id="order-queue"
        data-queue-url="<?= e(admin_url('api/queue.php') . '?' . $queueQuery) ?>"
        data-queue-action="<?= e($queueAction) ?>"
        data-csrf="<?= e(csrf_token()) ?>">
      <?php foreach ($queue as $order): ?>
        <?php $row = queue_row($order); ?>
        <li class="queue-item" data-order-id="<?= (int) $row['id'] ?>">
          <div class="queue-body">
            <div class="queue-head">
              <a class="queue-ref" href="<?= e($row['view_url']) ?>"><?= e($row['order_ref']) ?></a>
              <?= status_badge($row['status'], $row['order_type']) ?>
              <?= payment_badge($row['payment_status']) ?>
            </div>
            <p class="queue-customer"><?= e($row['customer_name']) ?> &middot; <?= e($row['customer_phone']) ?></p>
            <p class="queue-meta">
              <?= e($row['type_label']) ?> &middot;
              <?= (int) $row['item_count'] ?> item<?= $row['item_count'] === 1 ? '' : 's' ?> &middot;
              <span class="tabular"><?= e($row['total_display']) ?></span> &middot;
              <?= e($row['placed_at']) ?> (<?= e($row['waiting']) ?>)
            </p>
          </div>

          <div class="queue-actions">
            <?php foreach ($row['next_statuses'] as $next): ?>
              <form method="post" action="<?= e($queueAction) ?>" data-no-guard
                    data-live-form="#queue-live">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="status" value="<?= e($next['status']) ?>">
                <button type="submit"
                        class="btn btn-sm<?= $next['status'] === 'cancelled' ? ' btn-danger' : '' ?>"
                        <?php if ($next['status'] === 'cancelled'): ?>
                          data-confirm="Cancel order <?= e($row['order_ref']) ?>?"
                        <?php endif; ?>>
                  <?= e($next['label']) ?>
                </button>
              </form>
            <?php endforeach; ?>

            <a class="btn btn-sm btn-secondary" href="<?= e($row['view_url']) ?>">Open</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="empty" data-queue-empty<?= $queue === [] ? '' : ' hidden' ?>>
      <?= admin_icon('icon-check-circle', 'empty-icon') ?>
      <h3>No matching orders</h3>
      <p class="mb-0">Try another category or clear the filters. New matches appear automatically.</p>
    </div>
  </div>
</section>

<div id="queue-pagination" data-base-url="<?= e(admin_url('index.php')) ?>" data-filters="<?= e(json_encode($filters)) ?>">
  <p class="small subtle" data-queue-total><?= (int) $queueData['total'] ?> matching orders | Page <?= (int) $queueData['page'] ?> of <?= (int) $queueData['pages'] ?></p>
  <div data-queue-pages><?php admin_pagination($queueData['page'], $queueData['pages'], $filters, 'index.php'); ?></div>
</div>
<?php admin_footer(); ?>
