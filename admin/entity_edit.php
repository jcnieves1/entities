<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) ? (int) $_POST['id'] : null);
$entity = $id ? get_entity($id) : null;
if ($id && !$entity) {
    flash('error', 'Entity not found.');
    redirect('entities.php');
}

$error = null;

// ---- Create a brand new entity (name + fields + optional top-level flag) ----
if (!$entity && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $isTopLevel = !empty($_POST['is_top_level']);
    $fieldsInput = $_POST['fields'] ?? [];

    // A field's "type" can be a native type (Int/String/...) or "entity:<id>"
    // meaning "this field references a row of that other entity". We keep
    // native fields and reference fields in two buckets but assign each one
    // a shared, incrementing sort_order so the final form/list still shows
    // them interleaved in the order the admin defined them.
    $nativeFields = [];
    $referenceFields = [];
    $order = 0;
    foreach ($fieldsInput as $f) {
        if (trim($f['name'] ?? '') === '') {
            continue;
        }
        $type = $f['type'] ?? 'String';
        if (strpos($type, 'entity:') === 0) {
            $referenceFields[] = [
                'name' => $f['name'],
                'label' => trim($f['label'] ?? '') ?: $f['name'],
                'target_entity_id' => (int) substr($type, 7),
                'sort_order' => $order++,
            ];
        } else {
            $nativeFields[] = [
                'name' => $f['name'],
                'label' => trim($f['label'] ?? '') ?: $f['name'],
                'type' => $type,
                'max_length' => $f['max_length'] ?? null,
                'default_value' => $f['default_value'] ?? null,
                'is_required' => !empty($f['is_required']),
                'sort_order' => $order++,
            ];
        }
    }

    if (!$name || !$label) {
        $error = t('entity_name') . ' / ' . t('entity_label') . ' required.';
    } elseif (get_entity_by_name(slugify($name))) {
        $error = 'An entity with that internal name already exists.';
    } else {
        try {
            $newEntityId = create_entity($name, $label, $isTopLevel, $nativeFields);
            try {
                foreach ($referenceFields as $ref) {
                    create_relationship($newEntityId, $ref['target_entity_id'], $ref['name'], 'one_to_many', $ref['label'], $ref['sort_order']);
                }
            } catch (Throwable $e) {
                // Roll back the whole new entity (table + metadata) rather than leaving a half-built one.
                delete_entity($newEntityId);
                throw $e;
            }
            flash('success', t('entity_created'));
            redirect('entities.php');
        } catch (Throwable $e) {
            $error = 'Could not create entity: ' . $e->getMessage();
        }
    }
}

// ---- Update metadata (label / top-level flag) of an existing entity ----
if ($entity && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_meta') {
    require_csrf();
    $label = trim($_POST['label'] ?? '');
    $isTopLevel = !empty($_POST['is_top_level']);
    if ($label) {
        $stmt = db()->prepare('UPDATE entities SET label = ?, is_top_level = ? WHERE id = ?');
        $stmt->execute([$label, $isTopLevel ? 1 : 0, $entity['id']]);
        flash('success', t('save'));
        redirect('entity_edit.php?id=' . $entity['id']);
    }
}

// ---- Add a new field to an existing entity ----
if ($entity && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_field') {
    require_csrf();
    $f = $_POST['field'] ?? [];
    $type = $f['type'] ?? 'String';
    if (trim($f['name'] ?? '') !== '') {
        try {
            if (strpos($type, 'entity:') === 0) {
                $targetEntityId = (int) substr($type, 7);
                create_relationship($entity['id'], $targetEntityId, $f['name'], 'one_to_many', trim($f['label'] ?? '') ?: $f['name']);
            } else {
                add_field_to_entity($entity['id'], [
                    'name' => $f['name'],
                    'label' => trim($f['label'] ?? '') ?: $f['name'],
                    'type' => $type,
                    'max_length' => $f['max_length'] ?? null,
                    'default_value' => $f['default_value'] ?? null,
                    'is_required' => !empty($f['is_required']),
                ]);
            }
            flash('success', t('field_added'));
        } catch (Throwable $e) {
            flash('error', 'Could not add field: ' . $e->getMessage());
        }
        redirect('entity_edit.php?id=' . $entity['id']);
    }
}

