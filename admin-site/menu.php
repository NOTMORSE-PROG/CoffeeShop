<?php
/**
 * The menu: categories and the drinks inside them.
 */

declare(strict_types=1);

require_once __DIR__ . '/partials/layout.php';

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
        $isAvailable = isset($_POST['is_available']) ? 1 : 0;
        $isFeatured  = isset($_POST['is_featured']) ? 1 : 0;
        $sortOrder   = (int) ($_POST['sort_order'] ?? 0);

        $categoryExists = (int) db_value('SELECT COUNT(*) FROM categories WHERE id = ?', [$categoryId]) > 0;

        $errors = [];
        if ($name === '')          { $errors[] = 'An item needs a name.'; }
        if (!$categoryExists)      { $errors[] = 'Choose a category that exists.'; }
        if ($price < 0)            { $errors[] = 'The price cannot be negative.'; }
        if ($price > 99999999)     { $errors[] = 'That price is too large.'; }
        if (!valid_image_path($imagePath)) {
            $errors[] = 'The image path must be a relative path such as assets/img/products/mocha.svg.';
        }

        if ($errors !== []) {
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

            db_query(
                'UPDATE products
                 SET category_id = ?, name = ?, slug = ?, description = ?, price = ?,
                     image_path = ?, is_available = ?, is_featured = ?, sort_order = ?
                 WHERE id = ?',
                [$categoryId, $name, $slug, $description ?: null, $price,
                 $imagePath ?: null, $isAvailable, $isFeatured, $sortOrder, $id]
            );

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
                 $imagePath ?: null, $isAvailable, $isFeatured, $sortOrder]
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

    if ($action === 'product_delete') {
        $id     = (int) ($_POST['id'] ?? 0);
        $before = db_one('SELECT id, name, price FROM products WHERE id = ? LIMIT 1', [$id]);

        if ($before === null) {
            flash('error', 'That item no longer exists.');
        } else {
            // Past order lines keep their own copy of the name and price, so
            // removing an item here does not rewrite anyone's receipt.
            db_query('DELETE FROM products WHERE id = ?', [$id]);

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
                    <br><span class="subtle tiny"><?= e((string) $category['description']) ?></span>
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
        <table class="table table-compact">
          <thead>
            <tr>
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
                <td>
                  <span class="bold"><?= e((string) $product['name']) ?></span>
                  <?php if (!empty($product['description'])): ?>
                    <br><span class="subtle tiny"><?= e((string) $product['description']) ?></span>
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
    <?php endif; ?>

    <div class="card-body" id="product-form">
      <h3 class="form-heading"><?= $editProduct ? 'Edit menu item' : 'Add a menu item' ?></h3>

      <?php if ($categories === []): ?>
        <p class="small subtle mb-0">Add a category first.</p>
      <?php else: ?>
        <form method="post" action="<?= e(admin_url('menu.php')) ?>">
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

          <div class="field">
            <label class="label" for="product_image">Image path</label>
            <input class="input" type="text" id="product_image" name="image_path" maxlength="255"
                   placeholder="assets/img/products/mocha.svg"
                   value="<?= e((string) ($editProduct['image_path'] ?? '')) ?>">
            <p class="hint">A relative path inside the project. Leave blank for no picture.</p>
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

<?php admin_footer(); ?>
