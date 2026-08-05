<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_installed()) {
    redirect('install.php');
}
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $captcha = $_POST['captcha'] ?? '';

    if (!verify_captcha($captcha)) {
        $error = t('captcha_wrong');
    } else {
        $user = attempt_login($email, $password);
        if (!$user) {
            $error = t('invalid_credentials');
        } elseif (!$user['is_active']) {
            $error = t('account_inactive');
            logout();
        } else {
            redirect('dashboard.php');
        }
    }
}
$captchaQuestion = generate_captcha();
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('login_title')) ?> - <?= e(t('app_name')) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="theme-<?= e(current_theme()) ?>">
<div class="auth-wrap">
  <div class="auth-card">
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px;">
      <a class="toggle-btn" href="set_pref.php?lang=<?= current_lang() === 'en' ? 'es' : 'en' ?>&return=login.php"><?= current_lang() === 'en' ? 'ES' : 'EN' ?></a>
      <a class="toggle-btn" href="set_pref.php?theme=<?= current_theme() === 'light' ? 'dark' : 'light' ?>&return=login.php"><?= current_theme() === 'light' ? '🌙' : '☀' ?></a>
    </div>
    <h1><?= e(t('login_title')) ?> — <?= e(t('app_name')) ?></h1>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php foreach (get_flashes() as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label><?= e(t('email')) ?><input type="email" name="email" required autofocus></label>
      <label><?= e(t('password')) ?><input type="password" name="password" required></label>
      <label><?= e(t('captcha_label')) ?>: <strong><?= e($captchaQuestion) ?></strong><input type="text" name="captcha" required autocomplete="off"></label>
      <button type="submit" class="btn btn-primary" style="width:100%;"><?= e(t('login')) ?></button>
    </form>
    <div class="auth-links">
      <a href="forgot_password.php"><?= e(t('forgot_password')) ?></a><br>
      <?= e(t('no_account_yet')) ?> <a href="register.php"><?= e(t('register')) ?></a>
    </div>
  </div>
</div>
</body>
</html>
