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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    save_field_conditions($fieldId ?: null, $relId ?: null, $_POST['groups'] ?? []);
    flash('success', t('conditions_saved'));
    redirect('entity_edit.php?id=' . $entityId);
}

$sourceOptions = get_condition_source_options($entity, $fieldId ?: null, $relId ?: null);
$existingGroups = get_field_conditions($fieldId ?: null, $relId ?: null);
if (!$existingGroups) {
    $existingGroups = [[['source' => '', 'operator' => 'equals', 'compare_value' => '']]];
}

$operatorLabels = [
    'equals' => t('op_equals'), 'not_equals' => t('op_not_equals'),
    'greater_than' => t('op_greater_than'), 'greater_or_equal' => t('op_greater_or_equal'),
    'less_than' => t('op_less_than'), 'less_or_equal' => t('op_less_or_equal'),
    'contains' => t('op_contains'), 'is_null' => t('op_is_null'), 'is_not_null' => t('op_is_not_null'),
];

function render_source_select(array $sourceOptions, string $name, string $selected): void
{
    echo '<select name="' . e($name) . '" class="cond-source">';
    echo '<option value="">--</option>';
    if ($sourceOptions['own_fields']) {
        echo '<optgroup label="' . e(t('cond_this_entity_fields')) . '">';
        foreach ($sourceOptions['own_fields'] as $o) {
            echo '<option value="' . e($o['value']) . '" data-type="' . e($o['field_type']) . '" ' . ($selected === $o['value'] ? 'selected' : '') . '>' . e($o['label']) . '</option>';
        }
        echo '</optgroup>';
    }
    if ($sourceOptions['own_relationships']) {
        echo '<optgroup label="' . e(t('cond_this_entity_relationships')) . '">';
        foreach ($sourceOptions['own_relationships'] as $o) {
            echo '<option value="' . e($o['value']) . '" data-type="Int" ' . ($selected === $o['value'] ? 'selected' : '') . '>' . e($o['label']) . '</option>';
        }
        echo '</optgroup>';
    }
    foreach ($sourceOptions['related'] as $group) {
        echo '<optgroup label="' . e(sprintf(t('cond_fields_on'), $group['relationship_label'])) . '">';
        foreach ($group['fields'] as $o) {
            echo '<option value="' . e($o['value']) . '" data-type="' . e($o['field_type']) . '" ' . ($selected === $o['value'] ? 'selected' : '') . '>' . e($o['label']) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';
}

function render_operator_select(array $operatorLabels, string $name, string $selected): void
{
    echo '<select name="' . e($name) . '" class="cond-operator">';
    foreach ($operatorLabels as $val => $label) {
        echo '<option value="' . e($val) . '" ' . ($selected === $val ? 'selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select>';
}

$targetLabel = $field ? $field['label'] : ($rel['label'] ?: $rel['parent_label']);
$pageTitle = t('field_conditions') . ': ' . $targetLabel;
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h1><?= e($pageTitle) ?></h1>
  <a class="btn btn-secondary" href="entity_edit.php?id=<?= (int) $entityId ?>"><?= e(t('back')) ?></a>
</div>

<div class="card">
  <p class="text-muted"><?= e(t('field_conditions_hint')) ?></p>

  <form method="post" id="conditions-form">
    <?= csrf_field() ?>
    <input type="hidden" name="entity_id" value="<?= (int) $entityId ?>">
    <?php if ($field): ?><input type="hidden" name="field_id" value="<?= (int) $fieldId ?>"><?php else: ?><input type="hidden" name="rel_id" value="<?= (int) $relId ?>"><?php endif; ?>

    <div id="cond-groups-wrap">
      <?php foreach ($existingGroups as $g => $group): ?>
        <div class="cond-group" data-group-idx="<?= (int) $g ?>">
          <?php if ($g > 0): ?><div class="cond-or-label"><?= e(t('cond_or')) ?></div><?php endif; ?>
          <div class="cond-rows">
            <?php foreach ($group as $c => $cond): ?>
              <div class="cond-row">
                <?php if ($c > 0): ?><span class="cond-and-label"><?= e(t('cond_and')) ?></span><?php endif; ?>
                <?php render_source_select($sourceOptions, "groups[$g][$c][source]", $cond['source_value'] ?? '') ?>
                <?php render_operator_select($operatorLabels, "groups[$g][$c][operator]", $cond['operator'] ?? 'equals') ?>
                <input type="text" name="groups[<?= $g ?>][<?= $c ?>][value]" class="cond-value" value="<?= e((string) ($cond['compare_value'] ?? '')) ?>" placeholder="<?= e(t('cond_value_placeholder')) ?>" <?= in_array($cond['operator'] ?? '', ['is_null', 'is_not_null'], true) ? 'style="display:none"' : '' ?>>
                <button type="button" class="btn btn-secondary btn-sm remove-cond-row"><?= e(t('remove_field')) ?></button>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-secondary btn-sm add-cond-row"><?= e(t('cond_add_and')) ?></button>
          <button type="button" class="btn btn-secondary btn-sm remove-cond-group"><?= e(t('cond_remove_group')) ?></button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" id="add-cond-group"><?= e(t('cond_add_or_group')) ?></button>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= e(t('save')) ?></button>
      <a class="btn btn-secondary" href="entity_edit.php?id=<?= (int) $entityId ?>"><?= e(t('cancel')) ?></a>
    </div>
  </form>

  <!-- Templates for JS-driven add group/row -->
  <template id="cond-row-template">
    <div class="cond-row">
      <span class="cond-and-label"><?= e(t('cond_and')) ?></span>
      <?php render_source_select($sourceOptions, 'groups[__G__][__C__][source]', '') ?>
      <?php render_operator_select($operatorLabels, 'groups[__G__][__C__][operator]', 'equals') ?>
      <input type="text" name="groups[__G__][__C__][value]" class="cond-value" placeholder="<?= e(t('cond_value_placeholder')) ?>">
      <button type="button" class="btn btn-secondary btn-sm remove-cond-row"><?= e(t('remove_field')) ?></button>
    </div>
  </template>
  <template id="cond-group-template">
    <div class="cond-group" data-group-idx="__G__">
      <div class="cond-or-label"><?= e(t('cond_or')) ?></div>
      <div class="cond-rows">
        <div class="cond-row">
          <?php render_source_select($sourceOptions, 'groups[__G__][0][source]', '') ?>
          <?php render_operator_select($operatorLabels, 'groups[__G__][0][operator]', 'equals') ?>
          <input type="text" name="groups[__G__][0][value]" class="cond-value" placeholder="<?= e(t('cond_value_placeholder')) ?>">
          <button type="button" class="btn btn-secondary btn-sm remove-cond-row"><?= e(t('remove_field')) ?></button>
        </div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm add-cond-row"><?= e(t('cond_add_and')) ?></button>
      <button type="button" class="btn btn-secondary btn-sm remove-cond-group"><?= e(t('cond_remove_group')) ?></button>
    </div>
  </template>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
