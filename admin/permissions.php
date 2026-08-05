<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$entities = get_all_entities();
// Only regular (non-admin, non-superuser) roles need row-level permissions;
// admins/superusers always have full access.
$roles = db()->query('SELECT * FROM roles WHERE is_admin = 0 AND is_superuser = 0 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $grid = $_POST['perm'] ?? []; // [entity_id][can_view|can_create|can_edit|can_delete]

    $roleExists = false;
    foreach ($roles as $r) { if ((int) $r['id'] === $roleId) { $roleExists = true; break; } }

    if ($roleId && $roleExists) {
        $pdo = db();
        foreach ($entities as $ent) {
            $entId = $ent['id'];
            $v = !empty($grid[$entId]['can_view']) ? 1 : 0;
            $c = !empty($grid[$entId]['can_create']) ? 1 : 0;
            $ed = !empty($grid[$entId]['can_edit']) ? 1 : 0;
            $d = !empty($grid[$entId]['can_delete']) ? 1 : 0;

            $stmt = $pdo->prepare('SELECT id FROM role_permissions WHERE role_id = ? AND entity_id = ?');
            $stmt->execute([$roleId, $entId]);
            $existing = $stmt->fetch();
            if ($existing) {
                $upd = $pdo->prepare('UPDATE role_permissions SET can_view=?, can_create=?, can_edit=?, can_delete=? WHERE id=?');
                $upd->execute([$v, $c, $ed, $d, $existing['id']]);
            } else {
                $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, entity_id, can_view, can_create, can_edit, can_delete) VALUES (?,?,?,?,?,?)');
                $ins->execute([$roleId, $entId, $v, $c, $ed, $d]);
            }
        }
        flash('success', t('permissions_saved'));
        redirect('permissions.php?role_id=' . $roleId);
    }
}

$selectedRoleId = (int) ($_GET['role_id'] ?? ($roles[0]['id'] ?? 0));
$currentPerms = [];
if ($selectedRoleId) {
    $stmt = db()->prepare('SELECT * FROM role_permissions WHERE role_id = ?');
    $stmt->execute([$selectedRoleId]);
    foreach ($stmt->fetchAll() as $row) {
        $currentPerms[$row['entity_id']] = $row;
    }
}

$pageTitle = t('manage_permissions');
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><h1><?= e(t('manage_permissions')) ?></h1></div>

<?php if (!$roles): ?>
  <div class="card"><p class="text-muted">Create a non-admin role first (see <a href="roles.php"><?= e(t('roles')) ?></a>).</p></div>
<?php elseif (!$entities): ?>
  <div class="card"><p class="text-muted"><?= e(t('no_entities_yet')) ?></p></div>
<?php else: ?>
  <div class="card">
    <form method="get" style="margin-bottom:16px;">
      <label style="max-width:320px;"><?= e(t('assign_role')) ?>
        <select name="role_id" onchange="this.form.submit()">
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int) $r['id'] ?>" <?= $selectedRoleId === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="role_id" value="<?= (int) $selectedRoleId ?>">
      <table class="data-table perm-table">
        <thead><tr>
          <th><?= e(t('entities')) ?></th>
          <th><?= e(t('can_view')) ?></th>
          <th><?= e(t('can_create')) ?></th>
          <th><?= e(t('can_edit')) ?></th>
          <th><?= e(t('can_delete')) ?></th>
        </tr></thead>
        <tbody>
          <?php foreach ($entities as $ent): $p = $currentPerms[$ent['id']] ?? null; ?>
            <tr>
              <td><?= e($ent['label']) ?></td>
              <td><input type="checkbox" name="perm[<?= (int) $ent['id'] ?>][can_view]" value="1" <?= $p && $p['can_view'] ? 'checked' : '' ?>></td>
              <td><input type="checkbox" name="perm[<?= (int) $ent['id'] ?>][can_create]" value="1" <?= $p && $p['can_create'] ? 'checked' : '' ?>></td>
              <td><input type="checkbox" name="perm[<?= (int) $ent['id'] ?>][can_edit]" value="1" <?= $p && $p['can_edit'] ? 'checked' : '' ?>></td>
              <td><input type="checkbox" name="perm[<?= (int) $ent['id'] ?>][can_delete]" value="1" <?= $p && $p['can_delete'] ? 'checked' : '' ?>></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= e(t('save_permissions')) ?></button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
