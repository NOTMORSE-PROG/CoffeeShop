<?php
/**
 * The cart.
 *
 * Kept in the customer's own session. Only product ids, option ids and
 * quantities are stored; prices are never held here. Money is recalculated
 * from the database on every render and again at checkout, so an edited
 * session or a stale page cannot change what an order costs.
 */

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/orders.php';

const CART_KEY = 'cart';
const CART_MAX_LINES = 30;
const CART_MAX_QTY = 20;

/** The raw cart lines from the session. */
function cart_lines(): array
{
    start_session('ourcoffee_shop');

    $cart = $_SESSION[CART_KEY] ?? [];

    return is_array($cart) ? $cart : [];
}

/** Replace the cart. */
function cart_store(array $lines): void
{
    start_session('ourcoffee_shop');
    $_SESSION[CART_KEY] = array_values($lines);
}

/** Total number of drinks in the cart. */
function cart_count(): int
{
    $count = 0;

    foreach (cart_lines() as $line) {
        $count += max(0, (int) ($line['quantity'] ?? 0));
    }

    return $count;
}

/** Whether the cart holds anything. */
function cart_is_empty(): bool
{
    return cart_lines() === [];
}

/**
 * A stable key for one product plus one specific set of options, so adding
 * the same drink with the same customisation increments instead of adding a
 * second line, while a different sugar level stays separate.
 */
function cart_line_key(int $productId, array $optionIds): string
{
    sort($optionIds, SORT_NUMERIC);

    return $productId . ':' . implode('.', $optionIds);
}

/**
 * Add a drink.
 *
 * @return array{ok: bool, error?: string}
 */
function cart_add(int $productId, int $quantity = 1, array $optionIds = []): array
{
    $quantity = max(1, min(CART_MAX_QTY, $quantity));

    $product = db_one(
        'SELECT id, name, is_available FROM products WHERE id = ? LIMIT 1',
        [$productId]
    );

    if ($product === null) {
        return ['ok' => false, 'error' => 'That drink is not on the menu.'];
    }

    if ((int) $product['is_available'] !== 1) {
        return ['ok' => false, 'error' => $product['name'] . ' is not available right now.'];
    }

    // Keep only options that genuinely belong to this product.
    $optionIds = cart_valid_option_ids($productId, $optionIds);

    $lines = cart_lines();
    $key   = cart_line_key($productId, $optionIds);

    foreach ($lines as $index => $line) {
        if (($line['key'] ?? '') === $key) {
            $lines[$index]['quantity'] = min(CART_MAX_QTY, (int) $line['quantity'] + $quantity);
            cart_store($lines);

            return ['ok' => true];
        }
    }

    if (count($lines) >= CART_MAX_LINES) {
        return ['ok' => false, 'error' => 'Your cart is full. Please check out first.'];
    }

    $lines[] = [
        'key'        => $key,
        'product_id' => $productId,
        'quantity'   => $quantity,
        'option_ids' => $optionIds,
    ];

    cart_store($lines);

    return ['ok' => true];
}

/**
 * Filter a list of option ids down to the ones actually offered for a product,
 * respecting single-choice groups by keeping only the last one picked.
 */
function cart_valid_option_ids(int $productId, array $optionIds): array
{
    $optionIds = array_values(array_unique(array_filter(array_map('intval', $optionIds))));

    if ($optionIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($optionIds), '?'));

    $rows = db_all(
        "SELECT o.id, o.group_id, g.selection_type
         FROM options o
         JOIN option_groups g ON g.id = o.group_id
         JOIN product_option_groups pog ON pog.group_id = g.id AND pog.product_id = ?
         WHERE o.id IN ($placeholders)",
        array_merge([$productId], $optionIds)
    );

    $singlePicked = [];
    $keep = [];

    foreach ($rows as $row) {
        $groupId = (int) $row['group_id'];

        if ($row['selection_type'] === 'single') {
            // Last one wins for a pick-one group.
            $singlePicked[$groupId] = (int) $row['id'];
            continue;
        }

        $keep[] = (int) $row['id'];
    }

    return array_values(array_merge($keep, array_values($singlePicked)));
}

