<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$entityId = (int) ($_GET['entity_id'] ?? $_POST['entity_id'] ?? 0);
$entity = $entityId ? get_entity($entityId) : null;
if (!$entity) {
    flash('error', 'Entity not found.');
    redirect('entities.php');
}

$fieldId = isset($_GET['field_id']) ? (int) $_GET['field_id'] : (isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0);
$relId = isset($_GET['rel_id']) ? (int) $_GET['rel_id'] : (isset($_POST['rel_id']) ? (int) $_POST['rel_id'] : 0);

$field = $fieldId ? get_field($fieldId) : null;
$rel = $relId ? get_relationship($relId) : null;
if ($field && (int) $field['entity_id'] !== $entityId) { $field = null; }
if ($rel && (int) $rel['child_entity_id'] !== $entityId) { $rel = null; }

if (!$field && !$rel) {
    flash('error', 'Field not found.');
    redirect('entity_edit.php?id=' . $entityId);
}

$fieldTypes = FIELD_TYPES;
$referenceableEntities = array_values(array_filter(get_all_entities(), function ($e) use ($entityId) {
    return (int) $e['id'] !== $entityId;
}));

$error = null;
$impact = null;
$pending = null; // the change set we tried to apply, re-shown to the admin when confirmation is needed

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $confirm = !empty($_POST['confirm_data_loss']);

    if ($field) {
        $changes = [
            'name' => trim($_POST['name'] ?? ''),
            'label' => trim($_POST['label'] ?? ''),
            'type' => $_POST['type'] ?? $field['field_type'],
            'max_length' => $_POST['max_length'] ?? null,
            'default_value' => $_POST['default_value'] ?? '',
            'is_required' => !empty($_POST['is_required']),
        ];
        $maxLenForCheck = $changes['max_length'] !== '' && $changes['max_length'] !== null ? (int) $changes['max_length'] : null;
        if (!is_valid_value_for_type($changes['type'], $changes['default_value'], $maxLenForCheck)) {
            // Plain validation error (not a data-loss confirmation issue) -
            // the admin typed a default value that isn't valid for the new type.
            $error = sprintf(t('field_default_invalid_for_type'), $changes['type']);
        } else {
            try {
                update_field($entityId, $fieldId, $changes, $confirm);
                flash('success', t('field_updated'));
                redirect('entity_edit.php?id=' . $entityId);
            } catch (DataLossException $e) {
                $impact = $e->getImpact();
                $pending = $changes;
            } catch (Throwable $e) {
                $error = 'Could not update field: ' . $e->getMessage();
            }
        }
    } else {
        $changes = [
            'fk_field' => trim($_POST['fk_field'] ?? ''),
            'label' => trim($_POST['label'] ?? ''),
            'parent_entity_id' => (int) ($_POST['parent_entity_id'] ?? 0),
            'relationship_type' => $_POST['relationship_type'] ?? $rel['relationship_type'],
        ];
        try {
            update_relationship($relId, $changes, $confirm);
            flash('success', t('field_updated'));
            redirect('entity_edit.php?id=' . $entityId);
        } catch (DataLossException $e) {
            $impact = $e->getImpact();
            $pending = $changes;
        } catch (Throwable $e) {
            $error = 'Could not update relationship: ' . $e->getMessage();
        }
    }
}

