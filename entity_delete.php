<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/entity_engine.php';

if (!is_installed()) { redirect('install.php'); }
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}
require_csrf();

$entityName = $_POST['entity'] ?? '';
$id = (int) ($_POST['id'] ?? 0);
$return = $_POST['return'] ?? ('entity.php?entity=' . urlencode($entityName));
// Only allow relative redirects within the app.
if (preg_match('#^https?://#i', $return) || strpos($return, '//') === 0) {
    $return = 'dashboard.php';
}

$entity = get_entity_by_name($entityName);
if (!$entity || !$id) {
    flash('error', 'Unknown entity or record.');
    redirect($return);
}
if (!has_permission($entity['id'], 'delete')) {
    http_response_code(403);
    die(t('permission_denied_message'));
}

entity_delete_row($entity, $id);
flash('success', t('row_deleted'));
redirect($return);
