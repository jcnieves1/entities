<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_installed()) { redirect('../install.php'); }
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!is_superuser()) {
        http_response_code(403);
        die(t('access_denied'));
    }
    $targetId = (int) ($_POST['user_id'] ?? 0);
    if (start_impersonation($targetId)) {
        flash('info', t('impersonating_as') . ' ' . (get_user_by_id($targetId)['name'] ?? ''));
    } else {
        flash('error', 'Could not impersonate that user.');
    }
}
redirect('../dashboard.php');
