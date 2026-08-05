<?php
/**
 * Shared page header/shell. Expects config/db/functions/auth/entity_engine
 * already loaded and require_login() already called by the including page.
 * Set $pageTitle before including this file.
 */
$pageTitle = $pageTitle ?? t('dashboard');
$user = current_user();
$real = real_user();
$topLevelEntities = get_all_entities(true);
$currentEntity = $_GET['entity'] ?? null;
// Cache-bust static assets with their file mtime, so browsers that have
// aggressively cached an older assets/js/app.js or style.css (no explicit
// Cache-Control headers are sent for static files) always pick up the
// latest version right after a deploy, instead of silently running stale JS.
$assetVer = @filemtime(__DIR__ . '/../assets/js/app.js') ?: time();
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - <?= e(t('app_name')) ?></title>
<link rel="stylesheet" href="<?= strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../assets/css/style.css' : 'assets/css/style.css' ?>?v=<?= (int) $assetVer ?>">
</head>
<body class="theme-<?= e(current_theme()) ?>">
<div class="app-shell">
  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
  <aside class="sidebar">
    <div class="brand"><?= e(t('app_name')) ?></div>
    <nav>
      <a href="<?= strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../dashboard.php' : 'dashboard.php' ?>" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>"><?= e(t('dashboard')) ?></a>

      <div class="section-title"><?= e(t('top_level_entities')) ?></div>
      <?php $entityBase = strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../entity.php' : 'entity.php'; ?>
      <?php foreach ($topLevelEntities as $ent): ?>
        <a href="<?= e($entityBase) ?>?entity=<?= e($ent['name']) ?>" class="<?= $currentEntity === $ent['name'] ? 'active' : '' ?>"><?= e($ent['label']) ?></a>
      <?php endforeach; ?>

      <?php if (is_admin()): ?>
        <div class="section-title"><?= e(t('admin_area')) ?></div>
        <?php $adminBase = strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '' : 'admin/'; ?>
        <a href="<?= e($adminBase) ?>entities.php" class="<?= in_array(basename($_SERVER['PHP_SELF']), ['entities.php', 'entity_edit.php']) ? 'active' : '' ?>"><?= e(t('entities')) ?></a>
        <a href="<?= e($adminBase) ?>relationships.php" class="<?= basename($_SERVER['PHP_SELF']) === 'relationships.php' ? 'active' : '' ?>"><?= e(t('relationships')) ?></a>
        <a href="<?= e($adminBase) ?>roles.php" class="<?= basename($_SERVER['PHP_SELF']) === 'roles.php' ? 'active' : '' ?>"><?= e(t('roles')) ?></a>
        <a href="<?= e($adminBase) ?>permissions.php" class="<?= basename($_SERVER['PHP_SELF']) === 'permissions.php' ? 'active' : '' ?>"><?= e(t('permissions')) ?></a>
        <a href="<?= e($adminBase) ?>users.php" class="<?= basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : '' ?>"><?= e(t('users')) ?></a>
      <?php endif; ?>
    </nav>
  </aside>

  <div class="main">
    <?php if (is_impersonating()): ?>
      <div class="impersonate-banner">
        <span><?= e(t('impersonating_as')) ?>: <strong><?= e($user['name']) ?></strong> (<?= e($user['role_name']) ?>)</span>
        <a href="<?= strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../stop_impersonate.php' : 'stop_impersonate.php' ?>"><?= e(t('stop_impersonating')) ?></a>
      </div>
    <?php endif; ?>

    <div class="topbar">
      <button type="button" class="hamburger-btn" id="sidebar-toggle" aria-label="<?= e(t('menu')) ?>" aria-expanded="false">&#9776;</button>
      <span class="mobile-brand"><?= e(t('app_name')) ?></span>
      <div class="spacer"></div>
      <span class="user-chip"><?= e(t('welcome')) ?>, <?= e($real['name'] ?? '') ?></span>
      <?php
        $self = $_SERVER['REQUEST_URI'];
        $setPrefBase = strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../set_pref.php' : 'set_pref.php';
        $logoutBase = strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../logout.php' : 'logout.php';
      ?>
      <a class="toggle-btn" href="<?= e($setPrefBase) ?>?lang=<?= current_lang() === 'en' ? 'es' : 'en' ?>&return=<?= urlencode($self) ?>"><?= current_lang() === 'en' ? 'ES' : 'EN' ?></a>
      <a class="toggle-btn" href="<?= e($setPrefBase) ?>?theme=<?= current_theme() === 'light' ? 'dark' : 'light' ?>&return=<?= urlencode($self) ?>"><?= current_theme() === 'light' ? '🌙 ' . e(t('dark_mode')) : '☀ ' . e(t('light_mode')) ?></a>
      <a class="toggle-btn" href="<?= e($logoutBase) ?>"><?= e(t('logout')) ?></a>
    </div>

    <div class="content">
      <?php foreach (get_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