$pageTitle = t('edit_field') . ': ' . ($field ? $field['label'] : ($rel['label'] ?: $rel['parent_label']));
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h1><?= e($pageTitle) ?></h1>
  <a class="btn btn-secondary" href="entity_edit.php?id=<?= (int) $entityId ?>"><?= e(t('back')) ?></a>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($impact): ?>
  <div class="card">
    <div class="alert alert-error">
      <p><strong><?= e(t('field_change_risk_title')) ?></strong></p>
      <p><?= e(t('field_change_risk_warning')) ?></p>
      <ul>
        <?php if (!empty($impact['invalid_count'])): ?>
          <li><?= sprintf(e(t('field_change_invalid_rows')), '<strong>' . (int) $impact['invalid_count'] . '</strong>') ?>
            <?php if ($impact['invalid_samples']): ?>
              <ul><?php foreach ($impact['invalid_samples'] as $s): ?><li>#<?= (int) $s['id'] ?>: <code><?= e((string) $s['value']) ?></code></li><?php endforeach; ?></ul>
            <?php endif; ?>
          </li>
        <?php endif; ?>
        <?php if (!empty($impact['truncate_count'])): ?>
          <li><?= sprintf(e(t('field_change_truncate_rows')), '<strong>' . (int) $impact['truncate_count'] . '</strong>') ?>
            <?php if ($impact['truncate_samples']): ?>
              <ul><?php foreach ($impact['truncate_samples'] as $s): ?><li>#<?= (int) $s['id'] ?>: <code><?= e((string) $s['value']) ?></code></li><?php endforeach; ?></ul>
            <?php endif; ?>
          </li>
        <?php endif; ?>
        <?php if (!empty($impact['backfill_count'])): ?>
          <li><?= sprintf(e(t('field_change_backfill_rows')), '<strong>' . (int) $impact['backfill_count'] . '</strong>') ?></li>
        <?php endif; ?>
        <?php if (!empty($impact['target_changed']) && !empty($impact['linked_rows'])): ?>
          <li><?= sprintf(e(t('field_change_relink_rows')), '<strong>' . (int) $impact['linked_rows'] . '</strong>') ?></li>
        <?php endif; ?>
        <?php if (!empty($impact['duplicate_count'])): ?>
          <li><?= sprintf(e(t('field_change_duplicate_rows')), '<strong>' . (int) $impact['duplicate_count'] . '</strong>') ?></li>
        <?php endif; ?>
      </ul>
    </div>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="entity_id" value="<?= (int) $entityId ?>">
      <?php if ($field): ?>
        <input type="hidden" name="field_id" value="<?= (int) $fieldId ?>">
        <input type="hidden" name="name" value="<?= e($pending['name']) ?>">
        <input type="hidden" name="label" value="<?= e($pending['label']) ?>">
        <input type="hidden" name="type" value="<?= e($pending['type']) ?>">
        <input type="hidden" name="max_length" value="<?= e((string) $pending['max_length']) ?>">
        <input type="hidden" name="default_value" value="<?= e((string) $pending['default_value']) ?>">
        <?php if ($pending['is_required']): ?><input type="hidden" name="is_required" value="1"><?php endif; ?>
      <?php else: ?>
        <input type="hidden" name="rel_id" value="<?= (int) $relId ?>">
        <input type="hidden" name="fk_field" value="<?= e($pending['fk_field']) ?>">
        <input type="hidden" name="label" value="<?= e($pending['label']) ?>">
        <input type="hidden" name="parent_entity_id" value="<?= (int) $pending['parent_entity_id'] ?>">
        <input type="hidden" name="relationship_type" value="<?= e($pending['relationship_type']) ?>">
      <?php endif; ?>
      <label><input type="checkbox" name="confirm_data_loss" value="1" required> <?= e(t('confirm_data_loss_checkbox')) ?></label>
      <div class="form-actions">
        <button type="submit" class="btn btn-danger"><?= e(t('apply_changes')) ?></button>
        <a class="btn btn-secondary" href="entity_edit.php?id=<?= (int) $entityId ?>"><?= e(t('cancel')) ?></a>
      </div>
    </form>
  </div>
<?php elseif ($field): ?>
  <div class="card">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="entity_id" value="<?= (int) $entityId ?>">
      <input type="hidden" name="field_id" value="<?= (int) $fieldId ?>">
      <label><?= e(t('field_name')) ?><input type="text" name="name" required value="<?= e($field['name']) ?>"></label>
      <label><?= e(t('field_label')) ?><input type="text" name="label" required value="<?= e($field['label']) ?>"></label>
      <label><?= e(t('field_type')) ?>
        <select name="type">
          <?php foreach ($fieldTypes as $ft): ?><option value="<?= e($ft) ?>" <?= $field['field_type'] === $ft ? 'selected' : '' ?>><?= e($ft) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><?= e(t('max_length')) ?><input type="number" name="max_length" min="1" value="<?= e((string) $field['max_length']) ?>"></label>
      <label><?= e(t('default_value')) ?><input type="text" name="default_value" value="<?= e((string) $field['default_value']) ?>"></label>
      <label><input type="checkbox" name="is_required" value="1" <?= $field['is_required'] ? 'checked' : '' ?>> <?= e(t('required')) ?></label>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= e(t('save')) ?></button>
        <a class="btn btn-secondary" href="entity_edit.php?id=<?= (int) $entityId ?>"><?= e(t('cancel')) ?></a>
      </div>
    </form>
  </div>
<?php else: ?>
  <div class="card">
    <p class="text-muted"><?= e(t('field_type')) ?>: <span class="badge">&rarr; <?= e($rel['parent_label']) ?></span></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="entity_id" value="<?= (int) $entityId ?>">
      <input type="hidden" name="rel_id" value="<?= (int) $relId ?>">
      <label><?= e(t('fk_field_name')) ?><input type="text" name="fk_field" required value="<?= e($rel['fk_field']) ?>"></label>
      <label><?= e(t('field_label')) ?><input type="text" name="label" value="<?= e((string) $rel['label']) ?>"></label>
      <label><?= e(t('target_entity')) ?>
        <select name="parent_entity_id">
          <?php foreach ($referenceableEntities as $re): ?><option value="<?= (int) $re['id'] ?>" <?= (int) $re['id'] === (int) $rel['parent_entity_id'] ? 'selected' : '' ?>><?= e($re['label']) ?></option><?php endforeach; ?>
          <?php if (!in_array((int) $rel['parent_entity_id'], array_column($referenceableEntities, 'id'), true)): ?>
            <option value="<?= (int) $rel['parent_entity_id'] ?>" selected><?= e($rel['parent_label']) ?></option>
          <?php endif; ?>
        </select>
      </label>
      <label><?= e(t('relationship_type')) ?>
        <select name="relationship_type">
          <option value="one_to_many" <?= $rel['relationship_type'] === 'one_to_many' ? 'selected' : '' ?>><?= e(t('one_to_many')) ?></option>
          <option value="one_to_one" <?= $rel['relationship_type'] === 'one_to_one' ? 'selected' : '' ?>><?= e(t('one_to_one')) ?></option>
        </select>
      </label>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= e(t('save')) ?></button>
        <a class="btn btn-secondary" href="entity_edit.php?id=<?= (int) $entityId ?>"><?= e(t('cancel')) ?></a>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
