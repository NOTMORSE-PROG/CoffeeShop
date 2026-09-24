<?php
/**
 * Database access.
 *
 * One shared PDO connection, configured to throw on error and to use real
 * prepared statements. Every query in this project goes through here with
 * bound parameters. There is no string concatenation into SQL anywhere.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, not client-side emulation. This is what
            // makes bound parameters an actual defence against SQL injection.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());

        if (APP_DEBUG) {
            throw $e;
        }

        http_response_code(503);
        exit('The system is temporarily unavailable. Please try again in a moment.');
    }

    return $pdo;
}

/** Run a query with bound parameters and return the statement. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt;
}

/** Fetch a single row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();

    return $row === false ? null : $row;
}

/** Fetch every matching row. */
function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/** Fetch a single scalar value, or null. */
function db_value(string $sql, array $params = []): mixed
{
    $value = db_query($sql, $params)->fetchColumn();

    return $value === false ? null : $value;
}

/** Insert and return the new id. */
function db_insert(string $sql, array $params = []): int
{
    db_query($sql, $params);

    return (int) db()->lastInsertId();
}

/**
 * Run a closure inside a transaction, rolling back on any exception.
 * Used for order placement and stock deduction, which must not half-apply.
 */
function db_transaction(callable $work): mixed
{
    $pdo = db();

    // Nested calls just join the outer transaction
    if ($pdo->inTransaction()) {
        return $work($pdo);
    }

    $pdo->beginTransaction();

    try {
        $result = $work($pdo);
        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
