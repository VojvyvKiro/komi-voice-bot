<?php
/**
 * Админка: инициализация, HTTP Basic Auth
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if (ADMIN_USER === '' || ADMIN_PASS === '') {
    http_response_code(503);
    exit('Admin credentials are not configured');
}

// HTTP Basic Auth
if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
    header('WWW-Authenticate: Basic realm="FU-Lab admin"');
    http_response_code(401);
    exit('Гиж логин да пароль.');
}
if (!hash_equals(ADMIN_USER, (string)$_SERVER['PHP_AUTH_USER']) ||
    !hash_equals(ADMIN_PASS, (string)$_SERVER['PHP_AUTH_PW'])) {
    header('WWW-Authenticate: Basic realm="FU-Lab admin"');
    http_response_code(401);
    exit('Логин либӧ пароль абу стӧч.');
}

require_once __DIR__ . '/../queue.php';

// Basic Auth браузер отправляет автоматически: защищаем POST токеном сессии.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true, 'samesite' => 'Strict', 'path' => '/vk_bot/admin/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    ]);
    session_start();
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$adminCsrf = $_SESSION['csrf'];
session_write_close();

function admin_action_review(): void
{
    global $adminCsrf;
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($adminCsrf, $_POST['csrf'])) {
        http_response_code(403);
        exit('Обновите страницу и повторите действие.');
    }
    recording_review((int)($_POST['recording_id'] ?? 0), (string)($_POST['action'] ?? ''));
}
