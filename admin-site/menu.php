<?php
/**
 * The menu: categories and the drinks inside them.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';
require_once dirname(__DIR__) . '/shared/uploads.php';

start_session();
send_security_headers();
require_login();

$admin = current_admin();

/**
 * A slug that is not already taken.
 * $table is chosen from a fixed pair here, never from the request.
 */
function unique_slug(string $table, string $name, int $ignoreId = 0): string
{
    $table = $table === 'categories' ? 'categories' : 'products';
    $max   = $table === 'categories' ? 80 : 140;

    $base = mb_substr(slugify($name), 0, $max - 6);
    $slug = $base;
    $n    = 2;

    while ((int) db_value("SELECT COUNT(*) FROM `$table` WHERE slug = ? AND id <> ?", [$slug, $ignoreId]) > 0) {
        $slug = $base . '-' . $n;
        $n++;
    }

    return $slug;
}

/** Accept only a relative path under the project, never a URL or a scheme. */
function valid_image_path(string $path): bool
{
    if ($path === '') {
        return true;
    }

    if (str_contains($path, '..') || str_contains($path, ':') || str_starts_with($path, '/')) {
        return false;
    }

    return (bool) preg_match('#^[A-Za-z0-9._/-]{1,255}$#', $path);
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post_with_csrf();

    $action = post_string('action');
    $adminId = (int) $admin['id'];

    if ($action === 'category_save') {
        $id          = (int) ($_POST['id'] ?? 0);
        $name        = clean_text(post_string('name'), 80);
        $description = clean_text(post_string('description'), 255);
        $sortOrder   = (int) ($_POST['sort_order'] ?? 0);
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            flash('error', 'A category needs a name.');
        } elseif ($id > 0) {
            $before = db_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$id]);

            if ($before === null) {
                flash('error', 'That category no longer exists.');
            } else {
                $slug = unique_slug('categories', $name, $id);

                db_query(
                    'UPDATE categories SET name = ?, slug = ?, description = ?, sort_order = ?, is_active = ?
                     WHERE id = ?',
                    [$name, $slug, $description ?: null, $sortOrder, $isActive, $id]
                );

                [$old, $new] = audit_diff($before, [
                    'name' => $name, 'slug' => $slug, 'description' => $description,
                    'sort_order' => $sortOrder, 'is_active' => $isActive,
                ], ['name', 'slug', 'description', 'sort_order', 'is_active']);

                audit('category.updated', 'category', $id, 'Edited category ' . $name, $old, $new, $adminId);
                flash('success', 'Category updated.');
            }
        } else {
            $slug = unique_slug('categories', $name);

            $newId = db_insert(
                'INSERT INTO categories (name, slug, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?)',
                [$name, $slug, $description ?: null, $sortOrder, $isActive]
            );

            audit('category.created', 'category', $newId, 'Added category ' . $name, null, [
                'name' => $name, 'slug' => $slug, 'sort_order' => $sortOrder,
            ], $adminId);

            flash('success', 'Category added.');
        }

        redirect('menu.php');
    }

    if ($action === 'category_delete') {
        $id     = (int) ($_POST['id'] ?? 0);
        $before = db_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$id]);

        if ($before === null) {
            flash('error', 'That category no longer exists.');
        } else {
            // Deleting a category cascades to its products in the database, so
            // the empty check is what stops a whole section of the menu going
            // with one click.
            $productCount = (int) db_value('SELECT COUNT(*) FROM products WHERE category_id = ?', [$id]);

            if ($productCount > 0) {
                flash('error', sprintf(
                    'Move or remove the %d item%s in "%s" first.',
                    $productCount,
                    $productCount === 1 ? '' : 's',
                    (string) $before['name']
                ));
            } else {
                db_query('DELETE FROM categories WHERE id = ?', [$id]);

                audit('category.deleted', 'category', $id,
                    'Removed category ' . (string) $before['name'],
                    ['name' => $before['name'], 'slug' => $before['slug']], null, $adminId);

                flash('success', 'Category removed.');
            }
        }

        redirect('menu.php');
    }

    if ($action === 'product_save') {
        $id          = (int) ($_POST['id'] ?? 0);
        $name        = clean_text(post_string('name'), 120);
        $categoryId  = (int) ($_POST['category_id'] ?? 0);
        $description = clean_text(post_string('description'), 400);
        $price       = (float) str_replace(',', '', post_string('price', '0'));
        $imagePath   = clean_text(post_string('image_path'), 255);

        // A picture can arrive three ways: uploaded, typed as a path, or
        // cleared. An upload wins over a typed path when both are present.
        $upload = handle_image_upload($_FILES['image_file'] ?? null);

        if (!$upload['ok']) {
            flash('error', (string) $upload['error']);
            redirect('menu.php' . ($id > 0 ? '?edit_product=' . $id : ''));
        }

        $uploadedPath = $upload['path'] ?? null;
        $clearImage   = isset($_POST['clear_image']);
        $isAvailable = isset($_POST['is_available']) ? 1 : 0;
        $isFeatured  = isset($_POST['is_featured']) ? 1 : 0;
        $sortOrder   = (int) ($_POST['sort_order'] ?? 0);

        $categoryExists = (int) db_value('SELECT COUNT(*) FROM categories WHERE id = ?', [$categoryId]) > 0;

        $errors = [];
        if ($name === '')          { $errors[] = 'An item needs a name.'; }
        if (!$categoryExists)      { $errors[] = 'Choose a category that exists.'; }
        if ($price < 0)            { $errors[] = 'The price cannot be negative.'; }
        if ($price > 99999999)     { $errors[] = 'That price is too large.'; }
        if ($uploadedPath === null && !$clearImage && !valid_image_path($imagePath)) {
            $errors[] = 'The image path must be a relative path such as assets/img/products/mocha.svg.';
        }

        if ($errors !== []) {
            // The file is already on disk at this point, so remove it rather
            // than leaving an orphan nothing references.
            delete_uploaded_image($uploadedPath);

            flash('error', implode(' ', $errors));
            redirect('menu.php' . ($id > 0 ? '?edit_product=' . $id : ''));
        }

        if ($id > 0) {
            $before = db_one('SELECT * FROM products WHERE id = ? LIMIT 1', [$id]);

            if ($before === null) {
                flash('error', 'That item no longer exists.');
                redirect('menu.php');
            }

            $slug = unique_slug('products', $name, $id);

            // Work out which picture the item should end up with.
            if ($uploadedPath !== null) {
                $finalImage = $uploadedPath;
            } elseif ($clearImage) {
                $finalImage = null;
            } else {
                $finalImage = $imagePath ?: null;
            }

            db_query(
                'UPDATE products
                 SET category_id = ?, name = ?, slug = ?, description = ?, price = ?,
                     image_path = ?, is_available = ?, is_featured = ?, sort_order = ?
                 WHERE id = ?',
                [$categoryId, $name, $slug, $description ?: null, $price,
                 $finalImage, $isAvailable, $isFeatured, $sortOrder, $id]
            );

            // Once the row points elsewhere, the file it used to point at is
            // no longer referenced. Only ever removes our own uploads.
            if ($finalImage !== $before['image_path'] && is_uploaded_image($before['image_path'])) {
                delete_uploaded_image((string) $before['image_path']);
            }

            $imagePath = (string) ($finalImage ?? '');

            [$old, $new] = audit_diff($before, [
                'category_id' => $categoryId, 'name' => $name, 'price' => number_format($price, 2, '.', ''),
                'description' => $description, 'image_path' => $imagePath,
                'is_available' => $isAvailable, 'is_featured' => $isFeatured, 'sort_order' => $sortOrder,
            ], ['category_id', 'name', 'price', 'description', 'image_path',
                'is_available', 'is_featured', 'sort_order']);

            audit('product.updated', 'product', $id, 'Edited menu item ' . $name, $old, $new, $adminId);
            flash('success', 'Menu item updated.');
        } else {
            $slug = unique_slug('products', $name);

            $newId = db_insert(
                'INSERT INTO products
                    (category_id, name, slug, description, price, image_path, is_available, is_featured, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$categoryId, $name, $slug, $description ?: null, $price,
                 $uploadedPath ?? ($imagePath ?: null), $isAvailable, $isFeatured, $sortOrder]
            );

            audit('product.created', 'product', $newId, 'Added menu item ' . $name, null, [
                'name' => $name, 'price' => number_format($price, 2, '.', ''), 'category_id' => $categoryId,
            ], $adminId);

            flash('success', 'Menu item added.');
        }

        redirect('menu.php');
    }

    if ($action === 'product_availability') {
        $id     = (int) ($_POST['id'] ?? 0);
        $before = db_one('SELECT id, name, is_available FROM products WHERE id = ? LIMIT 1', [$id]);

        if ($before === null) {
            flash('error', 'That item no longer exists.');
        } else {
            $now = (int) $before['is_available'] === 1 ? 0 : 1;

            db_query('UPDATE products SET is_available = ? WHERE id = ?', [$now, $id]);

            audit('product.availability', 'product', $id,
                sprintf('%s is now %s', (string) $before['name'], $now === 1 ? 'available' : 'unavailable'),
                ['is_available' => (int) $before['is_available']], ['is_available' => $now], $adminId);

            flash('success', (string) $before['name'] . ($now === 1 ? ' is back on the menu.' : ' is now hidden from the menu.'));
        }

        redirect('menu.php');
    }

    // --- Add several items at once -----------------------------------------
    if ($action === 'product_batch_add') {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $raw        = (string) ($_POST['bulk'] ?? '');

        if ((int) db_value('SELECT COUNT(*) FROM categories WHERE id = ?', [$categoryId]) === 0) {
            flash('error', 'Choose a category that exists.');
            redirect('menu.php#batch-add');
        }

        $lines    = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $added    = 0;
        $problems = [];
        $lineNo   = 0;

        foreach ($lines as $line) {
            $lineNo++;
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Name | Price | Description, with the description optional.
            $parts       = array_map('trim', explode('|', $line));
            $name        = clean_text($parts[0] ?? '', 120);
            $priceRaw    = str_replace([',', "\u{20B1}"], '', $parts[1] ?? '');
            $description = clean_text($parts[2] ?? '', 400);

            if ($name === '') {
                $problems[] = 'Line ' . $lineNo . ' has no name.';
                continue;
            }

            if ($priceRaw === '' || !is_numeric($priceRaw)) {
                $problems[] = 'Line ' . $lineNo . ' (' . $name . ') has no valid price.';
                continue;
            }

            $price = round((float) $priceRaw, 2);

            if ($price < 0 || $price > 99999999) {
                $problems[] = 'Line ' . $lineNo . ' (' . $name . ') has a price out of range.';
                continue;
            }

            if ((int) db_value('SELECT COUNT(*) FROM products WHERE name = ? AND category_id = ?',
                    [$name, $categoryId]) > 0) {
                $problems[] = $name . ' is already in that category.';
                continue;
            }

            $newId = db_insert(
                'INSERT INTO products
                    (category_id, name, slug, description, price, is_available, is_featured, sort_order)
                 VALUES (?, ?, ?, ?, ?, 1, 0, 0)',
                [$categoryId, $name, unique_slug('products', $name), $description ?: null, $price]
            );

            audit('product.created', 'product', $newId,
                'Added menu item ' . $name . ' (batch)', null,
                ['name' => $name, 'price' => number_format($price, 2, '.', ''), 'category_id' => $categoryId],
                $adminId);

            $added++;
        }

        if ($added > 0) {
            flash('success', $added . ' item' . ($added === 1 ? '' : 's') . ' added.'
                . ($problems !== [] ? ' Some lines were skipped.' : ''));
        }

        if ($problems !== []) {
            flash('error', 'Skipped: ' . implode(' ', array_slice($problems, 0, 6))
                . (count($problems) > 6 ? ' And ' . (count($problems) - 6) . ' more.' : ''));
        }

        if ($added === 0 && $problems === []) {
            flash('error', 'There was nothing to add.');
        }

        redirect('menu.php#batch-add');
    }

    // --- Remove several items at once ----------------------------------------
    if ($action === 'product_batch_delete') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['product_ids'] ?? []))
        )));

        if ($ids === []) {
            flash('error', 'Tick the items you want to remove first.');
            redirect('menu.php');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = db_all(
            "SELECT id, name, image_path FROM products WHERE id IN ($placeholders)",
            $ids
        );

        if ($rows === []) {
            flash('error', 'Those items no longer exist.');
            redirect('menu.php');
        }

        db_transaction(function () use ($rows, $adminId) {
            foreach ($rows as $row) {
                // Past order lines keep their own copy of the name and price,
                // so removing an item here does not rewrite any receipt.
                db_query('DELETE FROM products WHERE id = ?', [$row['id']]);

                audit('product.deleted', 'product', (int) $row['id'],
                    'Removed menu item ' . (string) $row['name'] . ' (batch)',
                    ['name' => $row['name']], null, $adminId);
            }
        });

        // Only after the rows are gone, so a failed transaction never leaves
        // a live item pointing at a file that has been deleted.
        foreach ($rows as $row) {
            if (is_uploaded_image($row['image_path'])) {
                delete_uploaded_image((string) $row['image_path']);
            }
        }

        flash('success', count($rows) . ' item' . (count($rows) === 1 ? '' : 's') . ' removed.');
        redirect('menu.php');
    }

    if ($action === 'product_delete') {
        $id     = (int) ($_POST['id'] ?? 0);
        $before = db_one('SELECT id, name, price, image_path FROM products WHERE id = ? LIMIT 1', [$id]);

        if ($before === null) {
            flash('error', 'That item no longer exists.');
        } else {
            // Past order lines keep their own copy of the name and price, so
            // removing an item here does not rewrite anyone's receipt.
            db_query('DELETE FROM products WHERE id = ?', [$id]);

            if (is_uploaded_image($before['image_path'] ?? null)) {
                delete_uploaded_image((string) $before['image_path']);
            }

            audit('product.deleted', 'product', $id, 'Removed menu item ' . (string) $before['name'],
                ['name' => $before['name'], 'price' => $before['price']], null, $adminId);

            flash('success', 'Menu item removed.');
        }

        redirect('menu.php');
    }

    redirect('menu.php');
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

