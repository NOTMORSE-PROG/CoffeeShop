<?php
/**
 * Customisation: option groups, the choices inside them, and which drinks
 * offer which groups.
 *
 * This is what the Scope calls "order customization with options, for example
 * sugar level and add-ons". Kept separate from menu.php because that page is
 * already carrying categories and products.
 *
 * Changing a price here only affects future orders. Past order lines keep
 * their own copy of every option name and price, so an old receipt never
 * changes underneath the customer.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

start_session();
send_security_headers();
require_login();

$admin   = current_admin();
$adminId = (int) $admin['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $action = post_string('action');

    // --- Option groups -----------------------------------------------------
    if ($action === 'group_save') {
        $id        = (int) ($_POST['id'] ?? 0);
        $name      = clean_text(post_string('name'), 80);
        $selection = post_string('selection_type') === 'multiple' ? 'multiple' : 'single';
        $required  = isset($_POST['is_required']) ? 1 : 0;
        $sort      = (int) ($_POST['sort_order'] ?? 0);

        $before = $id > 0 ? db_one('SELECT * FROM option_groups WHERE id = ? LIMIT 1', [$id]) : null;

        if ($name === '') {
            flash('error', 'Give the group a name.');
        } elseif ($id > 0 && $before === null) {
            flash('error', 'That group no longer exists.');
        } elseif ($before !== null) {
            db_query(
                'UPDATE option_groups SET name = ?, selection_type = ?, is_required = ?, sort_order = ? WHERE id = ?',
                [$name, $selection, $required, $sort, $id]
            );

            [$old, $new] = audit_diff(
                $before,
                ['name' => $name, 'selection_type' => $selection, 'is_required' => $required, 'sort_order' => $sort],
                ['name', 'selection_type', 'is_required', 'sort_order']
            );

            audit('option_group.updated', 'option_group', $id, 'Edited the option group ' . $name,
                $old, $new, $adminId);

            flash('success', $name . ' updated.');
        } else {
            $newId = db_insert(
                'INSERT INTO option_groups (name, selection_type, is_required, sort_order) VALUES (?, ?, ?, ?)',
                [$name, $selection, $required, $sort]
            );

            audit('option_group.created', 'option_group', $newId, 'Added the option group ' . $name,
                null, ['name' => $name, 'selection_type' => $selection], $adminId);

            flash('success', $name . ' added. Now add the choices inside it.');
        }

        redirect('options.php?group=' . ($id > 0 ? $id : ''));
    }

    if ($action === 'group_delete') {
        $id     = (int) ($_POST['id'] ?? 0);
        $before = db_one('SELECT * FROM option_groups WHERE id = ? LIMIT 1', [$id]);

        if ($before === null) {
            flash('error', 'That group no longer exists.');
        } else {
            // The options inside it and the product links go with it, by the
            // foreign keys. Past orders keep their own snapshot regardless.
            db_query('DELETE FROM option_groups WHERE id = ?', [$id]);

            audit('option_group.deleted', 'option_group', $id,
                'Removed the option group ' . (string) $before['name'],
                ['name' => $before['name']], null, $adminId);

            flash('success', 'Option group removed.');
        }

        redirect('options.php');
    }

    // --- Options inside a group ---------------------------------------------
    if ($action === 'option_save') {
        $id      = (int) ($_POST['id'] ?? 0);
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $name    = clean_text(post_string('name'), 80);
        $delta   = round((float) str_replace(',', '', post_string('price_delta', '0')), 2);
        $default = isset($_POST['is_default']) ? 1 : 0;
        $sort    = (int) ($_POST['sort_order'] ?? 0);

        $group  = db_one('SELECT * FROM option_groups WHERE id = ? LIMIT 1', [$groupId]);
        $before = $id > 0 ? db_one('SELECT * FROM options WHERE id = ? LIMIT 1', [$id]) : null;

        if ($group === null) {
            flash('error', 'That option group no longer exists.');
        } elseif ($name === '') {
            flash('error', 'Give the choice a name.');
        } elseif ($delta < 0) {
            flash('error', 'An extra charge cannot be negative.');
        } elseif ($id > 0 && $before === null) {
            flash('error', 'That choice no longer exists.');
        } else {
            db_transaction(function () use ($id, $groupId, $name, $delta, $default, $sort, $group, $before, $adminId) {
                if ($before !== null) {
                    db_query(
                        'UPDATE options SET name = ?, price_delta = ?, is_default = ?, sort_order = ? WHERE id = ?',
                        [$name, $delta, $default, $sort, $id]
                    );
                    $optionId = $id;
                } else {
                    $optionId = db_insert(
                        'INSERT INTO options (group_id, name, price_delta, is_default, sort_order) VALUES (?, ?, ?, ?, ?)',
                        [$groupId, $name, $delta, $default, $sort]
                    );
                }

                // A pick-one group can only have one default, or the product
                // page would render two checked radios in the same group.
                if ($default === 1 && $group['selection_type'] === 'single') {
                    db_query(
                        'UPDATE options SET is_default = 0 WHERE group_id = ? AND id <> ?',
                        [$groupId, $optionId]
                    );
                }

                audit(
                    $before !== null ? 'option.updated' : 'option.created',
                    'option',
                    $optionId,
                    sprintf('%s the choice %s in %s',
                        $before !== null ? 'Edited' : 'Added', $name, (string) $group['name']),
                    $before === null ? null : ['name' => $before['name'], 'price_delta' => $before['price_delta']],
                    ['name' => $name, 'price_delta' => $delta, 'is_default' => $default],
                    $adminId
                );
            });

            flash('success', $name . ' saved.');
        }

        redirect('options.php?group=' . $groupId);
    }

    if ($action === 'option_delete') {
        $id     = (int) ($_POST['id'] ?? 0);
        $before = db_one('SELECT * FROM options WHERE id = ? LIMIT 1', [$id]);

        if ($before === null) {
            flash('error', 'That choice no longer exists.');
        } else {
            db_query('DELETE FROM options WHERE id = ?', [$id]);

            audit('option.deleted', 'option', $id, 'Removed the choice ' . (string) $before['name'],
                ['name' => $before['name'], 'price_delta' => $before['price_delta']], null, $adminId);

            flash('success', 'Choice removed.');
        }

        redirect('options.php?group=' . (int) ($_POST['group_id'] ?? 0));
    }

    // --- Which drinks offer this group ---------------------------------------
    if ($action === 'group_products') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $group   = db_one('SELECT * FROM option_groups WHERE id = ? LIMIT 1', [$groupId]);

        if ($group === null) {
            flash('error', 'That option group no longer exists.');
        } else {
            $wanted = array_values(array_unique(array_map('intval', (array) ($_POST['product_ids'] ?? []))));

            $before = array_map(
                'intval',
                array_column(
                    db_all('SELECT product_id FROM product_option_groups WHERE group_id = ?', [$groupId]),
                    'product_id'
                )
            );

            db_transaction(function () use ($groupId, $wanted) {
                db_query('DELETE FROM product_option_groups WHERE group_id = ?', [$groupId]);

                foreach ($wanted as $productId) {
                    // The insert is guarded by a lookup so a tampered form
                    // cannot create a row for a product that does not exist.
                    if ((int) db_value('SELECT COUNT(*) FROM products WHERE id = ?', [$productId]) === 1) {
                        db_query(
                            'INSERT INTO product_option_groups (product_id, group_id) VALUES (?, ?)',
                            [$productId, $groupId]
                        );
                    }
                }
            });

            sort($before);
            $after = $wanted;
            sort($after);

            audit('option_group.products', 'option_group', $groupId,
                sprintf('%s now offered on %d drink%s', (string) $group['name'], count($after), count($after) === 1 ? '' : 's'),
                ['products' => count($before)],
                ['products' => count($after)],
                $adminId
            );

            flash('success', 'Updated which drinks offer ' . (string) $group['name'] . '.');
        }

        redirect('options.php?group=' . $groupId);
    }

    redirect('options.php');
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

$groups = db_all(
    'SELECT g.*,
            (SELECT COUNT(*) FROM options o WHERE o.group_id = g.id) AS option_count,
            (SELECT COUNT(*) FROM product_option_groups pog WHERE pog.group_id = g.id) AS product_count
     FROM option_groups g
     ORDER BY g.sort_order ASC, g.name ASC'
);

$selectedId = get_int('group');

if ($selectedId === 0 && $groups !== []) {
    $selectedId = (int) $groups[0]['id'];
}

$selected = $selectedId > 0
    ? db_one('SELECT * FROM option_groups WHERE id = ? LIMIT 1', [$selectedId])
    : null;

$options = $selected === null ? [] : db_all(
    'SELECT * FROM options WHERE group_id = ? ORDER BY sort_order ASC, name ASC',
    [$selectedId]
);

$editGroup = null;
if (get_int('edit_group') > 0) {
    $editGroup = db_one('SELECT * FROM option_groups WHERE id = ? LIMIT 1', [get_int('edit_group')]);
}

$editOption = null;
if (get_int('edit_option') > 0) {
    $editOption = db_one('SELECT * FROM options WHERE id = ? LIMIT 1', [get_int('edit_option')]);
}

$products = db_all(
    'SELECT p.id, p.name, c.name AS category_name
     FROM products p
     JOIN categories c ON c.id = p.category_id
     ORDER BY c.sort_order ASC, c.name ASC, p.sort_order ASC, p.name ASC'
);

$assigned = $selected === null ? [] : array_map(
    'intval',
    array_column(
        db_all('SELECT product_id FROM product_option_groups WHERE group_id = ?', [$selectedId]),
        'product_id'
    )
);

admin_head('Customisation');
admin_header(
    'Customisation',
    count($groups) . ' option group' . (count($groups) === 1 ? '' : 's') . '. These are the choices a customer makes on a drink before adding it to the cart.'
);
?>

<div class="menu-grid">

  <!-- Groups -->
  <section class="card">
    <div class="card-header">
      <h2>Option groups</h2>
      <span class="small subtle"><?= count($groups) ?></span>
    </div>

    <?php if ($groups === []): ?>
      <div class="empty">
        <?= admin_icon('icon-settings', 'empty-icon') ?>
        <h3>No option groups yet</h3>
        <p class="mb-0">Add one, for example Sugar Level or Add-ons.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap table-flush">
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Group</th>
              <th scope="col">Choice</th>
              <th scope="col">Used on</th>
              <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $group): ?>
              <tr class="<?= (int) $group['id'] === $selectedId ? 'row-low' : '' ?>">
                <td>
                  <a class="bold" href="<?= e(admin_url('options.php')) ?>?group=<?= (int) $group['id'] ?>">
                    <?= e((string) $group['name']) ?>
                  </a>
                  <?php if ((int) $group['is_required'] === 1): ?>
                    <span class="badge badge-pending">Required</span>
                  <?php endif; ?>
                  <br>
                  <span class="small subtle"><?= (int) $group['option_count'] ?> choices</span>
                </td>
                <td>
                  <span class="badge badge-plain">
                    <?= $group['selection_type'] === 'single' ? 'Pick one' : 'Pick any' ?>
                  </span>
                </td>
                <td class="small subtle nowrap"><?= (int) $group['product_count'] ?> drinks</td>
                <td class="right nowrap">
                  <a class="btn btn-sm btn-secondary"
                     href="<?= e(admin_url('options.php')) ?>?group=<?= (int) $group['id'] ?>&edit_group=<?= (int) $group['id'] ?>#group-form">Edit</a>
                  <form method="post" action="<?= e(admin_url('options.php')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="group_delete">
                    <input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-ghost"
                            data-confirm="Remove <?= e((string) $group['name']) ?> and all of its choices? Past orders keep their own record.">
                      Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="card-footer" id="group-form">
      <h3 class="form-heading"><?= $editGroup === null ? 'Add a group' : 'Edit group' ?></h3>

      <form method="post" action="<?= e(admin_url('options.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="group_save">
        <input type="hidden" name="id" value="<?= (int) ($editGroup['id'] ?? 0) ?>">

        <div class="field">
          <label class="label" for="group_name">Name <span class="req">*</span></label>
          <input class="input" type="text" id="group_name" name="name" maxlength="80" required
                 value="<?= e((string) ($editGroup['name'] ?? '')) ?>"
                 placeholder="Sugar Level">
        </div>

        <div class="field-row">
          <div class="field">
            <label class="label" for="selection_type">How many</label>
            <select class="select" id="selection_type" name="selection_type">
              <option value="single"<?= ($editGroup['selection_type'] ?? 'single') === 'single' ? ' selected' : '' ?>>
                Pick one
              </option>
              <option value="multiple"<?= ($editGroup['selection_type'] ?? '') === 'multiple' ? ' selected' : '' ?>>
                Pick any
              </option>
            </select>
          </div>

          <div class="field">
            <label class="label" for="group_sort">Order</label>
            <input class="input tabular" type="number" id="group_sort" name="sort_order"
                   value="<?= (int) ($editGroup['sort_order'] ?? 0) ?>">
          </div>
        </div>

        <label class="choice">
          <input type="checkbox" name="is_required" value="1"
                 <?= (int) ($editGroup['is_required'] ?? 0) === 1 ? 'checked' : '' ?>>
          <span>
            <span class="choice-title">Required</span>
            <span class="choice-note">The customer must choose before adding to the cart.</span>
          </span>
        </label>

        <div class="row mt-4">
          <button type="submit" class="btn grow" data-busy-label="Saving">
            <?= $editGroup === null ? 'Add group' : 'Save group' ?>
          </button>
          <?php if ($editGroup !== null): ?>
            <a class="btn btn-ghost" href="<?= e(admin_url('options.php')) ?>?group=<?= $selectedId ?>">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </section>

  <!-- Choices inside the selected group, plus which drinks use it -->
  <section class="card">
    <div class="card-header">
      <h2><?= $selected === null ? 'Choices' : e((string) $selected['name']) ?></h2>
      <?php if ($selected !== null): ?>
        <span class="small subtle">
          <?= $selected['selection_type'] === 'single' ? 'Pick one' : 'Pick any' ?>
        </span>
      <?php endif; ?>
    </div>

    <?php if ($selected === null): ?>
      <div class="empty">
        <?= admin_icon('icon-settings', 'empty-icon') ?>
        <h3>Nothing selected</h3>
        <p class="mb-0">Add a group first, then its choices appear here.</p>
      </div>
    <?php else: ?>

      <?php if ($options === []): ?>
        <div class="empty">
          <?= admin_icon('icon-plus', 'empty-icon') ?>
          <h3>No choices yet</h3>
          <p class="mb-0">Add the first one below, for example "No Sugar".</p>
        </div>
      <?php else: ?>
        <div class="table-wrap table-flush">
          <table class="table table-compact">
            <thead>
              <tr>
                <th scope="col">Choice</th>
                <th scope="col">Extra</th>
                <th scope="col">Default</th>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($options as $option): ?>
                <tr>
                  <td class="bold"><?= e((string) $option['name']) ?></td>
                  <td class="tabular nowrap">
                    <?= (float) $option['price_delta'] > 0 ? '+' . e(peso($option['price_delta'])) : '<span class="subtle">Free</span>' ?>
                  </td>
                  <td>
                    <?php if ((int) $option['is_default'] === 1): ?>
                      <span class="badge badge-ready">Default</span>
                    <?php endif; ?>
                  </td>
                  <td class="right nowrap">
                    <a class="btn btn-sm btn-secondary"
                       href="<?= e(admin_url('options.php')) ?>?group=<?= $selectedId ?>&edit_option=<?= (int) $option['id'] ?>#option-form">Edit</a>
                    <form method="post" action="<?= e(admin_url('options.php')) ?>" class="inline-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="option_delete">
                      <input type="hidden" name="id" value="<?= (int) $option['id'] ?>">
                      <input type="hidden" name="group_id" value="<?= $selectedId ?>">
                      <button type="submit" class="btn btn-sm btn-ghost"
                              data-confirm="Remove <?= e((string) $option['name']) ?>?">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div class="card-body" id="option-form">
        <h3 class="form-heading"><?= $editOption === null ? 'Add a choice' : 'Edit choice' ?></h3>

        <form method="post" action="<?= e(admin_url('options.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="option_save">
          <input type="hidden" name="id" value="<?= (int) ($editOption['id'] ?? 0) ?>">
          <input type="hidden" name="group_id" value="<?= $selectedId ?>">

          <div class="field-row">
            <div class="field">
              <label class="label" for="option_name">Name <span class="req">*</span></label>
              <input class="input" type="text" id="option_name" name="name" maxlength="80" required
                     value="<?= e((string) ($editOption['name'] ?? '')) ?>"
                     placeholder="Extra Shot">
            </div>

            <div class="field">
              <label class="label" for="price_delta">Extra charge</label>
              <input class="input tabular" type="number" id="price_delta" name="price_delta"
                     step="0.01" min="0"
                     value="<?= e(number_format((float) ($editOption['price_delta'] ?? 0), 2, '.', '')) ?>">
              <p class="hint">0 for no extra charge.</p>
            </div>

            <div class="field">
              <label class="label" for="option_sort">Order</label>
              <input class="input tabular" type="number" id="option_sort" name="sort_order"
                     value="<?= (int) ($editOption['sort_order'] ?? 0) ?>">
            </div>
          </div>

          <label class="choice">
            <input type="checkbox" name="is_default" value="1"
                   <?= (int) ($editOption['is_default'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>
              <span class="choice-title">Selected by default</span>
              <span class="choice-note">
                <?= $selected['selection_type'] === 'single'
                    ? 'A pick-one group has one default. Setting this clears the others.'
                    : 'Pre-ticked when the customer opens the drink.' ?>
              </span>
            </span>
          </label>

          <div class="row mt-4">
            <button type="submit" class="btn grow" data-busy-label="Saving">
              <?= $editOption === null ? 'Add choice' : 'Save choice' ?>
            </button>
            <?php if ($editOption !== null): ?>
              <a class="btn btn-ghost" href="<?= e(admin_url('options.php')) ?>?group=<?= $selectedId ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- Product assignment -->
      <div class="card-footer">
        <h3 class="form-heading">Which drinks offer this</h3>

        <?php if ($products === []): ?>
          <p class="small subtle mb-0">There are no drinks on the menu yet.</p>
        <?php else: ?>
          <form method="post" action="<?= e(admin_url('options.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="group_products">
            <input type="hidden" name="group_id" value="<?= $selectedId ?>">

            <div class="assign-grid">
              <?php
              $currentCategory = null;
              foreach ($products as $product):
                  if ($product['category_name'] !== $currentCategory):
                      $currentCategory = $product['category_name'];
                      ?>
                      <p class="assign-heading"><?= e((string) $currentCategory) ?></p>
                      <?php
                  endif;
              ?>
                <label class="assign-item">
                  <input type="checkbox" name="product_ids[]" value="<?= (int) $product['id'] ?>"
                         <?= in_array((int) $product['id'], $assigned, true) ? 'checked' : '' ?>>
                  <span><?= e((string) $product['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-block mt-4" data-busy-label="Saving">
              <?= admin_icon('icon-check', 'icon-sm') ?> Save which drinks offer this
            </button>
          </form>
        <?php endif; ?>
      </div>

    <?php endif; ?>
  </section>

</div>

<!-- Plain-language explainer. This page describes a three-layer idea that is
     not obvious from the forms alone, so it is spelled out with a worked
     example and a picture of what the customer ends up seeing. -->
<section class="card explainer">
  <div class="card-header">
    <h2>How this page works</h2>
    <span class="small subtle">A worked example</span>
  </div>

  <div class="card-body">
    <p class="explainer-lede">
      A <strong>group</strong> is one question you ask the customer about their drink.
      The <strong>choices</strong> are the possible answers. Then you tick
      <strong>which drinks</strong> ask that question.
    </p>

    <ol class="explainer-steps">
      <li>
        <span class="explainer-num">1</span>
        <div>
          <h3>Make the group</h3>
          <p>
            The question. For example <strong>Sugar Level</strong>.
            Set it to <strong>Pick one</strong>, because a drink has one sweetness.
          </p>
        </div>
      </li>
      <li>
        <span class="explainer-num">2</span>
        <div>
          <h3>Add the choices</h3>
          <p>
            The answers: <strong>No Sugar, 25%, 50%, 75%, 100%</strong>.
            Mark one as the <strong>default</strong> so it is already selected.
          </p>
        </div>
      </li>
      <li>
        <span class="explainer-num">3</span>
        <div>
          <h3>Tick the drinks</h3>
          <p>
            Every drink that should ask it. Sugar Level suits all of them.
            Add-ons might only suit the coffee ones.
          </p>
        </div>
      </li>
    </ol>

    <div class="explainer-compare">
      <div>
        <p class="explainer-caption">What you set up here</p>
        <div class="explainer-panel">
          <p class="explainer-panel-title">Sugar Level <span class="badge badge-plain">Pick one</span></p>
          <ul class="explainer-list">
            <li>No Sugar <span class="subtle">free</span></li>
            <li>50% <span class="badge badge-ready">Default</span></li>
            <li>100% <span class="subtle">free</span></li>
          </ul>
          <p class="explainer-panel-title mt-4">Add-ons <span class="badge badge-plain">Pick any</span></p>
          <ul class="explainer-list">
            <li>Extra Shot <span class="accent-text">+<?= e(peso(25)) ?></span></li>
            <li>Pearls <span class="accent-text">+<?= e(peso(15)) ?></span></li>
          </ul>
        </div>
      </div>

      <div class="explainer-arrow" aria-hidden="true">
        <?= admin_icon('icon-arrow-right', 'icon') ?>
      </div>

      <div>
        <p class="explainer-caption">What the customer sees</p>
        <div class="explainer-panel explainer-preview">
          <p class="explainer-panel-title">Sugar Level <span class="subtle small">Pick one</span></p>
          <div class="preview-choices">
            <span class="preview-choice"><span class="preview-radio"></span> No Sugar</span>
            <span class="preview-choice is-on"><span class="preview-radio is-on"></span> 50%</span>
            <span class="preview-choice"><span class="preview-radio"></span> 100%</span>
          </div>

          <p class="explainer-panel-title mt-4">Add-ons <span class="subtle small">Pick any</span></p>
          <div class="preview-choices">
            <span class="preview-choice"><span class="preview-box"></span> Extra Shot <span class="accent-text">+<?= e(peso(25)) ?></span></span>
            <span class="preview-choice"><span class="preview-box"></span> Pearls <span class="accent-text">+<?= e(peso(15)) ?></span></span>
          </div>

          <p class="explainer-total">
            A <?= e(peso(95)) ?> drink with an Extra Shot becomes
            <strong><?= e(peso(120)) ?></strong>.
          </p>
        </div>
      </div>
    </div>

    <dl class="explainer-terms">
      <div>
        <dt>Pick one</dt>
        <dd>Round buttons. The customer chooses exactly one, like a sugar level or a size.</dd>
      </div>
      <div>
        <dt>Pick any</dt>
        <dd>Tick boxes. The customer can choose none, one, or several, like add-ons.</dd>
      </div>
      <div>
        <dt>Default</dt>
        <dd>Already selected when the drink opens, so most people never have to touch it.</dd>
      </div>
      <div>
        <dt>Required</dt>
        <dd>The customer cannot add the drink to the cart without answering.</dd>
      </div>
      <div>
        <dt>Extra charge</dt>
        <dd>Added on top of the drink's own price. Leave it at 0 for choices that cost nothing.</dd>
      </div>
      <div>
        <dt>Used on</dt>
        <dd>How many drinks currently ask this question. Set it at the bottom of this page.</dd>
      </div>
    </dl>

    <div class="alert alert-info mb-0">
      <?= admin_icon('icon-shield', 'icon-sm') ?>
      <div>
        Changing a name or a price here only affects <strong>future</strong> orders. Every order
        already placed keeps its own copy of what was chosen and what it cost, so an old receipt
        never changes underneath a customer.
      </div>
    </div>
  </div>
</section>

<?php admin_footer(); ?>
