<?php
/**
 * Inventory: what is in stock, and a way to correct it.
 *
 * Orders move stock on their own when they are accepted for preparation.
 * This page is for everything else: a delivery arriving, breakage, a
 * stocktake that disagrees with the system.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_login();

$admin = current_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $action = post_string('action');

    if ($action === 'item_save') {
        $itemId  = (int) ($_POST['item_id'] ?? 0);
        $name    = clean_text(post_string('name'), 120);
        $unit    = clean_text(post_string('unit'), 24);
        $reorder = max(0.0, (float) str_replace(',', '', post_string('reorder_level', '0')));
        $active  = isset($_POST['is_active']) ? 1 : 0;

        // Only set on create. Afterwards stock moves through adjustments, so
        // that every change has a reason attached to it in the audit trail.
        $opening = max(0.0, (float) str_replace(',', '', post_string('stock_qty', '0')));

        $existing = $itemId > 0
            ? db_one('SELECT * FROM inventory_items WHERE id = ? LIMIT 1', [$itemId])
            : null;

        $clash = db_value(
            'SELECT id FROM inventory_items WHERE name = ? AND id <> ?',
            [$name, $itemId]
        );

        if ($name === '') {
            flash('error', 'Give the item a name.');
        } elseif ($unit === '') {
            flash('error', 'Give the unit, for example g, ml or pc.');
        } elseif ($clash !== null) {
            flash('error', 'There is already an inventory item called ' . $name . '.');
        } elseif ($itemId > 0 && $existing === null) {
            flash('error', 'That inventory item no longer exists.');
        } elseif ($existing !== null) {
            db_query(
                'UPDATE inventory_items SET name = ?, unit = ?, reorder_level = ?, is_active = ? WHERE id = ?',
                [$name, $unit, $reorder, $active, $itemId]
            );

            [$old, $new] = audit_diff(
                $existing,
                ['name' => $name, 'unit' => $unit, 'reorder_level' => $reorder, 'is_active' => $active],
                ['name', 'unit', 'reorder_level', 'is_active']
            );

            audit('inventory.updated', 'inventory_item', $itemId,
                'Edited ' . $name, $old, $new, (int) $admin['id']);

            flash('success', $name . ' updated.');
        } else {
            $newId = db_insert(
                'INSERT INTO inventory_items (name, unit, stock_qty, reorder_level, is_active)
                 VALUES (?, ?, ?, ?, ?)',
                [$name, $unit, $opening, $reorder, $active]
            );

            audit('inventory.created', 'inventory_item', $newId,
                sprintf('Added %s, opening stock %s %s', $name, rtrim(rtrim(number_format($opening, 3, '.', ''), '0'), '.'), $unit),
                null,
                ['name' => $name, 'unit' => $unit, 'stock_qty' => $opening, 'reorder_level' => $reorder],
                (int) $admin['id']
            );

            flash('success', $name . ' added to the inventory.');
        }

        redirect('inventory.php');
    }

    if ($action === 'adjust') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $delta  = (float) str_replace(',', '', post_string('delta', '0'));
        $reason = clean_text(post_string('reason'), 255);

        $item = db_one('SELECT * FROM inventory_items WHERE id = ? LIMIT 1', [$itemId]);

        if ($item === null) {
            flash('error', 'That inventory item no longer exists.');
        } elseif ($delta === 0.0) {
            flash('error', 'Give an amount to add or take away.');
        } elseif ($reason === '') {
            flash('error', 'Say why the stock is being changed.');
        } else {
            $before = (float) $item['stock_qty'];
            $after  = max(0.0, round($before + $delta, 3));

            db_query('UPDATE inventory_items SET stock_qty = ? WHERE id = ?', [$after, $itemId]);

            audit(
                'inventory.adjusted',
                'inventory_item',
                $itemId,
                sprintf(
                    '%s %s%s %s: %s',
                    (string) $item['name'],
                    $delta > 0 ? '+' : '',
                    rtrim(rtrim(number_format($delta, 3, '.', ''), '0'), '.'),
                    (string) $item['unit'],
                    $reason
                ),
                ['stock_qty' => $before],
                ['stock_qty' => $after, 'reason' => $reason],
                (int) $admin['id']
            );

            flash('success', sprintf(
                '%s is now %s %s.',
                (string) $item['name'],
                rtrim(rtrim(number_format($after, 3, '.', ''), '0'), '.'),
                (string) $item['unit']
            ));
        }
    }

    redirect('inventory.php');
}

// The low-stock tally is always across everything, never just this page.
$lowCount = (int) db_value(
    'SELECT COUNT(*) FROM inventory_items WHERE stock_qty <= reorder_level AND is_active = 1'
);

// --- Filters ---------------------------------------------------------------
$search      = get_string('q');
$stateFilter = get_string('state');   // '', 'low', 'active', 'inactive'

$conditions = [];
$params     = [];

if ($search !== '') {
    $conditions[] = 'name LIKE ?';
    $params[]     = '%' . $search . '%';
}

if ($stateFilter === 'low') {
    $conditions[] = 'stock_qty <= reorder_level AND is_active = 1';
} elseif ($stateFilter === 'active') {
    $conditions[] = 'is_active = 1';
} elseif ($stateFilter === 'inactive') {
    $conditions[] = 'is_active = 0';
}

$where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

// --- Pagination -------------------------------------------------------------
const PER_PAGE = 10;

$totalItems = (int) db_value('SELECT COUNT(*) FROM inventory_items' . $where, $params);
$totalPages = max(1, (int) ceil($totalItems / PER_PAGE));
$page       = max(1, min($totalPages, get_int('page', 1)));
$offset     = ($page - 1) * PER_PAGE;

$items = db_all(
    'SELECT * FROM inventory_items' . $where . '
     ORDER BY (stock_qty <= reorder_level) DESC, name ASC
     LIMIT ' . PER_PAGE . ' OFFSET ' . $offset,
    $params
);

// Every item, for the adjust dropdown, which must not be limited to this page.
$allItems = db_all('SELECT id, name, unit, stock_qty FROM inventory_items ORDER BY name ASC');

$filterQuery = array_filter([
    'q'     => $search !== '' ? $search : null,
    'state' => $stateFilter !== '' ? $stateFilter : null,
]);

$hasFilters = $filterQuery !== [];

$selectedId = get_int('item');
$editId     = get_int('edit');
$editItem   = $editId > 0
    ? db_one('SELECT * FROM inventory_items WHERE id = ? LIMIT 1', [$editId])
    : null;

/** Trim the trailing zeros off a DECIMAL so 12.000 reads as 12. */
function qty(float|string $value): string
{
    $text = number_format((float) $value, 3, '.', '');

    return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
}

