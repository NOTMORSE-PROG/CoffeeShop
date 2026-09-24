<?php
/**
 * Audit trail.
 *
 * Every admin action that changes data is recorded here: who did it, what
 * changed, from where, and when. The log is append-only by convention, there
 * is no update or delete path anywhere in the application.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** Action names used across the admin site. Kept in one place so reports can group on them. */
const AUDIT_ACTIONS = [
    'auth.login'              => 'Signed in',
    'auth.login_failed'       => 'Failed sign-in attempt',
    'auth.logout'             => 'Signed out',
    'auth.password_changed'   => 'Changed password',
    'auth.locked_out'         => 'Account locked after repeated failures',
    'order.status_changed'    => 'Changed order status',
    'order.payment_verified'  => 'Marked payment as received',
    'order.cancelled'         => 'Cancelled an order',
    'product.created'         => 'Added a menu item',
    'product.updated'         => 'Edited a menu item',
    'product.availability'    => 'Changed item availability',
    'product.deleted'         => 'Removed a menu item',
    'category.created'        => 'Added a category',
    'category.updated'        => 'Edited a category',
    'category.deleted'        => 'Removed a category',
    'inventory.adjusted'      => 'Adjusted stock',
    'inventory.created'       => 'Added an inventory item',
    'inventory.updated'       => 'Edited an inventory item',
    'settings.updated'        => 'Changed shop settings',
    'user.created'            => 'Created an admin account',
    'user.updated'            => 'Edited an admin account',
    'user.deactivated'        => 'Deactivated an admin account',
    'sms.resent'              => 'Resent an SMS notification',
];

/** Readable label for an action key. */
function audit_action_label(string $action): string
{
    return AUDIT_ACTIONS[$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action));
}

/**
 * Record an action.
 *
 * $adminId may be null for things the system itself does, such as an order
 * being placed by a customer.
 *
 * Failure to write an audit row must never take down the action the user was
 * performing, so this swallows its own errors into the error log.
 */
function audit(
    string  $action,
    ?string $entityType = null,
    string|int|null $entityId = null,
    ?string $summary = null,
    ?array  $oldValues = null,
    ?array  $newValues = null,
    ?int    $adminId = null,
    ?string $adminUsername = null
): void {
    // Fall back to whoever is signed in, when the caller did not say.
    if ($adminId === null && isset($_SESSION['admin_id'])) {
        $adminId = (int) $_SESSION['admin_id'];
    }
    if ($adminUsername === null && isset($_SESSION['admin_username'])) {
        $adminUsername = (string) $_SESSION['admin_username'];
    }

    try {
        db_query(
            'INSERT INTO audit_log
                (admin_id, admin_username, action, entity_type, entity_id,
                 summary, old_values, new_values, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $adminId,
                $adminUsername,
                $action,
                $entityType,
                $entityId === null ? null : (string) $entityId,
                $summary === null ? null : mb_substr($summary, 0, 255),
                $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE),
                $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE),
                client_ip(),
                client_user_agent(),
            ]
        );
    } catch (Throwable $e) {
        error_log('Audit write failed for action ' . $action . ': ' . $e->getMessage());
    }
}

/**
 * Compare two associative arrays and return only what actually changed.
 * Keeps the audit trail readable instead of dumping whole rows.
 */
function audit_diff(array $before, array $after, array $fields): array
{
    $old = [];
    $new = [];

    foreach ($fields as $field) {
        $from = $before[$field] ?? null;
        $to   = $after[$field] ?? null;

        if ((string) $from !== (string) $to) {
            $old[$field] = $from;
            $new[$field] = $to;
        }
    }

    return [$old, $new];
}

/**
 * Read the audit trail with optional filters, newest first.
 */
function audit_search(array $filters = [], int $limit = 50, int $offset = 0): array
{
    $where  = [];
    $params = [];

    if (!empty($filters['admin_id'])) {
        $where[] = 'a.admin_id = ?';
        $params[] = (int) $filters['admin_id'];
    }

    if (!empty($filters['action'])) {
        $where[] = 'a.action = ?';
        $params[] = $filters['action'];
    }

    if (!empty($filters['entity_type'])) {
        $where[] = 'a.entity_type = ?';
        $params[] = $filters['entity_type'];
    }

    if (!empty($filters['date_from'])) {
        $where[] = 'a.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }

    if (!empty($filters['date_to'])) {
        $where[] = 'a.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    if (!empty($filters['search'])) {
        $where[] = '(a.summary LIKE ? OR a.admin_username LIKE ? OR a.entity_id LIKE ?)';
        $term = '%' . $filters['search'] . '%';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    $sql = 'SELECT a.* FROM audit_log a';

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    // LIMIT and OFFSET are cast to int, never interpolated from raw input.
    $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . max(1, min(500, $limit))
          . ' OFFSET ' . max(0, $offset);

    return db_all($sql, $params);
}

/** Count rows matching the same filters, for pagination. */
function audit_count(array $filters = []): int
{
    $where  = [];
    $params = [];

    if (!empty($filters['admin_id'])) {
        $where[] = 'admin_id = ?';
        $params[] = (int) $filters['admin_id'];
    }
    if (!empty($filters['action'])) {
        $where[] = 'action = ?';
        $params[] = $filters['action'];
    }
    if (!empty($filters['entity_type'])) {
        $where[] = 'entity_type = ?';
        $params[] = $filters['entity_type'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['search'])) {
        $where[] = '(summary LIKE ? OR admin_username LIKE ? OR entity_id LIKE ?)';
        $term = '%' . $filters['search'] . '%';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    $sql = 'SELECT COUNT(*) FROM audit_log';

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    return (int) db_value($sql, $params);
}
