<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/entity_engine.php';

if (!is_installed()) { redirect('install.php'); }
require_login();

$entityName = $_GET['entity'] ?? $_POST['entity'] ?? '';
$entity = get_entity_by_name($entityName);
if (!$entity) {
    flash('error', 'Unknown entity.');
    redirect('dashboard.php');
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'create';
$id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) ? (int) $_POST['id'] : null);
$isEdit = $action === 'edit' && $id;

if ($isEdit && !has_permission($entity['id'], 'edit')) {
    http_response_code(403);
    die(t('permission_denied_message'));
}
if (!$isEdit && !has_permission($entity['id'], 'create')) {
    http_response_code(403);
    die(t('permission_denied_message'));
}

$fields = get_entity_fields($entity['id']);
$parentRelationships = get_relationships_as_child($entity['id']); // FK columns this entity holds
$displayFields = merge_display_fields($fields, $parentRelationships); // both, in the admin's defined order

$row = $isEdit ? entity_get_row($entity, $id) : [];
if ($isEdit && !$row) {
    flash('error', 'Row not found.');
    redirect('entity.php?entity=' . urlencode($entity['name']));
}

// Context: if arriving from a parent record ("add child"), pre-fill & lock that FK.
$parentField = $_GET['parent_field'] ?? $_POST['parent_field'] ?? null;
$parentId = isset($_GET['parent_id']) ? (int) $_GET['parent_id'] : (isset($_POST['parent_id']) ? (int) $_POST['parent_id'] : null);
$parentEntityName = $_GET['parent_entity'] ?? $_POST['parent_entity'] ?? null;

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $data = [];
    foreach ($fields as $f) {
        $data[$f['name']] = $_POST['field'][$f['name']] ?? null;
        if ($f['field_type'] === 'Boolean') {
            $data[$f['name']] = isset($_POST['field'][$f['name']]) ? 1 : 0;
        } elseif ($f['field_type'] === 'Email' && $data[$f['name']] !== null) {
            // Trim before validating so incidental leading/trailing spaces
            // (e.g. from copy-paste) don't fail FILTER_VALIDATE_EMAIL, which
            // rejects surrounding whitespace even though it's harmless here.
            $data[$f['name']] = trim($data[$f['name']]);
        }
    }
    // Required-field validation
    foreach ($fields as $f) {
        if ($f['is_required'] && ($data[$f['name']] === null || $data[$f['name']] === '')) {
            $error = $f['label'] . ' is required.';
        }
        if ($f['field_type'] === 'Email' && !empty($data[$f['name']]) && !is_valid_email($data[$f['name']])) {
            $error = sprintf(t('invalid_email_field'), $f['label']);
        }
    }

    $fkValues = [];
    foreach ($parentRelationships as $rel) {
        $col = $rel['fk_field'];
        if ($parentField === $col && $parentId) {
            $fkValues[$col] = $parentId;
        } else {
            $fkValues[$col] = $_POST['fk'][$col] ?? null;
        }
    }

    if (!$error) {
        if ($isEdit) {
            entity_update_row($entity, $fields, $id, $data, $fkValues);
            flash('success', t('row_updated'));
        } else {
            $id = entity_insert_row($entity, $fields, $data, $fkValues);
            flash('success', t('row_created'));
        }
        $backQs = '';
        if ($parentField && $parentId) {
            $backQs = '&parent_field=' . urlencode($parentField) . '&parent_id=' . $parentId . '&parent_entity=' . urlencode($parentEntityName ?? '');
        }
        redirect('entity_view.php?entity=' . urlencode($entity['name']) . '&id=' . $id . $backQs);
    }
    $row = $data; // re-populate the form with submitted values on error
}

$pageTitle = ($isEdit ? t('edit_row') : t('add_row')) . ' — ' . $entity['label'];
include __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1><?= e($pageTitle) ?></h1></div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="entity" value="<?= e($entity['name']) ?>">
    <input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'create' ?>">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
    <?php if ($parentField): ?>
      <input type="hidden" name="parent_field" value="<?= e($parentField) ?>">
      <input type="hidden" name="parent_id" value="<?= (int) $parentId ?>">
      <input type="hidden" name="parent_entity" value="<?= e($parentEntityName) ?>">
    <?php endif; ?>

    <?php foreach ($displayFields as $item): ?>
      <?php if ($item['kind'] === 'field'): $f = $item; $val = $row[$f['name']] ?? $f['default_value']; ?>
        <label>
          <?= e($f['label']) ?><?= $f['is_required'] ? ' *' : '' ?>
          <?php if ($f['field_type'] === 'Boolean'): ?>
            <input type="checkbox" name="field[<?= e($f['name']) ?>]" value="1" <?= !empty($val) ? 'checked' : '' ?>>
          <?php elseif ($f['field_type'] === 'Date'): ?>
            <input type="date" name="field[<?= e($f['name']) ?>]" value="<?= e((string) $val) ?>" <?= $f['is_required'] ? 'required' : '' ?>>
          <?php elseif ($f['field_type'] === 'Int'): ?>
            <input type="number" step="1" name="field[<?= e($f['name']) ?>]" value="<?= e((string) $val) ?>" <?= $f['is_required'] ? 'required' : '' ?>>
          <?php elseif ($f['field_type'] === 'Float'): ?>
            <input type="number" step="any" name="field[<?= e($f['name']) ?>]" value="<?= e((string) $val) ?>" <?= $f['is_required'] ? 'required' : '' ?>>
          <?php elseif ($f['field_type'] === 'Email'): ?>
            <input type="email" name="field[<?= e($f['name']) ?>]" value="<?= e((string) $val) ?>"
                   maxlength="<?= (int) ($f['max_length'] ?: 255) ?>" pattern="[^@\s]+@[^@\s]+\.[^@\s]+"
                   placeholder="name@example.com" <?= $f['is_required'] ? 'required' : '' ?>>
          <?php else: ?>
            <input type="text" name="field[<?= e($f['name']) ?>]" value="<?= e((string) $val) ?>" maxlength="<?= (int) ($f['max_length'] ?: 255) ?>" <?= $f['is_required'] ? 'required' : '' ?>>
          <?php endif; ?>
        </label>
      <?php else: $rel = $item; $col = $rel['fk_field']; ?>
        <?php if ($parentField === $col && $parentId): ?>
          <p class="text-muted"><?= e($rel['label'] ?: $rel['parent_label']) ?>: <strong>#<?= (int) $parentId ?></strong> (<?= e(t('record')) ?> <?= e(t('yes')) ?>)</p>
        <?php else: ?>
          <?php $parentEnt = get_entity((int) $rel['parent_entity_id']); $options = get_entity_options($parentEnt); ?>
          <label><?= e($rel['label'] ?: $rel['parent_label']) ?>
            <select name="fk[<?= e($col) ?>]">
              <option value="">--</option>
              <?php foreach ($options as $oid => $label): ?>
                <option value="<?= (int) $oid ?>" <?= isset($row[$col]) && (int) $row[$col] === (int) $oid ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
      <?php endif; ?>
    <?php endforeach; ?>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= e(t('save')) ?></button>
      <a class="btn btn-secondary" href="entity.php?entity=<?= e($entity['name']) ?>"><?= e(t('cancel')) ?></a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
