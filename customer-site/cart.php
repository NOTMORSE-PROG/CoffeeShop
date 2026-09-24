<?php
/**
 * Cart page.
 *
 * The draft showed the cart as a modal stacked over the menu. It is a real
 * page here so quantities can be changed, the back button works, and nothing
 * is hidden behind an overlay.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $action = post_string('action');

    if ($action === 'update') {
        $key      = post_string('key');
        $quantity = (int) ($_POST['quantity'] ?? 1);
        cart_set_quantity($key, $quantity);
        flash('success', $quantity <= 0 ? 'Item removed.' : 'Cart updated.');
    } elseif ($action === 'remove') {
        cart_remove(post_string('key'));
        flash('success', 'Item removed from your cart.');
    } elseif ($action === 'clear') {
        cart_clear();
        flash('success', 'Your cart is empty again.');
    }

    redirect(url('cart.php'));
}

$cart   = cart_detailed();
$closed = shop_closed_reason();

customer_head('Your Cart', 'Review your order before checkout.');
?>

<section class="section-sm">
  <div class="container">
    <header class="section-head">
      <p class="eyebrow">Almost there</p>
      <h1>Your <span class="accent-text">Cart</span></h1>
      <p class="lede">Review your drinks before checkout.</p>
    </header>

    <?php foreach ($cart['notices'] as $notice): ?>
      <div class="alert alert-warning"><?= icon('alert') ?><p><?= e($notice) ?></p></div>
    <?php endforeach; ?>

    <?php if ($cart['lines'] === []): ?>

      <div class="card card-pad">
        <div class="empty">
          <?= icon('cart', 'icon') ?>
          <h3>Your cart is empty</h3>
          <p>Add some coffee to get started.</p>
          <p><a class="btn" href="<?= e(url('menu.php')) ?>">Browse the menu <?= icon('arrow-right') ?></a></p>
        </div>
      </div>

    <?php else: ?>

      <div class="cart-layout">
        <div class="card">
          <div class="card-header">
            <h2><?= count($cart['lines']) ?> item<?= count($cart['lines']) === 1 ? '' : 's' ?></h2>
            <form method="post" action="<?= e(url('cart.php')) ?>" data-no-guard>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="clear">
              <button class="btn btn-ghost btn-sm" type="submit"
                      data-confirm="Remove everything from your cart?">Clear cart</button>
            </form>
          </div>

          <?php foreach ($cart['lines'] as $line): ?>
            <div class="cart-line">
              <div class="cart-line-media">
                <img src="<?= e(asset(str_replace('assets/', '', (string) $line['image_path']))) ?>"
                     alt="" width="78" height="78" loading="lazy">
              </div>

              <div class="grow">
                <p class="cart-line-name"><?= e($line['name']) ?></p>

                <?php if ($line['options'] !== []): ?>
                  <ul class="cart-line-options">
                    <?php foreach ($line['options'] as $option): ?>
                      <li>
                        <?= e($option['name']) ?><?php
                          if ((float) $option['price_delta'] > 0) {
                              echo ' (+' . e(peso($option['price_delta'])) . ')';
                          }
                        ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>

                <p class="mt-1 small muted mb-0">
                  <?= peso($line['unit_price'] + $line['options_total']) ?> each
                </p>

                <div class="cart-line-actions">
                  <form class="row" method="post" action="<?= e(url('cart.php')) ?>" data-no-guard>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="key" value="<?= e($line['key']) ?>">

                    <label class="visually-hidden" for="qty-<?= e($line['key']) ?>">
                      Quantity for <?= e($line['name']) ?>
                    </label>
                    <input class="input w-qty" id="qty-<?= e($line['key']) ?>" type="number" name="quantity"
                           value="<?= (int) $line['quantity'] ?>" min="0" max="<?= CART_MAX_QTY ?>" inputmode="numeric">

                    <button class="btn btn-secondary btn-sm" type="submit">Update</button>
                  </form>

                  <form method="post" action="<?= e(url('cart.php')) ?>" data-no-guard>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="key" value="<?= e($line['key']) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"
                            aria-label="Remove <?= e($line['name']) ?>">
                      <?= icon('trash') ?> Remove
                    </button>
                  </form>
                </div>
              </div>

              <span class="cart-line-total"><?= peso($line['line_total']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <aside class="cart-summary">
          <div class="card card-pad">
            <h2 class="h-title">Order summary</h2>

            <div class="summary-row">
              <span class="muted">Subtotal</span>
              <span class="tabular"><?= peso($cart['subtotal']) ?></span>
            </div>

            <div class="summary-row">
              <span class="muted">Delivery</span>
              <span class="small muted">Chosen at checkout</span>
            </div>

            <div class="summary-row is-total">
              <span>Total so far</span>
              <span class="amount tabular"><?= peso($cart['subtotal']) ?></span>
            </div>

            <?php if ($closed !== null): ?>
              <div class="mt-4 alert alert-warning">
                <?= icon('clock') ?><p><?= e($closed) ?></p>
              </div>
              <button class="btn btn-block btn-lg" type="button" disabled>Checkout unavailable</button>
            <?php else: ?>
              <a class="btn btn-block btn-lg mt-4" href="<?= e(url('checkout.php')) ?>">
                Proceed to Checkout <?= icon('arrow-right') ?>
              </a>
            <?php endif; ?>

            <p class="mt-3 mb-0 small muted center">
              <?= icon('message') ?> You will get SMS updates on this order.
            </p>

            <p class="mt-3 center mb-0">
              <a class="btn btn-ghost btn-sm" href="<?= e(url('menu.php')) ?>">Add more drinks</a>
            </p>
          </div>
        </aside>
      </div>

    <?php endif; ?>
  </div>
</section>

<?php customer_footer(); ?>
