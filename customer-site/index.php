<?php
/**
 * Home page.
 *
 * Keeps the voice of the team's own draft: "Your daily cup of comfort."
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

$tagline  = (string) setting('shop_tagline', 'coffee is always a good idea');
$closed   = shop_closed_reason();

$featured = db_all(
    'SELECT p.id, p.name, p.slug, p.description, p.price, p.image_path, c.name AS category_name
     FROM products p
     JOIN categories c ON c.id = p.category_id
     WHERE p.is_featured = 1 AND p.is_available = 1
     ORDER BY p.sort_order, p.id
     LIMIT 4'
);

$categories = db_all(
    'SELECT c.id, c.name, c.slug, c.description,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_available = 1) AS product_count
     FROM categories c
     WHERE c.is_active = 1
     ORDER BY c.sort_order, c.id'
);

customer_head('', 'Order ahead from Our Coffee Shop in Mandaluyong City and get SMS updates on your order.', 'page-home');
?>

<?php if ($closed !== null): ?>
  <div class="container">
    <div class="alert alert-warning">
      <?= icon('clock') ?>
      <p><?= e($closed) ?></p>
    </div>
  </div>
<?php endif; ?>

<section class="hero">
  <div class="container hero-inner">
    <div class="hero-copy">
      <p class="eyebrow">Welcome to <span class="brand-inline">ouR</span> Coffee Shop</p>

      <h1 class="display">
        Your daily cup of<br>
        <span class="accent-text">comfort.</span>
      </h1>

      <p class="quote hero-quote">&ldquo; <?= e($tagline) ?> &rdquo;</p>

      <p class="lede">
        Freshly brewed coffee made for good conversations, quiet mornings,
        busy afternoons, and everything in between.
      </p>

      <p class="lede">
        Order your favourite cup online and we will text you the moment it is ready,
        so you can wait wherever you like instead of standing in the queue.
      </p>

      <div class="hero-actions">
        <a class="btn btn-lg" href="<?= e(url('menu.php')) ?>">
          Order Now <?= icon('arrow-right') ?>
        </a>
        <a class="btn btn-lg btn-secondary" href="<?= e(url('track.php')) ?>">
          Track an order
        </a>
      </div>

      <ul class="hero-points">
        <li><?= icon('message') ?> SMS updates at every stage</li>
        <li><?= icon('store') ?> Pick up or have it delivered</li>
        <li><?= icon('wallet') ?> Pay by GCash or at the counter</li>
      </ul>
    </div>

    <div class="hero-art" aria-hidden="true">
      <div class="hero-card hero-card-main">
        <img src="<?= e(asset('img/products/salted-caramel.svg')) ?>" alt="" width="240" height="240">
      </div>
      <div class="hero-card hero-card-float hero-card-a">
        <?= icon('message', 'icon icon-lg accent-text') ?>
        <div>
          <p class="hero-card-title">Order Received</p>
          <p class="hero-card-note">We texted you</p>
        </div>
      </div>
      <div class="hero-card hero-card-float hero-card-b">
        <?= icon('check-circle', 'icon icon-lg') ?>
        <div>
          <p class="hero-card-title">Ready for Pickup</p>
          <p class="hero-card-note">Come and get it</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <header class="section-head">
      <p class="eyebrow">Our Menu</p>
      <h2>Made to order, every time</h2>
      <p class="lede">
        Five ways to drink it. Pick a category and customise your cup.
      </p>
    </header>

    <div class="category-grid">
      <?php foreach ($categories as $category): ?>
        <a class="category-card" href="<?= e(url('menu.php#cat-' . $category['slug'])) ?>">
          <h3><?= e($category['name']) ?></h3>
          <p class="small muted"><?= e((string) $category['description']) ?></p>
          <p class="category-count">
            <?= (int) $category['product_count'] ?> drink<?= (int) $category['product_count'] === 1 ? '' : 's' ?>
            <?= icon('arrow-right') ?>
          </p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($featured !== []): ?>
<section class="section section-sunken">
  <div class="container">
    <header class="section-head">
      <p class="eyebrow">Regulars order these</p>
      <h2>The ones we are known for</h2>
    </header>

    <div class="product-grid">
      <?php foreach ($featured as $product): ?>
        <article class="product-card">
          <a class="product-media" href="<?= e(url('product.php?id=' . (int) $product['id'])) ?>">
            <img src="<?= e(asset(str_replace('assets/', '', (string) $product['image_path']))) ?>"
                 alt="<?= e($product['name']) ?>" width="240" height="240" loading="lazy">
          </a>
          <div class="product-body">
            <p class="product-category"><?= e($product['category_name']) ?></p>
            <h3 class="product-name">
              <a href="<?= e(url('product.php?id=' . (int) $product['id'])) ?>"><?= e($product['name']) ?></a>
            </h3>
            <p class="product-desc small muted"><?= e((string) $product['description']) ?></p>
            <div class="product-foot">
              <span class="product-price"><?= peso($product['price']) ?></span>
              <a class="btn btn-sm" href="<?= e(url('product.php?id=' . (int) $product['id'])) ?>">Choose</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section">
  <div class="container">
    <header class="section-head">
      <p class="eyebrow">How it works</p>
      <h2>Four steps, then your phone does the rest</h2>
    </header>

    <ol class="how-grid">
      <li class="how-step">
        <span class="how-num">1</span>
        <h3>Pick your drinks</h3>
        <p class="small muted">Browse the menu, set your size and sugar level, and add to your cart.</p>
      </li>
      <li class="how-step">
        <span class="how-num">2</span>
        <h3>Tell us where</h3>
        <p class="small muted">Pick up at the shop, or give us an address and the owner arranges delivery.</p>
      </li>
      <li class="how-step">
        <span class="how-num">3</span>
        <h3>Pay your way</h3>
        <p class="small muted">Scan the GCash QR, or simply pay at the counter when you collect.</p>
      </li>
      <li class="how-step">
        <span class="how-num">4</span>
        <h3>Wait for the text</h3>
        <p class="small muted">We text you when it is being prepared and again when it is ready.</p>
      </li>
    </ol>
  </div>
</section>

<?php customer_footer(); ?>
