<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
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

    $fields = [];
    foreach ($fieldsInput as $f) {
        if (trim($f['name'] ?? '') === '') {
            continue;
        }
        $fields[] = [
            'name' => $f['name'],
            'label' => trim($f['label'] ?? '') ?: $f['name'],
            'type' => $f['type'] ?? 'String',
            'max_length' => $f['max_length'] ?? null,
            'default_value' => $f['default_value'] ?? null,
            'is_required' => !empty($f['is_required']),
        ];
    }

    if (!$name || !$label) {
        $error = t('entity_name') . ' / ' . t('entity_label') . ' required.';
    } elseif (get_entity_by_name(slugify($name))) {
        $error = 'An entity with that internal name already exists.';
    } else {
        try {
            create_entity($name, $label, $isTopLevel, $fields);
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
    if (trim($f['name'] ?? '') !== '') {
        try {
            add_field_to_entity($entity['id'], [
                'name' => $f['name'],
                'label' => trim($f['label'] ?? '') ?: $f['name'],
                'type' => $f['type'] ?? 'String',
                'max_length' => $f['max_length'] ?? null,
                'default_value' => $f['default_value'] ?? null,
                'is_required' => !empty($f['is_required']),
            ]);
            flash('success', t('field_added'));
        } catch (Throwable $e) {
            flash('error', 'Could not add field: ' . $e->getMessage());
        }
        redirect('entity_edit.php?id=' . $entity['id']);
    }
}

$pageTitle = $entity ? $entity['label'] : t('new_entity');
$fieldTypes = FIELD_TYPES;
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
      <div id="fields-wrap">
        <div class="field-row">
          <label><?= e(t('field_name')) ?><input type="text" name="fields[0][name]" placeholder="e.g. title"></label>
          <label><?= e(t('field_label')) ?><input type="text" name="fields[0][label]" placeholder="e.g. Title"></label>
          <label><?= e(t('field_type')) ?>
            <select name="fields[0][type]">
              <?php foreach ($fieldTypes as $ft): ?><option value="<?= e($ft) ?>"><?= e($ft) ?></option><?php endforeach; ?>
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
          <?php foreach ($fieldTypes as $ft): ?><option value="<?= e($ft) ?>"><?= e($ft) ?></option><?php endforeach; ?>
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
      <thead><tr><th><?= e(t('field_name')) ?></th><th><?= e(t('field_label')) ?></th><th><?= e(t('field_type')) ?></th><th><?= e(t('max_length')) ?></th><th><?= e(t('default_value')) ?></th><th><?= e(t('required')) ?></th></tr></thead>
      <tbody>
        <?php foreach (get_entity_fields($entity['id']) as $f): ?>
          <tr>
            <td><code><?= e($f['name']) ?></code></td>
            <td><?= e($f['label']) ?></td>
            <td><?= e($f['field_type']) ?></td>
            <td><?= e((string) $f['max_length']) ?></td>
            <td><?= e((string) $f['default_value']) ?></td>
            <td><?= $f['is_required'] ? e(t('yes')) : e(t('no')) ?></td>
          </tr>
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
            <?php foreach ($fieldTypes as $ft): ?><option value="<?= e($ft) ?>"><?= e($ft) ?></option><?php endforeach; ?>
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
