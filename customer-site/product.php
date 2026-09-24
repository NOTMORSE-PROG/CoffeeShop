<?php
/**
 * One drink, with its customisation options.
 *
 * This replaces the "choose options in a modal" pattern from the draft. A
 * real page means the choice is linkable, the back button behaves, and the
 * form still submits without JavaScript.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

$productId = get_int('id');

$product = db_one(
    'SELECT p.*, c.name AS category_name, c.slug AS category_slug
     FROM products p
     JOIN categories c ON c.id = p.category_id
     WHERE p.id = ? LIMIT 1',
    [$productId]
);

if ($product === null) {
    http_response_code(404);
    customer_head('Drink not found');
    echo '<div class="container section"><div class="empty">'
       . icon('search')
       . '<h3>We could not find that drink</h3>'
       . '<p><a href="' . e(url('menu.php')) . '">Back to the menu</a></p>'
       . '</div></div>';
    customer_footer();
    exit;
}

// Handle "add to cart".
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $quantity = max(1, min(CART_MAX_QTY, (int) ($_POST['quantity'] ?? 1)));

    // Pick-any groups post as option_ids[], pick-one groups as
    // option_single[groupId]. Merge them into one list of option ids.
    $optionIds = array_merge(
        array_map('intval', (array) ($_POST['option_ids'] ?? [])),
        array_map('intval', array_values((array) ($_POST['option_single'] ?? [])))
    );

    $result = cart_add((int) $product['id'], $quantity, $optionIds);

    if ($result['ok']) {
        flash('success', $product['name'] . ' added to your cart.');
        redirect(url('cart.php'));
    }

    flash('error', $result['error'] ?? 'That could not be added to your cart.');
    redirect(url('product.php?id=' . (int) $product['id']));
}

$optionGroups = db_all(
    'SELECT g.id, g.name, g.selection_type, g.is_required
     FROM option_groups g
     JOIN product_option_groups pog ON pog.group_id = g.id
     WHERE pog.product_id = ?
     ORDER BY g.sort_order, g.id',
    [(int) $product['id']]
);

foreach ($optionGroups as $index => $group) {
    $optionGroups[$index]['options'] = db_all(
        'SELECT id, name, price_delta, is_default FROM options
         WHERE group_id = ? ORDER BY sort_order, id',
        [(int) $group['id']]
    );
}

$available = (int) $product['is_available'] === 1;
$closed    = shop_closed_reason();

customer_head($product['name'], (string) $product['description']);
?>

<section class="section-sm">
  <div class="container">
    <p class="mb-4">
      <a class="btn btn-ghost btn-sm" href="<?= e(url('menu.php#cat-' . $product['category_slug'])) ?>">
        <?= icon('arrow-left') ?> Back to <?= e($product['category_name']) ?>
      </a>
    </p>

    <div class="product-detail">
      <div class="product-detail-media">
        <img src="<?= e(asset(str_replace('assets/', '', (string) $product['image_path']))) ?>"
             alt="<?= e($product['name']) ?>" width="240" height="240">
      </div>

      <div>
        <p class="product-category"><?= e($product['category_name']) ?></p>
        <h1><?= e($product['name']) ?></h1>
        <p class="lede"><?= e((string) $product['description']) ?></p>
        <p class="product-detail-price"><?= peso($product['price']) ?></p>

        <?php if (!$available): ?>
          <div class="alert alert-warning">
            <?= icon('alert') ?>
            <p><?= e($product['name']) ?> is not available right now. Please pick something else from the menu.</p>
          </div>
        <?php else: ?>

          <?php if ($closed !== null): ?>
            <div class="alert alert-warning">
              <?= icon('clock') ?>
              <p><?= e($closed) ?> You can still build your cart and check out once we reopen.</p>
            </div>
          <?php endif; ?>

          <form method="post" action="<?= e(url('product.php?id=' . (int) $product['id'])) ?>"
                data-price-form data-base-price="<?= e((string) $product['price']) ?>">
            <?= csrf_field() ?>

            <?php foreach ($optionGroups as $group):
                $isSingle = $group['selection_type'] === 'single';
                $inputType = $isSingle ? 'radio' : 'checkbox';
            ?>
              <fieldset class="option-group">
                <div class="option-group-head">
                  <h3><?= e($group['name']) ?></h3>
                  <span class="small muted">
                    <?= $isSingle ? 'Pick one' : 'Pick any' ?>
                  </span>
                </div>

                <div class="choice-group choice-group-2">
                  <?php foreach ($group['options'] as $option): ?>
                    <label class="choice">
                      <?php
                      /* Each pick-one group needs its own name, otherwise every
                         radio on the page belongs to one group and choosing a
                         sugar level would clear the size. */
                      $fieldName = $isSingle
                          ? 'option_single[' . (int) $group['id'] . ']'
                          : 'option_ids[]';
                      ?>
                      <input type="<?= e($inputType) ?>"
                             name="<?= e($fieldName) ?>"
                             value="<?= (int) $option['id'] ?>"
                             data-price-delta="<?= e((string) $option['price_delta']) ?>"
                             <?= $isSingle && (int) $option['is_default'] === 1 ? 'checked' : '' ?>>
                      <span class="choice-title"><?= e($option['name']) ?></span>
                      <?php if ((float) $option['price_delta'] > 0): ?>
                        <span class="choice-price">+<?= peso($option['price_delta']) ?></span>
                      <?php endif; ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
            <?php endforeach; ?>

            <div class="mt-6 gap-5 row row-wrap">
              <div>
                <span class="label" id="qty-label">Quantity</span>
                <div class="qty-control" data-qty>
                  <button type="button" data-qty-down aria-label="Reduce quantity"><?= icon('minus') ?></button>
                  <output aria-labelledby="qty-label">1</output>
                  <button type="button" data-qty-up aria-label="Increase quantity"><?= icon('plus') ?></button>
                  <input class="visually-hidden" type="number" name="quantity"
                         value="1" min="1" max="<?= CART_MAX_QTY ?>" aria-label="Quantity">
                </div>
              </div>

              <div class="grow">
                <span class="label">Total</span>
                <p class="product-detail-price mb-0" data-price-output><?= peso($product['price']) ?></p>
              </div>
            </div>

            <button class="mt-5 btn btn-lg btn-block" type="submit"
                    data-busy-label="Adding">
              <?= icon('cart') ?> Add to cart
            </button>
          </form>

        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php customer_footer(); ?>
