<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/entity_engine.php';

if (!is_installed()) {
    redirect('install.php');
}
require_login();

$pageTitle = t('dashboard');
$entities = get_all_entities(true);
include __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1><?= e(t('dashboard')) ?></h1></div>

<?php if (!$entities): ?>
  <div class="card">
    <p><?= e(t('no_entities_yet')) ?></p>
    <?php if (is_admin()): ?>
      <p><a class="btn btn-primary" href="admin/entities.php"><?= e(t('new_entity')) ?></a></p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card">
    <p class="text-muted"><?= e(t('top_level_entities')) ?></p>
    <div style="display:flex;flex-wrap:wrap;gap:12px;">
      <?php foreach ($entities as $ent): ?>
        <a class="btn btn-secondary" href="entity.php?entity=<?= e($ent['name']) ?>"><?= e($ent['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
