<?php
/**
 * The Entity Engine.
 *
 * Turns admin-defined "entities" (name, fields, relationships) into real
 * SQL tables, and provides generic CRUD against those tables.
 *
 * Every entity table is physically named ent_<slug> and always has:
 *   id INT AUTO_INCREMENT PRIMARY KEY, created_at, updated_at
 * plus one column per admin-defined field, plus one INT column per
 * relationship where this entity is the "child" (foreign key column).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const ENTITY_TABLE_PREFIX = 'ent_';

// ---------------------------------------------------------------------
// Type mapping
// ---------------------------------------------------------------------
function sql_type_for(string $type, ?int $maxLen = null): string
{
    switch ($type) {
        case 'Int':
            return 'INT';
        case 'Float':
            return 'DOUBLE';
        case 'Boolean':
            return 'TINYINT(1)';
        case 'Date':
            return 'DATE';
        case 'String':
        case 'Email':
        default:
            // Email is stored as a plain string column - the "Email" type only
            // changes how the UI renders/validates it, not the SQL storage.
            $len = $maxLen && $maxLen > 0 && $maxLen <= 65535 ? $maxLen : 255;
            return $len > 255 ? "TEXT" : "VARCHAR($len)";
    }
}

/**
 * True if $value is a well-formed email address. Uses PHP's built-in
 * FILTER_VALIDATE_EMAIL (a thoroughly-tested RFC-aware validator) rather
 * than a hand-rolled regex, which is the standard, robust way to do this
 * validation in PHP.
 */
