<?php
/**
 * Вызовы VK API (messages.send, users.get и т.д.)
 */
require_once __DIR__ . '/config.php';

function vk_call(string $method, array $params = []): array
{
    $params['access_token'] = VK_TOKEN;
    $params['v'] = VK_API_VERSION;

    $ch = curl_init('https://api.vk.com/method/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log("[VK] curl error: $err");
        return ['error' => ['error_code' => -1, 'error_msg' => $err]];
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        error_log("[VK] bad response for $method: " . substr($raw, 0, 300));
        return ['error' => ['error_code' => -2, 'error_msg' => 'bad json']];
    }
    if (isset($json['error'])) {
        error_log("[VK] $method error {$json['error']['error_code']}: {$json['error']['error_msg']}");
    }
    return $json;
}

/** Отправить текстовое сообщение пользователю */
function vk_send(int $userId, string $text, ?array $keyboard = null): bool
{
    return vk_send_message_id($userId, $text, $keyboard) !== null;
}

/** Send to one user; return the message ID needed for reply_to, or null on failure. */
function vk_send_message_id(int $userId, string $text, ?array $keyboard = null, ?int $replyTo = null): ?int
{
    // random_id обязателен, иначе VK склеит дубли
    $params = [
        'user_id'   => $userId,
        'message'   => $text,
        'random_id' => random_int(1, 2147483647),
    ];
    if ($keyboard !== null) {
        $params['keyboard'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    if ($replyTo !== null) $params['reply_to'] = $replyTo;
    $r = vk_call('messages.send', $params);
    if (!empty($r['error'])) return null;
    $messageId = $r['response'] ?? null;
    return is_int($messageId) && $messageId > 0 ? $messageId : null;
}

/** Информация о пользователе VK */
function vk_user_info(int $vkId): ?array
{
    $r = vk_call('users.get', [
        'user_ids' => $vkId,
        'fields'   => 'sex,bdate,city',
    ]);
    if (!empty($r['error']) || empty($r['response'][0])) return null;
    return $r['response'][0];
}

/** Скачать файл по ссылке (голосовое из VK) */
function vk_download(string $url, string $destPath): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($data === false || $code !== 200) {
        error_log("[VK download] failed: code=$code err=$err url=$url");
        return false;
    }
    return file_put_contents($destPath, $data) !== false;
}