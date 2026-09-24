<?php
/**
 * Order confirmation.
 *
 * Reachable straight after checkout via the session, or later by reference
 * plus mobile number. The reference alone is never enough, because it would
 * otherwise leak a customer's name and address to anyone who guessed it.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

$ref   = strtoupper(get_string('ref'));
$order = null;

// Straight from checkout: the session vouches for this one.
$last = $_SESSION['last_order'] ?? null;

if (is_array($last) && ($last['ref'] ?? '') === $ref) {
    $order = find_order((int) $last['id']);
}

if ($order === null) {
    flash('info', 'Please enter your order reference and mobile number to see your order.');
    redirect(url('track.php?ref=' . urlencode($ref)));
}

$statusOrder = $order['order_type'] === 'delivery'
    ? ['pending', 'preparing', 'ready', 'out_for_delivery', 'completed']
    : ['pending', 'preparing', 'ready', 'completed'];

$currentIndex = array_search($order['status'], $statusOrder, true);
$isCancelled  = $order['status'] === 'cancelled';

customer_head('Order ' . $order['order_ref'], 'Your order has been received.');
?>

<section class="container-narrow">
  <div class="confirm-hero">
    <div class="confirm-tick"><?= icon('check') ?></div>
    <h1>Order Confirmed</h1>
    <p class="mx-auto lede center">
      Thank you, <?= e(explode(' ', trim((string) $order['customer_name']))[0]) ?>.
      We have your order and we are on it.
    </p>
    <p><span class="order-ref"><?= e($order['order_ref']) ?></span></p>
  </div>

  <div class="sms-banner mb-6">
    <?= icon('message') ?>
    <p>
      <span class="bold">SMS updates are on.</span><br>
      <span class="small">We will text
        <span class="phone"><?= e(format_ph_mobile((string) $order['customer_phone'])) ?></span>
        at each stage of your order.</span>
    </p>
  </div>

  <div class="card card-pad mb-6">
    <h2 class="h-title">Where your order is</h2>

    <?php if ($isCancelled): ?>
      <div class="alert alert-error">
        <?= icon('alert') ?>
        <p>
          This order was cancelled.
          <?php if (!empty($order['cancel_reason'])): ?>
            <br><span class="small"><?= e((string) $order['cancel_reason']) ?></span>
          <?php endif; ?>
          Please contact the shop if this was not expected.
        </p>
      </div>
    <?php else: ?>
      <ol class="steps">
        <?php foreach ($statusOrder as $index => $status):
            $state = $index < (int) $currentIndex ? 'is-done'
                   : ($index === (int) $currentIndex ? 'is-current' : '');
        ?>
          <li class="step <?= e($state) ?>">
            <span class="step-dot">
              <?= $index < (int) $currentIndex ? icon('check') : icon('clock') ?>
            </span>
            <span>
              <p class="step-title"><?= e(status_label($status, (string) $order['order_type'])) ?></p>
              <p class="step-note">
                <?php
                echo match ($status) {
                    'pending'          => 'We have your order and it is queued.',
                    'preparing'        => 'Your drinks are being made.',
                    'ready'            => 'Ready for pickup at the shop.',
                    'out_for_delivery' => 'On its way to your address.',
                    'completed'        => 'All done. Enjoy.',
                    default            => '',
                };
                ?>
              </p>
            </span>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <p class="mt-5 mb-0 center">
      <a class="btn btn-secondary"
         href="<?= e(url('track.php?ref=' . urlencode((string) $order['order_ref']))) ?>">
        <?= icon('refresh') ?> Check this order later
      </a>
    </p>
  </div>

  <div class="card card-pad mb-6">
    <h2 class="h-title">Order details</h2>

    <div class="order-meta-grid mb-6">
      <div class="order-meta-item">
        <span class="label-sm">Order type</span>
        <span><?= $order['order_type'] === 'delivery' ? 'Delivery' : 'Self pick up' ?></span>
      </div>

      <div class="order-meta-item">
        <span class="label-sm">Payment</span>
        <span>
          <?= $order['payment_method'] === 'gcash' ? 'GCash' : 'Cash' ?>
          <span class="badge badge-<?= e($order['payment_status']) ?>">
            <?= $order['payment_status'] === 'paid' ? 'Paid' : 'Not yet paid' ?>
          </span>
        </span>
      </div>

      <div class="order-meta-item">
        <span class="label-sm">Placed</span>
        <span><?= e(date('j M Y, g:i A', (int) strtotime((string) $order['placed_at']))) ?></span>
      </div>

      <?php if ($order['order_type'] === 'delivery'): ?>
        <div class="order-meta-item">
          <span class="label-sm">Deliver to</span>
          <span>
            <?= e((string) $order['delivery_address']) ?>
            <?php if (!empty($order['delivery_city'])): ?>
              , <?= e((string) $order['delivery_city']) ?>
            <?php endif; ?>
          </span>
        </div>
      <?php endif; ?>
    </div>

    <?php foreach ($order['items'] as $item): ?>
      <div class="items-start summary-row">
        <span>
          <span class="bold"><?= (int) $item['quantity'] ?> &times; <?= e($item['product_name']) ?></span>
          <?php if ($item['options'] !== []): ?>
            <br><span class="tiny muted">
              <?= e(implode(', ', array_column($item['options'], 'option_name'))) ?>
            </span>
          <?php endif; ?>
        </span>
        <span class="tabular nowrap"><?= peso($item['line_total']) ?></span>
      </div>
    <?php endforeach; ?>

    <div class="row-divider summary-row">
      <span class="muted">Subtotal</span>
      <span class="tabular"><?= peso($order['subtotal']) ?></span>
    </div>

    <?php if ($order['order_type'] === 'delivery'): ?>
      <div class="summary-row">
        <span class="muted">Delivery fee</span>
        <span class="tabular">
          <?= (float) $order['delivery_fee'] <= 0 ? 'Free' : peso($order['delivery_fee']) ?>
        </span>
      </div>
    <?php endif; ?>

    <div class="summary-row is-total">
      <span>Total</span>
      <span class="amount tabular"><?= peso($order['total']) ?></span>
    </div>

    <?php if (!empty($order['special_instructions'])): ?>
      <div class="mt-4 alert alert-info">
        <?= icon('message') ?>
        <p><span class="bold">Your note:</span> <?= e((string) $order['special_instructions']) ?></p>
      </div>
    <?php endif; ?>
  </div>

  <p class="pb-6 center">
    <a class="btn" href="<?= e(url('menu.php')) ?>">Order more coffee <?= icon('arrow-right') ?></a>
  </p>
</section>

<?php customer_footer(); ?>
