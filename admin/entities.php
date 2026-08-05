<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$pageTitle = t('manage_entities');
$entities = get_all_entities();
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h1><?= e(t('manage_entities')) ?></h1>
  <a class="btn btn-primary" href="entity_edit.php"><?= e(t('new_entity')) ?></a>
</div>

<div class="card">
  <?php if (!$entities): ?>
    <p class="text-muted"><?= e(t('no_entities_yet')) ?></p>
  <?php else: ?>
    <table class="data-table">
      <thead><tr>
        <th><?= e(t('entity_label')) ?></th>
        <th><?= e(t('entity_name')) ?></th>
        <th>Table</th>
        <th><?= e(t('is_top_level_question')) ?></th>
        <th><?= e(t('fields')) ?></th>
        <th><?= e(t('actions')) ?></th>
      </tr></thead>
      <tbody>
        <?php foreach ($entities as $ent): $fields = get_entity_fields($ent['id']); ?>
          <tr>
            <td><?= e($ent['label']) ?></td>
            <td><code><?= e($ent['name']) ?></code></td>
            <td><code><?= e($ent['table_name']) ?></code></td>
            <td><?= $ent['is_top_level'] ? '<span class="badge">' . e(t('yes')) . '</span>' : e(t('no')) ?></td>
            <td><?= count($fields) ?></td>
            <td>
              <a href="entity_edit.php?id=<?= (int) $ent['id'] ?>"><?= e(t('edit')) ?></a>
              &nbsp;|&nbsp;
              <a href="../entity.php?entity=<?= e($ent['name']) ?>"><?= e(t('view')) ?></a>
              &nbsp;|&nbsp;
              <a href="entity_delete.php?id=<?= (int) $ent['id'] ?>" style="color:var(--danger);"><?= e(t('delete_entity')) ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
