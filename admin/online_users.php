<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entity_engine.php';

if (!is_installed()) { redirect('../install.php'); }
require_admin();

$users = db()->query("SELECT u.*, r.name AS role_name
                       FROM users u JOIN roles r ON r.id = u.role_id
                       ORDER BY (u.last_seen_at IS NULL) ASC, u.last_seen_at DESC, u.name ASC")->fetchAll();

$onlineCount = 0;
foreach ($users as $u) {
    if (is_online($u['last_seen_at'])) {
        $onlineCount++;
    }
}

$metaRefresh = 20; // seconds - keep this presence list reasonably fresh without needing JS/AJAX
$pageTitle = t('online_users');
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h1><?= e(t('online_users')) ?> <span class="badge"><?= (int) $onlineCount ?> <?= e(t('online')) ?></span></h1>
</div>

<div class="card">
  <p class="text-muted"><?= e(t('online_users_hint')) ?></p>
  <table class="data-table">
    <thead>
      <tr>
        <th></th>
        <th><?= e(t('name')) ?></th>
        <th><?= e(t('email')) ?></th>
        <th><?= e(t('assign_role')) ?></th>
        <th><?= e(t('last_seen')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): $online = is_online($u['last_seen_at']); ?>
        <tr>
          <td><span class="status-dot <?= $online ? 'status-online' : 'status-offline' ?>" title="<?= $online ? e(t('online')) : e(t('offline')) ?>"></span></td>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><?= e($u['role_name']) ?></td>
          <td><?= $online ? '<strong>' . e(t('online')) . '</strong>' : e(time_ago($u['last_seen_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