$pageTitle = $entity ? $entity['label'] : t('new_entity');
$fieldTypes = FIELD_TYPES;
// Entities that can be picked as a field "type" (creates a relationship).
// Exclude the entity being edited itself, to keep self-references off for now.
$referenceableEntities = array_values(array_filter(get_all_entities(), function ($e) use ($entity) {
    return !$entity || (int) $e['id'] !== (int) $entity['id'];
}));
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <h1><?= e($pageTitle) ?></h1>
  <a class="btn btn-secondary" href="entities.php"><?= e(t('back')) ?></a>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if (!$entity): ?>
  <!-- CREATE NEW ENTITY -->
  <div class="card">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label><?= e(t('entity_name')) ?> <span class="text-muted">(letters, numbers, underscore — used for the internal table name)</span>
        <input type="text" name="name" required pattern="[A-Za-z0-9_ ]+" placeholder="e.g. project">
      </label>
      <label><?= e(t('entity_label')) ?>
        <input type="text" name="label" required placeholder="e.g. Projects">
      </label>
      <label>
        <input type="checkbox" name="is_top_level" value="1"> <?= e(t('is_top_level_question')) ?>
      </label>

      <h3><?= e(t('fields')) ?></h3>
      <p class="text-muted"><?= e(t('field_type_entity_hint')) ?></p>
      <div id="fields-wrap">
        <div class="field-row">
          <label><?= e(t('field_name')) ?><input type="text" name="fields[0][name]" placeholder="e.g. title"></label>
          <label><?= e(t('field_label')) ?><input type="text" name="fields[0][label]" placeholder="e.g. Title"></label>
          <label><?= e(t('field_type')) ?>
            <select name="fields[0][type]">
              <optgroup label="<?= e(t('native_types')) ?>">
                <?php foreach ($fieldTypes as $ft): ?><option value="<?= e($ft) ?>"><?= e($ft) ?></option><?php endforeach; ?>
              </optgroup>
              <?php if ($referenceableEntities): ?>
              <optgroup label="<?= e(t('entity_reference_types')) ?>">
                <?php foreach ($referenceableEntities as $re): ?><option value="entity:<?= (int) $re['id'] ?>"><?= e($re['label']) ?></option><?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
            </select>
          </label>
          <label><?= e(t('max_length')) ?><input type="number" name="fields[0][max_length]" min="1" placeholder="255"></label>
          <label><?= e(t('default_value')) ?><input type="text" name="fields[0][default_value]"></label>
          <label><?= e(t('required')) ?><input type="checkbox" name="fields[0][is_required]" value="1"></label>
          <button type="button" class="btn btn-secondary btn-sm remove-field-btn"><?= e(t('remove_field')) ?></button>
        </div>
      </div>
      <button type="button" id="add-field-btn" class="btn btn-secondary btn-sm"><?= e(t('add_field')) ?></button>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= e(t('save_entity')) ?></button>
      </div>
    </form>
  </div>

  <!-- Row template cloned by JS for additional fields -->
  <template id="field-row-template">
    <div class="field-row">
      <label><?= e(t('field_name')) ?><input type="text" name="fields[__INDEX__][name]"></label>
      <label><?= e(t('field_label')) ?><input type="text" name="fields[__INDEX__][label]"></label>
      <label><?= e(t('field_type')) ?>
        <select name="fields[__INDEX__][type]">
          <optgroup label="<?= e(t('native_types')) ?>">
            <?php foreach ($fieldTypes as $ft): ?><option value="<?= e($ft) ?>"><?= e($ft) ?></option><?php endforeach; ?>
          </optgroup>
          <?php if ($referenceableEntities): ?>
          <optgroup label="<?= e(t('entity_reference_types')) ?>">
            <?php foreach ($referenceableEntities as $re): ?><option value="entity:<?= (int) $re['id'] ?>"><?= e($re['label']) ?></option><?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
      </label>
      <label><?= e(t('max_length')) ?><input type="number" name="fields[__INDEX__][max_length]" min="1"></label>
      <label><?= e(t('default_value')) ?><input type="text" name="fields[__INDEX__][default_value]"></label>
      <label><?= e(t('required')) ?><input type="checkbox" name="fields[__INDEX__][is_required]" value="1"></label>
      <button type="button" class="btn btn-secondary btn-sm remove-field-btn"><?= e(t('remove_field')) ?></button>
    </div>
  </template>

