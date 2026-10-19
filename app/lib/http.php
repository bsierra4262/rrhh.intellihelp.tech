<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Cliente HTTP mínimo basado en cURL.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Ejecuta una petición HTTP (GET, o POST si se indica cuerpo).
 *
 * @return array{0: int, 1: string|false} Código HTTP y cuerpo de la respuesta.
 */
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
    if ($body === false) {
        $body = curl_error($ch);
    }
    curl_close($ch);

    return [$code, $body];
}
