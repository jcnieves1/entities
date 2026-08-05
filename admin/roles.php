<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    // Only a real super user may mint additional admin/superuser roles.
    $isAdmin = is_superuser() && !empty($_POST['is_admin']);
    $isSuperuser = is_superuser() && !empty($_POST['is_superuser']);

    if (!$name) {
        $error = t('role_name') . ' required.';
    } else {
        $stmt = db()->prepare('INSERT INTO roles (name, description, is_admin, is_superuser) VALUES (?, ?, ?, ?)');
        try {
            $stmt->execute([$name, $description, $isAdmin ? 1 : 0, $isSuperuser ? 1 : 0]);
            flash('success', t('role_created'));
            redirect('roles.php');
        } catch (Throwable $e) {
            $error = 'Could not create role (maybe the name is already used): ' . $e->getMessage();
        }
    }
}

$roles = db()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$pageTitle = t('manage_roles');
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><h1><?= e(t('manage_roles')) ?></h1></div>

<div class="card">
  <h3><?= e(t('new_role')) ?></h3>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label><?= e(t('role_name')) ?><input type="text" name="name" required></label>
    <label><?= e(t('description')) ?><input type="text" name="description"></label>
    <?php if (is_superuser()): ?>
      <label><input type="checkbox" name="is_admin" value="1"> Can access the admin area (manage entities, users, roles)</label>
      <label><input type="checkbox" name="is_superuser" value="1"> Super user (full access + can impersonate)</label>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary"><?= e(t('create')) ?></button>
  </form>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr><th><?= e(t('role_name')) ?></th><th><?= e(t('description')) ?></th><th><?= e(t('admin_area')) ?></th><th>Super</th></tr></thead>
    <tbody>
      <?php foreach ($roles as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td><?= e($r['description']) ?></td>
          <td><?= $r['is_admin'] ? e(t('yes')) : e(t('no')) ?></td>
          <td><?= $r['is_superuser'] ? e(t('yes')) : e(t('no')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
