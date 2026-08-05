<?php
/**
 * One-time installer: creates the core schema and the initial Super User.
 * Create an empty MySQL/MariaDB database first (matching config/config.php),
 * then load this file in the browser.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$error = null;
$connError = null;
try {
    db();
} catch (Throwable $e) {
    $connError = $e->getMessage();
}

$alreadyInstalled = !$connError && is_installed();

$done = false;
if (!$connError && !$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $secretQuestion = trim($_POST['secret_question'] ?? '');
    $secretAnswer = trim($_POST['secret_answer'] ?? '');

    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6 || !$secretQuestion || !$secretAnswer) {
        $error = 'Please fill every field with a valid value (password must be at least 6 characters).';
    } else {
        $pdo = db();
        $pdo->exec("CREATE TABLE roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(255) DEFAULT NULL,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            is_superuser TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            name VARCHAR(150) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            secret_question VARCHAR(255) NOT NULL,
            secret_answer_hash VARCHAR(255) NOT NULL,
            role_id INT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (role_id) REFERENCES roles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE entities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL UNIQUE,
            label VARCHAR(150) NOT NULL,
            table_name VARCHAR(80) NOT NULL UNIQUE,
            is_top_level TINYINT(1) NOT NULL DEFAULT 0,
            icon VARCHAR(50) DEFAULT NULL,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE entity_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_id INT NOT NULL,
            name VARCHAR(64) NOT NULL,
            label VARCHAR(150) NOT NULL,
            field_type ENUM('Int','String','Date','Boolean','Float','Email') NOT NULL,
            max_length INT DEFAULT NULL,
            default_value VARCHAR(255) DEFAULT NULL,
            is_required TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE entity_relationships (
            id INT AUTO_INCREMENT PRIMARY KEY,
            child_entity_id INT NOT NULL,
            parent_entity_id INT NOT NULL,
            fk_field VARCHAR(64) NOT NULL,
            relationship_type ENUM('one_to_one','one_to_many') NOT NULL DEFAULT 'one_to_many',
            label VARCHAR(150) DEFAULT NULL,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (child_entity_id) REFERENCES entities(id) ON DELETE CASCADE,
            FOREIGN KEY (parent_entity_id) REFERENCES entities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE field_conditions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            target_field_id INT NULL,
            target_relationship_id INT NULL,
            group_index INT NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            source_type ENUM('own_field','own_relationship','related_field') NOT NULL,
            source_field_id INT NULL,
            source_relationship_id INT NULL,
            via_relationship_id INT NULL,
            operator ENUM('equals','not_equals','greater_than','greater_or_equal','less_than','less_or_equal','contains','is_null','is_not_null') NOT NULL,
            compare_value VARCHAR(255) DEFAULT NULL,
            FOREIGN KEY (target_field_id) REFERENCES entity_fields(id) ON DELETE CASCADE,
            FOREIGN KEY (target_relationship_id) REFERENCES entity_relationships(id) ON DELETE CASCADE,
            FOREIGN KEY (source_field_id) REFERENCES entity_fields(id) ON DELETE CASCADE,
            FOREIGN KEY (source_relationship_id) REFERENCES entity_relationships(id) ON DELETE CASCADE,
            FOREIGN KEY (via_relationship_id) REFERENCES entity_relationships(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE role_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_id INT NOT NULL,
            entity_id INT NOT NULL,
            can_view TINYINT(1) DEFAULT 0,
            can_create TINYINT(1) DEFAULT 0,
            can_edit TINYINT(1) DEFAULT 0,
            can_delete TINYINT(1) DEFAULT 0,
            UNIQUE KEY role_entity (role_id, entity_id),
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("INSERT INTO roles (name, description, is_admin, is_superuser) VALUES
            ('Super Admin', 'Full access: manage entities, users, roles and impersonate.', 1, 1),
            ('Editor', 'Can create and edit rows for entities they are granted access to.', 0, 0),
            ('Viewer', 'Read-only access to entities they are granted access to.', 0, 0)");

        $superRole = $pdo->query("SELECT id FROM roles WHERE name = 'Super Admin'")->fetch();

        $stmt = $pdo->prepare('INSERT INTO users (email, name, password_hash, secret_question, secret_answer_hash, role_id, is_active)
                                VALUES (?, ?, ?, ?, ?, ?, 1)');
        $stmt->execute([
            strtolower($email),
            $name,
            password_hash($password, PASSWORD_DEFAULT),
            $secretQuestion,
            password_hash(strtolower($secretAnswer), PASSWORD_DEFAULT),
            $superRole['id'],
        ]);

        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Install - <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="theme-light" data-page="install">
<div class="auth-wrap">
  <div class="auth-card">
    <h1><?= e(APP_NAME) ?> — Installation</h1>

    <?php if ($connError): ?>
      <div class="alert alert-error">
        <p><strong>Could not connect to the database.</strong></p>
        <p>Create an empty database named <code><?= e(DB_NAME) ?></code> on <code><?= e(DB_HOST) ?></code>
        (matching <code>config/config.php</code>), then reload this page.</p>
        <p><small><?= e($connError) ?></small></p>
      </div>

    <?php elseif ($done): ?>
      <div class="alert alert-success">
        <p><strong>Installation complete.</strong> Your Super Admin account was created.</p>
        <p><a class="btn btn-primary" href="login.php">Go to login</a></p>
      </div>

    <?php elseif ($alreadyInstalled): ?>
      <div class="alert alert-info">
        <p>The system is already installed.</p>
        <p><a class="btn btn-primary" href="login.php">Go to login</a></p>
      </div>

    <?php else: ?>
      <p>This will create the core database schema and your Super Admin account.
      The Super Admin can create entities, users and roles, and impersonate other users.</p>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <label>Full name<input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>"></label>
        <label>Email<input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label>Password (min 6 chars)<input type="password" name="password" required minlength="6"></label>
        <label>Secret question (for password recovery)<input type="text" name="secret_question" required value="<?= e($_POST['secret_question'] ?? '') ?>" placeholder="e.g. What is your favorite book?"></label>
        <label>Secret answer<input type="text" name="secret_answer" required></label>
        <button type="submit" class="btn btn-primary">Install &amp; create Super Admin</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
