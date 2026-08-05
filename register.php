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
$old = ['email' => '', 'name' => '', 'secret_question' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $old['email'] = trim($_POST['email'] ?? '');
    $old['name'] = trim($_POST['name'] ?? '');
    $old['secret_question'] = trim($_POST['secret_question'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $secretAnswer = trim($_POST['secret_answer'] ?? '');
    $captcha = $_POST['captcha'] ?? '';

    if (!verify_captcha($captcha)) {
        $error = t('captcha_wrong');
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || !$old['name'] || strlen($password) < 6) {
        $error = 'Please fill every field with a valid value (password must be at least 6 characters).';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!$old['secret_question'] || !$secretAnswer) {
        $error = 'Please provide a secret question and answer for password recovery.';
    } elseif (get_user_by_email($old['email'])) {
        $error = t('email_taken');
    } else {
        register_user($old['email'], $old['name'], $password, $old['secret_question'], $secretAnswer);
        flash('success', t('registration_success'));
        redirect('login.php');
    }
}
$captchaQuestion = generate_captcha();
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('register_title')) ?> - <?= e(t('app_name')) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="theme-<?= e(current_theme()) ?>">
<div class="auth-wrap">
  <div class="auth-card">
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px;">
      <a class="toggle-btn" href="set_pref.php?lang=<?= current_lang() === 'en' ? 'es' : 'en' ?>&return=register.php"><?= current_lang() === 'en' ? 'ES' : 'EN' ?></a>
      <a class="toggle-btn" href="set_pref.php?theme=<?= current_theme() === 'light' ? 'dark' : 'light' ?>&return=register.php"><?= current_theme() === 'light' ? '🌙' : '☀' ?></a>
    </div>
    <h1><?= e(t('register_title')) ?></h1>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label><?= e(t('name')) ?><input type="text" name="name" required value="<?= e($old['name']) ?>"></label>
      <label><?= e(t('email')) ?><input type="email" name="email" required value="<?= e($old['email']) ?>"></label>
      <label><?= e(t('password')) ?><input type="password" name="password" required minlength="6"></label>
      <label><?= e(t('confirm_password')) ?><input type="password" name="confirm_password" required minlength="6"></label>
      <label><?= e(t('secret_question')) ?><input type="text" name="secret_question" required value="<?= e($old['secret_question']) ?>" placeholder="e.g. What city were you born in?"></label>
      <label><?= e(t('secret_answer')) ?><input type="text" name="secret_answer" required></label>
      <label><?= e(t('captcha_label')) ?>: <strong><?= e($captchaQuestion) ?></strong><input type="text" name="captcha" required autocomplete="off"></label>
      <button type="submit" class="btn btn-primary" style="width:100%;"><?= e(t('register')) ?></button>
    </form>
    <div class="auth-links">
      <?= e(t('already_have_account')) ?> <a href="login.php"><?= e(t('login')) ?></a>
    </div>
  </div>
</div>
</body>
</html>
