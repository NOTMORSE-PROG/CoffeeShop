<?php
/**
 * Every order, with filters and pagination.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_login();

const ORDERS_PER_PAGE = 25;

$filters = [
    'status'         => get_string('status'),
    'order_type'     => get_string('order_type'),
    'payment_status' => get_string('payment_status'),
    'date_from'      => get_string('date_from'),
    'date_to'        => get_string('date_to'),
    'q'              => get_string('q'),
];

$page = max(1, get_int('page', 1));

// --- Build the query -------------------------------------------------------
// Every value is bound. Only column names and the cast integer LIMIT are ever
// written into the SQL text.

$where  = [];
$params = [];

if (array_key_exists($filters['status'], ORDER_STATUSES)) {
    $where[]  = 'o.status = ?';
    $params[] = $filters['status'];
} else {
    $filters['status'] = '';
}

if (in_array($filters['order_type'], ['pickup', 'delivery'], true)) {
    $where[]  = 'o.order_type = ?';
    $params[] = $filters['order_type'];
} else {
    $filters['order_type'] = '';
}

if (in_array($filters['payment_status'], ['paid', 'unpaid'], true)) {
    $where[]  = 'o.payment_status = ?';
    $params[] = $filters['payment_status'];
} else {
    $filters['payment_status'] = '';
}

if ($filters['date_from'] !== '' && strtotime($filters['date_from']) !== false) {
    $where[]  = 'o.placed_at >= ?';
    $params[] = $filters['date_from'] . ' 00:00:00';
} else {
    $filters['date_from'] = '';
}

if ($filters['date_to'] !== '' && strtotime($filters['date_to']) !== false) {
    $where[]  = 'o.placed_at <= ?';
    $params[] = $filters['date_to'] . ' 23:59:59';
} else {
    $filters['date_to'] = '';
}

if ($filters['q'] !== '') {
    $term = '%' . $filters['q'] . '%';

    // A number typed as 09xx still has to match the stored 639xx form.
    $normalized = normalize_ph_mobile($filters['q']);

    if ($normalized !== null) {
        $where[]  = '(o.order_ref LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.customer_phone = ?)';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $normalized;
    } else {
        $where[]  = '(o.order_ref LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }
}

$clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$summary = db_one(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total ELSE 0 END), 0) AS total_value
     FROM orders o" . $clause,
    $params
);

$totalOrders = (int) ($summary['total_orders'] ?? 0);
$totalPages  = max(1, (int) ceil($totalOrders / ORDERS_PER_PAGE));
$page        = min($page, $totalPages);
$offset      = ($page - 1) * ORDERS_PER_PAGE;

$orders = db_all(
    "SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
     FROM orders o" . $clause . '
     ORDER BY o.placed_at DESC, o.id DESC
     LIMIT ' . (int) ORDERS_PER_PAGE . ' OFFSET ' . (int) $offset,
    $params
);

$queryForPager = array_filter($filters, static fn ($value) => $value !== '');

admin_head('Orders');
admin_header('Orders', $totalOrders === 1 ? '1 order found.' : number_format($totalOrders) . ' orders found.');
?>

<form class="card filter-bar" method="get" action="<?= e(admin_url('orders.php')) ?>" data-no-guard>
  <div class="filter-grid">
    <div class="field">
      <label class="label" for="q">Search</label>
      <input class="input" type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
             placeholder="Reference, name or number" maxlength="80">
    </div>

    <div class="field">
      <label class="label" for="status">Status</label>
      <select class="select" id="status" name="status">
        <option value="">Any status</option>
        <?php foreach (ORDER_STATUSES as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>>
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="order_type">Order type</label>
      <select class="select" id="order_type" name="order_type">
        <option value="">Any type</option>
        <option value="pickup"<?= $filters['order_type'] === 'pickup' ? ' selected' : '' ?>>Pickup</option>
        <option value="delivery"<?= $filters['order_type'] === 'delivery' ? ' selected' : '' ?>>Delivery</option>
      </select>
    </div>

    <div class="field">
      <label class="label" for="payment_status">Payment</label>
      <select class="select" id="payment_status" name="payment_status">
        <option value="">Any</option>
        <option value="paid"<?= $filters['payment_status'] === 'paid' ? ' selected' : '' ?>>Paid</option>
        <option value="unpaid"<?= $filters['payment_status'] === 'unpaid' ? ' selected' : '' ?>>Unpaid</option>
      </select>
    </div>

    <div class="field">
      <label class="label" for="date_from">From</label>
      <input class="input" type="date" id="date_from" name="date_from" value="<?= e($filters['date_from']) ?>">
    </div>

    <div class="field">
      <label class="label" for="date_to">To</label>
      <input class="input" type="date" id="date_to" name="date_to" value="<?= e($filters['date_to']) ?>">
    </div>
  </div>

  <div class="filter-actions">
    <button type="submit" class="btn btn-sm"><?= admin_icon('icon-search', 'icon-sm') ?> Apply</button>
    <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('orders.php')) ?>">Clear</a>
    <span class="grow"></span>
    <span class="small subtle">
      Value of the orders shown:
      <strong class="tabular"><?= peso((float) ($summary['total_value'] ?? 0)) ?></strong>
    </span>
  </div>
</form>

<?php if ($orders === []): ?>
  <div class="card">
    <div class="empty">
      <?= admin_icon('icon-receipt', 'empty-icon') ?>
      <h3>No orders match</h3>
      <p class="mb-0">Try widening the dates or clearing the search.</p>
    </div>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th scope="col">Reference</th>
          <th scope="col">Placed</th>
          <th scope="col">Customer</th>
          <th scope="col">Type</th>
          <th scope="col">Items</th>
          <th scope="col">Total</th>
          <th scope="col">Payment</th>
          <th scope="col">Status</th>
          <th scope="col"><span class="visually-hidden">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $order): ?>
          <tr>
            <td>
              <a class="bold" href="<?= e(admin_url('order-view.php')) ?>?id=<?= (int) $order['id'] ?>">
                <?= e((string) $order['order_ref']) ?>
              </a>
            </td>
            <td class="nowrap small">
              <?= e(date('j M Y', (int) strtotime((string) $order['placed_at']))) ?><br>
              <span class="subtle"><?= e(date('g:i A', (int) strtotime((string) $order['placed_at']))) ?></span>
            </td>
            <td>
              <?= e((string) $order['customer_name']) ?><br>
              <span class="subtle small"><?= e(format_ph_mobile((string) $order['customer_phone'])) ?></span>
            </td>
            <td class="small"><?= $order['order_type'] === 'delivery' ? 'Delivery' : 'Pickup' ?></td>
            <td class="tabular"><?= (int) $order['item_count'] ?></td>
            <td class="tabular nowrap"><?= peso($order['total']) ?></td>
            <td>
              <?= payment_badge((string) $order['payment_status']) ?>
              <span class="tiny subtle"><?= $order['payment_method'] === 'gcash' ? 'GCash' : 'Cash' ?></span>
            </td>
            <td><?= status_badge((string) $order['status']) ?></td>
            <td class="right">
              <a class="btn btn-sm btn-secondary"
                 href="<?= e(admin_url('order-view.php')) ?>?id=<?= (int) $order['id'] ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php admin_pagination($page, $totalPages, $queryForPager, 'orders.php'); ?>
<?php endif; ?>

<?php admin_footer(); ?>
