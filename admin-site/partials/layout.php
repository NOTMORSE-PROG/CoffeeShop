<?php
/**
 * Shared chrome for every admin page.
 *
 * One place for the <head>, the loading splash, the sidebar, the top bar and
 * the closing markup, so the pages themselves only carry their own content.
 *
 * Nothing here emits output on include. A page calls admin_head(), then
 * admin_header() when it wants the sidebar, then admin_footer().
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/config.php';
require_once __DIR__ . '/../../shared/db.php';
require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/security.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/audit.php';
require_once __DIR__ . '/../../shared/settings.php';
require_once __DIR__ . '/../../shared/sms.php';
require_once __DIR__ . '/../../shared/orders.php';

// ---------------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------------

/**
 * The URL prefix the admin site is mounted at, worked out from the request
 * rather than hard-coded, so the same files run under /ourcoffee-admin locally
 * and under whatever path the host gives them later.
 */
function admin_base(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));

    if ($dir === '/' || $dir === '.' || $dir === '') {
        $dir = '';
    }

    // api/queue.php sits one level down but shares the same asset and nav root.
    if (str_ends_with($dir, '/api')) {
        $dir = substr($dir, 0, -4);
    }

    $base = rtrim($dir, '/');

    return $base;
}

/** A URL for a file under the shared assets folder. */
function admin_asset(string $path): string
{
    // assets/ is shared with the customer site, so it may sit outside this
    // site's own base. ASSETS_URL pins it when that is the case.
    $root = ASSETS_URL !== '' ? ASSETS_URL : admin_base() . '/assets';
    $url  = $root . '/' . ltrim($path, '/');

    // Only the files that change with the code need stamping.
    if (preg_match('/\.(css|js)$/', $path)) {
        $version = asset_version($path);
        if ($version !== '') {
            $url .= '?v=' . $version;
        }
    }

    return $url;
}

/** A URL for another admin page. */
function admin_url(string $page): string
{
    return admin_base() . '/' . ltrim($page, '/');
}

/** The current page's filename, used to mark the active nav item. */
function admin_current_page(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
}

// ---------------------------------------------------------------------------
// Small view helpers
// ---------------------------------------------------------------------------

/** One icon from the shared sprite. */
function admin_icon(string $name, string $class = 'icon'): string
{
    return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"><use href="'
        . e(admin_asset('img/icons.svg')) . '#' . e($name) . '"></use></svg>';
}

/** A status pill using the shared badge classes. */
function status_badge(string $status, ?string $orderType = null): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(status_label($status, $orderType)) . '</span>';
}

/** A payment pill using the shared badge classes. */
function payment_badge(string $paymentStatus): string
{
    return '<span class="badge badge-' . e($paymentStatus) . '">'
        . ($paymentStatus === 'paid' ? 'Paid' : 'Unpaid') . '</span>';
}

/** A short, readable "how long ago" for the queue. */
function time_ago(string $timestamp): string
{
    $seconds = time() - (int) strtotime($timestamp);

    if ($seconds < 60) {
        return 'just now';
    }

    $minutes = intdiv($seconds, 60);

    if ($minutes < 60) {
        return $minutes . ' min ago';
    }

    $hours = intdiv($minutes, 60);

    if ($hours < 24) {
        return $hours . ' hr ago';
    }

    return intdiv($hours, 24) . ' d ago';
}

/** The nav, with Settings only for the owner. */
function admin_nav_items(): array
{
    $items = [
        ['page' => 'index.php',     'label' => 'Dashboard',   'icon' => 'icon-dashboard'],
        ['page' => 'orders.php',    'label' => 'Orders',      'icon' => 'icon-receipt'],
        ['page' => 'menu.php',      'label' => 'Menu',        'icon' => 'icon-cup'],
        ['page' => 'options.php',   'label' => 'Customisation', 'icon' => 'icon-settings'],
        ['page' => 'inventory.php', 'label' => 'Inventory',   'icon' => 'icon-box'],
        ['page' => 'analytics.php', 'label' => 'Analytics',   'icon' => 'icon-chart'],
        ['page' => 'audit.php',     'label' => 'Audit Trail', 'icon' => 'icon-shield'],
    ];

    if (is_owner()) {
        $items[] = ['page' => 'users.php',    'label' => 'Accounts', 'icon' => 'icon-user'];
        $items[] = ['page' => 'settings.php', 'label' => 'Settings', 'icon' => 'icon-settings'];
    }

    return $items;
}