function is_valid_email(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

// ---------------------------------------------------------------------
// Entity metadata reads
// ---------------------------------------------------------------------
function get_all_entities(bool $topLevelOnly = false): array
{
    $sql = 'SELECT * FROM entities';
    if ($topLevelOnly) {
        $sql .= ' WHERE is_top_level = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, label ASC';
    return db()->query($sql)->fetchAll();
}

function get_entity(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM entities WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function get_entity_by_name(string $name): ?array
{
    $stmt = db()->prepare('SELECT * FROM entities WHERE name = ?');
    $stmt->execute([$name]);
    return $stmt->fetch() ?: null;
}

function get_entity_fields(int $entityId): array
{
    $stmt = db()->prepare('SELECT * FROM entity_fields WHERE entity_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$entityId]);
    return $stmt->fetchAll();
}

/** Relationships where this entity holds the foreign key (its parents). */
function get_relationships_as_child(int $entityId): array
{
    $stmt = db()->prepare('SELECT r.*, p.name AS parent_name, p.label AS parent_label, p.table_name AS parent_table
                            FROM entity_relationships r
                            JOIN entities p ON p.id = r.parent_entity_id
                            WHERE r.child_entity_id = ?
                            ORDER BY r.sort_order ASC, r.id ASC');
    $stmt->execute([$entityId]);
    return $stmt->fetchAll();
}

/** Relationships where other entities point to this one (its children). */
function get_relationships_as_parent(int $entityId): array
{
    $stmt = db()->prepare('SELECT r.*, c.name AS child_name, c.label AS child_label, c.table_name AS child_table
                            FROM entity_relationships r
                            JOIN entities c ON c.id = r.child_entity_id
                            WHERE r.parent_entity_id = ?
                            ORDER BY r.sort_order ASC, r.id ASC');
    $stmt->execute([$entityId]);
    return $stmt->fetchAll();
}

/**
 * Next available sort_order for an entity's "fields", counting both native
 * columns (entity_fields) and entity-reference fields (entity_relationships
 * where this entity is the child) as one shared sequence, so newly added
 * fields of either kind always append after everything that exists.
 */
function next_field_sort_order(int $entityId): int
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM entity_fields WHERE entity_id = ?');
    $stmt->execute([$entityId]);
    $maxField = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM entity_relationships WHERE child_entity_id = ?');
    $stmt->execute([$entityId]);
    $maxRel = (int) $stmt->fetchColumn();

    return max($maxField, $maxRel) + 1;
}

/**
 * Merge an entity's native fields and its entity-reference fields
 * (relationships where it is the child) into a single list ordered the way
 * the admin defined them, each item tagged with 'kind' => 'field' or
 * 'relationship' so callers can branch on how to render/process it.
 */
function merge_display_fields(array $fields, array $relationships): array
{
    $items = [];
    foreach ($fields as $f) {
        $f['kind'] = 'field';
        $items[] = $f;
    }
    foreach ($relationships as $r) {
        $r['kind'] = 'relationship';
        $items[] = $r;
    }
    usort($items, function ($a, $b) {
        return ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
    });
    return $items;
}

// ---------------------------------------------------------------------
// Entity / field creation (DDL)
// ---------------------------------------------------------------------
function create_entity(string $name, string $label, bool $isTopLevel, array $fields, ?string $icon = null): int
{
    // Note: CREATE TABLE / ALTER TABLE cause an implicit commit on
    // MySQL/MariaDB, so this cannot be wrapped in a single PDO transaction.
    // Instead we insert the metadata rows first and, if the DDL fails,
    // manually compensate by deleting what we just inserted.
    $pdo = db();
    $slug = slugify($name);
    $tableName = ENTITY_TABLE_PREFIX . $slug;

    $stmt = $pdo->prepare('INSERT INTO entities (name, label, table_name, is_top_level, icon) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$slug, $label, $tableName, $isTopLevel ? 1 : 0, $icon]);
    $entityId = (int) $pdo->lastInsertId();

    try {
        $columnsSql = [];
        $order = 0;
        foreach ($fields as $field) {
            $fieldName = slugify($field['name']);
            if (in_array($fieldName, ['id', 'created_at', 'updated_at'], true)) {
                $fieldName .= '_f';
            }
            $type = in_array($field['type'], FIELD_TYPES, true) ? $field['type'] : 'String';
            $maxLen = !empty($field['max_length']) ? (int) $field['max_length'] : null;
            $default = $field['default_value'] ?? null;
            $required = !empty($field['is_required']) ? 1 : 0;

            $sortOrder = array_key_exists('sort_order', $field) ? (int) $field['sort_order'] : $order++;

            $fstmt = $pdo->prepare('INSERT INTO entity_fields (entity_id, name, label, field_type, max_length, default_value, is_required, sort_order)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $fstmt->execute([$entityId, $fieldName, $field['label'] ?: $field['name'], $type, $maxLen, $default, $required, $sortOrder]);

            $colType = sql_type_for($type, $maxLen);
            $colSql = "`$fieldName` $colType";
            if ($required) {
                $colSql .= ' NOT NULL';
            } else {
                $colSql .= ' NULL';
            }
            $columnsSql[] = $colSql;
        }

        $ddl = "CREATE TABLE `$tableName` (\n" .
            "  `id` INT AUTO_INCREMENT PRIMARY KEY,\n" .
            (count($columnsSql) ? "  " . implode(",\n  ", $columnsSql) . ",\n" : '') .
            "  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,\n" .
            "  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($ddl);

        return $entityId;
    } catch (Throwable $e) {
        // Compensate: remove the metadata row(s) we already committed.
        $del = $pdo->prepare('DELETE FROM entities WHERE id = ?');
        $del->execute([$entityId]);
        throw $e;
    }
}

function add_field_to_entity(int $entityId, array $field): int
{
    $entity = get_entity($entityId);
    if (!$entity) {
        throw new RuntimeException('Entity not found');
    }
    $pdo = db();
    $fieldName = slugify($field['name']);
    $type = in_array($field['type'], FIELD_TYPES, true) ? $field['type'] : 'String';
    $maxLen = !empty($field['max_length']) ? (int) $field['max_length'] : null;
    $default = $field['default_value'] ?? null;
    $required = !empty($field['is_required']) ? 1 : 0;

    $nextOrder = next_field_sort_order($entityId);

    $stmt = $pdo->prepare('INSERT INTO entity_fields (entity_id, name, label, field_type, max_length, default_value, is_required, sort_order)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$entityId, $fieldName, $field['label'] ?: $field['name'], $type, $maxLen, $default, $required, $nextOrder]);
    $fieldId = (int) $pdo->lastInsertId();

    try {
        $colType = sql_type_for($type, $maxLen);
        $table = $entity['table_name'];
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$fieldName` $colType NULL");
        return $fieldId;
    } catch (Throwable $e) {
        $del = $pdo->prepare('DELETE FROM entity_fields WHERE id = ?');
        $del->execute([$fieldId]);
        throw $e;
    }
}

/**
 * Create a relationship: adds an FK column on the child table pointing to
 * the parent entity's primary key. one_to_one adds a UNIQUE constraint.
 *
 * $sortOrder controls where this shows up alongside the child entity's
 * native fields (see merge_display_fields()). Pass null to auto-append
 * after everything the child entity already has.
 */
function create_relationship(int $childEntityId, int $parentEntityId, string $fkFieldName, string $type, ?string $label = null, ?int $sortOrder = null): int
{
    // Same caveat as create_entity(): ALTER TABLE implicitly commits, so we
    // insert metadata first and compensate manually if the DDL fails.
    $child = get_entity($childEntityId);
    $parent = get_entity($parentEntityId);
    if (!$child || !$parent) {
        throw new RuntimeException('Entity not found');
    }
    $type = $type === 'one_to_one' ? 'one_to_one' : 'one_to_many';
    $fkField = slugify($fkFieldName);
    if ($sortOrder === null) {
        $sortOrder = next_field_sort_order($childEntityId);
    }

    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO entity_relationships (child_entity_id, parent_entity_id, fk_field, relationship_type, label, sort_order)
                            VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$childEntityId, $parentEntityId, $fkField, $type, $label, $sortOrder]);
    $relId = (int) $pdo->lastInsertId();

    try {
        $childTable = $child['table_name'];
        $parentTable = $parent['table_name'];
        $constraintName = 'fk_' . $childTable . '_' . $fkField;

        $pdo->exec("ALTER TABLE `$childTable` ADD COLUMN `$fkField` INT NULL");
        $pdo->exec("ALTER TABLE `$childTable` ADD CONSTRAINT `$constraintName` FOREIGN KEY (`$fkField`) REFERENCES `$parentTable`(`id`) ON DELETE SET NULL");
        if ($type === 'one_to_one') {
            $pdo->exec("ALTER TABLE `$childTable` ADD UNIQUE KEY `uniq_$constraintName` (`$fkField`)");
        }

        return $relId;
    } catch (Throwable $e) {
        $del = $pdo->prepare('DELETE FROM entity_relationships WHERE id = ?');
        $del->execute([$relId]);
        throw $e;
    }
}

// ---------------------------------------------------------------------
// Generic CRUD against entity tables
// ---------------------------------------------------------------------
function cast_value_for_save(array $field, $raw)
{
    if ($raw === null || $raw === '') {
        return $field['is_required'] ? sql_default_for($field) : null;
    }
    switch ($field['field_type']) {
        case 'Int':
            return (int) $raw;
        case 'Float':
            return (float) $raw;
        case 'Boolean':
            return !empty($raw) && $raw !== '0' ? 1 : 0;
        case 'Date':
            return $raw;
        case 'Email':
            // Normalize so lookups/comparisons are consistent regardless of
            // how the user typed it in (extra spaces, mixed case).
            return strtolower(trim((string) $raw));
        default:
            return (string) $raw;
    }
}

function sql_default_for(array $field)
{
    if ($field['default_value'] !== null && $field['default_value'] !== '') {
        return $field['default_value'];
    }
    switch ($field['field_type']) {
        case 'Int':
            return 0;
        case 'Float':
            return 0.0;
        case 'Boolean':
            return 0;
        default:
            return '';
    }
}

function entity_list_rows(array $entity, array $fields, array $filters = [], int $page = 1, int $perPage = ROWS_PER_PAGE): array
{
    $table = $entity['table_name'];
    $where = [];
    $params = [];
    foreach ($filters as $col => $val) {
        $where[] = "`" . sanitize_identifier($col) . "` = ?";
        $params[] = $val;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = db()->prepare("SELECT COUNT(*) FROM `$table` $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare("SELECT * FROM `$table` $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

function entity_get_row(array $entity, int $id): ?array
{
    $table = $entity['table_name'];
    $stmt = db()->prepare("SELECT * FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function entity_insert_row(array $entity, array $fields, array $data, array $fkValues = []): int
{
    $table = $entity['table_name'];
    $cols = [];
    $placeholders = [];
    $values = [];

    foreach ($fields as $field) {
        $name = $field['name'];
        if (!array_key_exists($name, $data)) {
            continue;
        }
        $cols[] = "`$name`";
        $placeholders[] = '?';
        $values[] = cast_value_for_save($field, $data[$name]);
    }
    foreach ($fkValues as $col => $val) {
        $cols[] = '`' . sanitize_identifier($col) . '`';
        $placeholders[] = '?';
        $values[] = $val === '' ? null : (int) $val;
    }

    if (!$cols) {
        $stmt = db()->prepare("INSERT INTO `$table` () VALUES ()");
        $stmt->execute();
        return (int) db()->lastInsertId();
    }

    $sql = "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = db()->prepare($sql);
    $stmt->execute($values);
    return (int) db()->lastInsertId();
}

function entity_update_row(array $entity, array $fields, int $id, array $data, array $fkValues = []): bool
{
    $table = $entity['table_name'];
    $sets = [];
    $values = [];

    foreach ($fields as $field) {
        $name = $field['name'];
        if (!array_key_exists($name, $data)) {
            continue;
        }
        $sets[] = "`$name` = ?";
        $values[] = cast_value_for_save($field, $data[$name]);
    }
    foreach ($fkValues as $col => $val) {
        $sets[] = '`' . sanitize_identifier($col) . '` = ?';
        $values[] = $val === '' ? null : (int) $val;
    }
    if (!$sets) {
        return true;
    }
    $values[] = $id;
    $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = db()->prepare($sql);
    return $stmt->execute($values);
}

function entity_delete_row(array $entity, int $id): bool
{
    $table = $entity['table_name'];
    $stmt = db()->prepare("DELETE FROM `$table` WHERE id = ?");
    return $stmt->execute([$id]);
}

/** Human readable label for an FK value: shows the related row's id (and name/label field if present). */
function entity_fk_display(array $parentEntity, array $parentFields, int $id): string
{
    $nameField = null;
    foreach ($parentFields as $f) {
        if (in_array($f['name'], ['name', 'title', 'label'], true)) {
            $nameField = $f['name'];
            break;
        }
    }
    $row = entity_get_row($parentEntity, $id);
    if (!$row) {
        return "#$id";
    }
    return $nameField && !empty($row[$nameField]) ? $row[$nameField] . " (#$id)" : "#$id";
}

/** Number of data rows currently stored in an entity's table. */
function entity_row_count(array $entity): int
{
    $table = $entity['table_name'];
    return (int) db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

/**
 * Permanently delete an entity: its own data table (and every row in it),
 * the foreign-key constraint/column on any child table that links to it,
 * and its metadata (fields, relationships, role permissions).
 *
 * This is destructive and irreversible - callers must confirm with the
 * admin before invoking it. Relationships where this entity is the CHILD
 * simply disappear along with its own table. Relationships where this
 * entity is the PARENT are unwound first: the FK column is dropped from
 * the other entity's table, but that other entity's table and its own
 * data are left intact.
 */
function delete_entity(int $entityId): void
{
    $pdo = db();
    $entity = get_entity($entityId);
    if (!$entity) {
        throw new RuntimeException('Entity not found');
    }

    // 1) Unwind relationships where this entity is the parent: drop the FK
    //    constraint (and column) from each child table first, since MySQL
    //    refuses to drop a table that's still referenced by a live FK.
    foreach (get_relationships_as_parent($entityId) as $rel) {
        $childTable = $rel['child_table'];
        $fkField = $rel['fk_field'];
        $constraintName = 'fk_' . $childTable . '_' . $fkField;
        try {
            $pdo->exec("ALTER TABLE `$childTable` DROP FOREIGN KEY `$constraintName`");
        } catch (Throwable $e) {
            // Constraint may already be gone; continue - the column drop below still cleans up.
        }
        try {
            $pdo->exec("ALTER TABLE `$childTable` DROP COLUMN `$fkField`");
        } catch (Throwable $e) {
            // Column may already be gone; nothing more we can do here.
        }
    }

    // 2) Drop the entity's own table - this destroys all of its data.
    $table = $entity['table_name'];
    $pdo->exec("DROP TABLE IF EXISTS `$table`");

    // 3) Delete the entity's metadata row. ON DELETE CASCADE takes care of
    //    entity_fields, entity_relationships (both as parent and child) and
    //    role_permissions rows that reference this entity.
    $stmt = $pdo->prepare('DELETE FROM entities WHERE id = ?');
    $stmt->execute([$entityId]);
}

/** All rows of an entity as id => display-label pairs, for building FK <select> dropdowns. */
function get_entity_options(array $entity): array
{
    $fields = get_entity_fields($entity['id']);
    $nameField = null;
    foreach ($fields as $f) {
        if (in_array($f['name'], ['name', 'title', 'label'], true)) {
            $nameField = $f['name'];
            break;
        }
    }
    $table = $entity['table_name'];
    $col = $nameField ? "`$nameField`" : 'NULL';
    $stmt = db()->query("SELECT id, $col AS disp FROM `$table` ORDER BY id DESC LIMIT 500");
    $options = [];
    foreach ($stmt->fetchAll() as $row) {
        $options[$row['id']] = $row['disp'] !== null && $row['disp'] !== '' ? $row['disp'] . ' (#' . $row['id'] . ')' : ('#' . $row['id']);
    }
    return $options;
}
