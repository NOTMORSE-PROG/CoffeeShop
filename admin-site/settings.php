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
    'SMS' => [
        'blurb' => 'Each message costs a credit, so switch off any status the shop does not need.',
        'keys'  => ['sms_enabled', 'sms_sender_name', 'sms_on_pending', 'sms_on_preparing',
                    'sms_on_ready', 'sms_on_out_for_delivery', 'sms_on_completed', 'sms_on_cancelled'],
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
    'sms_sender_name'         => 'Semaphore sender name',
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
        default                             => 'text',
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

    $posted  = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
    $current = all_settings();

    $errors = [];
    $wanted = [];

    foreach ($allKeys as $key) {
        $type = setting_field_type($key);
        $raw  = is_string($posted[$key] ?? null) ? (string) $posted[$key] : '';

        if ($type === 'bool') {
            $wanted[$key] = $raw === '1' ? '1' : '0';
            continue;
        }

        $value = clean_text($raw, 2000);

        if ($type === 'time' && $value !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            $errors[] = setting_label($key) . ' must be a time such as 07:00.';
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

<?php admin_footer(); ?>
