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

    // entity_fields.field_type: add the 'Email' field type to the ENUM.
    $stmt = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'entity_fields' AND COLUMN_NAME = 'field_type'");
    $stmt->execute();
    $columnType = (string) $stmt->fetchColumn();
    if ($columnType !== '' && strpos($columnType, "'Email'") === false) {
        $pdo->exec("ALTER TABLE entity_fields MODIFY COLUMN field_type ENUM('Int','String','Date','Boolean','Float','Email') NOT NULL");
    }

    // field_conditions: whole table added later, for the "enable this field
    // only if..." feature. Create it if this install predates that feature.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'field_conditions'");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        $pdo->exec("CREATE TABLE field_conditions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            target_field_id INT NULL,
            target_relationship_id INT NULL,
            group_index INT NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            source_type ENUM('own_field','own_relationship','related_field') NOT NULL,
            source_field_id INT NULL,
            source_relationship_id INT NULL,
            via_relationship_id INT NULL,
            operator ENUM('equals','not_equals','greater_than','greater_or_equal','less_than','less_or_equal','contains','is_null','is_not_null') NOT NULL,
            compare_value VARCHAR(255) DEFAULT NULL,
            FOREIGN KEY (target_field_id) REFERENCES entity_fields(id) ON DELETE CASCADE,
            FOREIGN KEY (target_relationship_id) REFERENCES entity_relationships(id) ON DELETE CASCADE,
            FOREIGN KEY (source_field_id) REFERENCES entity_fields(id) ON DELETE CASCADE,
            FOREIGN KEY (source_relationship_id) REFERENCES entity_relationships(id) ON DELETE CASCADE,
            FOREIGN KEY (via_relationship_id) REFERENCES entity_relationships(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