$categories = db_all(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
     FROM categories c ORDER BY c.sort_order ASC, c.name ASC'
);

$categoryFilter = get_int('category');

$productParams = [];
$productWhere  = '';

if ($categoryFilter > 0) {
    $productWhere  = ' WHERE p.category_id = ?';
    $productParams = [$categoryFilter];
}

$products = db_all(
    'SELECT p.*, c.name AS category_name
     FROM products p
     JOIN categories c ON c.id = p.category_id' . $productWhere . '
     ORDER BY c.sort_order ASC, c.name ASC, p.sort_order ASC, p.name ASC',
    $productParams
);

$editCategory = null;
if (get_int('edit_category') > 0) {
    $editCategory = db_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [get_int('edit_category')]);
}

$editProduct = null;
if (get_int('edit_product') > 0) {
    $editProduct = db_one('SELECT * FROM products WHERE id = ? LIMIT 1', [get_int('edit_product')]);
}

admin_head('Menu');
admin_header('Menu', count($categories) . ' categories, ' . count($products) . ' items.');
?>

<div class="menu-grid">

  <!-- ----------------------------------------------------------- categories -->
  <section class="card">
    <div class="card-header"><h2>Categories</h2></div>

    <?php if ($categories === []): ?>
      <div class="empty">
        <?= admin_icon('icon-box', 'empty-icon') ?>
        <h3>No categories yet</h3>
        <p class="mb-0">Add one below before adding drinks.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap table-flush">
        <table class="table table-compact">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Items</th>
              <th scope="col">Order</th>
              <th scope="col">Shown</th>
              <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $category): ?>
              <tr>
                <td>
                  <span class="bold"><?= e((string) $category['name']) ?></span>
                  <?php if (!empty($category['description'])): ?>
                    <br><span class="subtle tiny menu-item-desc"><?= e((string) $category['description']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="tabular">
                  <a href="<?= e(admin_url('menu.php')) ?>?category=<?= (int) $category['id'] ?>">
                    <?= (int) $category['product_count'] ?>
                  </a>
                </td>
                <td class="tabular"><?= (int) $category['sort_order'] ?></td>
                <td>
                  <span class="badge <?= (int) $category['is_active'] === 1 ? 'badge-ready' : 'badge-completed' ?>">
                    <?= (int) $category['is_active'] === 1 ? 'Yes' : 'No' ?>
                  </span>
                </td>
                <td class="right nowrap">
                  <a class="btn btn-sm btn-ghost"
                     href="<?= e(admin_url('menu.php')) ?>?edit_category=<?= (int) $category['id'] ?>#category-form">Edit</a>

                  <form method="post" action="<?= e(admin_url('menu.php')) ?>" class="inline-form" data-no-guard>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="category_delete">
                    <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-ghost btn-danger-text"
                            data-confirm="Remove the category &quot;<?= e((string) $category['name']) ?>&quot;?">
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

    <div class="card-body" id="category-form">
      <h3 class="form-heading"><?= $editCategory ? 'Edit category' : 'Add a category' ?></h3>

      <form method="post" action="<?= e(admin_url('menu.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="category_save">
        <input type="hidden" name="id" value="<?= (int) ($editCategory['id'] ?? 0) ?>">

        <div class="field">
          <label class="label" for="category_name">Name <span class="req">*</span></label>
          <input class="input" type="text" id="category_name" name="name" maxlength="80" required
                 value="<?= e((string) ($editCategory['name'] ?? '')) ?>">
        </div>

        <div class="field">
          <label class="label" for="category_description">Description</label>
          <input class="input" type="text" id="category_description" name="description" maxlength="255"
                 value="<?= e((string) ($editCategory['description'] ?? '')) ?>">
        </div>

        <div class="field">
          <label class="label" for="category_sort">Sort order</label>
          <input class="input" type="number" id="category_sort" name="sort_order" step="1" min="0" max="9999"
                 value="<?= (int) ($editCategory['sort_order'] ?? 0) ?>">
          <p class="hint">Lower numbers appear first on the customer menu.</p>
        </div>

        <label class="choice mb-4">
          <input type="checkbox" name="is_active" value="1"
                 <?= (int) ($editCategory['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
          <span>
            <span class="choice-title">Show this category</span>
            <span class="choice-note">Unticking hides the whole section from customers.</span>
          </span>
        </label>

        <div class="row">
          <button type="submit" class="btn btn-sm" data-busy-label="Saving">
            <?= $editCategory ? 'Save changes' : 'Add category' ?>
          </button>
          <?php if ($editCategory): ?>
            <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('menu.php')) ?>">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </section>

  <!-- ------------------------------------------------------------- products -->
  <section class="card">
    <div class="card-header">
      <h2>Menu items</h2>
      <?php if ($categoryFilter > 0): ?>
        <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('menu.php')) ?>">Show all</a>
      <?php endif; ?>
    </div>

    <?php if ($products === []): ?>
      <div class="empty">
        <?= admin_icon('icon-cup', 'empty-icon') ?>
        <h3>No items here</h3>
        <p class="mb-0">Add one with the form below.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap table-flush">
        <table class="table table-compact" data-batch-table>
          <thead>
            <tr>
              <th scope="col" class="tick-col">
                <input type="checkbox" data-batch-all aria-label="Select every item">
              </th>
              <th scope="col" class="pic-col"><span class="visually-hidden">Picture</span></th>
              <th scope="col">Item</th>
              <th scope="col">Category</th>
              <th scope="col">Price</th>
              <th scope="col">State</th>
              <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $product): ?>
              <tr>
                <td class="tick-col">
                  <input type="checkbox" name="product_ids[]" value="<?= (int) $product['id'] ?>"
                         data-batch-item form="batch-delete-form"
                         aria-label="Select <?= e((string) $product['name']) ?>">
                </td>
                <td class="pic-col">
                  <?php if (!empty($product['image_path'])): ?>
                    <img class="menu-thumb"
                         src="<?= e(admin_base() . '/' . ltrim((string) $product['image_path'], '/')) ?>"
                         alt="" width="44" height="44" loading="lazy">
                  <?php else: ?>
                    <span class="menu-thumb menu-thumb-empty" aria-hidden="true">
                      <?= admin_icon('icon-cup', 'icon-sm') ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="bold"><?= e((string) $product['name']) ?></span>
                  <?php if (!empty($product['description'])): ?>
                    <br><span class="subtle tiny menu-item-desc"><?= e((string) $product['description']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="small"><?= e((string) $product['category_name']) ?></td>
                <td class="tabular nowrap"><?= peso($product['price']) ?></td>
                <td class="nowrap">
                  <span class="badge <?= (int) $product['is_available'] === 1 ? 'badge-ready' : 'badge-cancelled' ?>">
                    <?= (int) $product['is_available'] === 1 ? 'On sale' : 'Hidden' ?>
                  </span>
                  <?php if ((int) $product['is_featured'] === 1): ?>
                    <span class="badge badge-pending">Featured</span>
                  <?php endif; ?>
                </td>
                <td class="right nowrap">
                  <a class="btn btn-sm btn-ghost"
                     href="<?= e(admin_url('menu.php')) ?>?edit_product=<?= (int) $product['id'] ?>#product-form">Edit</a>

                  <form method="post" action="<?= e(admin_url('menu.php')) ?>" class="inline-form" data-no-guard>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="product_availability">
                    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-ghost">
                      <?= (int) $product['is_available'] === 1 ? 'Hide' : 'Show' ?>
                    </button>
                  </form>

                  <form method="post" action="<?= e(admin_url('menu.php')) ?>" class="inline-form" data-no-guard>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="product_delete">
                    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-ghost btn-danger-text"
                            data-confirm="Remove &quot;<?= e((string) $product['name']) ?>&quot; from the menu?">
                      Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <form class="batch-bar" data-batch-bar hidden id="batch-delete-form"
            method="post" action="<?= e(admin_url('menu.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="product_batch_delete">

        <span><strong data-batch-count>0</strong> selected</span>
        <button type="submit" class="btn btn-sm btn-danger"
                data-confirm="Remove the selected items from the menu? Past orders keep their own record.">
          <?= admin_icon('icon-trash', 'icon-sm') ?> Delete selected
        </button>
        <button type="button" class="btn btn-sm btn-ghost" data-batch-clear>Clear</button>
      </form>
    <?php endif; ?>

    <div class="card-body" id="product-form">
      <h3 class="form-heading"><?= $editProduct ? 'Edit menu item' : 'Add a menu item' ?></h3>

      <?php if ($categories === []): ?>
        <p class="small subtle mb-0">Add a category first.</p>
      <?php else: ?>
        <form method="post" action="<?= e(admin_url('menu.php')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="product_save">
          <input type="hidden" name="id" value="<?= (int) ($editProduct['id'] ?? 0) ?>">

          <div class="field">
            <label class="label" for="product_name">Name <span class="req">*</span></label>
            <input class="input" type="text" id="product_name" name="name" maxlength="120" required
                   value="<?= e((string) ($editProduct['name'] ?? '')) ?>">
          </div>

          <div class="field">
            <label class="label" for="product_category">Category <span class="req">*</span></label>
            <select class="select" id="product_category" name="category_id" required>
              <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>"
                  <?= (int) ($editProduct['category_id'] ?? $categoryFilter) === (int) $category['id'] ? 'selected' : '' ?>>
                  <?= e((string) $category['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="label" for="product_description">Description</label>
            <textarea class="textarea" id="product_description" name="description" maxlength="400"
                      rows="2"><?= e((string) ($editProduct['description'] ?? '')) ?></textarea>
          </div>

          <div class="form-row">
            <div class="field">
              <label class="label" for="product_price">Price <span class="req">*</span></label>
              <input class="input tabular" type="number" id="product_price" name="price"
                     step="0.01" min="0" max="99999999" required
                     value="<?= e(number_format((float) ($editProduct['price'] ?? 0), 2, '.', '')) ?>">
            </div>

            <div class="field">
              <label class="label" for="product_sort">Sort order</label>
              <input class="input" type="number" id="product_sort" name="sort_order" step="1" min="0" max="9999"
                     value="<?= (int) ($editProduct['sort_order'] ?? 0) ?>">
            </div>
          </div>

          <?php $currentImage = (string) ($editProduct['image_path'] ?? ''); ?>

          <div class="field">
            <span class="label">Picture</span>

            <div class="picture-field">
              <div class="picture-preview">
                <?php if ($currentImage !== ''): ?>
                  <img data-picture-preview
                       src="<?= e(admin_base() . '/' . ltrim($currentImage, '/')) ?>"
                       alt="Current picture for this item" width="96" height="96">
                <?php else: ?>
                  <img data-picture-preview hidden src="" alt="" width="96" height="96">
                  <span class="picture-empty" data-picture-empty>
                    <?= admin_icon('icon-cup', 'icon') ?>
                    <span class="tiny">No picture</span>
                  </span>
                <?php endif; ?>
              </div>

              <div class="grow">
                <input class="input" type="file" id="product_image_file" name="image_file"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       data-picture-input>
                <p class="hint">
                  JPG, PNG, GIF or WebP, up to 2 MB. Square pictures look best.
                  SVG is not accepted for uploads.
                </p>

                <?php if ($currentImage !== ''): ?>
                  <label class="choice mt-2">
                    <input type="checkbox" name="clear_image" value="1">
                    <span>
                      <span class="choice-title">Remove the current picture</span>
                      <span class="choice-note">The item falls back to a plain placeholder.</span>
                    </span>
                  </label>
                <?php endif; ?>
              </div>
            </div>

            <details class="picture-advanced">
              <summary>Or point at a file already in the project</summary>
              <input class="input mt-2" type="text" id="product_image" name="image_path" maxlength="255"
                     placeholder="assets/img/products/mocha.svg"
                     value="<?= e($currentImage) ?>">
              <p class="hint">
                A relative path inside the project. This is how the drink art that ships with
                the system is referenced. Uploading a picture above replaces whatever is here.
              </p>
            </details>
          </div>

          <div class="choice-group choice-group-2 mb-4">
            <label class="choice">
              <input type="checkbox" name="is_available" value="1"
                     <?= (int) ($editProduct['is_available'] ?? 1) === 1 ? 'checked' : '' ?>>
              <span>
                <span class="choice-title">Available</span>
                <span class="choice-note">Customers can order it.</span>
              </span>
            </label>

            <label class="choice">
              <input type="checkbox" name="is_featured" value="1"
                     <?= (int) ($editProduct['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
              <span>
                <span class="choice-title">Featured</span>
                <span class="choice-note">Highlighted on the home page.</span>
              </span>
            </label>
          </div>

          <div class="row">
            <button type="submit" class="btn btn-sm" data-busy-label="Saving">
              <?= $editProduct ? 'Save changes' : 'Add item' ?>
            </button>
            <?php if ($editProduct): ?>
              <a class="btn btn-sm btn-secondary" href="<?= e(admin_url('menu.php')) ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </section>

</div>

<!-- Paste a whole category in one go, for setting the menu up the first time. -->
<section class="card" id="batch-add">
  <div class="card-header">
    <h2>Add several items at once</h2>
    <span class="small subtle">One per line</span>
  </div>

  <div class="card-body">
    <?php if ($categories === []): ?>
      <p class="small subtle mb-0">Add a category first.</p>
    <?php else: ?>
      <p class="explainer-lede">
        Type or paste one drink per line as
        <strong>Name | Price | Description</strong>.
        The description is optional. Everything goes into the category you pick.
      </p>

      <form method="post" action="<?= e(admin_url('menu.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="product_batch_add">

        <div class="field">
          <label class="label" for="batch_category">Put them all in <span class="req">*</span></label>
          <select class="select" id="batch_category" name="category_id" required>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>"><?= e((string) $category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="label" for="batch_bulk">The items <span class="req">*</span></label>
          <textarea class="textarea batch-textarea" id="batch_bulk" name="bulk" rows="8" required
placeholder="Iced Americano | 85 | Double shot over ice
Cold Brew | 110 | Steeped for sixteen hours
Hot Chocolate | 75"></textarea>
          <p class="hint">
            Prices are in pesos. Blank lines are ignored. A line with no price, or a name that is
            already in that category, is skipped and reported back to you rather than guessed at.
          </p>
        </div>

        <button type="submit" class="btn" data-busy-label="Adding">
          <?= admin_icon('icon-plus', 'icon-sm') ?> Add these items
        </button>

        <p class="tiny subtle mt-3 mb-0">
          They arrive on sale, unfeatured, with no picture. Add pictures afterwards by editing
          each one, or leave them with the plain placeholder.
        </p>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php admin_footer(); ?>
