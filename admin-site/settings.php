<?php
/**
 * Shop settings, owner only.
 *
 * Every row in the settings table is editable here, grouped so the page reads
 * like the shop rather than like a database table.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_owner();

$admin = current_admin();

const SETTING_GROUPS = [
    'Shop' => [
        'blurb' => 'The name, hours and contact details customers see.',
        'keys'  => ['shop_name', 'shop_tagline', 'shop_phone', 'shop_address',
                    'shop_open_time', 'shop_close_time', 'accepting_orders', 'low_stock_alert'],
    ],
    'Ordering & Delivery' => [
        'blurb' => 'Delivery is a simplified option: the shop arranges it, and the fee is a flat amount you set.',
        'keys'  => ['delivery_enabled', 'delivery_fee', 'free_delivery_city', 'delivery_note'],
    ],
    'Payment' => [
        'blurb' => 'GCash is a static QR code that you confirm by hand. There is no payment processor.',
        'keys'  => ['payment_cash_enabled', 'payment_gcash_enabled', 'gcash_name', 'gcash_number', 'gcash_qr_path'],
    ],
    'SMS delivery' => [
        'blurb' => 'How texts leave the system. Sending through the shop handset uses your own '
                 . 'call and text plan, so it costs nothing per message. Semaphore is the paid '
                 . 'alternative and is billed per credit.',
        'keys'  => ['sms_enabled', 'sms_provider', 'sms_sender_name'],
    ],
    'SMS timing' => [
        'blurb' => 'Every message here is one text. Switch off any status the shop does not need.',
        'keys'  => ['sms_on_pending', 'sms_on_preparing', 'sms_on_ready',
                    'sms_on_out_for_delivery', 'sms_on_completed', 'sms_on_cancelled'],
    ],
    'Handset queue' => [
        'blurb' => 'Only used when sending through the handset. The defaults suit a small shop.',
        'keys'  => ['sms_device_name', 'sms_batch_size', 'sms_ttl_minutes',
                    'sms_max_attempts', 'sms_claim_timeout_seconds'],
    ],
];

const SETTING_BOOLEANS = [
    'accepting_orders', 'low_stock_alert', 'delivery_enabled',
    'payment_cash_enabled', 'payment_gcash_enabled', 'sms_enabled',
    'sms_on_pending', 'sms_on_preparing', 'sms_on_ready',
    'sms_on_out_for_delivery', 'sms_on_completed', 'sms_on_cancelled',
];

const SETTING_LABELS = [
    'shop_name'               => 'Shop name',
    'shop_tagline'            => 'Tagline',
    'shop_phone'              => 'Contact number',
    'shop_address'            => 'Address',
    'shop_open_time'          => 'Opens at',
    'shop_close_time'         => 'Closes at',
    'accepting_orders'        => 'Accepting online orders',
    'low_stock_alert'         => 'Warn me about low stock',
    'delivery_enabled'        => 'Offer delivery',
    'delivery_fee'            => 'Flat delivery fee',
    'free_delivery_city'      => 'City with free delivery',
    'delivery_note'           => 'Delivery note at checkout',
    'payment_cash_enabled'    => 'Allow paying at the counter',
    'payment_gcash_enabled'   => 'Allow GCash',
    'gcash_name'              => 'GCash account name',
    'gcash_number'            => 'GCash number',
    'gcash_qr_path'           => 'GCash QR image path',
    'sms_enabled'             => 'Send SMS notifications',
    'sms_provider'            => 'Send texts through',
    'sms_sender_name'         => 'Semaphore sender name',
    'sms_device_name'         => 'Handset label',
    'sms_batch_size'          => 'Messages per collection',
    'sms_ttl_minutes'         => 'Give up on a message after (minutes)',
    'sms_max_attempts'        => 'Hand a message over at most',
    'sms_claim_timeout_seconds' => 'Return an uncollected message after (seconds)',
    'sms_on_pending'          => 'Text when an order is received',
    'sms_on_preparing'        => 'Text when an order is being prepared',
    'sms_on_ready'            => 'Text when an order is ready',
    'sms_on_out_for_delivery' => 'Text when an order is out for delivery',
    'sms_on_completed'        => 'Text when an order is completed',
    'sms_on_cancelled'        => 'Text when an order is cancelled',
];

/** How a given key should be rendered and validated. */
function setting_field_type(string $key): string
{
    if (in_array($key, SETTING_BOOLEANS, true)) {
        return 'bool';
    }

    return match ($key) {
        'shop_open_time', 'shop_close_time' => 'time',
        'delivery_fee'                      => 'money',
        'delivery_note', 'shop_address'     => 'textarea',
        'sms_provider'                      => 'choice',
        'sms_batch_size', 'sms_ttl_minutes',
        'sms_max_attempts', 'sms_claim_timeout_seconds' => 'number',
        default                             => 'text',
    };
}

