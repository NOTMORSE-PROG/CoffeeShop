<?php
declare(strict_types=1);
require_once __DIR__ . '/queue.php';

/** Same payment columns on the dashboard and the staff Orders page. */
function render_order_board(array $orders, array $options = []): void
{
    $action = (string) ($options['action'] ?? '');
    $lanes = ['cash' => [], 'wallet' => []];
    foreach ($orders as $order) {
        $lanes[$order['payment_method'] === 'cash' ? 'cash' : 'wallet'][] = queue_row($order);
    }
    ?>
    <div class="payment-board" id="order-queue"
         data-queue-url="<?= e((string) ($options['url'] ?? '')) ?>"
         data-queue-action="<?= e($action) ?>" data-csrf="<?= e(csrf_token()) ?>">
      <?php foreach (['cash' => ['Cash', 'Cash payments'], 'wallet' => ['GCash / Maya', 'QR payments']] as $key => [$label, $note]): ?>
        <section class="payment-lane" aria-labelledby="lane-<?= e($key) ?>-title" data-payment-lane="<?= e($key) ?>">
          <header class="payment-lane-header">
            <div><h3 id="lane-<?= e($key) ?>-title"><?= e($label) ?></h3><p><?= e($note) ?></p></div>
            <span class="badge badge-completed" data-lane-count><?= count($lanes[$key]) ?> on this page</span>
          </header>
          <div class="payment-lane-scroll" tabindex="0" role="region" aria-label="<?= e($label) ?> orders">
            <ul class="queue" data-payment-list="<?= e($key) ?>">
              <?php foreach ($lanes[$key] as $row): ?>
                <li class="queue-item" data-order-id="<?= (int) $row['id'] ?>" data-payment-method="<?= e($row['payment_method']) ?>">
                  <div class="queue-body">
                    <div class="queue-head"><a class="queue-ref" href="<?= e($row['view_url']) ?>"><?= e($row['order_ref']) ?></a>
                      <?= status_badge($row['status'], $row['order_type']) ?><?= payment_badge($row['payment_status']) ?></div>
                    <p class="queue-customer"><?= e($row['customer_name']) ?> &middot; <?= e($row['customer_phone']) ?></p>
                    <p class="queue-meta"><?= e($row['payment_label']) ?> &middot; <?= e($row['type_label']) ?> &middot; <?= (int) $row['item_count'] ?> items &middot; <strong><?= e($row['total_display']) ?></strong></p>
                    <p class="queue-meta"><?= e($row['placed_at']) ?> &middot; <?= e($row['waiting']) ?></p>
                  </div>
                  <div class="queue-actions">
                    <?php if ($action !== ''): foreach ($row['next_statuses'] as $next): ?>
                      <form method="post" action="<?= e($action) ?>" data-no-guard data-live-form="#queue-live">
                        <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="status" value="<?= e($next['status']) ?>">
                        <button type="submit" class="btn btn-sm<?= $next['status'] === 'cancelled' ? ' btn-danger' : '' ?>"<?= $next['status'] === 'cancelled' ? ' data-confirm="Cancel this order?"' : '' ?>><?= e($next['label']) ?></button>
                      </form>
                    <?php endforeach; endif; ?>
                    <a class="btn btn-sm btn-secondary" href="<?= e($row['view_url']) ?>">Open order</a>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="payment-lane-empty" data-lane-empty<?= $lanes[$key] === [] ? '' : ' hidden' ?>>No <?= $key === 'cash' ? 'cash' : 'GCash or Maya' ?> orders on this page.</p>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
    <?php
}