admin_head('Inventory');
admin_header('Inventory', $lowCount === 0
    ? 'Everything is above its reorder level.'
    : $lowCount . ' item' . ($lowCount === 1 ? ' is' : 's are') . ' at or below the reorder level.');
?>

<?php if ($lowCount > 0): ?>
  <div class="alert alert-warning" role="status">
    <div>
      <strong><?= $lowCount ?> item<?= $lowCount === 1 ? '' : 's' ?></strong> need restocking.
      They are listed first below.
    </div>
  </div>
<?php endif; ?>

<div class="inventory-grid">

  <section class="card">
    <div class="card-header">
      <h2>Stock on hand</h2>
      <span class="small subtle">
        <?php if ($totalItems === 0): ?>
          none
        <?php else: ?>
          <?= $offset + 1 ?>&ndash;<?= min($offset + PER_PAGE, $totalItems) ?> of <?= $totalItems ?>
        <?php endif; ?>
      </span>
    </div>

    <form class="filter-bar" method="get" action="<?= e(admin_url('inventory.php')) ?>" data-no-guard>
      <div class="filter-field filter-grow">
        <label class="visually-hidden" for="filter_q">Search items</label>
        <input class="input" type="search" id="filter_q" name="q" value="<?= e($search) ?>"
               placeholder="Search by name">
      </div>

      <div class="filter-field">
        <label class="visually-hidden" for="filter_state">State</label>
        <select class="select" id="filter_state" name="state">
          <option value="">Any state</option>
          <option value="low"<?= $stateFilter === 'low' ? ' selected' : '' ?>>Low on stock</option>
          <option value="active"<?= $stateFilter === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="inactive"<?= $stateFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
      </div>

      <button type="submit" class="btn btn-sm btn-secondary">
        <?= admin_icon('icon-search', 'icon-sm') ?> Filter
      </button>

      <?php if ($hasFilters): ?>
        <a class="btn btn-sm btn-ghost" href="<?= e(admin_url('inventory.php')) ?>">Clear</a>
      <?php endif; ?>
    </form>

    <?php if ($items === []): ?>
      <div class="empty">
        <?= admin_icon('icon-box', 'empty-icon') ?>
        <?php if ($hasFilters): ?>
          <h3>Nothing matches those filters</h3>
          <p class="mb-0">
            <a href="<?= e(admin_url('inventory.php')) ?>">Clear them</a> to see everything.
          </p>
        <?php else: ?>
          <h3>No inventory items</h3>
          <p class="mb-0">Add your first item using the form beside this list.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="table-wrap table-flush">
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Item</th>
              <th scope="col">In stock</th>
              <th scope="col">Reorder at</th>
              <th scope="col">State</th>
              <th scope="col">Updated</th>
              <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <?php $isLow = (float) $item['stock_qty'] <= (float) $item['reorder_level']; ?>
              <tr class="<?= $isLow ? 'row-low' : '' ?>">
                <td>
                  <span class="bold"><?= e((string) $item['name']) ?></span>
                  <?php if ((int) $item['is_active'] !== 1): ?>
                    <span class="badge badge-completed">Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="tabular nowrap"><?= e(qty($item['stock_qty'])) ?> <span class="subtle small"><?= e((string) $item['unit']) ?></span></td>
                <td class="tabular nowrap subtle"><?= e(qty($item['reorder_level'])) ?></td>
                <td>
                  <span class="badge <?= $isLow ? 'badge-cancelled' : 'badge-ready' ?>">
                    <?= $isLow ? 'Low' : 'OK' ?>
                  </span>
                </td>
                <td class="small subtle nowrap">
                  <?= e(date('j M, g:i A', (int) strtotime((string) $item['updated_at']))) ?>
                </td>
                <td class="right nowrap">
                  <a class="btn btn-sm btn-secondary"
                     href="<?= e(admin_url('inventory.php')) ?>?item=<?= (int) $item['id'] ?>#adjust-form">Adjust</a>
                  <a class="btn btn-sm btn-ghost"
                     href="<?= e(admin_url('inventory.php')) ?>?edit=<?= (int) $item['id'] ?>#item-form">Edit</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="card-footer">
          <?php admin_pagination($page, $totalPages, $filterQuery, 'inventory.php'); ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <div class="inventory-forms">

  <section class="card" id="item-form">
    <div class="card-header">
      <h2><?= $editItem === null ? 'Add an item' : 'Edit item' ?></h2>
      <?php if ($editItem !== null): ?>
        <a class="btn btn-sm btn-ghost" href="<?= e(admin_url('inventory.php')) ?>#item-form">Cancel</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" action="<?= e(admin_url('inventory.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="item_save">
        <input type="hidden" name="item_id" value="<?= (int) ($editItem['id'] ?? 0) ?>">

        <div class="field">
          <label class="label" for="item_name">Name <span class="req">*</span></label>
          <input class="input" type="text" id="item_name" name="name" maxlength="120" required
                 value="<?= e((string) ($editItem['name'] ?? '')) ?>"
                 placeholder="Espresso Beans">
        </div>

        <div class="field-row">
          <div class="field">
            <label class="label" for="item_unit">Unit <span class="req">*</span></label>
            <input class="input" type="text" id="item_unit" name="unit" maxlength="24" required
                   value="<?= e((string) ($editItem['unit'] ?? '')) ?>"
                   placeholder="g, ml, pc">
          </div>

          <div class="field">
            <label class="label" for="item_reorder">Reorder level</label>
            <input class="input tabular" type="number" id="item_reorder" name="reorder_level"
                   step="0.001" min="0"
                   value="<?= e(qty($editItem['reorder_level'] ?? 0)) ?>">
            <p class="hint">Flagged as low at or below this.</p>
          </div>
        </div>

        <?php if ($editItem === null): ?>
          <div class="field">
            <label class="label" for="item_stock">Opening stock</label>
            <input class="input tabular" type="number" id="item_stock" name="stock_qty"
                   step="0.001" min="0" value="0">
            <p class="hint">
              Set once, here. After this, stock only moves through adjustments and orders,
              so every change carries a reason.
            </p>
          </div>
        <?php else: ?>
          <div class="field">
            <span class="label">In stock</span>
            <p class="mb-0 tabular">
              <span class="bold"><?= e(qty($editItem['stock_qty'])) ?></span>
              <span class="subtle small"><?= e((string) $editItem['unit']) ?></span>
            </p>
            <p class="hint">
              Change this with <a href="<?= e(admin_url('inventory.php')) ?>?item=<?= (int) $editItem['id'] ?>#adjust-form">an adjustment</a>,
              so the reason is recorded.
            </p>
          </div>
        <?php endif; ?>

        <label class="choice">
          <input type="checkbox" name="is_active" value="1"
                 <?= (int) ($editItem['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>
            <span class="choice-title">Active</span>
            <span class="choice-note">Inactive items are ignored by the low-stock warning.</span>
          </span>
        </label>

        <button type="submit" class="btn btn-block mt-4" data-busy-label="Saving">
          <?= admin_icon($editItem === null ? 'icon-plus' : 'icon-check', 'icon-sm') ?>
          <?= $editItem === null ? 'Add item' : 'Save changes' ?>
        </button>
      </form>
    </div>
  </section>

  <section class="card" id="adjust-form">
    <div class="card-header"><h2>Adjust stock</h2></div>
    <div class="card-body">
      <?php if ($allItems === []): ?>
        <p class="small subtle mb-0">There is nothing to adjust yet.</p>
      <?php else: ?>
        <form method="post" action="<?= e(admin_url('inventory.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="adjust">

          <div class="field">
            <label class="label" for="item_id">Item <span class="req">*</span></label>
            <select class="select" id="item_id" name="item_id" required>
              <?php foreach ($allItems as $item): ?>
                <option value="<?= (int) $item['id'] ?>"<?= $selectedId === (int) $item['id'] ? ' selected' : '' ?>>
                  <?= e((string) $item['name']) ?> (<?= e(qty($item['stock_qty'])) ?> <?= e((string) $item['unit']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="label" for="delta">Change <span class="req">*</span></label>
            <input class="input tabular" type="number" id="delta" name="delta" step="0.001" required
                   placeholder="e.g. 12 to add, -3 to take away">
            <p class="hint">A positive number adds stock, a negative number takes it away.</p>
          </div>

          <div class="field">
            <label class="label" for="reason">Reason <span class="req">*</span></label>
            <input class="input" type="text" id="reason" name="reason" maxlength="255" required
                   placeholder="Delivery received, breakage, stocktake correction">
            <p class="hint">This is recorded in the audit trail against your name.</p>
          </div>

          <button type="submit" class="btn btn-block" data-busy-label="Saving">
            <?= admin_icon('icon-refresh', 'icon-sm') ?> Save adjustment
          </button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card-footer">
      <p class="tiny subtle mb-0">
        Stock also comes off automatically when an order is moved to Preparing,
        and goes back on if that order is later cancelled.
      </p>
    </div>
  </section>

  </div>

</div>

<?php admin_footer(); ?>
