<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Notificaciones a un espacio de Google Chat mediante webhook entrante.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Publica un mensaje en el espacio de Google Chat configurado.
 */
function send_gchat(string $text, ?string &$error = null): bool
{
    $hook = setting('gchat_webhook');
    if ($hook === '') {
        return false;
    }
    [$code, $body] = http_request($hook, json_encode(['text' => $text], JSON_UNESCAPED_UNICODE), ['Content-Type: application/json; charset=UTF-8']);
    $ok = $code >= 200 && $code < 300;
    if (!$ok) {
        $error = "HTTP $code: " . mb_substr((string) $body, 0, 300);
    }
    log_line('gchat.log', ($ok ? 'OK' : 'ERROR ' . $error));

    return $ok;
}

/**
 * Arma un mensaje de Google Chat a partir de sus líneas, omitiendo las vacías.
 */
function chat_message(string ...$lines): string
{
    return implode("\n", array_filter($lines, fn ($l) => $l !== ''));
}
