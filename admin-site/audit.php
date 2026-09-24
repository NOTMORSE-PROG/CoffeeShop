<?php
/**
 * The audit trail.
 *
 * Append-only: there is no edit or delete path here or anywhere else, which
 * is the whole point of keeping it.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_login();

const AUDIT_PER_PAGE = 50;

$filters = [
    'admin_id'    => get_int('admin_id'),
    'action'      => get_string('action'),
    'entity_type' => get_string('entity_type'),
    'date_from'   => get_string('date_from'),
    'date_to'     => get_string('date_to'),
];

if ($filters['date_from'] !== '' && strtotime($filters['date_from']) === false) {
    $filters['date_from'] = '';
}

if ($filters['date_to'] !== '' && strtotime($filters['date_to']) === false) {
    $filters['date_to'] = '';
}

$page       = max(1, get_int('page', 1));
$totalRows  = audit_count($filters);
$totalPages = max(1, (int) ceil($totalRows / AUDIT_PER_PAGE));
$page       = min($page, $totalPages);

$entries = audit_search($filters, AUDIT_PER_PAGE, ($page - 1) * AUDIT_PER_PAGE);

$admins      = db_all('SELECT id, username, full_name FROM admin_users ORDER BY full_name ASC');
$actions     = db_all('SELECT DISTINCT action FROM audit_log ORDER BY action ASC');
$entityTypes = db_all('SELECT DISTINCT entity_type FROM audit_log WHERE entity_type IS NOT NULL ORDER BY entity_type ASC');

$pagerQuery = array_filter(
    $filters,
    static fn ($value) => $value !== '' && $value !== 0
);

/** Values in the log are JSON, so a nested one still has to print as something. */
function audit_value(mixed $value): string
{
    if ($value === null) {
        return '—';
    }

    if (is_bool($value)) {
        return $value ? 'yes' : 'no';
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
}

admin_head('Audit trail');
admin_header('Audit trail', number_format($totalRows) . ' recorded action' . ($totalRows === 1 ? '' : 's') . '.');
?>

<form class="card filter-bar" method="get" action="<?= e(admin_url('audit.php')) ?>" data-no-guard>
  <div class="filter-grid">
    <div class="field">
      <label class="label" for="admin_id">Who</label>
      <select class="select" id="admin_id" name="admin_id">
        <option value="0">Anyone</option>
        <?php foreach ($admins as $person): ?>
          <option value="<?= (int) $person['id'] ?>"<?= $filters['admin_id'] === (int) $person['id'] ? ' selected' : '' ?>>
            <?= e((string) $person['full_name']) ?> (<?= e((string) $person['username']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="action">Action</label>
      <select class="select" id="action" name="action">
        <option value="">Any action</option>
        <?php foreach ($actions as $row): ?>
          <?php $key = (string) $row['action']; ?>
          <option value="<?= e($key) ?>"<?= $filters['action'] === $key ? ' selected' : '' ?>>
            <?= e(audit_action_label($key)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="entity_type">Record type</label>
      <select class="select" id="entity_type" name="entity_type">
        <option value="">Any record</option>
        <?php foreach ($entityTypes as $row): ?>
          <?php $key = (string) $row['entity_type']; ?>
          <option value="<?= e($key) ?>"<?= $filters['entity_type'] === $key ? ' selected' : '' ?>>
            <?= e(ucfirst(str_replace('_', ' ', $key))) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label class="label" for="date_from">From</label>
      <input class="input" type="date" id="date_from" name="date_from" value="<?= e($filters['date_from']) ?>">
    </div>

    <div class="field">
      <label class="label" for="date_to">To</label>
      <input class="input" type="date" id="date_to" name="date_to" value="<?= e($filters['date_to']) ?>">
    </div>
  </div>

  <div class="filter-actions">
    <button type="submit" class="btn btn-sm"><?= admin_icon('icon-search', 'icon-sm') ?> Apply</button>
    <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('audit.php')) ?>">Clear</a>
  </div>
</form>

<?php if ($entries === []): ?>
  <div class="card">
    <div class="empty">
      <?= admin_icon('icon-shield', 'empty-icon') ?>
      <h3>Nothing recorded here</h3>
      <p class="mb-0">Try widening the dates or clearing the filters.</p>
    </div>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th scope="col">When</th>
          <th scope="col">Who</th>
          <th scope="col">Action</th>
          <th scope="col">Record</th>
          <th scope="col">What changed</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
          <?php
          $old  = json_decode((string) ($entry['old_values'] ?? ''), true);
          $new  = json_decode((string) ($entry['new_values'] ?? ''), true);
          $old  = is_array($old) ? $old : [];
          $new  = is_array($new) ? $new : [];
          $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
          ?>
          <tr>
            <td class="nowrap small">
              <?= e(date('j M Y', (int) strtotime((string) $entry['created_at']))) ?><br>
              <span class="subtle"><?= e(date('g:i:s A', (int) strtotime((string) $entry['created_at']))) ?></span>
            </td>
            <td class="small">
              <?= e((string) ($entry['admin_username'] ?? 'system')) ?><br>
              <span class="subtle tiny"><?= e((string) ($entry['ip_address'] ?? '')) ?></span>
            </td>
            <td class="small">
              <span class="bold"><?= e(audit_action_label((string) $entry['action'])) ?></span>
              <?php if (!empty($entry['summary'])): ?>
                <br><span class="subtle"><?= e((string) $entry['summary']) ?></span>
              <?php endif; ?>
            </td>
            <td class="small nowrap">
              <?php if (!empty($entry['entity_type'])): ?>
                <?= e(ucfirst(str_replace('_', ' ', (string) $entry['entity_type']))) ?>
                <?php if (!empty($entry['entity_id'])): ?>
                  <br><span class="subtle tabular"><?= e((string) $entry['entity_id']) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="subtle">—</span>
              <?php endif; ?>
            </td>
            <td class="small">
              <?php if ($keys === []): ?>
                <span class="subtle">—</span>
              <?php else: ?>
                <ul class="change-list">
                  <?php foreach ($keys as $key): ?>
                    <li>
                      <span class="change-field"><?= e(ucfirst(str_replace('_', ' ', (string) $key))) ?></span>
                      <span class="change-old"><?= e(audit_value($old[$key] ?? null)) ?></span>
                      <?= admin_icon('icon-arrow-right', 'icon-xs') ?>
                      <span class="change-new"><?= e(audit_value($new[$key] ?? null)) ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php admin_pagination($page, $totalPages, $pagerQuery, 'audit.php'); ?>
<?php endif; ?>

<?php admin_footer(); ?>
