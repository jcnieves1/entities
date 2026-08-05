<?php
/** Switches language and/or theme (top-bar toggles), then redirects back. */
require_once __DIR__ . '/config/config.php';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'es'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
    setcookie('lang', $_GET['lang'], time() + 60 * 60 * 24 * 365, '/');
}
if (isset($_GET['theme']) && in_array($_GET['theme'], ['light', 'dark'], true)) {
    $_SESSION['theme'] = $_GET['theme'];
    setcookie('theme', $_GET['theme'], time() + 60 * 60 * 24 * 365, '/');
}

$return = $_GET['return'] ?? 'dashboard.php';
// Only allow relative redirects within the app.
if (preg_match('#^https?://#i', $return) || strpos($return, '//') === 0) {
    $return = 'dashboard.php';
}
header('Location: ' . $return);
exit;
