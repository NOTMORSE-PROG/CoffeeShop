<?php
/**
 * Admin accounts.
 *
 * Owner only. The shop is a single-owner business, so this exists mainly so
 * the owner can give a helper their own sign-in rather than sharing one
 * password. Separate accounts are what make the audit trail meaningful.
 *
 * Accounts are never deleted, only deactivated, because the audit trail
 * references them and history should not lose its author.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_owner();

$admin  = current_admin();
$selfId = (int) $admin['id'];

/** How many active owners exist, so the last one cannot lock everyone out. */
function active_owner_count(): int
{
    return (int) db_value(
        "SELECT COUNT(*) FROM admin_users WHERE role = 'owner' AND is_active = 1"
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $action = post_string('action');
    $userId = (int) ($_POST['user_id'] ?? 0);

    $target = $userId > 0
        ? db_one('SELECT * FROM admin_users WHERE id = ? LIMIT 1', [$userId])
        : null;

    if ($action === 'user_save') {
        $username = strtolower(clean_text(post_string('username'), 50));
        $fullName = clean_text(post_string('full_name'), 120);
        $email    = clean_text(post_string('email'), 160);
        $role     = post_string('role') === 'owner' ? 'owner' : 'staff';
        $password = post_string('password');

        $clash = db_value(
            'SELECT id FROM admin_users WHERE username = ? AND id <> ?',
            [$username, $userId]
        );

        // Guard rails, checked before anything is written.
        $error = null;

        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            $error = 'The username may only use lowercase letters, numbers, dots, dashes and underscores, and must be at least 3 characters.';
        } elseif ($fullName === '') {
            $error = 'Give the person a full name.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address does not look right.';
        } elseif ($clash !== null) {
            $error = 'The username ' . $username . ' is already taken.';
        } elseif ($userId > 0 && $target === null) {
            $error = 'That account no longer exists.';
        } elseif ($userId === 0 && $password === '') {
            $error = 'Give the new account a starting password.';
        } elseif ($password !== '' && ($policy = password_policy_error($password)) !== null) {
            $error = $policy;
        } elseif (
            $target !== null
            && $target['role'] === 'owner'
            && $role !== 'owner'
            && active_owner_count() <= 1
        ) {
            $error = 'This is the only active owner. Make someone else an owner first.';
        }

        if ($error !== null) {
            flash('error', $error);
        } elseif ($target !== null) {
            db_query(
                'UPDATE admin_users SET username = ?, full_name = ?, email = ?, role = ? WHERE id = ?',
                [$username, $fullName, $email === '' ? null : $email, $role, $userId]
            );

            // A reset also forces a change, so the owner never keeps knowing
            // another person's working password.
            if ($password !== '') {
                db_query(
                    'UPDATE admin_users SET password_hash = ?, must_change_password = 1 WHERE id = ?',
                    [hash_password($password), $userId]
                );
            }

            [$old, $new] = audit_diff(
                $target,
                ['username' => $username, 'full_name' => $fullName, 'email' => $email, 'role' => $role],
                ['username', 'full_name', 'email', 'role']
            );

            if ($password !== '') {
                $new['password'] = 'reset';
            }

            audit('user.updated', 'admin_user', $userId,
                'Edited the account ' . $username, $old, $new, $selfId);

            flash('success', 'Account ' . $username . ' updated.'
                . ($password !== '' ? ' They must set a new password at their next sign-in.' : ''));
        } else {
            $newId = db_insert(
                'INSERT INTO admin_users (username, full_name, email, password_hash, role, must_change_password)
                 VALUES (?, ?, ?, ?, ?, 1)',
                [$username, $fullName, $email === '' ? null : $email, hash_password($password), $role]
            );

            audit('user.created', 'admin_user', $newId,
                sprintf('Created the %s account %s', $role, $username),
                null,
                ['username' => $username, 'full_name' => $fullName, 'role' => $role],
                $selfId
            );

            flash('success', 'Account ' . $username . ' created. They will set their own password at first sign-in.');
        }
    } elseif ($action === 'user_active') {
        $makeActive = (int) ($_POST['is_active'] ?? 0) === 1;

        if ($target === null) {
            flash('error', 'That account no longer exists.');
        } elseif ($userId === $selfId) {
            flash('error', 'You cannot deactivate the account you are signed in with.');
        } elseif (!$makeActive && $target['role'] === 'owner' && active_owner_count() <= 1) {
            flash('error', 'This is the only active owner. Promote someone else first.');
        } else {
            db_query('UPDATE admin_users SET is_active = ? WHERE id = ?', [$makeActive ? 1 : 0, $userId]);

            audit(
                $makeActive ? 'user.updated' : 'user.deactivated',
                'admin_user',
                $userId,
                sprintf('%s the account %s', $makeActive ? 'Reactivated' : 'Deactivated', (string) $target['username']),
                ['is_active' => (int) $target['is_active']],
                ['is_active' => $makeActive ? 1 : 0],
                $selfId
            );

            flash('success', sprintf(
                '%s is now %s.',
                (string) $target['username'],
                $makeActive ? 'active' : 'deactivated'
            ));
        }
    }

    redirect('users.php');
}

