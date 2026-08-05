<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/entity_engine.php';

if (!is_installed()) { redirect('install.php'); }
require_login();

$entityName = $_GET['entity'] ?? '';
$entity = get_entity_by_name($entityName);
if (!$entity) {
    flash('error', 'Unknown entity.');
    redirect('dashboard.php');
}
if (!has_permission($entity['id'], 'view')) {
    http_response_code(403);
    die(t('permission_denied_message'));
}

$fields = get_entity_fields($entity['id']);
$childRelationships = get_relationships_as_parent($entity['id']);
$parentRelationships = get_relationships_as_child($entity['id']);

// Optional filter: viewing this entity as the child list of a specific parent row.
$parentField = $_GET['parent_field'] ?? null;
$parentId = isset($_GET['parent_id']) ? (int) $_GET['parent_id'] : null;
$parentEntityName = $_GET['parent_entity'] ?? null;
$filters = [];
$backLink = null;
if ($parentField && $parentId) {
    $filters[$parentField] = $parentId;
    if ($parentEntityName) {
        $backLink = 'entity_view.php?entity=' . urlencode($parentEntityName) . '&id=' . $parentId;
    }
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$result = entity_list_rows($entity, $fields, $filters, $page, ROWS_PER_PAGE);
$totalPages = max(1, (int) ceil($result['total'] / ROWS_PER_PAGE));

$canCreate = has_permission($entity['id'], 'create');
$canEdit = has_permission($entity['id'], 'edit');
$canDelete = has_permission($entity['id'], 'delete');

$pageTitle = $entity['label'];
include __DIR__ . '/includes/header.php';

function extra_qs($parentField, $parentId, $parentEntityName) {
    $qs = '';
    if ($parentField && $parentId) {
        $qs = '&parent_field=' . urlencode($parentField) . '&parent_id=' . (int) $parentId . '&parent_entity=' . urlencode($parentEntityName ?? '');
    }
    return $qs;
}
$qs = extra_qs($parentField, $parentId, $parentEntityName);
?>
<div class="page-header">
  <h1><?= e($entity['label']) ?></h1>
  <div>
    <?php if ($backLink): ?><a class="btn btn-secondary" href="<?= e($backLink) ?>"><?= e(t('back')) ?></a><?php endif; ?>
    <?php if ($canCreate): ?>
      <a class="btn btn-primary" href="entity_form.php?entity=<?= e($entity['name']) ?>&action=create<?= $qs ?>"><?= e(t('add_row')) ?></a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <?php if (!$result['rows']): ?>
    <p class="text-muted"><?= e(t('no_rows')) ?></p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>ID</th>
          <?php foreach ($fields as $f): ?><th><?= e($f['label']) ?></th><?php endforeach; ?>
          <?php foreach ($parentRelationships as $r): ?><th><?= e($r['label'] ?: $r['parent_label']) ?></th><?php endforeach; ?>
          <th><?= e(t('actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result['rows'] as $row): ?>
          <tr>
            <td>#<?= (int) $row['id'] ?></td>
            <?php foreach ($fields as $f): ?>
              <td>
                <?php
                  $val = $row[$f['name']] ?? null;
                  if ($f['field_type'] === 'Boolean') {
                      echo $val ? e(t('yes')) : e(t('no'));
                  } else {
                      echo e((string) $val);
                  }
                ?>
              </td>
            <?php endforeach; ?>
            <?php foreach ($parentRelationships as $r): ?>
              <td>
                <?php
                  $fkVal = $row[$r['fk_field']] ?? null;
                  if ($fkVal) {
                      $parentEnt = get_entity((int) $r['parent_entity_id']);
                      echo e(entity_fk_display($parentEnt, get_entity_fields($parentEnt['id']), (int) $fkVal));
                  } else {
                      echo '&mdash;';
                  }
                ?>
              </td>
            <?php endforeach; ?>
            <td>
              <a href="entity_view.php?entity=<?= e($entity['name']) ?>&id=<?= (int) $row['id'] ?><?= $qs ?>"><?= e(t('view_row')) ?></a>
              <?php if ($canEdit): ?> | <a href="entity_form.php?entity=<?= e($entity['name']) ?>&action=edit&id=<?= (int) $row['id'] ?><?= $qs ?>"><?= e(t('edit_row')) ?></a><?php endif; ?>
              <?php if ($canDelete): ?>
                | <form class="inline-form" method="post" action="entity_delete.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="entity" value="<?= e($entity['name']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="return" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                    <button type="submit" class="btn-link" style="background:none;border:none;color:var(--danger);cursor:pointer;padding:0;" data-confirm="<?= e(t('delete_confirm')) ?>"><?= e(t('delete_row')) ?></button>
                  </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="?entity=<?= e($entity['name']) ?>&page=<?= $page - 1 ?><?= $qs ?>"><?= e(t('previous')) ?></a><?php endif; ?>
        <span class="text-muted"><?= sprintf(t('page_of'), $page, $totalPages) ?></span>
        <?php if ($page < $totalPages): ?><a class="btn btn-secondary btn-sm" href="?entity=<?= e($entity['name']) ?>&page=<?= $page + 1 ?><?= $qs ?>"><?= e(t('next')) ?></a><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
