<?php
/**
 * Checkout.
 *
 * In the team's draft this was a modal stacked on top of the cart modal,
 * which the developer flagged in the discovery meeting. It is one page here.
 *
 * Delivery is the simplified fulfilment option agreed with the team: the form
 * records where the order is going and the owner arranges the delivery. There
 * is no rider, no tracking and no distance-based fee.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

$cart = cart_detailed();

if ($cart['lines'] === []) {
    flash('info', 'Your cart is empty, so there is nothing to check out yet.');
    redirect(url('menu.php'));
}

$closed = shop_closed_reason();

if ($closed !== null) {
    flash('warning', $closed);
    redirect(url('cart.php'));
}

$deliveryOn = delivery_available();
$cashOn     = setting_bool('payment_cash_enabled', true);
$gcashOn    = setting_bool('payment_gcash_enabled', true);
$freeCity   = (string) setting('free_delivery_city', '');
$flatFee    = setting_float('delivery_fee', 0.0);

$errors = [];

// Sticky values so a validation failure does not wipe the form.
$form = [
    'name'                 => '',
    'phone'                => '',
    'order_type'           => 'pickup',
    'delivery_address'     => '',
    'delivery_city'        => $freeCity !== '' ? $freeCity : '',
    'payment_method'       => $cashOn ? 'cash' : 'gcash',
    'special_instructions' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $form['name']                 = clean_text(post_string('name'), 120);
    $form['phone']                = post_string('phone');
    $form['order_type']           = post_string('order_type', 'pickup') === 'delivery' ? 'delivery' : 'pickup';
    $form['delivery_address']     = clean_text(post_string('delivery_address'), 255);
    $form['delivery_city']        = clean_text(post_string('delivery_city'), 80);
    $form['payment_method']       = post_string('payment_method', 'cash') === 'gcash' ? 'gcash' : 'cash';
    $form['special_instructions'] = clean_text(post_string('special_instructions'), 400);

    if (!valid_person_name($form['name'])) {
        $errors['name'] = 'Please give the name we should put on the order.';
    }

    if (normalize_ph_mobile($form['phone']) === null) {
        $errors['phone'] = 'Please give a valid Philippine mobile number, for example 09171234567.';
    }

    if ($form['order_type'] === 'delivery') {
        if (!$deliveryOn) {
            $errors['order_type'] = 'Delivery is not available at the moment. Please choose pickup.';
        }
        if ($form['delivery_address'] === '') {
            $errors['delivery_address'] = 'Please give the address we should deliver to.';
        }
        if ($form['delivery_city'] === '') {
            $errors['delivery_city'] = 'Please give the city.';
        }
    }

    if ($form['payment_method'] === 'gcash' && !$gcashOn) {
        $errors['payment_method'] = 'GCash is not available at the moment.';
    }
    if ($form['payment_method'] === 'cash' && !$cashOn) {
        $errors['payment_method'] = 'Paying at the counter is not available at the moment.';
    }

    if ($errors === []) {
        $result = place_order(cart_for_order(), [
            'name'                 => $form['name'],
            'phone'                => $form['phone'],
            'order_type'           => $form['order_type'],
            'delivery_address'     => $form['order_type'] === 'delivery' ? $form['delivery_address'] : null,
            'delivery_city'        => $form['order_type'] === 'delivery' ? $form['delivery_city'] : null,
            'payment_method'       => $form['payment_method'],
            'special_instructions' => $form['special_instructions'] !== '' ? $form['special_instructions'] : null,
        ]);

        if ($result['ok']) {
            cart_clear();

            // Remembered only so the confirmation page can be shown once.
            $_SESSION['last_order'] = [
                'id'  => $result['order_id'],
                'ref' => $result['order_ref'],
            ];

            redirect(url('confirmation.php?ref=' . urlencode((string) $result['order_ref'])));
        }

        foreach ($result['errors'] ?? ['Your order could not be placed.'] as $message) {
            $errors['form'][] = $message;
        }
    }
}

$deliveryFee = $form['order_type'] === 'delivery'
    ? delivery_fee_for_city($form['delivery_city'])
    : 0.0;

$total = $cart['subtotal'] + $deliveryFee;

customer_head('Checkout', 'Confirm your details and place your order.');
?>

<section class="section-sm">
  <div class="container">
    <header class="section-head">
      <p class="eyebrow">Last step</p>
      <h1><span class="accent-text">Checkout</span></h1>
      <p class="lede">
        Confirm your details and choose how you would like to receive and pay for your order.
      </p>
    </header>

    <?php if (!empty($errors['form'])): ?>
      <div class="alert alert-error">
        <?= icon('alert') ?>
        <div>
          <p class="bold mb-2">Your order was not placed.</p>
          <ul>
            <?php foreach ($errors['form'] as $message): ?>
              <li><?= e($message) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('checkout.php')) ?>" data-checkout novalidate
          data-subtotal="<?= e((string) $cart['subtotal']) ?>"
          data-flat-fee="<?= e((string) $flatFee) ?>"
          data-free-city="<?= e(strtolower($freeCity)) ?>">
      <?= csrf_field() ?>

      <div class="checkout-layout">
        <div>
          <!-- Customer details -->
          <fieldset class="fieldset">
            <legend>Your details</legend>

            <div class="field-row">
              <div class="field">
                <label class="label" for="name">Name <span class="req">*</span></label>
                <input class="input" id="name" name="name" type="text" required
                       autocomplete="name" maxlength="120"
                       value="<?= e($form['name']) ?>"
                       <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="name-error"' : '' ?>>
                <?php if (isset($errors['name'])): ?>
                  <p class="error-text" id="name-error"><?= e($errors['name']) ?></p>
                <?php endif; ?>
              </div>

              <div class="field">
                <label class="label" for="phone">Mobile number <span class="req">*</span></label>
                <input class="input" id="phone" name="phone" type="tel" required
                       autocomplete="tel" inputmode="numeric" maxlength="20"
                       placeholder="09171234567"
                       value="<?= e($form['phone']) ?>"
                       aria-describedby="<?= isset($errors['phone']) ? 'phone-error' : 'phone-hint' ?>"
                       <?= isset($errors['phone']) ? 'aria-invalid="true"' : '' ?>>
                <?php if (isset($errors['phone'])): ?>
                  <p class="error-text" id="phone-error"><?= e($errors['phone']) ?></p>
                <?php else: ?>
                  <p class="hint" id="phone-hint">
                    <?= icon('message') ?> Your order updates are texted to this number.
                  </p>
                <?php endif; ?>
              </div>
            </div>
          </fieldset>

          <!-- Fulfilment -->
          <fieldset class="fieldset">
            <legend>How would you like it?</legend>

            <div class="choice-group choice-group-2">
              <label class="choice">
                <input type="radio" name="order_type" value="pickup"
                       <?= $form['order_type'] === 'pickup' ? 'checked' : '' ?>>
                <span>
                  <span class="choice-title"><?= icon('store') ?> Self Pick Up</span>
                  <span class="choice-note">Collect your order at the shop.</span>
                </span>
              </label>

              <label class="choice">
                <input type="radio" name="order_type" value="delivery"
                       <?= $form['order_type'] === 'delivery' ? 'checked' : '' ?>
                       <?= $deliveryOn ? '' : 'disabled' ?>>
                <span>
                  <span class="choice-title"><?= icon('truck') ?> Delivery</span>
                  <span class="choice-note">
                    <?= $deliveryOn
                        ? 'Have your order brought to you.'
                        : 'Not available at the moment.' ?>
                  </span>
                </span>
              </label>
            </div>

            <?php if (isset($errors['order_type'])): ?>
              <p class="error-text"><?= e($errors['order_type']) ?></p>
            <?php endif; ?>

            <div class="delivery-fields mt-4" <?= $form['order_type'] === 'delivery' ? '' : 'hidden' ?>>
              <div class="field">
                <label class="label" for="delivery_address">Delivery address <span class="req">*</span></label>
                <textarea class="textarea" id="delivery_address" name="delivery_address"
                          maxlength="255" autocomplete="street-address"
                          placeholder="House or unit number, street, barangay"
                          <?= isset($errors['delivery_address']) ? 'aria-invalid="true"' : '' ?>><?= e($form['delivery_address']) ?></textarea>
                <?php if (isset($errors['delivery_address'])): ?>
                  <p class="error-text"><?= e($errors['delivery_address']) ?></p>
                <?php endif; ?>
              </div>

              <div class="field">
                <label class="label" for="delivery_city">City <span class="req">*</span></label>
                <input class="input" id="delivery_city" name="delivery_city" type="text"
                       maxlength="80" autocomplete="address-level2"
                       value="<?= e($form['delivery_city']) ?>"
                       <?= isset($errors['delivery_city']) ? 'aria-invalid="true"' : '' ?>>
                <?php if (isset($errors['delivery_city'])): ?>
                  <p class="error-text"><?= e($errors['delivery_city']) ?></p>
                <?php elseif ($freeCity !== ''): ?>
                  <p class="hint"><?= icon('truck') ?> Free delivery within <?= e($freeCity) ?>.</p>
                <?php endif; ?>
              </div>

              <div class="alert alert-info">
                <?= icon('alert') ?>
                <p><?= e((string) setting(
                    'delivery_note',
                    'The shop owner arranges delivery personally or books a courier for you.'
                )) ?></p>
              </div>
            </div>
          </fieldset>

          <!-- Payment -->
          <fieldset class="fieldset">
            <legend>Payment</legend>

            <div class="choice-group choice-group-2">
              <?php if ($cashOn): ?>
                <label class="choice">
                  <input type="radio" name="payment_method" value="cash"
                         <?= $form['payment_method'] === 'cash' ? 'checked' : '' ?>>
                  <span>
                    <span class="choice-title"><?= icon('cash') ?> Cash</span>
                    <span class="choice-note">Pay when you collect or receive your order.</span>
                  </span>
                </label>
              <?php endif; ?>

              <?php if ($gcashOn): ?>
                <label class="choice">
                  <input type="radio" name="payment_method" value="gcash"
                         <?= $form['payment_method'] === 'gcash' ? 'checked' : '' ?>>
                  <span>
                    <span class="choice-title"><?= icon('wallet') ?> GCash</span>
                    <span class="choice-note">Scan the shop QR and send your payment.</span>
                  </span>
                </label>
              <?php endif; ?>
            </div>

            <?php if (isset($errors['payment_method'])): ?>
              <p class="error-text"><?= e($errors['payment_method']) ?></p>
            <?php endif; ?>

            <?php if ($gcashOn): ?>
              <div class="gcash-panel" data-gcash-panel
                   <?= $form['payment_method'] === 'gcash' ? '' : 'hidden' ?>>
                <div class="gcash-qr">
                  <?php
                  $qrPath = (string) setting('gcash_qr_path', '');
                  $qrFile = $qrPath !== '' ? APP_ROOT . '/' . ltrim($qrPath, '/') : '';
                  ?>
                  <?php if ($qrFile !== '' && is_file($qrFile)): ?>
                    <img src="<?= e(asset(str_replace('assets/', '', $qrPath))) ?>"
                         alt="GCash QR code for the shop" width="128" height="128">
                  <?php else: ?>
                    <span class="p-2 small muted center">
                      QR code not uploaded yet
                    </span>
                  <?php endif; ?>
                </div>

                <div>
                  <p class="bold mb-2">Pay with GCash</p>
                  <?php
                  $gcashName   = (string) setting('gcash_name', '');
                  $gcashNumber = (string) setting('gcash_number', '');
                  ?>
                  <?php if ($gcashName !== '' || $gcashNumber !== ''): ?>
                    <p class="small mb-2">
                      <?= e($gcashName) ?><?= $gcashNumber !== '' ? ' &middot; ' . e($gcashNumber) : '' ?>
                    </p>
                  <?php endif; ?>
                  <p class="small muted mb-0">
                    Scan the QR and send <?= peso($total) ?>. Keep your GCash receipt and show it
                    when you collect. The shop confirms your payment manually before your order
                    is marked as paid.
                  </p>
                </div>
              </div>
            <?php endif; ?>
          </fieldset>

          <!-- Notes -->
          <fieldset class="fieldset">
            <legend>Anything else?</legend>

            <div class="field">
              <label class="label" for="special_instructions">Special instructions</label>
              <textarea class="textarea" id="special_instructions" name="special_instructions"
                        maxlength="400"
                        placeholder="For example: extra hot, no foam, less ice"><?= e($form['special_instructions']) ?></textarea>
              <p class="hint">Optional. We will pass this to the person making your drink.</p>
            </div>
          </fieldset>
        </div>

        <!-- Summary -->
        <aside class="checkout-summary">
          <div class="card card-pad">
            <h2 class="h-title">Your order</h2>

            <?php foreach ($cart['lines'] as $line): ?>
              <div class="items-start summary-row">
                <span>
                  <span class="bold"><?= (int) $line['quantity'] ?> &times; <?= e($line['name']) ?></span>
                  <?php if ($line['options'] !== []): ?>
                    <br><span class="tiny muted">
                      <?= e(implode(', ', array_column($line['options'], 'name'))) ?>
                    </span>
                  <?php endif; ?>
                </span>
                <span class="tabular nowrap"><?= peso($line['line_total']) ?></span>
              </div>
            <?php endforeach; ?>

            <div class="row-divider summary-row">
              <span class="muted">Subtotal</span>
              <span class="tabular"><?= peso($cart['subtotal']) ?></span>
            </div>

            <div class="summary-row">
              <span class="muted">Delivery fee</span>
              <span class="tabular" data-delivery-fee>
                <?php if ($form['order_type'] !== 'delivery'): ?>
                  &mdash;
                <?php elseif ($deliveryFee <= 0): ?>
                  Free
                <?php else: ?>
                  <?= peso($deliveryFee) ?>
                <?php endif; ?>
              </span>
            </div>

            <div class="summary-row is-total">
              <span>Total</span>
              <span class="amount tabular" data-order-total><?= peso($total) ?></span>
            </div>

            <?php if ($form['order_type'] === 'delivery' && $flatFee > 0 && $freeCity !== ''): ?>
              <p class="mt-2 tiny muted">
                Delivery is free within <?= e($freeCity) ?>. Other areas are
                <?= peso($flatFee) ?>, set by the shop.
              </p>
            <?php endif; ?>

            <button class="mt-4 btn btn-block btn-lg" type="submit"
                    data-busy-label="Placing your order">
              Place Order &amp; Get SMS Updates
            </button>

            <p class="mt-3 mb-0 tiny muted center">
              By placing this order you agree that we may text you about its status.
            </p>

            <p class="mt-2 center mb-0">
              <a class="btn btn-ghost btn-sm" href="<?= e(url('cart.php')) ?>">
                <?= icon('arrow-left') ?> Back to cart
              </a>
            </p>
          </div>
        </aside>
      </div>
    </form>
  </div>
</section>

<?php customer_footer(); ?>
