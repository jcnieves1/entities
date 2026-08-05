<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$entity = $id ? get_entity($id) : null;
if (!$entity) {
    flash('error', 'Entity not found.');
    redirect('entities.php');
}

$rowCount = entity_row_count($entity);
$asParent = get_relationships_as_parent($entity['id']); // other entities that link down to this one
$asChild = get_relationships_as_child($entity['id']);    // this entity's own links up to other entities

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $typed = trim($_POST['confirm_name'] ?? '');
    if ($typed !== $entity['name']) {
        $error = 'The typed name did not match. Nothing was deleted.';
    } else {
        try {
            delete_entity($entity['id']);
            flash('success', t('entity_deleted'));
            redirect('entities.php');
        } catch (Throwable $e) {
            $error = 'Could not delete entity: ' . $e->getMessage();
        }
    }
}

$pageTitle = t('delete_entity') . ': ' . $entity['label'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h1><?= e(t('delete_entity')) ?>: <?= e($entity['label']) ?></h1>
  <a class="btn btn-secondary" href="entities.php"><?= e(t('back')) ?></a>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <div class="alert alert-error">
    <p><strong><?= e(t('delete_entity_warning_title')) ?></strong></p>
    <p><?= e(t('delete_entity_warning_body')) ?></p>
    <ul>
      <li><?= sprintf(e(t('delete_entity_rows_line')), '<strong>' . (int) $rowCount . '</strong>') ?></li>
      <?php if ($asParent): ?>
        <li><?= e(t('delete_entity_children_line')) ?>
          <ul>
            <?php foreach ($asParent as $rel): ?>
              <li><strong><?= e($rel['child_label']) ?></strong> (<?= e(t('fk_field_name')) ?>: <code><?= e($rel['fk_field']) ?></code>) &mdash; <?= e(t('delete_entity_child_note')) ?></li>
            <?php endforeach; ?>
          </ul>
        </li>
      <?php endif; ?>
      <?php if ($asChild): ?>
        <li><?= e(t('delete_entity_parents_line')) ?>
          <ul>
            <?php foreach ($asChild as $rel): ?>
              <li><strong><?= e($rel['parent_label']) ?></strong> (<?= e(t('fk_field_name')) ?>: <code><?= e($rel['fk_field']) ?></code>)</li>
            <?php endforeach; ?>
          </ul>
        </li>
      <?php endif; ?>
    </ul>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $entity['id'] ?>">
    <label><?= sprintf(e(t('delete_entity_type_to_confirm')), '<code>' . e($entity['name']) . '</code>') ?>
      <input type="text" name="confirm_name" required autocomplete="off" placeholder="<?= e($entity['name']) ?>">
    </label>
    <div class="form-actions">
      <button type="submit" class="btn btn-danger"><?= e(t('delete_entity_confirm_button')) ?></button>
      <a class="btn btn-secondary" href="entities.php"><?= e(t('cancel')) ?></a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
