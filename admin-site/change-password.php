<?php
/**
 * Change the signed-in user's password.
 *
 * The seeded owner account is created with must_change_password set, so this
 * page is the first thing a new installation shows after sign-in.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_login();

$admin  = current_admin();
$forced = !empty($_SESSION['must_change_password']);
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $result = change_password(
        (int) $admin['id'],
        (string) ($_POST['current_password'] ?? ''),
        (string) ($_POST['new_password'] ?? ''),
        (string) ($_POST['confirm_password'] ?? '')
    );

    if ($result['ok']) {
        flash('success', 'Your password has been changed.');
        redirect('index.php');
    }

    $error = (string) $result['error'];
}

admin_head('Change password', ['bodyClass' => $forced ? 'admin-auth' : '']);

if (!$forced) {
    admin_header('Change password', 'Choose a new password for ' . (string) $admin['username'] . '.');
}
?>

<?php if ($forced): ?>
<div class="auth-wrap">
  <div class="auth-card card">
    <div class="auth-brand">
      <img src="<?= e(admin_asset('img/logo-mark.svg')) ?>" alt="" width="52" height="52">
      <h1 class="auth-title">Choose a new password</h1>
      <p class="auth-note">Signed in as <?= e((string) $admin['username']) ?></p>
    </div>
    <div class="card-body">
      <div class="alert alert-warning" role="status">
        <div>This account still uses the password it was set up with. Please choose your own before carrying on.</div>
      </div>
<?php else: ?>
  <div class="card card-narrow">
    <div class="card-body">
<?php endif; ?>

      <?php admin_flashes(); ?>

      <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><div><?= e($error) ?></div></div>
      <?php endif; ?>

      <form method="post" action="<?= e(admin_url('change-password.php')) ?>" class="stack">
        <?= csrf_field() ?>

        <div class="field">
          <label class="label" for="current_password">Current password</label>
          <input class="input" type="password" id="current_password" name="current_password"
                 required autocomplete="current-password" maxlength="200" autofocus>
        </div>

        <div class="field">
          <label class="label" for="new_password">New password</label>
          <input class="input" type="password" id="new_password" name="new_password"
                 required autocomplete="new-password" maxlength="200">
          <p class="hint">
            At least <?= (int) PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number.
          </p>
        </div>

        <div class="field">
          <label class="label" for="confirm_password">Repeat the new password</label>
          <input class="input" type="password" id="confirm_password" name="confirm_password"
                 required autocomplete="new-password" maxlength="200">
        </div>

        <button type="submit" class="btn btn-lg btn-block" data-busy-label="Saving">
          <?= admin_icon('icon-lock', 'icon-sm') ?> Save new password
        </button>
      </form>

<?php if ($forced): ?>
    </div>
  </div>
</div>
<?php else: ?>
    </div>
  </div>
<?php endif; ?>

<?php admin_footer(); ?>
