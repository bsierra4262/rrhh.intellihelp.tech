<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Conectores opcionales de WhatsApp (CallMeBot, Cloud API de Meta, Evolution API, WAHA).
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Normaliza un teléfono a solo dígitos (formato internacional).
 */
function wa_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone);
}

/**
 * Envía un mensaje de WhatsApp con el proveedor configurado.
 *
 * Proveedores: callmebot (apikey por destinatario), cloud (Cloud API de Meta con plantilla
 * aprobada), evolution / waha (gateway propio vinculado por QR).
 */
function send_whatsapp(array $user, string $text, ?string &$error = null): bool
{
    $driver = setting('wa_driver', 'off');
    if ($driver === 'off' || empty($user['notify_whatsapp']) || empty($user['phone'])) {
        return false;
    }
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
        // Evolution API: POST {url}/message/sendText/{instancia} con encabezado apikey.
        [$code, $body] = http_request(
            rtrim(setting('wa_gw_url'), '/') . '/message/sendText/' . rawurlencode(setting('wa_gw_instance', 'rrhh')),
            json_encode(['number' => $phone, 'text' => $text]),
            ['apikey: ' . setting('wa_gw_key'), 'Content-Type: application/json']
        );
    } elseif ($driver === 'waha') {
        // WAHA: POST {url}/api/sendText con encabezado X-Api-Key.
        [$code, $body] = http_request(
            rtrim(setting('wa_gw_url'), '/') . '/api/sendText',
            json_encode(['session' => setting('wa_gw_instance', 'default'), 'chatId' => $phone . '@c.us', 'text' => $text]),
            ['X-Api-Key: ' . setting('wa_gw_key'), 'Content-Type: application/json']
        );
    } else {
        return false;
    }

    $ok = $code >= 200 && $code < 300;
    if (!$ok) {
        $error = "HTTP $code: " . mb_substr((string) $body, 0, 300);
    }
    log_line('whatsapp.log', ($ok ? 'OK ' : 'ERROR ') . $phone . ($ok ? '' : ' — ' . $error));

    return $ok;
}
