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
$id = (int) ($_GET['id'] ?? 0);
if (!$entity || !$id) {
    flash('error', 'Unknown entity or record.');
    redirect('dashboard.php');
}
if (!has_permission($entity['id'], 'view')) {
    http_response_code(403);
    die(t('permission_denied_message'));
}

$fields = get_entity_fields($entity['id']);
$row = entity_get_row($entity, $id);
if (!$row) {
    flash('error', 'Row not found.');
    redirect('entity.php?entity=' . urlencode($entity['name']));
}

$parentRelationships = get_relationships_as_child($entity['id']);  // this row's own FK links "up"
$childRelationships = get_relationships_as_parent($entity['id']);  // other entities that link "down" to this row
$displayFields = merge_display_fields($fields, $parentRelationships); // both, in the admin's defined order

$canEdit = has_permission($entity['id'], 'edit');
$canDelete = has_permission($entity['id'], 'delete');

// Optional breadcrumb context (we were reached from a parent's child list)
$parentField = $_GET['parent_field'] ?? null;
$parentIdCtx = isset($_GET['parent_id']) ? (int) $_GET['parent_id'] : null;
$parentEntityNameCtx = $_GET['parent_entity'] ?? null;

$pageTitle = $entity['label'] . ' #' . $id;
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><?= e($entity['label']) ?> #<?= (int) $id ?></h1>
  <div>
    <?php if ($parentEntityNameCtx && $parentIdCtx): ?>
      <a class="btn btn-secondary" href="entity_view.php?entity=<?= e($parentEntityNameCtx) ?>&id=<?= (int) $parentIdCtx ?>"><?= e(t('back')) ?></a>
    <?php else: ?>
      <a class="btn btn-secondary" href="entity.php?entity=<?= e($entity['name']) ?>"><?= e(t('back')) ?></a>
    <?php endif; ?>
    <?php if ($canEdit): ?><a class="btn btn-primary" href="entity_form.php?entity=<?= e($entity['name']) ?>&action=edit&id=<?= (int) $id ?>"><?= e(t('edit')) ?></a><?php endif; ?>
    <?php if ($canDelete): ?>
      <form class="inline-form" method="post" action="entity_delete.php">
        <?= csrf_field() ?>
        <input type="hidden" name="entity" value="<?= e($entity['name']) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="hidden" name="return" value="entity.php?entity=<?= e($entity['name']) ?>">
        <button type="submit" class="btn btn-danger" data-confirm="<?= e(t('delete_confirm')) ?>"><?= e(t('delete')) ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <table class="data-table">
    <tbody>
      <?php foreach ($displayFields as $item): ?>
        <?php if ($item['kind'] === 'field'): $val = $row[$item['name']] ?? null; ?>
          <tr>
            <th style="width:220px;"><?= e($item['label']) ?></th>
            <td><?= $item['field_type'] === 'Boolean' ? ($val ? e(t('yes')) : e(t('no'))) : e((string) $val) ?></td>
          </tr>
        <?php else: $fkVal = $row[$item['fk_field']] ?? null; $parentEnt = get_entity((int) $item['parent_entity_id']); ?>
          <tr>
            <th><?= e($item['label'] ?: $item['parent_label']) ?></th>
            <td>
              <?php if ($fkVal): ?>
                <a href="entity_view.php?entity=<?= e($parentEnt['name']) ?>&id=<?= (int) $fkVal ?>"><?= e(entity_fk_display($parentEnt, get_entity_fields($parentEnt['id']), (int) $fkVal)) ?></a>
              <?php else: ?>&mdash;<?php endif; ?>
            </td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php foreach ($childRelationships as $rel): ?>
  <?php
    $childEnt = get_entity((int) $rel['child_entity_id']);
    $childFields = get_entity_fields($childEnt['id']);
    $canViewChild = has_permission($childEnt['id'], 'view');
    if (!$canViewChild) continue;
    $canCreateChild = has_permission($childEnt['id'], 'create');
    $childList = entity_list_rows($childEnt, $childFields, [$rel['fk_field'] => $id], 1, 10);
    $qs = '&parent_field=' . urlencode($rel['fk_field']) . '&parent_id=' . $id . '&parent_entity=' . urlencode($entity['name']);
  ?>
  <div class="card">
    <div class="page-header" style="margin-bottom:10px;">
      <h3 style="margin:0;font-size:15px;"><?= e($rel['label'] ?: $rel['child_label']) ?> <span class="badge"><?= (int) $childList['total'] ?></span></h3>
      <?php if ($canCreateChild): ?>
        <a class="btn btn-secondary btn-sm" href="entity_form.php?entity=<?= e($childEnt['name']) ?>&action=create<?= $qs ?>"><?= e(t('add_child')) ?></a>
      <?php endif; ?>
    </div>
    <?php if (!$childList['rows']): ?>
      <p class="text-muted"><?= e(t('no_rows')) ?></p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th>ID</th><?php foreach ($childFields as $cf): ?><th><?= e($cf['label']) ?></th><?php endforeach; ?><th><?= e(t('actions')) ?></th></tr></thead>
        <tbody>
          <?php foreach ($childList['rows'] as $crow): ?>
            <tr>
              <td>#<?= (int) $crow['id'] ?></td>
              <?php foreach ($childFields as $cf): $cval = $crow[$cf['name']] ?? null; ?>
                <td><?= $cf['field_type'] === 'Boolean' ? ($cval ? e(t('yes')) : e(t('no'))) : e((string) $cval) ?></td>
              <?php endforeach; ?>
              <td><a href="entity_view.php?entity=<?= e($childEnt['name']) ?>&id=<?= (int) $crow['id'] ?><?= $qs ?>"><?= e(t('view_row')) ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($childList['total'] > 10): ?>
        <p><a href="entity.php?entity=<?= e($childEnt['name']) ?><?= $qs ?>"><?= e(t('view')) ?> all (<?= (int) $childList['total'] ?>)</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