/** Render any queued flash messages. */
function admin_flashes(): void
{
    $allowed = ['success', 'error', 'danger', 'warning', 'info'];

    foreach (take_flashes() as $flash) {
        $type = in_array($flash['type'], $allowed, true) ? $flash['type'] : 'info';
        $dismiss = in_array($type, ['success', 'info'], true) ? ' data-autodismiss="7000"' : '';

        echo '<div class="alert alert-' . $type . '" role="status"' . $dismiss . '><div>'
            . e((string) $flash['message']) . '</div></div>';
    }
}

/**
 * Pagination links that keep the current filters.
 * $query is the filter set to carry through, without the page number.
 */
function admin_pagination(int $page, int $totalPages, array $query, string $script = ''): void
{
    if ($totalPages < 2) {
        return;
    }

    $script = $script !== '' ? $script : admin_current_page();

    $link = static function (int $target) use ($query, $script): string {
        $query['page'] = $target;

        return admin_url($script) . '?' . http_build_query($query);
    };

    $first = max(1, $page - 2);
    $last  = min($totalPages, $first + 4);
    $first = max(1, $last - 4);

    echo '<nav class="pager" aria-label="Pagination">';

    if ($page > 1) {
        echo '<a class="pager-link" href="' . e($link($page - 1)) . '" rel="prev">Previous</a>';
    } else {
        echo '<span class="pager-link is-disabled">Previous</span>';
    }

    for ($i = $first; $i <= $last; $i++) {
        if ($i === $page) {
            echo '<span class="pager-link is-current" aria-current="page">' . $i . '</span>';
        } else {
            echo '<a class="pager-link" href="' . e($link($i)) . '">' . $i . '</a>';
        }
    }

    if ($page < $totalPages) {
        echo '<a class="pager-link" href="' . e($link($page + 1)) . '" rel="next">Next</a>';
    } else {
        echo '<span class="pager-link is-disabled">Next</span>';
    }

    echo '</nav>';
}

// ---------------------------------------------------------------------------
// Page chrome
// ---------------------------------------------------------------------------

/** Tracks whether admin_header() opened the shell, so the footer closes it. */
function admin_shell_open(?bool $set = null): bool
{
    static $open = false;

    if ($set !== null) {
        $open = $set;
    }

    return $open;
}

/**
 * Opens the document and renders the loading splash.
 *
 * $options:
 *   bodyClass  extra class on <body>
 *   scripts    extra script URLs, loaded after app.js and before admin.js
 */
function admin_head(string $title, array $options = []): void
{
    $shopName  = (string) setting('shop_name', APP_NAME);
    $bodyClass = 'admin' . (isset($options['bodyClass']) ? ' ' . $options['bodyClass'] : '');
    $extra     = $options['scripts'] ?? [];

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> &middot; <?= e($shopName) ?> Admin</title>
<link rel="icon" type="image/svg+xml" href="<?= e(admin_asset('img/logo-mark.svg')) ?>">
<link rel="stylesheet" href="<?= e(admin_asset('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(admin_asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(admin_asset('css/components.css')) ?>">
<link rel="stylesheet" href="<?= e(admin_asset('css/admin.css')) ?>">
<script src="<?= e(admin_asset('js/app.js')) ?>" defer></script>
<?php foreach ($extra as $script): ?>
<script src="<?= e($script) ?>" defer></script>
<?php endforeach; ?>
<script src="<?= e(admin_asset('js/admin.js')) ?>" defer></script>
</head>
<body class="<?= e($bodyClass) ?>" data-sprite="<?= e(admin_asset('img/icons.svg')) ?>">

<div id="page-loader" class="page-loader" role="status" aria-live="polite">
  <svg class="loader-cup" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.6"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <g class="loader-steam">
      <path d="M24 17c0-3 3-3.6 3-6.6S24 4 24 4"/>
      <path d="M32 15c0-3 3-3.6 3-6.6S32 2 32 2"/>
      <path d="M40 17c0-3 3-3.6 3-6.6S40 4 40 4"/>
    </g>
    <path d="M13 24h30v15a13 13 0 0 1-13 13h-4a13 13 0 0 1-13-13V24Z"/>
    <path d="M43 28h4.5a6.5 6.5 0 0 1 0 13H43"/>
    <path d="M10 58h38"/>
  </svg>
  <p class="loader-label">Brewing&hellip;</p>
  <div class="loader-bar"><span></span></div>
</div>

<a class="skip-link" href="#main">Skip to content</a>
<?php
}