/** The options for a choice field. */
function setting_choices(string $key): array
{
    return match ($key) {
        'sms_provider' => [
            'phone'     => 'The shop handset (free, uses your own plan)',
            'semaphore' => 'Semaphore API (paid, one credit per text)',
            'off'       => 'Do not send anything',
        ],
        default => [],
    };
}

/** A readable label, falling back to the key itself. */
function setting_label(string $key): string
{
    return SETTING_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

// Every key that actually exists, so a key added to the table later still
// shows up rather than becoming uneditable.
$rows = db_all('SELECT `key`, `value`, `description` FROM settings ORDER BY `key` ASC');

$descriptions = [];
$allKeys      = [];

foreach ($rows as $row) {
    $allKeys[]                       = (string) $row['key'];
    $descriptions[(string) $row['key']] = (string) ($row['description'] ?? '');
}

$grouped = [];
$claimed = [];

foreach (SETTING_GROUPS as $title => $group) {
    $keys = array_values(array_intersect($group['keys'], $allKeys));

    if ($keys !== []) {
        $grouped[$title] = ['blurb' => $group['blurb'], 'keys' => $keys];
        $claimed = array_merge($claimed, $keys);
    }
}

$leftover = array_values(array_diff($allKeys, $claimed));

if ($leftover !== []) {
    $grouped['Other'] = ['blurb' => 'Settings that do not belong to a section above.', 'keys' => $leftover];
}

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    // --- The handset token ---------------------------------------------------
    $action = post_string('action');

    if ($action === 'gateway_token') {
        $token = sms_generate_device_token();

        set_setting('sms_device_token', $token);

        // Clear the connection record, since the old handset can no longer
        // authenticate and reporting it as connected would be misleading.
        set_setting('sms_device_last_seen', '');
        set_setting('sms_device_last_ip', '');

        audit('settings.updated', 'settings', 'sms_device_token',
            'Generated a new handset token', null, ['token' => 'regenerated'],
            (int) $admin['id']);

        flash('success', 'New handset token generated. Put it into the phone app, because the old one no longer works.');
        redirect('settings.php#handset');
    }

    if ($action === 'gateway_revoke') {
        set_setting('sms_device_token', '');
        set_setting('sms_device_last_seen', '');
        set_setting('sms_device_last_ip', '');

        audit('settings.updated', 'settings', 'sms_device_token',
            'Revoked the handset token', null, ['token' => 'revoked'],
            (int) $admin['id']);

        flash('success', 'Handset token revoked. Nothing can collect messages until you generate a new one.');
        redirect('settings.php#handset');
    }

    $posted  = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
    $current = all_settings();

    $errors = [];
    $wanted = [];

    foreach ($allKeys as $key) {
        // A key the form did not carry is left exactly as it is. Booleans
        // always arrive, because each checkbox has a hidden 0 in front of it.
        if (!array_key_exists($key, $posted)) {
            continue;
        }

        $type = setting_field_type($key);
        $raw  = is_string($posted[$key]) ? (string) $posted[$key] : '';

        if ($type === 'bool') {
            $wanted[$key] = $raw === '1' ? '1' : '0';
            continue;
        }

        $value = clean_text($raw, 2000);

        if ($type === 'time' && $value !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            $errors[] = setting_label($key) . ' must be a time such as 07:00.';
            continue;
        }

        if ($type === 'choice') {
            // Only a value we offer may be stored, so a tampered form cannot
            // put the system into a state the code does not handle.
            if (!array_key_exists($value, setting_choices($key))) {
                $errors[] = setting_label($key) . ' is not one of the available options.';
                continue;
            }

            $wanted[$key] = $value;
            continue;
        }

        if ($type === 'number') {
            if ($value === '' || !ctype_digit($value)) {
                $errors[] = setting_label($key) . ' must be a whole number.';
                continue;
            }

            $wanted[$key] = (string) max(1, min(86400, (int) $value));
            continue;
        }

        if ($type === 'money') {
            $value = str_replace(',', '', $value);

            if ($value === '' || !is_numeric($value) || (float) $value < 0) {
                $errors[] = setting_label($key) . ' must be zero or more.';
                continue;
            }

            $value = number_format((float) $value, 2, '.', '');
        }

        $wanted[$key] = $value;
    }

    if ($errors !== []) {
        flash('error', implode(' ', $errors));
        redirect('settings.php');
    }

    $old = [];
    $new = [];

    foreach ($wanted as $key => $value) {
        if ((string) ($current[$key] ?? '') === $value) {
            continue;
        }

        if (set_setting($key, $value)) {
            $old[$key] = (string) ($current[$key] ?? '');
            $new[$key] = $value;
        }
    }

    if ($new === []) {
        flash('info', 'Nothing was changed.');
    } else {
        audit(
            'settings.updated',
            'settings',
            null,
            sprintf('Changed %d shop setting%s', count($new), count($new) === 1 ? '' : 's'),
            $old,
            $new,
            (int) $admin['id']
        );

        flash('success', count($new) === 1 ? 'One setting saved.' : count($new) . ' settings saved.');
    }

    redirect('settings.php');
}

