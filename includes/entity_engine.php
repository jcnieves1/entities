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

// Operators available when building "enable this field if..." conditions.
const CONDITION_OPERATORS = [
    'equals', 'not_equals', 'greater_than', 'greater_or_equal',
    'less_than', 'less_or_equal', 'contains', 'is_null', 'is_not_null',
];

/**
 * Thrown by update_field()/update_relationship() when applying the
 * requested change would alter or destroy existing row data and the caller
 * has not explicitly confirmed that via $confirmDataLoss = true. Callers
 * catch this, show the admin the impact details ($e->getImpact()), and
 * resubmit with confirmation once the admin accepts the loss.
 */
class DataLossException extends RuntimeException
{
    private array $impact;

    public function __construct(string $message, array $impact)
    {
        parent::__construct($message);
        $this->impact = $impact;
    }

    public function getImpact(): array
    {
        return $this->impact;
    }
}

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

function get_field(int $fieldId): ?array
{
    $stmt = db()->prepare('SELECT * FROM entity_fields WHERE id = ?');
    $stmt->execute([$fieldId]);
    return $stmt->fetch() ?: null;
}

function get_relationship(int $relId): ?array
{
    $stmt = db()->prepare('SELECT r.*, p.name AS parent_name, p.label AS parent_label, p.table_name AS parent_table,
                                   c.name AS child_name, c.label AS child_label, c.table_name AS child_table
                            FROM entity_relationships r
                            JOIN entities p ON p.id = r.parent_entity_id
                            JOIN entities c ON c.id = r.child_entity_id
                            WHERE r.id = ?');
    $stmt->execute([$relId]);
    return $stmt->fetch() ?: null;
}

/**
 * True if $name is already used as a column on $entityId's table, whether
 * by a native field or a relationship's FK column (they share one physical
 * table so they also share one namespace). Excludes the field/relationship
 * being edited itself, so renaming a field to its own current name is fine.
 */
function column_name_taken(int $entityId, string $name, ?int $excludeFieldId = null, ?int $excludeRelId = null): bool
{
    $pdo = db();

    $sql = 'SELECT COUNT(*) FROM entity_fields WHERE entity_id = ? AND name = ?';
    $params = [$entityId, $name];
    if ($excludeFieldId) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeFieldId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }

    $sql = 'SELECT COUNT(*) FROM entity_relationships WHERE child_entity_id = ? AND fk_field = ?';
    $params = [$entityId, $name];
    if ($excludeRelId) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeRelId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
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
// Field / relationship editing & deletion (DDL)
// ---------------------------------------------------------------------

/**
 * True if a single scalar $value already conforms to $type's format (e.g.
 * a well-formed date, a valid email, a numeric value for Int/Float). Empty
 * values are always considered valid here - required-ness is a separate
 * concern, checked elsewhere.
 */
function is_valid_value_for_type(string $type, $value, ?int $maxLen = null): bool
{
    if ($value === null || $value === '') {
        return true;
    }
    return classify_value_for_new_type($value, $type, $maxLen, false) !== 'invalid';
}

/**
 * Classify how a single existing value would fare if converted to a new
 * field type/length/required-ness:
 *   'ok'       - converts losslessly, nothing to warn about
 *   'backfill' - value is empty/null and the field is becoming required, so
 *                it will be replaced with the type's default value
 *   'truncate' - value converts but loses precision/characters
 *   'invalid'  - value cannot be represented in the new type at all
 */
function classify_value_for_new_type($value, string $newType, ?int $newMaxLen, bool $newRequired): string
{
    if ($value === null || $value === '') {
        return $newRequired ? 'backfill' : 'ok';
    }
    $value = (string) $value;

    switch ($newType) {
        case 'Int':
            if (!is_numeric($value)) {
                return 'invalid';
            }
            return ((float) $value == (int) (float) $value) ? 'ok' : 'truncate';
        case 'Float':
            return is_numeric($value) ? 'ok' : 'invalid';
        case 'Boolean':
            return in_array($value, ['0', '1'], true) ? 'ok' : 'truncate';
        case 'Date':
            $d = DateTime::createFromFormat('Y-m-d', $value);
            return ($d && $d->format('Y-m-d') === $value) ? 'ok' : 'invalid';
        case 'Email':
            if (!is_valid_email($value)) {
                return 'invalid';
            }
            $len = $newMaxLen ?: 255;
            return strlen($value) > $len ? 'truncate' : 'ok';
        case 'String':
        default:
            $len = $newMaxLen ?: 255;
            return strlen($value) > $len ? 'truncate' : 'ok';
    }
}

/**
 * Scan a native field's real column data and report what would happen if it
 * were converted per $changes (any subset of: type, max_length,
 * is_required). Read-only - used to preview the impact before update_field()
 * actually applies anything.
 */
function compute_field_change_impact(array $entity, array $field, array $changes): array
{
    $newType = $changes['type'] ?? $field['field_type'];
    $newMaxLen = array_key_exists('max_length', $changes) ? $changes['max_length'] : $field['max_length'];
    $newRequired = array_key_exists('is_required', $changes) ? (bool) $changes['is_required'] : (bool) $field['is_required'];

    $table = $entity['table_name'];
    $col = $field['name'];

    $result = [
        'total_rows' => 0,
        'invalid_count' => 0, 'invalid_samples' => [],
        'truncate_count' => 0, 'truncate_samples' => [],
        'backfill_count' => 0, 'backfill_samples' => [],
    ];

    $stmt = db()->query("SELECT id, `$col` AS v FROM `$table`");
    foreach ($stmt as $row) {
        $result['total_rows']++;
        $cls = classify_value_for_new_type($row['v'], $newType, $newMaxLen, $newRequired);
        if ($cls === 'ok') {
            continue;
        }
        $result[$cls . '_count']++;
        if (count($result[$cls . '_samples']) < 5) {
            $result[$cls . '_samples'][] = ['id' => $row['id'], 'value' => $row['v']];
        }
    }

    $result['has_data_loss'] = ($result['invalid_count'] + $result['truncate_count']) > 0;
    $result['requires_confirmation'] = $result['has_data_loss'] || $result['backfill_count'] > 0;
    return $result;
}

/**
 * Update a native field's name/label/type/max_length/default_value/required.
 * $changes may contain any subset of: name, label, type, max_length,
 * default_value, is_required. Only pass $confirmDataLoss = true after the
 * admin has been shown compute_field_change_impact() and explicitly
 * accepted it - otherwise this throws DataLossException when the conversion
 * would alter or destroy existing data.
 */
function update_field(int $entityId, int $fieldId, array $changes, bool $confirmDataLoss = false): void
{
    $entity = get_entity($entityId);
    $field = get_field($fieldId);
    if (!$entity || !$field || (int) $field['entity_id'] !== $entityId) {
        throw new RuntimeException('Field not found');
    }

    $newName = array_key_exists('name', $changes) && trim($changes['name']) !== '' ? slugify($changes['name']) : $field['name'];
    if (in_array($newName, ['id', 'created_at', 'updated_at'], true)) {
        $newName .= '_f';
    }
    $newLabel = array_key_exists('label', $changes) && trim($changes['label']) !== '' ? trim($changes['label']) : $field['label'];
    $newType = array_key_exists('type', $changes) && in_array($changes['type'], FIELD_TYPES, true) ? $changes['type'] : $field['field_type'];
    $newMaxLen = array_key_exists('max_length', $changes) && $changes['max_length'] !== '' && $changes['max_length'] !== null ? (int) $changes['max_length'] : null;
    $newDefault = array_key_exists('default_value', $changes) ? ($changes['default_value'] === '' ? null : $changes['default_value']) : $field['default_value'];
    $newRequired = array_key_exists('is_required', $changes) ? (bool) $changes['is_required'] : (bool) $field['is_required'];

    if ($newName !== $field['name'] && column_name_taken($entityId, $newName, $fieldId, null)) {
        throw new RuntimeException('A field with that name already exists on this entity.');
    }

    $typeOrLenOrReqChanged = $newType !== $field['field_type']
        || (int) ($newMaxLen ?: 255) !== (int) ($field['max_length'] ?: 255)
        || $newRequired !== (bool) $field['is_required'];

    if ($typeOrLenOrReqChanged) {
        $impact = compute_field_change_impact($entity, $field, ['type' => $newType, 'max_length' => $newMaxLen, 'is_required' => $newRequired]);
        if ($impact['requires_confirmation'] && !$confirmDataLoss) {
            throw new DataLossException('Applying this change would alter or remove existing data; confirmation required.', $impact);
        }
    }

    $pdo = db();
    $table = $entity['table_name'];
    $oldName = $field['name'];
    $col = $oldName;

    if ($typeOrLenOrReqChanged) {
        // Sanitize values BEFORE the ALTER, since relying on MySQL's implicit
        // conversion during MODIFY is unreliable across types (it may error
        // instead of truncating, or silently mangle data differently than we
        // told the admin it would).
        if ($newRequired) {
            $backfill = ($newDefault !== null && $newDefault !== '') ? $newDefault : sql_default_for(['field_type' => $newType, 'default_value' => null]);
            $upd = $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE `$col` IS NULL OR `$col` = ''");
            $upd->execute([$backfill]);
        }
        if ($newType !== $field['field_type']) {
            $rows = $pdo->query("SELECT id, `$col` AS v FROM `$table` WHERE `$col` IS NOT NULL AND `$col` <> ''")->fetchAll();
            foreach ($rows as $r) {
                if (classify_value_for_new_type($r['v'], $newType, $newMaxLen, false) === 'invalid') {
                    $replacement = $newRequired ? sql_default_for(['field_type' => $newType, 'default_value' => $newDefault]) : null;
                    $u = $pdo->prepare("UPDATE `$table` SET `$col` = ? WHERE id = ?");
                    $u->execute([$replacement, $r['id']]);
                }
            }
        }
        if (in_array($newType, ['String', 'Email'], true)) {
            $len = $newMaxLen ?: 255;
            $pdo->exec("UPDATE `$table` SET `$col` = LEFT(`$col`, $len) WHERE `$col` IS NOT NULL AND CHAR_LENGTH(`$col`) > $len");
        }
    }

    $colType = sql_type_for($newType, $newMaxLen);
    $nullSql = $newRequired ? 'NOT NULL' : 'NULL';
    if ($newName !== $oldName) {
        $pdo->exec("ALTER TABLE `$table` CHANGE COLUMN `$oldName` `$newName` $colType $nullSql");
    } else {
        $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$newName` $colType $nullSql");
    }

    $stmt = $pdo->prepare('UPDATE entity_fields SET name = ?, label = ?, field_type = ?, max_length = ?, default_value = ?, is_required = ? WHERE id = ?');
    $stmt->execute([$newName, $newLabel, $newType, $newMaxLen, $newDefault, $newRequired ? 1 : 0, $fieldId]);
}

/**
 * Permanently remove a native field: drops its column (and all data stored
 * in it) from the entity's table, then deletes its metadata row. Callers
 * must confirm with the admin before invoking this - it is irreversible.
 */
function delete_field(int $entityId, int $fieldId): void
{
    $entity = get_entity($entityId);
    $field = get_field($fieldId);
    if (!$entity || !$field || (int) $field['entity_id'] !== $entityId) {
        throw new RuntimeException('Field not found');
    }
    $pdo = db();
    $pdo->exec('ALTER TABLE `' . $entity['table_name'] . '` DROP COLUMN `' . $field['name'] . '`');
    $stmt = $pdo->prepare('DELETE FROM entity_fields WHERE id = ?');
    $stmt->execute([$fieldId]);
}

/**
 * Preview the impact of changing a relationship's target entity and/or
 * cardinality. Read-only - used before update_relationship() applies
 * anything.
 */
function compute_relationship_change_impact(array $rel, array $changes): array
{
    $childTable = $rel['child_table'];
    $col = $rel['fk_field'];

    $result = [
        'total_rows' => (int) db()->query("SELECT COUNT(*) FROM `$childTable`")->fetchColumn(),
        'linked_rows' => (int) db()->query("SELECT COUNT(*) FROM `$childTable` WHERE `$col` IS NOT NULL")->fetchColumn(),
        'target_changed' => false,
        'duplicate_count' => 0,
        'duplicate_samples' => [],
    ];

    $newParentId = array_key_exists('parent_entity_id', $changes) ? (int) $changes['parent_entity_id'] : (int) $rel['parent_entity_id'];
    $result['target_changed'] = $newParentId !== (int) $rel['parent_entity_id'];

    $newType = $changes['relationship_type'] ?? $rel['relationship_type'];
    if ($newType === 'one_to_one' && $rel['relationship_type'] !== 'one_to_one') {
        $dupStmt = db()->query("SELECT `$col` AS v, COUNT(*) AS c FROM `$childTable` WHERE `$col` IS NOT NULL GROUP BY `$col` HAVING c > 1 LIMIT 5");
        $dups = $dupStmt->fetchAll();
        $result['duplicate_samples'] = $dups;
        $result['duplicate_count'] = count($dups);
    }

    $result['requires_confirmation'] = ($result['target_changed'] && $result['linked_rows'] > 0) || $result['duplicate_count'] > 0;
    return $result;
}

/**
 * Update a relationship's target entity, FK column name, label, and/or
 * cardinality. $changes may contain any subset of: parent_entity_id,
 * fk_field, label, relationship_type. Only pass $confirmDataLoss = true
 * after the admin has been shown compute_relationship_change_impact() and
 * explicitly accepted it - otherwise this throws DataLossException when the
 * change would clear existing links or reject duplicate values.
 */
function update_relationship(int $relId, array $changes, bool $confirmDataLoss = false): void
{
    $rel = get_relationship($relId);
    if (!$rel) {
        throw new RuntimeException('Relationship not found');
    }

    $newFkField = array_key_exists('fk_field', $changes) && trim($changes['fk_field']) !== '' ? slugify($changes['fk_field']) : $rel['fk_field'];
    $newLabel = array_key_exists('label', $changes) ? trim((string) $changes['label']) : (string) $rel['label'];
    $newType = array_key_exists('relationship_type', $changes) && in_array($changes['relationship_type'], ['one_to_one', 'one_to_many'], true) ? $changes['relationship_type'] : $rel['relationship_type'];
    $newParentId = array_key_exists('parent_entity_id', $changes) ? (int) $changes['parent_entity_id'] : (int) $rel['parent_entity_id'];

    $newParent = get_entity($newParentId);
    if (!$newParent) {
        throw new RuntimeException('Target entity not found');
    }
    if ($newFkField !== $rel['fk_field'] && column_name_taken((int) $rel['child_entity_id'], $newFkField, null, $relId)) {
        throw new RuntimeException('A field/relationship with that name already exists on this entity.');
    }

    $riskyChange = $newType !== $rel['relationship_type'] || $newParentId !== (int) $rel['parent_entity_id'];
    if ($riskyChange) {
        $impact = compute_relationship_change_impact($rel, ['relationship_type' => $newType, 'parent_entity_id' => $newParentId]);
        if ($impact['requires_confirmation'] && !$confirmDataLoss) {
            throw new DataLossException('Applying this change would clear existing links or reject duplicate values; confirmation required.', $impact);
        }
    }

    $pdo = db();
    $childTable = $rel['child_table'];
    $oldCol = $rel['fk_field'];
    $oldConstraint = 'fk_' . $childTable . '_' . $oldCol;

    // Old FK values point at rows of the OLD target table - if the target
    // entity changed, keeping them would silently corrupt lookups, so clear
    // them (the admin was already warned about this via compute_relationship_change_impact()).
    if ($newParentId !== (int) $rel['parent_entity_id']) {
        $pdo->exec("UPDATE `$childTable` SET `$oldCol` = NULL");
    }

    // Drop the old constraint/unique-key before touching the column - MySQL
    // won't allow renaming or retyping a column that's still constrained.
    try { $pdo->exec("ALTER TABLE `$childTable` DROP FOREIGN KEY `$oldConstraint`"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE `$childTable` DROP INDEX `uniq_$oldConstraint`"); } catch (Throwable $e) {}

    if ($newFkField !== $oldCol) {
        $pdo->exec("ALTER TABLE `$childTable` CHANGE COLUMN `$oldCol` `$newFkField` INT NULL");
    }

    // Switching to one_to_one: existing duplicate values would violate the
    // new UNIQUE key. Keep the earliest row's value, null out the rest (a
    // portable MIN()-based dedup - avoids relying on window functions, which
    // some shared-hosting MySQL versions don't support).
    if ($newType === 'one_to_one') {
        $pdo->exec("
            UPDATE `$childTable` t
            JOIN (
                SELECT `$newFkField` AS fkval, MIN(id) AS keep_id
                FROM `$childTable`
                WHERE `$newFkField` IS NOT NULL
                GROUP BY `$newFkField`
                HAVING COUNT(*) > 1
            ) d ON d.fkval = t.`$newFkField` AND t.id <> d.keep_id
            SET t.`$newFkField` = NULL
        ");
    }

    $parentTable = $newParent['table_name'];
    $newConstraint = 'fk_' . $childTable . '_' . $newFkField;
    $pdo->exec("ALTER TABLE `$childTable` ADD CONSTRAINT `$newConstraint` FOREIGN KEY (`$newFkField`) REFERENCES `$parentTable`(`id`) ON DELETE SET NULL");
    if ($newType === 'one_to_one') {
        $pdo->exec("ALTER TABLE `$childTable` ADD UNIQUE KEY `uniq_$newConstraint` (`$newFkField`)");
    }

    $stmt = $pdo->prepare('UPDATE entity_relationships SET parent_entity_id = ?, fk_field = ?, relationship_type = ?, label = ? WHERE id = ?');
    $stmt->execute([$newParentId, $newFkField, $newType, $newLabel !== '' ? $newLabel : null, $relId]);
}

/**
 * Permanently remove a relationship: drops the FK constraint/unique-key and
 * the FK column itself (destroying the link data) from the child table,
 * then deletes its metadata row. The parent entity and its data are never
 * touched. Callers must confirm with the admin before invoking this.
 */
function delete_relationship(int $relId): void
{
    $rel = get_relationship($relId);
    if (!$rel) {
        throw new RuntimeException('Relationship not found');
    }
    $pdo = db();
    $childTable = $rel['child_table'];
    $col = $rel['fk_field'];
    $constraintName = 'fk_' . $childTable . '_' . $col;
    try { $pdo->exec("ALTER TABLE `$childTable` DROP FOREIGN KEY `$constraintName`"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE `$childTable` DROP COLUMN `$col`"); } catch (Throwable $e) {}
    $stmt = $pdo->prepare('DELETE FROM entity_relationships WHERE id = ?');
    $stmt->execute([$relId]);
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
        case 'Date':
            return date('Y-m-d');
        default:
            return '';
    }
}

/**
 * Turn the list view's ?f_<field>=... query params into structured filter
 * specs for entity_list_rows()'s $advancedFilters. Recognizes, per field:
 *   - String/Email:      f_<name>            -> substring match (LIKE)
 *   - Int/Float:         f_<name>            -> exact match
 *   - Boolean:           f_<name>            -> '1'/'0' exact match
 *   - Date:              f_<name>            -> exact date
 *                         f_<name>_from/_to  -> date range (either or both)
 *   - Relationship (FK): f_<fk_field>        -> exact row id
 */
function build_entity_filters_from_request(array $displayFields, array $params): array
{
    $filters = [];
    foreach ($displayFields as $item) {
        if ($item['kind'] === 'field') {
            $name = $item['name'];
            $key = 'f_' . $name;
            switch ($item['field_type']) {
                case 'Date':
                    if (!empty($params[$key])) {
                        $filters[] = ['column' => $name, 'op' => 'eq', 'value' => $params[$key]];
                    }
                    if (!empty($params[$key . '_from'])) {
                        $filters[] = ['column' => $name, 'op' => 'gte', 'value' => $params[$key . '_from']];
                    }
                    if (!empty($params[$key . '_to'])) {
                        $filters[] = ['column' => $name, 'op' => 'lte', 'value' => $params[$key . '_to']];
                    }
                    break;
                case 'Boolean':
                    if (isset($params[$key]) && $params[$key] !== '') {
                        $filters[] = ['column' => $name, 'op' => 'eq', 'value' => $params[$key] === '1' ? 1 : 0];
                    }
                    break;
                case 'Int':
                case 'Float':
                    if (isset($params[$key]) && $params[$key] !== '') {
                        $filters[] = ['column' => $name, 'op' => 'eq', 'value' => $params[$key]];
                    }
                    break;
                default: // String, Email
                    if (isset($params[$key]) && $params[$key] !== '') {
                        $filters[] = ['column' => $name, 'op' => 'like', 'value' => $params[$key]];
                    }
            }
        } else {
            $key = 'f_' . $item['fk_field'];
            if (isset($params[$key]) && $params[$key] !== '') {
                $filters[] = ['column' => $item['fk_field'], 'op' => 'eq', 'value' => (int) $params[$key]];
            }
        }
    }
    return $filters;
}

function entity_list_rows(array $entity, array $fields, array $filters = [], int $page = 1, int $perPage = ROWS_PER_PAGE, array $advancedFilters = []): array
{
    $table = $entity['table_name'];
    $where = [];
    $params = [];
    foreach ($filters as $col => $val) {
        $where[] = "`" . sanitize_identifier($col) . "` = ?";
        $params[] = $val;
    }
    foreach ($advancedFilters as $f) {
        $col = sanitize_identifier($f['column']);
        switch ($f['op']) {
            case 'like':
                $where[] = "`$col` LIKE ?";
                $params[] = '%' . $f['value'] . '%';
                break;
            case 'gte':
                $where[] = "`$col` >= ?";
                $params[] = $f['value'];
                break;
            case 'lte':
                $where[] = "`$col` <= ?";
                $params[] = $f['value'];
                break;
            case 'eq':
            default:
                $where[] = "`$col` = ?";
                $params[] = $f['value'];
        }
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

// ---------------------------------------------------------------------
// Field-enable conditions ("enable this field only if...")
// ---------------------------------------------------------------------

/**
 * Parse a source-selector string built by admin/field_conditions.php's form
 * into [source_type, source_field_id, source_relationship_id, via_relationship_id].
 * Formats: "own_field:<field_id>", "own_relationship:<rel_id>",
 * "related_field:<via_rel_id>:<field_id>".
 */
function parse_condition_source(string $source): array
{
    $parts = explode(':', $source);
    if (($parts[0] ?? '') === 'own_field' && isset($parts[1])) {
        return ['own_field', (int) $parts[1], null, null];
    }
    if (($parts[0] ?? '') === 'own_relationship' && isset($parts[1])) {
        return ['own_relationship', null, (int) $parts[1], null];
    }
    if (($parts[0] ?? '') === 'related_field' && isset($parts[1], $parts[2])) {
        return ['related_field', (int) $parts[2], null, (int) $parts[1]];
    }
    return [null, null, null, null];
}

/**
 * All possible condition sources for building the admin UI's source dropdown:
 * this entity's own fields, this entity's own relationships (their FK value),
 * and - one hop out - the fields of each entity this one has a relationship
 * to. Optionally excludes one field/relationship (the condition's own target,
 * so a field can't be made to depend on itself).
 */
function get_condition_source_options(array $entity, ?int $excludeFieldId = null, ?int $excludeRelId = null): array
{
    $options = ['own_fields' => [], 'own_relationships' => [], 'related' => []];

    foreach (get_entity_fields($entity['id']) as $f) {
        if ((int) $f['id'] === $excludeFieldId) {
            continue;
        }
        $options['own_fields'][] = ['value' => 'own_field:' . $f['id'], 'label' => $f['label'], 'field_type' => $f['field_type']];
    }

    foreach (get_relationships_as_child($entity['id']) as $r) {
        if ((int) $r['id'] !== $excludeRelId) {
            $options['own_relationships'][] = ['value' => 'own_relationship:' . $r['id'], 'label' => $r['label'] ?: $r['parent_label']];
        }
        $parent = get_entity((int) $r['parent_entity_id']);
        if (!$parent) {
            continue;
        }
        $group = [];
        foreach (get_entity_fields($parent['id']) as $pf) {
            $group[] = ['value' => 'related_field:' . $r['id'] . ':' . $pf['id'], 'label' => $pf['label'], 'field_type' => $pf['field_type']];
        }
        if ($group) {
            $options['related'][] = ['relationship_label' => ($r['label'] ?: $r['parent_label']), 'fields' => $group];
        }
    }

    return $options;
}

/** Enrich a raw field_conditions row with human labels and the actual column names needed to evaluate it. */
function enrich_condition_row(array $r): array
{
    $out = [
        'id' => (int) $r['id'],
        'source_type' => $r['source_type'],
        'operator' => $r['operator'],
        'compare_value' => $r['compare_value'],
        'source_field_name' => null,
        'source_fk_field' => null,
        'via_fk_field' => null,
        'via_relationship_id' => $r['via_relationship_id'] !== null ? (int) $r['via_relationship_id'] : null,
        'field_type' => 'String',
        'label' => '',
        // Reconstructed "own_field:ID" / "own_relationship:ID" / "related_field:RELID:FIELDID"
        // selector string, matching parse_condition_source() - used to pre-select the admin UI's dropdown.
        'source_value' => '',
    ];

    if ($r['source_type'] === 'own_field') {
        $f = get_field((int) $r['source_field_id']);
        if ($f) {
            $out['source_field_name'] = $f['name'];
            $out['field_type'] = $f['field_type'];
            $out['label'] = $f['label'];
        }
        $out['source_value'] = 'own_field:' . (int) $r['source_field_id'];
    } elseif ($r['source_type'] === 'own_relationship') {
        $rel = get_relationship((int) $r['source_relationship_id']);
        if ($rel) {
            $out['source_fk_field'] = $rel['fk_field'];
            $out['field_type'] = 'Int';
            $out['label'] = $rel['label'] ?: $rel['parent_label'];
        }
        $out['source_value'] = 'own_relationship:' . (int) $r['source_relationship_id'];
    } elseif ($r['source_type'] === 'related_field') {
        $via = get_relationship((int) $r['via_relationship_id']);
        $f = get_field((int) $r['source_field_id']);
        if ($via) {
            $out['via_fk_field'] = $via['fk_field'];
        }
        if ($f) {
            $out['source_field_name'] = $f['name'];
            $out['field_type'] = $f['field_type'];
            $out['label'] = ($via ? (($via['label'] ?: $via['parent_label']) . ': ') : '') . $f['label'];
        }
        $out['source_value'] = 'related_field:' . (int) $r['via_relationship_id'] . ':' . (int) $r['source_field_id'];
    }

    return $out;
}

/**
 * A target's condition tree: a list of OR-groups, each an ordered list of
 * AND-conditions - i.e. "(c1 AND c2) OR (c3)". An empty list means the field
 * has no conditions defined and is always enabled.
 */
function get_field_conditions(?int $targetFieldId, ?int $targetRelationshipId): array
{
    $pdo = db();
    if ($targetFieldId) {
        $stmt = $pdo->prepare('SELECT * FROM field_conditions WHERE target_field_id = ? ORDER BY group_index ASC, sort_order ASC, id ASC');
        $stmt->execute([$targetFieldId]);
    } elseif ($targetRelationshipId) {
        $stmt = $pdo->prepare('SELECT * FROM field_conditions WHERE target_relationship_id = ? ORDER BY group_index ASC, sort_order ASC, id ASC');
        $stmt->execute([$targetRelationshipId]);
    } else {
        return [];
    }

    $groups = [];
    foreach ($stmt->fetchAll() as $r) {
        $groups[(int) $r['group_index']][] = enrich_condition_row($r);
    }
    ksort($groups);
    return array_values($groups);
}

/**
 * Replace the entire condition tree for one target (field or relationship)
 * with $groups, built from the admin form's nested groups[G][C][source|operator|value]
 * arrays. Empty conditions/groups are dropped silently.
 */
function save_field_conditions(?int $targetFieldId, ?int $targetRelationshipId, array $groups): void
{
    $pdo = db();
    if ($targetFieldId) {
        $del = $pdo->prepare('DELETE FROM field_conditions WHERE target_field_id = ?');
        $del->execute([$targetFieldId]);
    } elseif ($targetRelationshipId) {
        $del = $pdo->prepare('DELETE FROM field_conditions WHERE target_relationship_id = ?');
        $del->execute([$targetRelationshipId]);
    } else {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO field_conditions
        (target_field_id, target_relationship_id, group_index, sort_order, source_type, source_field_id, source_relationship_id, via_relationship_id, operator, compare_value)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $groupIndex = 0;
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $sortOrder = 0;
        $wroteAny = false;
        foreach ($group as $cond) {
            $source = trim($cond['source'] ?? '');
            $operator = $cond['operator'] ?? '';
            $value = $cond['value'] ?? null;
            if ($source === '' || !in_array($operator, CONDITION_OPERATORS, true)) {
                continue;
            }
            [$sourceType, $sourceFieldId, $sourceRelationshipId, $viaRelationshipId] = parse_condition_source($source);
            if (!$sourceType) {
                continue;
            }
            $insert->execute([
                $targetFieldId, $targetRelationshipId, $groupIndex, $sortOrder,
                $sourceType, $sourceFieldId, $sourceRelationshipId, $viaRelationshipId,
                $operator, ($value === '' || $value === null ? null : $value),
            ]);
            $sortOrder++;
            $wroteAny = true;
        }
        if ($wroteAny) {
            $groupIndex++;
        }
    }
}

/** Preload {row_id: {field_name: value}} for a related entity, for the fields conditions actually need. */
function get_related_field_lookup(int $relationshipId, array $fieldNames): array
{
    $fieldNames = array_values(array_unique(array_filter($fieldNames)));
    if (!$fieldNames) {
        return [];
    }
    $rel = get_relationship($relationshipId);
    if (!$rel) {
        return [];
    }
    $cols = array_unique(array_merge(['id'], $fieldNames));
    $colsSql = implode(', ', array_map(function ($c) { return '`' . sanitize_identifier($c) . '`'; }, $cols));
    $stmt = db()->query("SELECT $colsSql FROM `{$rel['parent_table']}` LIMIT 1000");
    $out = [];
    foreach ($stmt as $row) {
        $out[$row['id']] = $row;
    }
    return $out;
}

/**
 * Gather everything needed to enable/disable $displayFields on a form:
 * ['targets' => ['field:<name>' | 'rel:<fk_field>' => groups, ...],
 *  'related_lookups' => ['<fk_field>' => [row_id => [field_name => value]]]].
 * Only fields/relationships that actually have conditions appear in 'targets'.
 */
function build_condition_context(array $displayFields): array
{
    $targets = [];
    $neededByRel = [];

    foreach ($displayFields as $item) {
        $targetFieldId = $item['kind'] === 'field' ? (int) $item['id'] : null;
        $targetRelId = $item['kind'] === 'relationship' ? (int) $item['id'] : null;
        $groups = get_field_conditions($targetFieldId, $targetRelId);
        if (!$groups) {
            continue;
        }
        $key = $item['kind'] === 'field' ? ('field:' . $item['name']) : ('rel:' . $item['fk_field']);
        $targets[$key] = $groups;
        foreach ($groups as $group) {
            foreach ($group as $cond) {
                if ($cond['source_type'] === 'related_field' && $cond['via_relationship_id'] && $cond['source_field_name']) {
                    $neededByRel[$cond['via_relationship_id']][] = $cond['source_field_name'];
                }
            }
        }
    }

    $relatedLookups = [];
    foreach ($neededByRel as $relId => $fieldNames) {
        $rel = get_relationship((int) $relId);
        if (!$rel) {
            continue;
        }
        $relatedLookups[$rel['fk_field']] = get_related_field_lookup((int) $relId, $fieldNames);
    }

    return ['targets' => $targets, 'related_lookups' => $relatedLookups];
}

/**
 * Resolve a single condition's left-hand value from $row (a merged
 * name=>value array covering both native columns and FK columns - exactly
 * what entity_get_row() returns, or $data + $fkValues on a fresh submission)
 * and, for related_field conditions, $relatedLookups (see build_condition_context()).
 */
function resolve_condition_value(array $cond, array $row, array $relatedLookups)
{
    if ($cond['source_type'] === 'own_field') {
        return $cond['source_field_name'] !== null ? ($row[$cond['source_field_name']] ?? null) : null;
    }
    if ($cond['source_type'] === 'own_relationship') {
        return $cond['source_fk_field'] !== null ? ($row[$cond['source_fk_field']] ?? null) : null;
    }
    if ($cond['source_type'] === 'related_field' && $cond['via_fk_field'] !== null) {
        $parentId = $row[$cond['via_fk_field']] ?? null;
        if (!$parentId) {
            return null;
        }
        $prow = $relatedLookups[$cond['via_fk_field']][$parentId] ?? null;
        return $prow[$cond['source_field_name']] ?? null;
    }
    return null;
}

/**
 * True if $value satisfies $operator/$compareValue. Empty/null values only
 * ever satisfy is_null/is_not_null - every other operator treats an empty
 * left-hand side as "doesn't match". Numeric-looking values are compared
 * numerically; Date-typed values are compared as dates; everything else
 * falls back to a case-insensitive string comparison. Mirrored in
 * assets/js/app.js's EntityConditions.passes() for live client-side toggling.
 */
function evaluate_condition($value, string $operator, ?string $compareValue, string $fieldType = 'String'): bool
{
    $isEmpty = ($value === null || $value === '');
    if ($operator === 'is_null') {
        return $isEmpty;
    }
    if ($operator === 'is_not_null') {
        return !$isEmpty;
    }
    if ($isEmpty || $compareValue === null || $compareValue === '') {
        return false;
    }

    if ($fieldType === 'Date') {
        $a = strtotime((string) $value);
        $b = strtotime((string) $compareValue);
        if ($a !== false && $b !== false) {
            switch ($operator) {
                case 'equals': return $a === $b;
                case 'not_equals': return $a !== $b;
                case 'greater_than': return $a > $b;
                case 'greater_or_equal': return $a >= $b;
                case 'less_than': return $a < $b;
                case 'less_or_equal': return $a <= $b;
                case 'contains': return false;
            }
        }
    }

    $bothNumeric = is_numeric($value) && is_numeric($compareValue);
    switch ($operator) {
        case 'equals':
            return $bothNumeric ? ((float) $value == (float) $compareValue) : (strtolower((string) $value) === strtolower((string) $compareValue));
        case 'not_equals':
            return $bothNumeric ? ((float) $value != (float) $compareValue) : (strtolower((string) $value) !== strtolower((string) $compareValue));
        case 'greater_than':
            return $bothNumeric && (float) $value > (float) $compareValue;
        case 'greater_or_equal':
            return $bothNumeric && (float) $value >= (float) $compareValue;
        case 'less_than':
            return $bothNumeric && (float) $value < (float) $compareValue;
        case 'less_or_equal':
            return $bothNumeric && (float) $value <= (float) $compareValue;
        case 'contains':
            return stripos((string) $value, (string) $compareValue) !== false;
        default:
            return false;
    }
}

/** Evaluate a full OR-of-ANDs condition tree. No groups at all => always enabled. */
function conditions_pass(array $groups, callable $resolver): bool
{
    if (!$groups) {
        return true;
    }
    foreach ($groups as $group) {
        $allPass = true;
        foreach ($group as $cond) {
            $value = $resolver($cond);
            if (!evaluate_condition($value, $cond['operator'], $cond['compare_value'], $cond['field_type'])) {
                $allPass = false;
                break;
            }
        }
        if ($allPass) {
            return true;
        }
    }
    return false;
}

/** Convenience wrapper: is this target's condition tree satisfied by $row? */
function field_is_enabled(array $groups, array $row, array $relatedLookups): bool
{
    return conditions_pass($groups, function ($cond) use ($row, $relatedLookups) {
        return resolve_condition_value($cond, $row, $relatedLookups);
    });
}

/** Shrink build_condition_context()'s output down to what the browser needs (see assets/js/app.js). */
function build_js_conditions_payload(array $ctx): array
{
    $targets = [];
    foreach ($ctx['targets'] as $key => $groups) {
        $jsGroups = [];
        foreach ($groups as $group) {
            $jsConds = [];
            foreach ($group as $cond) {
                if ($cond['source_type'] === 'own_field') {
                    $inputName = $cond['source_field_name'];
                } elseif ($cond['source_type'] === 'own_relationship') {
                    $inputName = $cond['source_fk_field'];
                } else {
                    $inputName = $cond['via_fk_field'];
                }
                $jsConds[] = [
                    'source_type' => $cond['source_type'],
                    'input_name' => $inputName,
                    'related_field_name' => $cond['source_type'] === 'related_field' ? $cond['source_field_name'] : null,
                    'operator' => $cond['operator'],
                    'compare_value' => $cond['compare_value'],
                    'field_type' => $cond['field_type'],
                ];
            }
            $jsGroups[] = $jsConds;
        }
        $targets[] = ['key' => $key, 'groups' => $jsGroups];
    }
    return ['targets' => $targets, 'relatedLookups' => $ctx['related_lookups']];
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