<?php else: ?>
  <!-- EDIT EXISTING ENTITY -->
  <div class="card">
    <p class="text-muted"><?= e(t('entity_name')) ?>: <code><?= e($entity['name']) ?></code> &nbsp; Table: <code><?= e($entity['table_name']) ?></code></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_meta">
      <label><?= e(t('entity_label')) ?><input type="text" name="label" required value="<?= e($entity['label']) ?>"></label>
      <label><input type="checkbox" name="is_top_level" value="1" <?= $entity['is_top_level'] ? 'checked' : '' ?>> <?= e(t('is_top_level_question')) ?></label>
      <button type="submit" class="btn btn-primary btn-sm"><?= e(t('save')) ?></button>
    </form>
  </div>

  <div class="card">
    <h3><?= e(t('fields')) ?></h3>
    <table class="data-table">
      <thead><tr><th><?= e(t('field_name')) ?></th><th><?= e(t('field_label')) ?></th><th><?= e(t('field_type')) ?></th><th><?= e(t('max_length')) ?></th><th><?= e(t('default_value')) ?></th><th><?= e(t('required')) ?></th><th><?= e(t('actions')) ?></th></tr></thead>
      <tbody>
        <?php foreach (merge_display_fields(get_entity_fields($entity['id']), get_relationships_as_child($entity['id'])) as $item): ?>
          <?php if ($item['kind'] === 'field'): ?>
            <tr>
              <td><code><?= e($item['name']) ?></code></td>
              <td><?= e($item['label']) ?></td>
              <td><?= e($item['field_type']) ?></td>
              <td><?= e((string) $item['max_length']) ?></td>
              <td><?= e((string) $item['default_value']) ?></td>
              <td><?= $item['is_required'] ? e(t('yes')) : e(t('no')) ?></td>
              <td>
                <a class="btn btn-secondary btn-sm" href="field_edit.php?entity_id=<?= (int) $entity['id'] ?>&field_id=<?= (int) $item['id'] ?>"><?= e(t('edit')) ?></a>
                <a class="btn btn-secondary btn-sm" href="field_conditions.php?entity_id=<?= (int) $entity['id'] ?>&field_id=<?= (int) $item['id'] ?>"><?= e(t('conditions')) ?><?php $cc = count(get_field_conditions((int) $item['id'], null)); ?><?= $cc ? ' (' . $cc . ')' : '' ?></a>
                <a class="btn btn-danger btn-sm" href="field_delete.php?entity_id=<?= (int) $entity['id'] ?>&field_id=<?= (int) $item['id'] ?>"><?= e(t('delete')) ?></a>
              </td>
            </tr>
          <?php else: ?>
            <tr>
              <td><code><?= e($item['fk_field']) ?></code></td>
              <td><?= e($item['label'] ?: $item['parent_label']) ?></td>
              <td><span class="badge">&rarr; <?= e($item['parent_label']) ?></span></td>
              <td>&mdash;</td>
              <td>&mdash;</td>
              <td>&mdash;</td>
              <td>
                <a class="btn btn-secondary btn-sm" href="field_edit.php?entity_id=<?= (int) $entity['id'] ?>&rel_id=<?= (int) $item['id'] ?>"><?= e(t('edit')) ?></a>
                <a class="btn btn-secondary btn-sm" href="field_conditions.php?entity_id=<?= (int) $entity['id'] ?>&rel_id=<?= (int) $item['id'] ?>"><?= e(t('conditions')) ?><?php $cc = count(get_field_conditions(null, (int) $item['id'])); ?><?= $cc ? ' (' . $cc . ')' : '' ?></a>
                <a class="btn btn-danger btn-sm" href="field_delete.php?entity_id=<?= (int) $entity['id'] ?>&rel_id=<?= (int) $item['id'] ?>"><?= e(t('delete')) ?></a>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h4><?= e(t('add_field')) ?></h4>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_field">
      <div class="field-row">
        <label><?= e(t('field_name')) ?><input type="text" name="field[name]" required></label>
        <label><?= e(t('field_label')) ?><input type="text" name="field[label]"></label>
        <label><?= e(t('field_type')) ?>
          <select name="field[type]">
            <optgroup label="<?= e(t('native_types')) ?>">
              <?php foreach ($fieldTypes as $ft): ?><option value="<?= e($ft) ?>"><?= e($ft) ?></option><?php endforeach; ?>
            </optgroup>
            <?php if ($referenceableEntities): ?>
            <optgroup label="<?= e(t('entity_reference_types')) ?>">
              <?php foreach ($referenceableEntities as $re): ?><option value="entity:<?= (int) $re['id'] ?>"><?= e($re['label']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
        </label>
        <label><?= e(t('max_length')) ?><input type="number" name="field[max_length]" min="1" placeholder="255"></label>
        <label><?= e(t('default_value')) ?><input type="text" name="field[default_value]"></label>
        <label><?= e(t('required')) ?><input type="checkbox" name="field[is_required]" value="1"></label>
        <button type="submit" class="btn btn-primary btn-sm"><?= e(t('add_field')) ?></button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
