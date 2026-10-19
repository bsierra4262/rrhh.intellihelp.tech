<?php
declare(strict_types=1);

function wa_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone);
}

/**
 * Envía un WhatsApp según el driver configurado:
 *  - callmebot: gratis, cada destinatario activa su apikey (ver Mi perfil).
 *  - cloud: WhatsApp Cloud API de Meta (token + phone number id; plantilla aprobada con 1 parámetro).
 *  - evolution / waha: gateway propio y gratuito (Evolution API o WAHA) vinculado por QR a un número de la empresa.
 */
function send_whatsapp(array $user, string $text, ?string &$error = null): bool
{
    $driver = setting('wa_driver', 'off');
    if ($driver === 'off' || empty($user['notify_whatsapp']) || empty($user['phone'])) return false;
    $phone = wa_phone($user['phone']);

    if ($driver === 'callmebot') {
        if (empty($user['wa_apikey'])) {
            $error = 'El usuario no tiene apikey de CallMeBot.';
            return false;
        }
        [$code, $body] = http_request('https://api.callmebot.com/whatsapp.php?' . http_build_query([
            'phone' => '+' . $phone, 'text' => $text, 'apikey' => $user['wa_apikey'],
        ]));
    } elseif ($driver === 'cloud') {
        $template = setting('wa_cloud_template');
        $payload = $template !== ''
            ? ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'template', 'template' => [
                'name' => $template, 'language' => ['code' => setting('wa_cloud_lang', 'es_AR')],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr(str_replace(["\n", "\t"], ' ', $text), 0, 1000)]]]],
            ]]
            : ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]];
        [$code, $body] = http_request(
            'https://graph.facebook.com/v21.0/' . rawurlencode(setting('wa_cloud_phone_id')) . '/messages',
            json_encode($payload),
            ['Authorization: Bearer ' . setting('wa_cloud_token'), 'Content-Type: application/json']
        );
    } elseif ($driver === 'evolution') {
        // Evolution API: POST {url}/message/sendText/{instancia}  header apikey
        [$code, $body] = http_request(
            rtrim(setting('wa_gw_url'), '/') . '/message/sendText/' . rawurlencode(setting('wa_gw_instance', 'rrhh')),
            json_encode(['number' => $phone, 'text' => $text]),
            ['apikey: ' . setting('wa_gw_key'), 'Content-Type: application/json']
        );
    } elseif ($driver === 'waha') {
        // WAHA: POST {url}/api/sendText  header X-Api-Key
        [$code, $body] = http_request(
            rtrim(setting('wa_gw_url'), '/') . '/api/sendText',
            json_encode(['session' => setting('wa_gw_instance', 'default'), 'chatId' => $phone . '@c.us', 'text' => $text]),
            ['X-Api-Key: ' . setting('wa_gw_key'), 'Content-Type: application/json']
        );
    } else {
        return false;
    }

    $ok = $code >= 200 && $code < 300;
    if (!$ok) $error = "HTTP $code: " . mb_substr((string) $body, 0, 300);
    log_line('whatsapp.log', ($ok ? 'OK ' : 'ERROR ') . $phone . ($ok ? '' : ' — ' . $error));
    return $ok;
}

function http_request(string $url, ?string $postBody = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($postBody !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) $body = curl_error($ch);
    curl_close($ch);
    return [$code, $body];
}

/** Publica un mensaje en un espacio de Google Chat (webhook entrante). Gratis con Google Workspace. */
function send_gchat(string $text, ?string &$error = null): bool
{
    $hook = setting('gchat_webhook');
    if ($hook === '') return false;
    [$code, $body] = http_request($hook, json_encode(['text' => $text], JSON_UNESCAPED_UNICODE), ['Content-Type: application/json; charset=UTF-8']);
    $ok = $code >= 200 && $code < 300;
    if (!$ok) $error = "HTTP $code: " . mb_substr((string) $body, 0, 300);
    log_line('gchat.log', ($ok ? 'OK' : 'ERROR ' . $error));
    return $ok;
}
