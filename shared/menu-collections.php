<?php
/** Fixed collections share product identities with the regular menu. */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const BEST_SELLERS_CATEGORY_ID = -1;

function menu_best_sellers(): array
{
    $rows = db_all("SELECT p.*, c.id AS category_id, c.name AS category_name, c.slug AS category_slug,
        sales.quantity AS sold_quantity
        FROM products p JOIN categories c ON c.id = p.category_id
        JOIN (SELECT oi.product_id, SUM(oi.quantity) AS quantity, SUM(oi.line_total) AS revenue
          FROM order_items oi JOIN orders o ON o.id = oi.order_id
          WHERE o.status = 'completed' AND COALESCE(o.completed_at, o.placed_at) >= ?
          AND COALESCE(o.completed_at, o.placed_at) < ? GROUP BY oi.product_id) sales ON sales.product_id = p.id
        WHERE p.is_available = 1 AND c.is_active = 1
        ORDER BY sales.quantity DESC, sales.revenue DESC, p.name ASC, p.id ASC LIMIT 5",
        [date('Y-m-d', strtotime('-29 days')) . ' 00:00:00', date('Y-m-d', strtotime('+1 day')) . ' 00:00:00']);
    if ($rows !== []) return ['products' => $rows, 'fallback' => false];
    $rows = db_all('SELECT p.*, c.id AS category_id, c.name AS category_name, c.slug AS category_slug,
        0 AS sold_quantity FROM products p JOIN categories c ON c.id = p.category_id
        WHERE p.is_available = 1 AND c.is_active = 1
        ORDER BY p.is_featured DESC, p.sort_order ASC, p.id ASC LIMIT 1');
    return ['products' => $rows, 'fallback' => true];
}

/** Evaluate the proposed write before images or rows are changed. */
function menu_collection_mutation_error(string $action, array $post): ?string
{
    $id = (int) ($post['id'] ?? 0);
    if (in_array($action, ['category_save', 'category_delete'], true)) {
        if ($id === BEST_SELLERS_CATEGORY_ID
            || ($action === 'category_save' && slugify(clean_text((string) ($post['name'] ?? ''), 80)) === 'best-sellers')) {
            return 'Best Sellers is a protected collection. Its name and visibility cannot be changed, and it cannot be deleted.';
        }
    }
    $rows = db_all('SELECT p.id, p.category_id, p.is_available, c.is_active
        FROM products p JOIN categories c ON c.id = p.category_id');
    $before = count(array_filter($rows, static fn ($r) => (int) $r['is_available'] === 1 && (int) $r['is_active'] === 1));
    $deleteIds = $action === 'product_batch_delete' && is_array($post['product_ids'] ?? null)
        ? array_map('intval', $post['product_ids']) : ($action === 'product_delete' ? [$id] : []);
    $remaining = 0;
    $available = 0;
    foreach ($rows as $row) {
        if (in_array((int) $row['id'], $deleteIds, true)) continue;
        $remaining++;
        $on = (int) $row['is_available'] === 1;
        $active = (int) $row['is_active'] === 1;
        if ($action === 'category_save' && (int) $row['category_id'] === $id) $active = isset($post['is_active']);
        if ((int) $row['id'] === $id) {
            if ($action === 'product_availability') $on = !$on;
            if ($action === 'product_save') {
                $on = isset($post['is_available']);
                $active = (int) db_value('SELECT is_active FROM categories WHERE id = ?', [(int) ($post['category_id'] ?? 0)]) === 1;
            }
        }
        if ($on && $active) $available++;
    }
    if (($rows !== [] && $remaining === 0) || ($before > 0 && $available === 0)) {
        return 'Keep at least one drink on sale in a visible category so Best Sellers always has a drink. Show or add another drink first.';
    }
    return null;
}
