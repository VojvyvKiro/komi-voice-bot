<?php
/**
 * FU-Lab • Точка входа Callback API VK
 * URL: https://домен/vk_bot/callback.php
 */
require_once __DIR__ . '/bot_logic.php';

// VK иногда шлёт JSON в сыром теле
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);
    exit('bad request');
}

// Логируем каждое событие (кроме confirmation) для отладки
if (($data['type'] ?? '') !== 'confirmation') {
    log_line('event ' . ($data['type'] ?? '?'));
}

// Secret key (если задан в настройках Callback API)
if (VK_SECRET_KEY === '' ||
    !is_string($data['secret'] ?? null) ||
    !hash_equals(VK_SECRET_KEY, $data['secret'])) {
    http_response_code(403);
    exit('forbidden');
}

switch ($data['type'] ?? '') {
    // Первый тестовый запрос VK: отвечаем строкой подтверждения
    case 'confirmation':
        echo VK_CONFIRMATION_CODE;
        exit;

    case 'message_new':
        // В API 5.x объект лежит в object.message
        $msg = $data['object']['message'] ?? null;
        if (!$msg && isset($data['object']['text'])) {
            $msg = $data['object']; // совместимость со старыми версиями
        }
        if (is_array($msg)) {
            $identity = $data['event_id'] ?? null;
            if (!is_string($identity) || $identity === '') {
                $messageId = $msg['conversation_message_id'] ?? $msg['id'] ?? null;
                if (!$messageId) {
                    http_response_code(400);
                    exit('missing message id');
                }
                $identity = ($msg['peer_id'] ?? $msg['from_id'] ?? 0) . ':' . $messageId;
            }
            $msg['_event_key'] = hash('sha256', ($data['group_id'] ?? 0) . ':' . $identity);
            try {
                bot_handle_message($msg);
            } catch (Throwable $e) {
                error_log('Callback processing failed: ' . get_class($e));
                http_response_code(500);
                exit('retry');
            }
        }
        echo 'ok';
        exit;

    // message_allow: пользователь разрешил сообщения от бота
    case 'message_allow':
        // Ничего не делаем — диалог начнётся с /start
        echo 'ok';
        exit;

    case 'message_deny':
        // Пользователь запретил сообщения
        echo 'ok';
        exit;

    default:
        // Неизвестное событие — всё равно отвечаем ok, чтобы VK не ретраил
        echo 'ok';
        exit;
}