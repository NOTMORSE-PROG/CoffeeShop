<?php
/**
 * Order tracking.
 *
 * This is the fallback recommended in the documentation review: if SMS
 * credits run out or a message is delayed, the customer can still see exactly
 * where their order is. It asks for the reference and the mobile number
 * together, so knowing a reference alone reveals nothing.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

$ref   = strtoupper(get_string('ref'));
$phone = '';
$order = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $ref   = strtoupper(post_string('ref'));
    $phone = post_string('phone');

    if ($ref === '' || $phone === '') {
        $error = 'Please give both your order reference and the mobile number you used.';
    } else {
        $order = find_order_for_customer($ref, $phone);

        if ($order === null) {
            // Deliberately vague: this must not confirm whether a reference exists.
            $error = 'We could not find an order with those details. Please check and try again.';
            write_log('security.log', sprintf('Failed order lookup: ref=%s ip=%s', $ref, client_ip()));
        }
    }
}

customer_head('Track Order', 'Check the status of your order at Our Coffee Shop.');
?>

<section class="section-sm">
  <div class="container-narrow">

    <?php if ($order === null): ?>

      <header class="mx-auto section-head center">
        <p class="eyebrow">Order status</p>
        <h1>Track your <span class="accent-text">order</span></h1>
        <p class="mx-auto lede">
          Enter the reference from your confirmation text and the mobile number you gave us.
        </p>
      </header>

      <?php if ($error !== null): ?>
        <div class="alert alert-error track-form"><?= icon('alert') ?><p><?= e($error) ?></p></div>
      <?php endif; ?>

      <form class="card card-pad track-form" method="post" action="<?= e(url('track.php')) ?>">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="ref">Order reference <span class="req">*</span></label>
          <input class="input" id="ref" name="ref" type="text" required
                 placeholder="ORD-20260924-1234" maxlength="24"
                 value="<?= e($ref) ?>" autocomplete="off" spellcheck="false">
          <p class="hint">It looks like ORD followed by the date and four digits.</p>
        </div>

        <div class="field">
          <label class="label" for="phone">Mobile number <span class="req">*</span></label>
          <input class="input" id="phone" name="phone" type="tel" required
                 placeholder="09171234567" maxlength="20" inputmode="numeric"
                 value="<?= e($phone) ?>" autocomplete="tel">
          <p class="hint">The same number your SMS updates are sent to.</p>
        </div>

        <button class="btn btn-block btn-lg" type="submit" data-busy-label="Looking">
          <?= icon('search') ?> Find my order
        </button>
      </form>

    <?php else:
        $statusOrder = $order['order_type'] === 'delivery'
            ? ['pending', 'preparing', 'ready', 'out_for_delivery', 'completed']
            : ['pending', 'preparing', 'ready', 'completed'];

        $currentIndex = array_search($order['status'], $statusOrder, true);
        $isCancelled  = $order['status'] === 'cancelled';
    ?>

      <header class="section-head">
        <p class="eyebrow">Order status</p>
        <h1><span class="order-ref"><?= e($order['order_ref']) ?></span></h1>
        <p class="lede">
          Placed <?= e(date('j M Y, g:i A', (int) strtotime((string) $order['placed_at']))) ?>
          for <?= e($order['customer_name']) ?>.
        </p>
      </header>

      <div class="card card-pad mb-6"
           data-track-poll="<?= e(url('api/order-status.php?ref=' . urlencode((string) $order['order_ref'])
                . '&phone=' . urlencode(format_ph_mobile((string) $order['customer_phone'])))) ?>"
           data-current-status="<?= e((string) $order['status']) ?>">

        <div class="row-between mb-4">
          <h2 class="h-title mb-0">Progress</h2>
          <span class="badge badge-<?= e((string) $order['status']) ?>">
            <?= e(status_label((string) $order['status'], (string) $order['order_type'])) ?>
          </span>
        </div>

        <?php if ($isCancelled): ?>
          <div class="alert alert-error">
            <?= icon('alert') ?>
            <p>
              This order was cancelled.
              <?php if (!empty($order['cancel_reason'])): ?>
                <br><span class="small"><?= e((string) $order['cancel_reason']) ?></span>
              <?php endif; ?>
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
                    $when = null;
                    foreach ($order['history'] as $entry) {
                        if ($entry['to_status'] === $status) {
                            $when = $entry['created_at'];
                            break;
                        }
                    }
                    echo $when !== null
                        ? e(date('j M, g:i A', (int) strtotime((string) $when)))
                        : '<span class="subtle">Not yet</span>';
                    ?>
                  </p>
                </span>
              </li>
            <?php endforeach; ?>
          </ol>

          <p class="mt-4 mb-0 tiny muted center">
            <?= icon('refresh') ?> This page updates itself while it is open.
          </p>
        <?php endif; ?>
      </div>

      <div class="card card-pad mb-6">
        <h2 class="h-title">Your order</h2>

        <div class="order-meta-grid mb-6">
          <div class="order-meta-item">
            <span class="label-sm">Order type</span>
            <span><?= $order['order_type'] === 'delivery' ? 'Delivery' : 'Self pick up' ?></span>
          </div>
          <div class="order-meta-item">
            <span class="label-sm">Payment</span>
            <span>
              <?= $order['payment_method'] === 'gcash' ? 'GCash' : 'Cash' ?>
              <span class="badge badge-<?= e((string) $order['payment_status']) ?>">
                <?= $order['payment_status'] === 'paid' ? 'Paid' : 'Not yet paid' ?>
              </span>
            </span>
          </div>
          <?php if ($order['order_type'] === 'delivery'): ?>
            <div class="order-meta-item">
              <span class="label-sm">Deliver to</span>
              <span><?= e((string) $order['delivery_address']) ?></span>
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

        <div class="summary-row is-total">
          <span>Total</span>
          <span class="amount tabular"><?= peso($order['total']) ?></span>
        </div>
      </div>

      <p class="pb-6 center">
        <a class="btn btn-secondary" href="<?= e(url('track.php')) ?>">Track another order</a>
        <a class="btn" href="<?= e(url('menu.php')) ?>">Order again</a>
      </p>

    <?php endif; ?>
  </div>
</section>

<?php customer_footer(); ?>
