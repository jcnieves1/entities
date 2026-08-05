<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_installed()) {
    redirect('install.php');
}

$step = $_POST['step'] ?? 'find';
$error = null;
$success = null;
$secretQuestion = null;
$email = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if ($step === 'find') {
        $user = get_user_by_email($email);
        if (!$user) {
            $error = t('secret_answer_wrong');
            $step = 'find';
        } else {
            $secretQuestion = $user['secret_question'];
            $step = 'answer';
        }
    } elseif ($step === 'answer') {
        $answer = trim($_POST['secret_answer'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $user = get_user_by_email($email);
        $secretQuestion = $user['secret_question'] ?? null;

        if (strlen($newPassword) < 6 || $newPassword !== $confirm) {
            $error = 'Passwords must match and be at least 6 characters.';
        } elseif (!reset_password_with_secret($email, $answer, $newPassword)) {
            $error = t('secret_answer_wrong');
        } else {
            flash('success', t('password_reset_success'));
            redirect('login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('forgot_password_title')) ?> - <?= e(t('app_name')) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="theme-<?= e(current_theme()) ?>">
<div class="auth-wrap">
  <div class="auth-card">
    <h1><?= e(t('forgot_password_title')) ?></h1>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($step === 'answer' && $secretQuestion): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="answer">
        <input type="hidden" name="email" value="<?= e($email) ?>">
        <label><?= e(t('secret_question')) ?><br><strong><?= e($secretQuestion) ?></strong></label>
        <label><?= e(t('secret_answer')) ?><input type="text" name="secret_answer" required></label>
        <label><?= e(t('new_password')) ?><input type="password" name="new_password" required minlength="6"></label>
        <label><?= e(t('confirm_password')) ?><input type="password" name="confirm_password" required minlength="6"></label>
        <button type="submit" class="btn btn-primary" style="width:100%;"><?= e(t('reset_password')) ?></button>
      </form>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="find">
        <label><?= e(t('email')) ?><input type="email" name="email" required value="<?= e($email) ?>"></label>
        <button type="submit" class="btn btn-primary" style="width:100%;"><?= e(t('submit')) ?></button>
      </form>
    <?php endif; ?>

    <div class="auth-links"><a href="login.php"><?= e(t('back')) ?></a></div>
  </div>
</div>
</body>
</html>