$users = db_all(
    'SELECT id, username, full_name, email, role, is_active, must_change_password,
            last_login_at, last_login_ip, created_at
     FROM admin_users
     ORDER BY is_active DESC, role = \'owner\' DESC, username ASC'
);

$editId   = get_int('edit');
$editUser = $editId > 0
    ? db_one('SELECT * FROM admin_users WHERE id = ? LIMIT 1', [$editId])
    : null;

$activeCount = 0;
foreach ($users as $user) {
    if ((int) $user['is_active'] === 1) {
        $activeCount++;
    }
}

admin_head('Admin accounts');
admin_header(
    'Admin accounts',
    $activeCount . ' active account' . ($activeCount === 1 ? '' : 's') . '. Give each person their own sign-in so the audit trail names them.'
);
?>

<div class="users-grid">

  <section class="card">
    <div class="card-header">
      <h2>Accounts</h2>
      <span class="small subtle"><?= count($users) ?> total</span>
    </div>

    <div class="table-wrap table-flush">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Person</th>
            <th scope="col">Role</th>
            <th scope="col">State</th>
            <th scope="col">Last signed in</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
            <?php $isSelf = (int) $user['id'] === $selfId; ?>
            <tr>
              <td>
                <span class="bold"><?= e((string) $user['full_name']) ?></span>
                <?php if ($isSelf): ?><span class="badge badge-plain">You</span><?php endif; ?>
                <br>
                <span class="small subtle"><?= e((string) $user['username']) ?></span>
                <?php if (!empty($user['email'])): ?>
                  <span class="small subtle">&middot; <?= e((string) $user['email']) ?></span>
                <?php endif; ?>
              </td>

              <td>
                <span class="badge <?= $user['role'] === 'owner' ? 'badge-ready' : 'badge-plain' ?>">
                  <?= $user['role'] === 'owner' ? 'Owner' : 'Staff' ?>
                </span>
              </td>

              <td>
                <?php if ((int) $user['is_active'] !== 1): ?>
                  <span class="badge badge-cancelled">Deactivated</span>
                <?php elseif ((int) $user['must_change_password'] === 1): ?>
                  <span class="badge badge-pending">Must set password</span>
                <?php else: ?>
                  <span class="badge badge-ready">Active</span>
                <?php endif; ?>
              </td>

              <td class="small subtle nowrap">
                <?php if (empty($user['last_login_at'])): ?>
                  Never
                <?php else: ?>
                  <?= e(date('j M, g:i A', (int) strtotime((string) $user['last_login_at']))) ?>
                  <?php if (!empty($user['last_login_ip'])): ?>
                    <br><span class="tiny"><?= e((string) $user['last_login_ip']) ?></span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>

              <td class="right nowrap">
                <a class="btn btn-sm btn-secondary"
                   href="<?= e(admin_url('users.php')) ?>?edit=<?= (int) $user['id'] ?>#user-form">Edit</a>

                <?php if (!$isSelf): ?>
                  <form method="post" action="<?= e(admin_url('users.php')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="user_active">
                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= (int) $user['is_active'] === 1 ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-sm btn-ghost"
                            data-confirm="<?= (int) $user['is_active'] === 1
                                ? 'Deactivate ' . e((string) $user['username']) . '? They will not be able to sign in.'
                                : 'Reactivate ' . e((string) $user['username']) . '?' ?>">
                      <?= (int) $user['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card-footer">
      <p class="tiny subtle mb-0">
        Accounts are deactivated rather than deleted, so the audit trail keeps naming
        whoever performed each past action.
      </p>
    </div>
  </section>

  <section class="card" id="user-form">
    <div class="card-header">
      <h2><?= $editUser === null ? 'Add an account' : 'Edit account' ?></h2>
      <?php if ($editUser !== null): ?>
        <a class="btn btn-sm btn-ghost" href="<?= e(admin_url('users.php')) ?>#user-form">Cancel</a>
      <?php endif; ?>
    </div>

    <div class="card-body">
      <form method="post" action="<?= e(admin_url('users.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="user_save">
        <input type="hidden" name="user_id" value="<?= (int) ($editUser['id'] ?? 0) ?>">

        <div class="field">
          <label class="label" for="full_name">Full name <span class="req">*</span></label>
          <input class="input" type="text" id="full_name" name="full_name" maxlength="120" required
                 value="<?= e((string) ($editUser['full_name'] ?? '')) ?>"
                 placeholder="Juan Dela Cruz">
        </div>

        <div class="field">
          <label class="label" for="username">Username <span class="req">*</span></label>
          <input class="input" type="text" id="username" name="username" maxlength="50" required
                 value="<?= e((string) ($editUser['username'] ?? '')) ?>"
                 autocomplete="off" spellcheck="false" placeholder="juan">
          <p class="hint">Lowercase letters, numbers, dots, dashes and underscores.</p>
        </div>

        <div class="field">
          <label class="label" for="email">Email</label>
          <input class="input" type="email" id="email" name="email" maxlength="160"
                 value="<?= e((string) ($editUser['email'] ?? '')) ?>"
                 placeholder="Optional">
        </div>

        <div class="field">
          <span class="label">Role</span>
          <div class="choice-group choice-group-2">
            <label class="choice">
              <input type="radio" name="role" value="staff"
                     <?= ($editUser['role'] ?? 'staff') === 'staff' ? 'checked' : '' ?>>
              <span>
                <span class="choice-title">Staff</span>
                <span class="choice-note">Everything except Settings and this page.</span>
              </span>
            </label>
            <label class="choice">
              <input type="radio" name="role" value="owner"
                     <?= ($editUser['role'] ?? '') === 'owner' ? 'checked' : '' ?>>
              <span>
                <span class="choice-title">Owner</span>
                <span class="choice-note">Full access, including shop settings.</span>
              </span>
            </label>
          </div>
        </div>

        <div class="field">
          <label class="label" for="password">
            <?= $editUser === null ? 'Starting password' : 'Reset password' ?>
            <?php if ($editUser === null): ?><span class="req">*</span><?php endif; ?>
          </label>
          <input class="input" type="text" id="password" name="password" maxlength="200"
                 autocomplete="new-password"
                 <?= $editUser === null ? 'required' : 'placeholder="Leave blank to keep the current password"' ?>>
          <p class="hint">
            At least <?= PASSWORD_MIN_LENGTH ?> characters with a letter and a number.
            Shown as plain text so you can read it out once. They are made to change it
            at their first sign-in.
          </p>
        </div>

        <button type="submit" class="btn btn-block" data-busy-label="Saving">
          <?= admin_icon($editUser === null ? 'icon-plus' : 'icon-check', 'icon-sm') ?>
          <?= $editUser === null ? 'Create account' : 'Save changes' ?>
        </button>
      </form>
    </div>
  </section>

</div>

<?php admin_footer(); ?>
