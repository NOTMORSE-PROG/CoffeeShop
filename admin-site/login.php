<?php
/**
 * Admin sign-in.
 *
 * Renders without the sidebar: there is nothing to navigate to until the
 * visitor has proved who they are.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();

// Already signed in, so there is nothing to do here.
if (is_logged_in()) {
    redirect(!empty($_SESSION['must_change_password']) ? 'change-password.php' : 'index.php');
}

$error    = '';
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $username = post_string('username');
    $password = (string) ($_POST['password'] ?? '');

    $result = attempt_login($username, $password);

    if ($result['ok']) {
        $intended = (string) ($_SESSION['intended_url'] ?? '');
        unset($_SESSION['intended_url']);

        if (!empty($_SESSION['must_change_password'])) {
            redirect('change-password.php');
        }

        // Only ever follow a path on this site, never an absolute URL from
        // somewhere else, so the form cannot be turned into an open redirect.
        if ($intended !== '' && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            redirect($intended);
        }

        redirect('index.php');
    }

    $error = (string) $result['error'];
}

// A lockout that started on an earlier request should still be visible on a
// fresh load of the form.
$lockedOut = $error === '' && is_throttled($username);

admin_head('Sign in', ['bodyClass' => 'admin-auth']);
?>

<div class="auth-wrap">
  <div class="auth-card card">
    <div class="auth-brand">
      <img src="<?= e(admin_asset('img/logo-mark.svg')) ?>" alt="" width="52" height="52">
      <h1 class="auth-title"><?= e((string) setting('shop_name', APP_NAME)) ?></h1>
      <p class="auth-note">Admin sign-in</p>
    </div>

    <div class="card-body">
      <?php admin_flashes(); ?>

      <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><div><?= e($error) ?></div></div>
      <?php elseif ($lockedOut): ?>
        <div class="alert alert-warning" role="alert">
          <div>
            Too many failed attempts from this computer. Please wait
            <?= (int) LOGIN_LOCKOUT_MINUTES ?> minutes and try again.
          </div>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(admin_url('login.php')) ?>" class="stack" autocomplete="on">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="username">Username</label>
          <input class="input" type="text" id="username" name="username"
                 value="<?= e($username) ?>" required autofocus
                 autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50">
        </div>

        <div class="field">
          <label class="label" for="password">Password</label>
          <input class="input" type="password" id="password" name="password"
                 required autocomplete="current-password" maxlength="200">
        </div>

        <button type="submit" class="btn btn-lg btn-block" data-busy-label="Signing in">
          <?= admin_icon('icon-lock', 'icon-sm') ?> Sign in
        </button>
      </form>
    </div>

    <div class="card-footer center">
      <p class="tiny subtle mb-0">This area is for shop staff only. Every action is recorded.</p>
    </div>
  </div>
</div>

<?php admin_footer(); ?>
