<?php
/**
 * Entity System - Global configuration.
 * Edit the DB_* constants to match your MySQL/MariaDB server, then visit
 * install.php once to create the schema and the initial super user.
 */

// ---- Database settings ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'juanca44_entities');
define('DB_USER', 'juanca44_entities_user');
define('DB_PASS', 'Michael1Scott');
define('DB_CHARSET', 'utf8mb4');

// ---- App settings ----
define('APP_NAME', 'Entity System');
define('DEFAULT_LANG', 'en');
define('DEFAULT_THEME', 'light');
define('ROWS_PER_PAGE', 20);

// Field types supported by the entity engine
define('FIELD_TYPES', ['Int', 'String', 'Date', 'Boolean', 'Float']);

// ---- Error reporting (disable display_errors in production) ----
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

// ---- Sessions ----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Language / theme preference (session first, then cookie, then default)
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = $_COOKIE['lang'] ?? DEFAULT_LANG;
}
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = $_COOKIE['theme'] ?? DEFAULT_THEME;
}
