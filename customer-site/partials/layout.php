<?php
/**
 * Customer site layout: page head, header, footer.
 *
 * Every page calls customer_head(), then its own markup, then customer_footer().
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/config.php';
require_once dirname(__DIR__, 2) . '/shared/db.php';
require_once dirname(__DIR__, 2) . '/shared/helpers.php';
require_once dirname(__DIR__, 2) . '/shared/security.php';
require_once dirname(__DIR__, 2) . '/shared/settings.php';
require_once dirname(__DIR__, 2) . '/shared/cart.php';

/*
 * Start the customer session as soon as this file is included.
 *
 * A page handles its POST before it calls customer_head(), and the CSRF check
 * needs the session to already exist. Starting it here means no page can get
 * that order wrong. The name is explicit so the customer site never shares a
 * session with the admin site.
 */
start_session('ourcoffee_shop');

/**
 * Base URL path for this site.
 *
 * Taken from CUSTOMER_URL rather than from the script path, because a rewrite
 * can serve this site from the address root while the files still live in
 * customer-site/. An empty string means the site answers at the domain root,
 * which is a real answer rather than a missing one.
 */
function site_base(): string
{
    return CUSTOMER_BASE_PATH;
}

/** URL for a shared asset. */
function asset(string $path): string
{
    // assets/ is shared by both sites, so it does not necessarily sit under
    // this site's own base. ASSETS_URL pins it where that is the case.
    $root = ASSETS_URL !== '' ? ASSETS_URL : site_base() . '/assets';
    $url  = $root . '/' . ltrim($path, '/');

    // Stylesheets and scripts get a cache-busting stamp, since assets/ is
    // served with a long expiry. See asset_version() in shared/helpers.php.
    if (preg_match('/\.(css|js)$/', $path)) {
        $version = asset_version($path);
        if ($version !== '') {
            $url .= '?v=' . $version;
        }
    }

    return $url;
}

/** URL for a page on this site. */
function url(string $path = ''): string
{
    return site_base() . '/' . ltrim($path, '/');
}

/** An icon from the shared sprite. */
function icon(string $name, string $class = 'icon'): string
{
    return sprintf(
        '<svg class="%s" aria-hidden="true" focusable="false"><use href="%s#icon-%s"></use></svg>',
        e($class),
        e(asset('img/icons.svg')),
        e($name)
    );
}

/**
 * Open the page.
 *
 * @param string $title       Page title, without the shop name.
 * @param string $description Meta description.
 * @param string $bodyClass   Extra class for the body element.
 */