$values = all_settings(true);

admin_head('Settings');
admin_header('Settings', 'Only the owner can change these.');
?>

<form method="post" action="<?= e(admin_url('settings.php')) ?>">
  <?= csrf_field() ?>

  <div class="settings-grid">
    <?php foreach ($grouped as $title => $group): ?>
      <section class="card">
        <div class="card-header"><h2><?= e($title) ?></h2></div>
        <div class="card-body">
          <p class="hint settings-blurb"><?= e($group['blurb']) ?></p>

          <?php foreach ($group['keys'] as $key): ?>
            <?php
            $type    = setting_field_type($key);
            $value   = (string) ($values[$key] ?? '');
            $label   = setting_label($key);
            $hint    = $descriptions[$key] ?? '';
            $inputId = 'setting_' . $key;
            ?>

            <?php if ($type === 'bool'): ?>
              <label class="choice mb-4" for="<?= e($inputId) ?>">
                <input type="hidden" name="settings[<?= e($key) ?>]" value="0">
                <input type="checkbox" id="<?= e($inputId) ?>" name="settings[<?= e($key) ?>]" value="1"
                       <?= in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? 'checked' : '' ?>>
                <span>
                  <span class="choice-title"><?= e($label) ?></span>
                  <?php if ($hint !== ''): ?>
                    <span class="choice-note"><?= e($hint) ?></span>
                  <?php endif; ?>
                </span>
              </label>

            <?php elseif ($type === 'textarea'): ?>
              <div class="field">
                <label class="label" for="<?= e($inputId) ?>"><?= e($label) ?></label>
                <textarea class="textarea" id="<?= e($inputId) ?>" name="settings[<?= e($key) ?>]"
                          rows="2" maxlength="2000"><?= e($value) ?></textarea>
                <?php if ($hint !== ''): ?><p class="hint"><?= e($hint) ?></p><?php endif; ?>
              </div>

            <?php elseif ($type === 'time'): ?>
              <div class="field">
                <label class="label" for="<?= e($inputId) ?>"><?= e($label) ?></label>
                <input class="input" type="time" id="<?= e($inputId) ?>"
                       name="settings[<?= e($key) ?>]" value="<?= e($value) ?>">
                <?php if ($hint !== ''): ?><p class="hint"><?= e($hint) ?></p><?php endif; ?>
              </div>

            <?php elseif ($type === 'choice'): ?>
              <div class="field">
                <label class="label" for="<?= e($inputId) ?>"><?= e($label) ?></label>
                <select class="select" id="<?= e($inputId) ?>" name="settings[<?= e($key) ?>]">
                  <?php foreach (setting_choices($key) as $option => $optionLabel): ?>
                    <option value="<?= e($option) ?>"<?= $value === $option ? ' selected' : '' ?>>
                      <?= e($optionLabel) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($hint !== ''): ?><p class="hint"><?= e($hint) ?></p><?php endif; ?>
              </div>

            <?php elseif ($type === 'number'): ?>
              <div class="field">
                <label class="label" for="<?= e($inputId) ?>"><?= e($label) ?></label>
                <input class="input tabular" type="number" step="1" min="1" max="86400"
                       id="<?= e($inputId) ?>" name="settings[<?= e($key) ?>]" value="<?= e($value) ?>">
                <?php if ($hint !== ''): ?><p class="hint"><?= e($hint) ?></p><?php endif; ?>
              </div>

            <?php elseif ($type === 'money'): ?>
              <div class="field">
                <label class="label" for="<?= e($inputId) ?>"><?= e($label) ?></label>
                <input class="input tabular" type="number" step="0.01" min="0" max="99999"
                       id="<?= e($inputId) ?>" name="settings[<?= e($key) ?>]" value="<?= e($value) ?>">
                <?php if ($hint !== ''): ?><p class="hint"><?= e($hint) ?></p><?php endif; ?>
              </div>

            <?php else: ?>
              <div class="field">
                <label class="label" for="<?= e($inputId) ?>"><?= e($label) ?></label>
                <input class="input" type="text" id="<?= e($inputId) ?>"
                       name="settings[<?= e($key) ?>]" value="<?= e($value) ?>" maxlength="2000">
                <?php if ($hint !== ''): ?><p class="hint"><?= e($hint) ?></p><?php endif; ?>
              </div>
            <?php endif; ?>

          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <div class="card settings-save">
    <div class="card-body row row-wrap">
      <button type="submit" class="btn" data-busy-label="Saving">Save settings</button>
      <span class="small subtle">Changes take effect on both sites straight away, and are recorded in the audit trail.</span>
    </div>
  </div>
