<?php
/**
 * Authentication, session and role-based access control (RBAC) helpers,
 * including super-user impersonation.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------
// User lookup
// ---------------------------------------------------------------------
function get_user_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT u.*, r.name AS role_name, r.is_admin, r.is_superuser
                            FROM users u JOIN roles r ON r.id = u.role_id
                            WHERE u.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT u.*, r.name AS role_name, r.is_admin, r.is_superuser
                            FROM users u JOIN roles r ON r.id = u.role_id
                            WHERE u.email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------
// Login / logout / register
// ---------------------------------------------------------------------
function attempt_login(string $email, string $password): ?array
{
    $user = get_user_by_email($email);
    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    $_SESSION['user_id'] = (int) $user['id'];
    return $user;
}

function logout(): void
{
    unset($_SESSION['user_id'], $_SESSION['impersonate_id']);
    session_regenerate_id(true);
}

function register_user(string $email, string $name, string $password, string $secretQuestion, string $secretAnswer): int
{
    // Default role assigned to self-registered users.
    $stmt = db()->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
    $stmt->execute(['Viewer']);
    $role = $stmt->fetch();
    $roleId = $role ? (int) $role['id'] : lowest_privilege_role_id();

    $stmt = db()->prepare('INSERT INTO users (email, name, password_hash, secret_question, secret_answer_hash, role_id, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, 1)');
    $stmt->execute([
        strtolower(trim($email)),
        trim($name),
        password_hash($password, PASSWORD_DEFAULT),
        trim($secretQuestion),
        password_hash(strtolower(trim($secretAnswer)), PASSWORD_DEFAULT),
        $roleId,
    ]);
    return (int) db()->lastInsertId();
}

function lowest_privilege_role_id(): int
{
    $row = db()->query('SELECT id FROM roles WHERE is_admin = 0 AND is_superuser = 0 ORDER BY id LIMIT 1')->fetch();
    return $row ? (int) $row['id'] : 1;
}

function reset_password_with_secret(string $email, string $secretAnswer, string $newPassword): bool
{
    $user = get_user_by_email($email);
    if (!$user || !password_verify(strtolower(trim($secretAnswer)), $user['secret_answer_hash'])) {
        return false;
    }
    $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
    return true;
}

// ---------------------------------------------------------------------
// Current session user + impersonation
// ---------------------------------------------------------------------
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** The account that actually authenticated (ignores impersonation). */
function real_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $cache = null;
    if ($cache === null) {
        $cache = get_user_by_id((int) $_SESSION['user_id']);
    }
    return $cache;
}

/** The account whose permissions currently apply (impersonated user, if any). */
function current_user(): ?array
{
    if (!empty($_SESSION['impersonate_id'])) {
        static $cache = null;
        if ($cache === null) {
            $cache = get_user_by_id((int) $_SESSION['impersonate_id']);
        }
        return $cache;
    }
    return real_user();
}

function is_impersonating(): bool
{
    return !empty($_SESSION['impersonate_id']);
}

function start_impersonation(int $targetUserId): bool
{
    $real = real_user();
    if (!$real || !$real['is_superuser']) {
        return false;
    }
    $target = get_user_by_id($targetUserId);
    if (!$target) {
        return false;
    }
    $_SESSION['impersonate_id'] = $targetUserId;
    return true;
}

function stop_impersonation(): void
{
    unset($_SESSION['impersonate_id']);
}

// ---------------------------------------------------------------------
// Presence ("who's online")
// ---------------------------------------------------------------------

// A user is considered "online" if their last_seen_at is within this many
// seconds. Kept generous relative to the write-throttle below since presence
// here is page-load-driven, not a live heartbeat.
const ONLINE_THRESHOLD_SECONDS = 180;

/**
 * Record that the REAL (never the impersonated) account is actively using
 * the app right now. Throttled to at most once every 30s per session so a
 * click-heavy user doesn't turn into a write on every single request.
 */
function touch_last_seen(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $now = time();
    if (!empty($_SESSION['last_seen_touch']) && ($now - $_SESSION['last_seen_touch']) < 30) {
        return;
    }
    $_SESSION['last_seen_touch'] = $now;
    // Compute the timestamp in PHP (as explicit UTC) rather than using SQL's
    // NOW() - MySQL/MariaDB's NOW() reflects the DB server's own SYSTEM
    // timezone, which on many hosts (including this app's shared hosting)
    // does not match PHP's UTC default set in config.php. Mixing the two
    // would silently skew every online/offline and "last seen" calculation
    // by however many hours the two clocks disagree.
    $stmt = db()->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?');
    $stmt->execute([gmdate('Y-m-d H:i:s', $now), (int) $_SESSION['user_id']]);
}

/** True if $lastSeenAt (a DATETIME string or null) falls within the online window. */
function is_online(?string $lastSeenAt): bool
{
    if (!$lastSeenAt) {
        return false;
    }
    $ts = strtotime($lastSeenAt);
    return $ts !== false && (time() - $ts) <= ONLINE_THRESHOLD_SECONDS;
}

// ---------------------------------------------------------------------
// Access guards
// ---------------------------------------------------------------------
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
    touch_last_seen();
}

function is_admin(): bool
{
    $u = current_user();
    return $u ? (bool) $u['is_admin'] || (bool) $u['is_superuser'] : false;
}

function is_superuser(): bool
{
    // Superuser powers (impersonation, user creation) always follow the REAL
    // logged in account, never the impersonated one.
    $u = real_user();
    return $u ? (bool) $u['is_superuser'] : false;
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die(t('access_denied'));
    }
}

// ---------------------------------------------------------------------
// Entity row-level permissions
// ---------------------------------------------------------------------
function has_permission(int $entityId, string $action): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    if ($u['is_admin'] || $u['is_superuser']) {
        return true;
    }
    $column = ['view' => 'can_view', 'create' => 'can_create', 'edit' => 'can_edit', 'delete' => 'can_delete'][$action] ?? null;
    if (!$column) {
        return false;
    }
    $stmt = db()->prepare("SELECT $column FROM role_permissions WHERE role_id = ? AND entity_id = ?");
    $stmt->execute([$u['role_id'], $entityId]);
    $row = $stmt->fetch();
    return $row ? (bool) $row[$column] : false;
}
