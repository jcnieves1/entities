<?php
/**
 * General helper functions: identifiers, CSRF, captcha, i18n, flash messages.
 */

// ---------------------------------------------------------------------
// Identifiers (used whenever a dynamic table/column name is built)
// ---------------------------------------------------------------------
function sanitize_identifier(string $name, int $maxLen = 60): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9_]/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = trim($name, '_');
    if ($name === '' || preg_match('/^[0-9]/', $name)) {
        $name = 'f_' . $name;
    }
    return substr($name, 0, $maxLen);
}

// ---------------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
        http_response_code(400);
        die('Invalid or expired form submission (CSRF check failed). Go back and try again.');
    }
}

// ---------------------------------------------------------------------
// Basic math captcha (no GD dependency required)
// ---------------------------------------------------------------------
function generate_captcha(): string
{
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $ops = ['+', '-'];
    $op = $ops[array_rand($ops)];
    $answer = $op === '+' ? $a + $b : $a - $b;
    $_SESSION['captcha_answer'] = $answer;
    return "$a $op $b";
}

function verify_captcha(?string $input): bool
{
    if ($input === null || !isset($_SESSION['captcha_answer'])) {
        return false;
    }
    $ok = ((int) trim($input)) === (int) $_SESSION['captcha_answer'];
    unset($_SESSION['captcha_answer']);
    return $ok;
}

// ---------------------------------------------------------------------
// i18n
// ---------------------------------------------------------------------
function current_lang(): string
{
    return $_SESSION['lang'] ?? DEFAULT_LANG;
}

function load_lang(): array
{
    static $strings = null;
    if ($strings === null) {
        $code = in_array(current_lang(), ['en', 'es'], true) ? current_lang() : 'en';
        $strings = require __DIR__ . '/../lang/' . $code . '.php';
    }
    return $strings;
}

function t(string $key): string
{
    $strings = load_lang();
    return $strings[$key] ?? $key;
}

// ---------------------------------------------------------------------
// Theme
// ---------------------------------------------------------------------
function current_theme(): string
{
    return ($_SESSION['theme'] ?? DEFAULT_THEME) === 'dark' ? 'dark' : 'light';
}

// ---------------------------------------------------------------------
// Flash messages (one-time, shown after redirect)
// ---------------------------------------------------------------------
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// ---------------------------------------------------------------------
// Misc
// ---------------------------------------------------------------------
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function slugify(string $value): string
{
    return sanitize_identifier($value, 64);
}
