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
$displayFields = merge_display_fields($fields, $parentRelationships); // both, in the admin's defined order

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

$advancedFilters = build_entity_filters_from_request($displayFields, $_GET);

$page = max(1, (int) ($_GET['page'] ?? 1));
$result = entity_list_rows($entity, $fields, $filters, $page, ROWS_PER_PAGE, $advancedFilters);
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

/** Query-string fragment for every currently-set f_* filter param, so pagination/row links keep them applied. */
function filter_qs(array $displayFields): string
{
    $qs = '';
    foreach ($displayFields as $item) {
        if ($item['kind'] === 'field' && $item['field_type'] === 'Date') {
            $keys = ['f_' . $item['name'], 'f_' . $item['name'] . '_from', 'f_' . $item['name'] . '_to'];
        } elseif ($item['kind'] === 'field') {
            $keys = ['f_' . $item['name']];
        } else {
            $keys = ['f_' . $item['fk_field']];
        }
        foreach ($keys as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') {
                $qs .= '&' . $k . '=' . urlencode($_GET[$k]);
            }
        }
    }
    return $qs;
}

$qs = extra_qs($parentField, $parentId, $parentEntityName) . filter_qs($displayFields);
$hasActiveFilters = (bool) $advancedFilters;
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

<?php if ($displayFields): ?>
<div class="card filters-card">
  <form method="get" class="filters-form">
    <input type="hidden" name="entity" value="<?= e($entity['name']) ?>">
    <?php if ($parentField): ?>
      <input type="hidden" name="parent_field" value="<?= e($parentField) ?>">
      <input type="hidden" name="parent_id" value="<?= (int) $parentId ?>">
      <input type="hidden" name="parent_entity" value="<?= e($parentEntityName) ?>">
    <?php endif; ?>

    <?php foreach ($displayFields as $item): ?>
      <?php if ($item['kind'] === 'field'):
        $f = $item;
        $key = 'f_' . $f['name'];
        $current = $_GET[$key] ?? '';
      ?>
        <?php if ($f['field_type'] === 'Boolean'): ?>
          <label class="filter-field"><?= e($f['label']) ?>
            <select name="<?= e($key) ?>">
              <option value=""><?= e(t('filter_any')) ?></option>
              <option value="1" <?= $current === '1' ? 'selected' : '' ?>><?= e(t('filter_yes')) ?></option>
              <option value="0" <?= $current === '0' ? 'selected' : '' ?>><?= e(t('filter_no')) ?></option>
            </select>
          </label>
        <?php elseif ($f['field_type'] === 'Date'): ?>
          <fieldset class="filter-field filter-date-group">
            <legend><?= e($f['label']) ?></legend>
            <label class="filter-subfield"><?= e(t('filter_on_date')) ?><input type="date" name="<?= e($key) ?>" value="<?= e((string) $current) ?>"></label>
            <label class="filter-subfield"><?= e(t('filter_from_date')) ?><input type="date" name="<?= e($key) ?>_from" value="<?= e((string) ($_GET[$key . '_from'] ?? '')) ?>"></label>
            <label class="filter-subfield"><?= e(t('filter_to_date')) ?><input type="date" name="<?= e($key) ?>_to" value="<?= e((string) ($_GET[$key . '_to'] ?? '')) ?>"></label>
          </fieldset>
        <?php elseif ($f['field_type'] === 'Int' || $f['field_type'] === 'Float'): ?>
          <label class="filter-field"><?= e($f['label']) ?>
            <input type="number" <?= $f['field_type'] === 'Float' ? 'step="any"' : 'step="1"' ?> name="<?= e($key) ?>" value="<?= e((string) $current) ?>">
          </label>
        <?php else: ?>
          <label class="filter-field"><?= e($f['label']) ?>
            <input type="text" name="<?= e($key) ?>" value="<?= e((string) $current) ?>" placeholder="<?= e(t('filter_contains_placeholder')) ?>">
          </label>
        <?php endif; ?>
      <?php else:
        $rel = $item;
        $key = 'f_' . $rel['fk_field'];
        $current = $_GET[$key] ?? '';
        $relParentEnt = get_entity((int) $rel['parent_entity_id']);
        $relOptions = get_entity_options($relParentEnt);
      ?>
        <label class="filter-field"><?= e($rel['label'] ?: $rel['parent_label']) ?>
          <select name="<?= e($key) ?>">
            <option value=""><?= e(t('filter_any')) ?></option>
            <?php foreach ($relOptions as $oid => $label): ?>
              <option value="<?= (int) $oid ?>" <?= (string) $current === (string) $oid ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
    <?php endforeach; ?>

    <div class="filter-actions">
      <button type="submit" class="btn btn-primary btn-sm"><?= e(t('apply_filters')) ?></button>
      <?php if ($hasActiveFilters): ?><a class="btn btn-secondary btn-sm" href="entity.php?entity=<?= e($entity['name']) ?><?= e(extra_qs($parentField, $parentId, $parentEntityName)) ?>"><?= e(t('clear_filters')) ?></a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <?php if (!$result['rows']): ?>
    <p class="text-muted"><?= e($hasActiveFilters ? t('no_rows_filtered') : t('no_rows')) ?></p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>ID</th>
          <?php foreach ($displayFields as $item): ?>
            <th><?= e($item['kind'] === 'field' ? $item['label'] : ($item['label'] ?: $item['parent_label'])) ?></th>
          <?php endforeach; ?>
          <th><?= e(t('actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result['rows'] as $row): ?>
          <tr>
            <td>#<?= (int) $row['id'] ?></td>
            <?php foreach ($displayFields as $item): ?>
              <td>
                <?php if ($item['kind'] === 'field'):
                    $val = $row[$item['name']] ?? null;
                    if ($item['field_type'] === 'Boolean') {
                        echo $val ? e(t('yes')) : e(t('no'));
                    } elseif ($item['field_type'] === 'Email' && $val) {
                        echo '<a href="mailto:' . e($val) . '">' . e($val) . '</a>';
                    } else {
                        echo e((string) $val);
                    }
                  else:
                    $fkVal = $row[$item['fk_field']] ?? null;
                    if ($fkVal) {
                        $parentEnt = get_entity((int) $item['parent_entity_id']);
                        $fkLabel = entity_fk_display($parentEnt, get_entity_fields($parentEnt['id']), (int) $fkVal);
                        echo '<a href="entity_view.php?entity=' . e($parentEnt['name']) . '&id=' . (int) $fkVal . '">' . e($fkLabel) . '</a>';
                    } else {
                        echo '&mdash;';
                    }
                  endif;
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
