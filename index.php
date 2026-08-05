<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_installed()) {
    redirect('install.php');
}
redirect(is_logged_in() ? 'dashboard.php' : 'login.php');
