<?php
/**
 * Menu.
 *
 * Everything is on one page grouped by category, with a sticky category list,
 * so choosing a drink never needs a round trip.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

$closed = shop_closed_reason();
$search = get_string('q');

$params = [];
$where  = ['p.is_available IS NOT NULL'];

if ($search !== '') {
    $where[]  = '(p.name LIKE ? OR p.description LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$products = db_all(
    'SELECT p.id, p.name, p.slug, p.description, p.price, p.image_path, p.is_available,
            c.id AS category_id, c.name AS category_name, c.slug AS category_slug
     FROM products p
     JOIN categories c ON c.id = p.category_id
     WHERE c.is_active = 1 AND ' . implode(' AND ', $where) . '
     ORDER BY c.sort_order, c.id, p.sort_order, p.id',
    $params
);

// Group by category for rendering.
$grouped = [];
foreach ($products as $product) {
    $grouped[(int) $product['category_id']]['category'] = [
        'name' => $product['category_name'],
        'slug' => $product['category_slug'],
    ];
    $grouped[(int) $product['category_id']]['items'][] = $product;
}

customer_head(
    'Menu',
    'Browse the full menu at Our Coffee Shop and order ahead with SMS updates.'
);
?>

<section class="section-sm">
  <div class="container">
    <header class="section-head">
      <p class="eyebrow">Our Menu</p>
      <h1 class="h-page display">
        What Would You Like To <span class="accent-text">Order?</span>
      </h1>
      <p class="quote"><?= e('" ' . (string) setting('shop_tagline', 'coffee is always a good idea') . ' "') ?></p>
    </header>

    <?php if ($closed !== null): ?>
      <div class="alert alert-warning">
        <?= icon('clock') ?>
        <p><?= e($closed) ?> You can still browse the menu.</p>
      </div>
    <?php endif; ?>

    <form class="row row-wrap mb-6" method="get" action="<?= e(url('menu.php')) ?>" role="search" data-no-guard>
      <label class="visually-hidden" for="menu-search">Search the menu</label>
      <input class="input grow" id="menu-search" type="search" name="q"
             value="<?= e($search) ?>" placeholder="Search for a drink, for example matcha">
      <button class="btn btn-secondary" type="submit"><?= icon('search') ?> Search</button>
      <?php if ($search !== ''): ?>
        <a class="btn btn-ghost" href="<?= e(url('menu.php')) ?>">Clear</a>
      <?php endif; ?>
    </form>

    <?php if ($grouped === []): ?>
      <div class="empty">
        <?= icon('search', 'icon') ?>
        <h3>Nothing matched &ldquo;<?= e($search) ?>&rdquo;</h3>
        <p>Try a shorter word, or <a href="<?= e(url('menu.php')) ?>">see the whole menu</a>.</p>
      </div>
    <?php else: ?>

      <div class="menu-layout">
        <aside class="menu-sidebar">
          <nav aria-label="Menu categories">
            <ul class="menu-cat-list">
              <?php foreach ($grouped as $group): ?>
                <li>
                  <a href="#cat-<?= e($group['category']['slug']) ?>">
                    <?= e($group['category']['name']) ?>
                    <span class="count"><?= count($group['items']) ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </nav>
        </aside>

        <div>
          <?php foreach ($grouped as $group): ?>
            <section class="menu-category" id="cat-<?= e($group['category']['slug']) ?>">
              <div class="menu-category-head">
                <h2><?= e($group['category']['name']) ?></h2>
                <span class="small muted"><?= count($group['items']) ?> drinks</span>
              </div>

              <div class="product-grid">
                <?php foreach ($group['items'] as $product):
                    $available = (int) $product['is_available'] === 1;
                    $href = url('product.php?id=' . (int) $product['id']);
                ?>
                  <article class="product-card<?= $available ? '' : ' is-unavailable' ?>">
                    <?php if ($available): ?>
                      <a class="product-media" href="<?= e($href) ?>">
                        <img src="<?= e(asset(str_replace('assets/', '', (string) $product['image_path']))) ?>"
                             alt="<?= e($product['name']) ?>" width="240" height="240" loading="lazy">
                      </a>
                    <?php else: ?>
                      <span class="product-media">
                        <img src="<?= e(asset(str_replace('assets/', '', (string) $product['image_path']))) ?>"
                             alt="<?= e($product['name']) ?>" width="240" height="240" loading="lazy">
                      </span>
                    <?php endif; ?>

                    <div class="product-body">
                      <p class="product-category"><?= e($product['category_name']) ?></p>
                      <h3 class="product-name">
                        <?php if ($available): ?>
                          <a href="<?= e($href) ?>"><?= e($product['name']) ?></a>
                        <?php else: ?>
                          <?= e($product['name']) ?>
                        <?php endif; ?>
                      </h3>
                      <p class="product-desc small muted"><?= e((string) $product['description']) ?></p>

                      <div class="product-foot">
                        <span class="product-price"><?= peso($product['price']) ?></span>
                        <?php if ($available): ?>
                          <a class="btn btn-sm" href="<?= e($href) ?>">Choose</a>
                        <?php else: ?>
                          <span class="badge badge-plain">Sold out</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
      </div>

    <?php endif; ?>
  </div>
</section>

<?php customer_footer(); ?>
