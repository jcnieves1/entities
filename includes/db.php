<?php
/**
 * PDO singleton connection.
 */
require_once __DIR__ . '/../config/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die('Database connection failed. Have you run install.php? (' . htmlspecialchars($e->getMessage()) . ')');
        }
    }
    return $pdo;
}

/** True once the core schema (users table) exists. */
function is_installed(): bool
{
    try {
        db()->query('SELECT 1 FROM users LIMIT 1');
        run_migrations();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Lightweight self-migration for installs created before a schema change.
 * Safe to run on every request: each check is a cheap indexed lookup and
 * only ALTERs when the column is actually missing.
 */
function run_migrations(): void
{
    $pdo = db();

    // entity_relationships.sort_order: lets relationship-based fields
    // (entity-reference field types) be interleaved with native fields in
    // the order the admin defined them, instead of always trailing.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'entity_relationships' AND COLUMN_NAME = 'sort_order'");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE entity_relationships ADD COLUMN sort_order INT DEFAULT 0');
    }
}
