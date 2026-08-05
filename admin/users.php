<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$roles = db()->query('SELECT * FROM roles ORDER BY name')->fetchAll();
$error = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editUser = $editId ? get_user_by_id($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $secretQuestion = trim($_POST['secret_question'] ?? '') ?: 'Not set';
        $secretAnswer = trim($_POST['secret_answer'] ?? '') ?: bin2hex(random_bytes(6));

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6 || !$roleId) {
            $error = 'Please fill every required field (password min 6 chars).';
        } elseif (get_user_by_email($email)) {
            $error = t('email_taken');
        } else {
            $stmt = db()->prepare('INSERT INTO users (email, name, password_hash, secret_question, secret_answer_hash, role_id, is_active) VALUES (?,?,?,?,?,?,1)');
            $stmt->execute([strtolower($email), $name, password_hash($password, PASSWORD_DEFAULT), $secretQuestion, password_hash(strtolower($secretAnswer), PASSWORD_DEFAULT), $roleId]);
            flash('success', t('user_created'));
            redirect('users.php');
        }
    } elseif ($action === 'update' && $editUser) {
        $roleId = (int) ($_POST['role_id'] ?? $editUser['role_id']);
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $newPassword = $_POST['new_password'] ?? '';

        if ($newPassword !== '' && strlen($newPassword) < 6) {
            $error = 'New password must be at least 6 characters.';
        } else {
            if ($newPassword !== '') {
                $stmt = db()->prepare('UPDATE users SET role_id=?, is_active=?, password_hash=? WHERE id=?');
                $stmt->execute([$roleId, $isActive, password_hash($newPassword, PASSWORD_DEFAULT), $editUser['id']]);
            } else {
                $stmt = db()->prepare('UPDATE users SET role_id=?, is_active=? WHERE id=?');
                $stmt->execute([$roleId, $isActive, $editUser['id']]);
            }
            flash('success', t('user_updated'));
            redirect('users.php');
        }
    }
}

$users = db()->query('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.id')->fetchAll();
$pageTitle = t('manage_users');
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><h1><?= e(t('manage_users')) ?></h1></div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($editUser): ?>
  <div class="card">
    <h3><?= e(t('edit')) ?>: <?= e($editUser['name']) ?> (<?= e($editUser['email']) ?>)</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <label><?= e(t('assign_role')) ?>
        <select name="role_id">
          <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) $editUser['role_id'] === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><input type="checkbox" name="is_active" value="1" <?= $editUser['is_active'] ? 'checked' : '' ?>> <?= e(t('active')) ?></label>
      <label><?= e(t('new_password')) ?> (leave blank to keep current)<input type="password" name="new_password" minlength="6"></label>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= e(t('save')) ?></button>
        <a class="btn btn-secondary" href="users.php"><?= e(t('cancel')) ?></a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h3><?= e(t('new_user')) ?></h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label><?= e(t('name')) ?><input type="text" name="name" required></label>
    <label><?= e(t('email')) ?><input type="email" name="email" required></label>
    <label><?= e(t('password')) ?><input type="password" name="password" required minlength="6"></label>
    <label><?= e(t('assign_role')) ?>
      <select name="role_id" required>
        <option value="">--</option>
        <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><?= e(t('secret_question')) ?><input type="text" name="secret_question"></label>
    <label><?= e(t('secret_answer')) ?><input type="text" name="secret_answer"></label>
    <button type="submit" class="btn btn-primary"><?= e(t('create')) ?></button>
  </form>
</div>

<div class="card">
  <table class="data-table">
    <thead><tr><th><?= e(t('name')) ?></th><th><?= e(t('email')) ?></th><th><?= e(t('assign_role')) ?></th><th><?= e(t('active')) ?></th><th><?= e(t('actions')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><span class="badge"><?= e($u['role_name']) ?></span></td>
          <td><?= $u['is_active'] ? e(t('yes')) : e(t('no')) ?></td>
          <td>
            <a href="users.php?edit=<?= (int) $u['id'] ?>"><?= e(t('edit')) ?></a>
            <?php if (is_superuser() && (int) $u['id'] !== (int) real_user()['id']): ?>
              &nbsp;|&nbsp;
              <form class="inline-form" method="post" action="impersonate.php">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn-link" style="background:none;border:none;color:var(--primary);cursor:pointer;padding:0;" data-confirm="<?= e(t('impersonate_confirm')) ?>"><?= e(t('impersonate')) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