/**
 * The sidebar and top bar, then opens <main>.
 * Pages that must stand alone, such as the sign-in screen, simply do not call
 * this and get a bare page instead.
 */
function admin_header(string $heading, string $subtitle = ''): void
{
    admin_shell_open(true);

    $admin   = current_admin();
    $name    = (string) ($admin['full_name'] ?? 'Signed in');
    $role    = (string) ($admin['role'] ?? 'staff');
    $current = admin_current_page();
    $open    = shop_is_open();

    ?>
<div class="admin-shell">

  <aside class="admin-sidebar" id="admin-sidebar">
    <a class="admin-brand" href="<?= e(admin_url('index.php')) ?>">
      <img src="<?= e(admin_asset('img/logo-mark.svg')) ?>" alt="" width="36" height="36">
      <span>
        <span class="admin-brand-name"><?= e((string) setting('shop_name', APP_NAME)) ?></span>
        <span class="admin-brand-note">Admin</span>
      </span>
    </a>

    <nav class="admin-nav" aria-label="Admin sections">
      <?php foreach (admin_nav_items() as $item): ?>
        <?php $active = $current === $item['page']
            || ($item['page'] === 'orders.php' && $current === 'order-view.php'); ?>
        <a class="admin-nav-link<?= $active ? ' is-active' : '' ?>"
           href="<?= e(admin_url($item['page'])) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
          <?= admin_icon($item['icon'], 'admin-nav-icon') ?>
          <span><?= e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="admin-user">
      <p class="admin-user-name"><?= e($name) ?></p>
      <p class="admin-user-role"><?= $role === 'owner' ? 'Owner' : 'Staff' ?></p>
      <form method="post" action="<?= e(admin_url('logout.php')) ?>" data-no-guard>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-secondary btn-sm btn-block">
          <?= admin_icon('icon-logout', 'icon-sm') ?> Sign out
        </button>
      </form>
    </div>
  </aside>

  <div class="admin-main">
    <header class="admin-topbar">
      <button type="button" class="btn btn-secondary btn-sm admin-menu-btn"
              data-sidebar-toggle aria-controls="admin-sidebar" aria-expanded="false">
        <?= admin_icon('icon-menu', 'icon-sm') ?> Menu
      </button>

      <div class="grow">
        <h1 class="admin-title"><?= e($heading) ?></h1>
        <?php if ($subtitle !== ''): ?>
          <p class="admin-subtitle"><?= e($subtitle) ?></p>
        <?php endif; ?>
      </div>

      <div class="admin-topbar-meta">
        <span class="badge <?= $open ? 'badge-ready' : 'badge-cancelled' ?>">
          <?= $open ? 'Open for orders' : 'Closed' ?>
        </span>
        <span class="small subtle nowrap"><?= e(date('D, j M Y')) ?></span>
      </div>
    </header>

    <main class="admin-content" id="main">
      <?php admin_flashes(); ?>
<?php
}

/** Closes the shell and the document. */
function admin_footer(): void
{
    if (admin_shell_open()) {
        ?>
    </main>
  </div>
</div>
<div class="admin-scrim" data-sidebar-close hidden></div>
<?php
        admin_shell_open(false);
    }
    ?>
</body>
</html>
<?php
}