/** Change the quantity on one line. Zero removes it. */
function cart_set_quantity(string $key, int $quantity): void
{
    $lines = cart_lines();

    foreach ($lines as $index => $line) {
        if (($line['key'] ?? '') !== $key) {
            continue;
        }

        if ($quantity <= 0) {
            unset($lines[$index]);
        } else {
            $lines[$index]['quantity'] = min(CART_MAX_QTY, $quantity);
        }

        cart_store($lines);

        return;
    }
}

/** Remove one line. */
function cart_remove(string $key): void
{
    cart_set_quantity($key, 0);
}

/** Empty the cart. */
function cart_clear(): void
{
    start_session('ourcoffee_shop');
    unset($_SESSION[CART_KEY]);
}

/**
 * The cart priced up for display, with the product name, image and chosen
 * options resolved. Any line whose product has since been removed or turned
 * off is dropped and reported.
 *
 * @return array{lines: array, subtotal: float, notices: string[]}
 */
function cart_detailed(): array
{
    $lines    = cart_lines();
    $detailed = [];
    $notices  = [];
    $subtotal = 0.0;
    $changed  = false;

    foreach ($lines as $line) {
        $productId = (int) ($line['product_id'] ?? 0);
        $quantity  = max(1, (int) ($line['quantity'] ?? 1));

        $product = db_one(
            'SELECT p.id, p.name, p.price, p.image_path, p.is_available, c.name AS category_name
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.id = ? LIMIT 1',
            [$productId]
        );

        if ($product === null) {
            $notices[] = 'An item was removed from your cart because it is no longer on the menu.';
            $changed = true;
            continue;
        }

        if ((int) $product['is_available'] !== 1) {
            $notices[] = $product['name'] . ' was removed because it is not available right now.';
            $changed = true;
            continue;
        }

        $options      = [];
        $optionsTotal = 0.0;
        $optionIds    = array_map('intval', $line['option_ids'] ?? []);

        if ($optionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($optionIds), '?'));

            $options = db_all(
                "SELECT o.name, o.price_delta, g.name AS group_name
                 FROM options o
                 JOIN option_groups g ON g.id = o.group_id
                 WHERE o.id IN ($placeholders)
                 ORDER BY g.sort_order, o.sort_order",
                $optionIds
            );

            foreach ($options as $option) {
                $optionsTotal += (float) $option['price_delta'];
            }
        }

        $unitPrice = (float) $product['price'];
        $lineTotal = ($unitPrice + $optionsTotal) * $quantity;
        $subtotal += $lineTotal;

        $detailed[] = [
            'key'           => (string) ($line['key'] ?? cart_line_key($productId, $optionIds)),
            'product_id'    => $productId,
            'name'          => $product['name'],
            'category_name' => $product['category_name'],
            'image_path'    => $product['image_path'],
            'unit_price'    => $unitPrice,
            'quantity'      => $quantity,
            'options'       => $options,
            'options_total' => $optionsTotal,
            'line_total'    => $lineTotal,
        ];
    }

    // Persist the pruning so the count in the header matches what is shown.
    if ($changed) {
        $keys = array_column($detailed, 'key');
        cart_store(array_values(array_filter(
            $lines,
            static fn (array $line): bool => in_array((string) ($line['key'] ?? ''), $keys, true)
        )));
    }

    return [
        'lines'    => $detailed,
        'subtotal' => round($subtotal, 2),
        'notices'  => $notices,
    ];
}

/** The cart shaped the way place_order() expects it. */
function cart_for_order(): array
{
    return array_map(
        static fn (array $line): array => [
            'product_id' => (int) $line['product_id'],
            'quantity'   => (int) $line['quantity'],
            'option_ids' => array_map('intval', $line['option_ids'] ?? []),
        ],
        cart_lines()
    );
}
