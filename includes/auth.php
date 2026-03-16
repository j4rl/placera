<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

function plc_csrf_token(): string
{
    if (empty($_SESSION['plc_csrf'])) {
        $_SESSION['plc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['plc_csrf'];
}

function plc_verify_csrf_or_403(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($token) || !hash_equals((string)($_SESSION['plc_csrf'] ?? ''), $token)) {
        plc_json(['ok' => false, 'error' => 'invalid_csrf'], 403);
    }
}

function plc_current_user(): ?array
{
    static $cached = null;
    static $loaded = false;
    if ($loaded) {
        return $cached;
    }
    $loaded = true;

    $uid = isset($_SESSION['plc_user_id']) ? (int)$_SESSION['plc_user_id'] : 0;
    if ($uid <= 0) {
        return null;
    }

    $db = plc_db();
    $stmt = $db->prepare(
        'SELECT id, username, full_name, email, role, status
         FROM plc_users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc() ?: null;

    if (!$user || $user['status'] !== 'approved') {
        unset($_SESSION['plc_user_id']);
        return null;
    }

    $cached = $user;
    return $cached;
}

function plc_login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['plc_user_id'] = $userId;
}

function plc_logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function plc_require_login(bool $asJson = false): array
{
    $user = plc_current_user();
    if ($user) {
        return $user;
    }
    if ($asJson) {
        plc_json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    plc_redirect('index.php');
}

function plc_require_admin(bool $asJson = false): array
{
    $user = plc_require_login($asJson);
    if (($user['role'] ?? '') === 'admin') {
        return $user;
    }
    if ($asJson) {
        plc_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
    plc_redirect('/placera/app.php');
}