</form>

<!-- The handset connection. Kept outside the settings form because these are
     actions, not values, and regenerating a token must not depend on the rest
     of the form validating. -->
<section class="card" id="handset">
  <div class="card-header">
    <h2>Shop handset</h2>
    <?php
    $queue    = sms_queue_summary();
    $token    = trim((string) setting('sms_device_token', ''));
    $lastSeen = trim((string) setting('sms_device_last_seen', ''));
    $seenAgo  = $lastSeen === '' ? null : time() - (int) strtotime($lastSeen);
    $connected = $seenAgo !== null && $seenAgo < 600;
    ?>
    <span class="badge <?= $connected ? 'badge-ready' : 'badge-cancelled' ?>">
      <?= $connected ? 'Connected' : 'Not connected' ?>
    </span>
  </div>

  <p class="panel-help">
    The phone collects messages from the server and sends them on your own plan, so each text
    costs nothing. Nothing is pushed to the phone, because a web host cannot reach a handset
    behind a home router or on mobile data.
  </p>

  <div class="card-body">
    <div class="handset-stats">
      <div>
        <span class="label-sm">Waiting to send</span>
        <p class="handset-stat<?= $queue['queued'] > 0 ? ' is-warn' : '' ?>"><?= (int) $queue['queued'] ?></p>
      </div>
      <div>
        <span class="label-sm">Sent today</span>
        <p class="handset-stat"><?= (int) $queue['sent_today'] ?></p>
      </div>
      <div>
        <span class="label-sm">Failed today</span>
        <p class="handset-stat<?= $queue['failed_today'] > 0 ? ' is-bad' : '' ?>"><?= (int) $queue['failed_today'] ?></p>
      </div>
      <div>
        <span class="label-sm">Last heard from</span>
        <p class="handset-stat-small">
          <?= $lastSeen === '' ? 'Never' : e(date('j M, g:i A', (int) strtotime($lastSeen))) ?>
        </p>
      </div>
    </div>

    <?php if ($queue['queued'] > 0 && !$connected): ?>
      <div class="alert alert-warning">
        <?= admin_icon('icon-alert', 'icon-sm') ?>
        <div>
          There <?= $queue['queued'] === 1 ? 'is a message' : 'are ' . (int) $queue['queued'] . ' messages' ?>
          waiting and the phone has not checked in recently. Customers can still follow their
          orders on the tracking page, so nothing is lost, but the texts will not go out until
          the phone is back online.
        </div>
      </div>
    <?php endif; ?>

    <h3 class="form-heading">Connection details</h3>

    <div class="field">
      <span class="label">Address the phone calls</span>
      <input class="input" type="text" readonly
             value="<?= e(rtrim(ADMIN_URL, '/') . '/api/sms-gateway.php') ?>"
             data-select-all data-no-reveal="true">
      <p class="hint">
        Use the address the phone can actually reach. On a hosted site that is your real domain,
        not localhost.
      </p>
    </div>

    <div class="field">
      <span class="label">Token</span>
      <?php if ($token === ''): ?>
        <p class="small subtle">No token yet. Generate one, then put it into the phone app.</p>
      <?php else: ?>
        <input class="input" type="password" readonly value="<?= e($token) ?>"
               data-select-all>
        <p class="hint">
          Treat this like a password. Anyone holding it can read the message queue, which
          contains customer numbers.
        </p>
      <?php endif; ?>
    </div>

    <div class="row row-wrap">
      <form method="post" action="<?= e(admin_url('settings.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="gateway_token">
        <button type="submit" class="btn btn-sm"
                data-confirm="<?= $token === '' ? 'Generate a token for the phone?' : 'Generate a new token? The phone will stop working until you update it there too.' ?>">
          <?= admin_icon('icon-refresh', 'icon-sm') ?>
          <?= $token === '' ? 'Generate token' : 'Generate a new token' ?>
        </button>
      </form>

      <?php if ($token !== ''): ?>
        <form method="post" action="<?= e(admin_url('settings.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="gateway_revoke">
          <button type="submit" class="btn btn-sm btn-ghost btn-danger-text"
                  data-confirm="Revoke the token? Nothing will be able to collect messages until you generate a new one.">
            Revoke
          </button>
        </form>
      <?php endif; ?>
    </div>

    <p class="tiny subtle mt-4 mb-0">
      Setup steps for the phone are in <code>docs/sms-handset-setup.md</code>.
    </p>
  </div>
</section>

<?php admin_footer(); ?>