function customer_head(string $title, string $description = '', string $bodyClass = ''): void
{
    start_session('ourcoffee_shop');
    send_security_headers();

    $shopName = (string) setting('shop_name', APP_NAME);
    $fullTitle = $title === '' ? $shopName : $title . ' | ' . $shopName;
    $cartCount = cart_count();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($description !== '' ? $description : 'Order ahead from ' . $shopName . ' and get SMS updates on your order.') ?>">
<meta name="theme-color" content="#C41B1B">
<link rel="icon" type="image/svg+xml" href="<?= e(asset('img/logo-mark.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/customer.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">

<!-- Loading splash. Removed by app.js once the page has painted. -->
<div id="page-loader" class="page-loader" role="status" aria-live="polite">
  <svg class="loader-cup" viewBox="0 0 64 64" aria-hidden="true">
    <g class="loader-steam" fill="none" stroke="#C41B1B" stroke-width="3" stroke-linecap="round">
      <path d="M22 16c0-4 3-4 3-8"/>
      <path d="M31 14c0-4 3-4 3-8"/>
      <path d="M40 16c0-4 3-4 3-8"/>
    </g>
    <path d="M12 26h34v13a15 15 0 0 1-15 15h-4a15 15 0 0 1-15-15V26Z" fill="#C41B1B"/>
    <path d="M46 30h4a7 7 0 0 1 0 14h-4" fill="none" stroke="#C41B1B" stroke-width="4.5" stroke-linecap="round"/>
    <rect x="9" y="22" width="40" height="6" rx="3" fill="#201713"/>
    <rect x="8" y="56" width="42" height="5" rx="2.5" fill="#201713"/>
  </svg>
  <p class="loader-label">Warming up the machine</p>
  <div class="loader-bar"><span></span></div>
</div>

<a class="skip-link" href="#main">Skip to main content</a>

<header class="site-header">
  <div class="container site-header-inner">
    <a class="brand" href="<?= e(url('index.php')) ?>">
      <img src="<?= e(asset('img/logo.svg')) ?>" alt="<?= e($shopName) ?>" width="200" height="49">
    </a>

    <nav class="site-nav" aria-label="Main">
      <a href="<?= e(url('index.php')) ?>">Home</a>
      <a href="<?= e(url('menu.php')) ?>">Menu</a>
      <a href="<?= e(url('track.php')) ?>">Track Order</a>
      <a href="<?= e(url('index.php#about')) ?>">About</a>
    </nav>

    <div class="site-header-actions">
      <a class="cart-button" href="<?= e(url('cart.php')) ?>" aria-label="View cart, <?= (int) $cartCount ?> item<?= $cartCount === 1 ? '' : 's' ?>">
        <?= icon('cart') ?>
        <span class="cart-count<?= $cartCount === 0 ? ' is-empty' : '' ?>"><?= (int) $cartCount ?></span>
      </a>
      <a class="btn btn-sm nav-order-btn" href="<?= e(url('menu.php')) ?>">Order Now</a>
      <button class="nav-toggle btn-icon btn-secondary" type="button"
              aria-expanded="false" aria-controls="mobile-nav" aria-label="Open menu">
        <?= icon('menu') ?>
      </button>
    </div>
  </div>

  <nav class="mobile-nav" id="mobile-nav" hidden aria-label="Main, mobile">
    <a href="<?= e(url('index.php')) ?>">Home</a>
    <a href="<?= e(url('menu.php')) ?>">Menu</a>
    <a href="<?= e(url('cart.php')) ?>">Cart</a>
    <a href="<?= e(url('track.php')) ?>">Track Order</a>
  </nav>
</header>

<main id="main">
<?php
    foreach (take_flashes() as $flash) {
        printf(
            '<div class="container"><div class="alert alert-%s" data-autodismiss="7000">%s</div></div>',
            e($flash['type']),
            e($flash['message'])
        );
    }
}

/** Close the page. */
function customer_footer(): void
{
    $shopName = (string) setting('shop_name', APP_NAME);
    $address  = (string) setting('shop_address', '');
    $phone    = (string) setting('shop_phone', '');
    $open     = (string) setting('shop_open_time', '07:00');
    $close    = (string) setting('shop_close_time', '20:00');
    ?>
</main>

<footer class="site-footer" id="about">
  <div class="container">
    <div class="footer-grid">
      <div>
        <img src="<?= e(asset('img/logo.svg')) ?>" alt="" width="190" height="47" class="footer-logo">
        <p class="muted small">
          A small shop run by one owner, serving <?= e($address !== '' ? $address : 'Mandaluyong City') ?>.
          Order ahead, skip the queue, and let your phone tell you when it is ready.
        </p>
      </div>

      <div>
        <h3 class="footer-heading">Visit</h3>
        <p class="small muted">
          <?= e($address !== '' ? $address : 'Main Street, Mandaluyong City') ?><br>
          Open daily <?= e(date('g:i A', (int) strtotime($open))) ?>
          to <?= e(date('g:i A', (int) strtotime($close))) ?>
          <?php if ($phone !== ''): ?><br><?= e($phone) ?><?php endif; ?>
        </p>
      </div>

      <div>
        <h3 class="footer-heading">Ordering</h3>
        <ul class="footer-links">
          <li><a href="<?= e(url('menu.php')) ?>">Browse the menu</a></li>
          <li><a href="<?= e(url('cart.php')) ?>">Your cart</a></li>
          <li><a href="<?= e(url('track.php')) ?>">Track an order</a></li>
        </ul>
      </div>
    </div>

    <p class="footer-legal small">
      &copy; <?= date('Y') ?> <?= e($shopName) ?>. A capstone project for
      Makati Science Technological Institute of the Philippines.
    </p>
  </div>
</footer>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<script src="<?= e(asset('js/customer.js')) ?>" defer></script>
</body>
</html>
<?php
}
