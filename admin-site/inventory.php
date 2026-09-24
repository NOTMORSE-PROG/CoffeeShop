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

    if (post_string('action') === 'adjust') {
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

$items = db_all(
    'SELECT * FROM inventory_items ORDER BY (stock_qty <= reorder_level) DESC, name ASC'
);

$lowCount = 0;
foreach ($items as $item) {
    if ((float) $item['stock_qty'] <= (float) $item['reorder_level'] && (int) $item['is_active'] === 1) {
        $lowCount++;
    }
}

$selectedId = get_int('item');

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
      <span class="small subtle"><?= count($items) ?> items</span>
    </div>

    <?php if ($items === []): ?>
      <div class="empty">
        <?= admin_icon('icon-box', 'empty-icon') ?>
        <h3>No inventory items</h3>
        <p class="mb-0">Import the seed data, or add items to the database.</p>
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
                <td class="right">
                  <a class="btn btn-sm btn-secondary"
                     href="<?= e(admin_url('inventory.php')) ?>?item=<?= (int) $item['id'] ?>#adjust-form">Adjust</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" id="adjust-form">
    <div class="card-header"><h2>Adjust stock</h2></div>
    <div class="card-body">
      <?php if ($items === []): ?>
        <p class="small subtle mb-0">There is nothing to adjust yet.</p>
      <?php else: ?>
        <form method="post" action="<?= e(admin_url('inventory.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="adjust">

          <div class="field">
            <label class="label" for="item_id">Item <span class="req">*</span></label>
            <select class="select" id="item_id" name="item_id" required>
              <?php foreach ($items as $item): ?>
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

<?php admin_footer(); ?>
