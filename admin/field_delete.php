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

// How many existing rows actually hold a non-empty value in this column - shown in the warning.
$table = $entity['table_name'];
$col = $field ? $field['name'] : $rel['fk_field'];
$valueCount = (int) db()->query("SELECT COUNT(*) FROM `$table` WHERE `$col` IS NOT NULL AND `$col` <> ''")->fetchColumn();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        if ($field) {
            delete_field($entityId, $fieldId);
        } else {
            delete_relationship($relId);
        }
        flash('success', t('field_deleted'));
        redirect('entity_edit.php?id=' . $entityId);
    } catch (Throwable $e) {
        $error = 'Could not delete field: ' . $e->getMessage();
    }
}

$label = $field ? $field['label'] : ($rel['label'] ?: $rel['parent_label']);
$pageTitle = t('delete_field') . ': ' . $label;
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h1><?= e(t('delete_field')) ?>: <?= e($label) ?></h1>
  <a class="btn btn-secondary" href="entity_edit.php?id=<?= (int) $entityId ?>"><?= e(t('back')) ?></a>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <div class="alert alert-error">
    <p><strong><?= e(t('delete_field_warning_title')) ?></strong></p>
    <p><?= e(t('delete_field_warning_body')) ?></p>
    <ul>
      <li><?= sprintf(e(t('delete_entity_rows_line')), '<strong>' . $valueCount . '</strong>') ?></li>
      <?php if ($rel): ?><li><?= e(t('delete_field_relationship_note')) ?></li><?php endif; ?>
    </ul>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="entity_id" value="<?= (int) $entityId ?>">
    <?php if ($field): ?><input type="hidden" name="field_id" value="<?= (int) $fieldId ?>"><?php else: ?><input type="hidden" name="rel_id" value="<?= (int) $relId ?>"><?php endif; ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-danger"><?= e(t('delete_field_confirm_button')) ?></button>
      <a class="btn btn-secondary" href="entity_edit.php?id=<?= (int) $entityId ?>"><?= e(t('cancel')) ?></a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
