<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$entities = get_all_entities();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $childId = (int) ($_POST['child_entity_id'] ?? 0);
    $parentId = (int) ($_POST['parent_entity_id'] ?? 0);
    $fkField = trim($_POST['fk_field'] ?? '');
    $type = ($_POST['relationship_type'] ?? '') === 'one_to_one' ? 'one_to_one' : 'one_to_many';
    $label = trim($_POST['label'] ?? '');

    if (!$childId || !$parentId || !$fkField) {
        $error = 'All fields are required.';
    } elseif ($childId === $parentId) {
        $error = 'Parent and child must be different entities.';
    } else {
        try {
            create_relationship($childId, $parentId, $fkField, $type, $label ?: null);
            flash('success', t('relationship_created'));
            redirect('relationships.php');
        } catch (Throwable $e) {
            $error = 'Could not create relationship: ' . $e->getMessage();
        }
    }
}

$stmt = db()->query('SELECT r.*, c.label AS child_label, p.label AS parent_label
                      FROM entity_relationships r
                      JOIN entities c ON c.id = r.child_entity_id
                      JOIN entities p ON p.id = r.parent_entity_id
                      ORDER BY r.id DESC');
$relationships = $stmt->fetchAll();

$pageTitle = t('relationships');
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><h1><?= e(t('relationships')) ?></h1></div>

<?php if (count($entities) < 2): ?>
  <div class="card"><p class="text-muted">You need at least two entities to create a relationship.</p></div>
<?php else: ?>
  <div class="card">
    <h3><?= e(t('new_relationship')) ?></h3>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label><?= e(t('parent_entity')) ?>
        <select name="parent_entity_id" required>
          <option value="">--</option>
          <?php foreach ($entities as $ent): ?><option value="<?= (int) $ent['id'] ?>"><?= e($ent['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><?= e(t('child_entity')) ?>
        <select name="child_entity_id" required>
          <option value="">--</option>
          <?php foreach ($entities as $ent): ?><option value="<?= (int) $ent['id'] ?>"><?= e($ent['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><?= e(t('fk_field_name')) ?><input type="text" name="fk_field" required placeholder="e.g. project_id"></label>
      <label><?= e(t('relationship_type')) ?>
        <select name="relationship_type">
          <option value="one_to_many"><?= e(t('one_to_many')) ?></option>
          <option value="one_to_one"><?= e(t('one_to_one')) ?></option>
        </select>
      </label>
      <label><?= e(t('entity_label')) ?> (optional display label)<input type="text" name="label"></label>
      <button type="submit" class="btn btn-primary"><?= e(t('create_relationship')) ?></button>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h3><?= e(t('relationships')) ?></h3>
  <?php if (!$relationships): ?>
    <p class="text-muted"><?= e(t('no_relationships_yet')) ?></p>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th><?= e(t('parent_entity')) ?></th><th><?= e(t('child_entity')) ?></th><th><?= e(t('fk_field_name')) ?></th><th><?= e(t('relationship_type')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($relationships as $r): ?>
          <tr>
            <td><?= e($r['parent_label']) ?></td>
            <td><?= e($r['child_label']) ?></td>
            <td><code><?= e($r['fk_field']) ?></code></td>
            <td><span class="badge"><?= e(t($r['relationship_type'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
