<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Almacenamiento de la documentación en Google Drive.
 *
 * Estructura dentro de la carpeta raíz configurada:
 *   Nombre y Apellido / Año de la licencia / Tipo de licencia / AAAAMMDD.ext
 * Al crear la carpeta de un año se generan las carpetas de todos los tipos de licencia.
 * Los archivos se renombran con la fecha de carga; si ya existe uno ese día (con cualquier
 * extensión) se agrega -2, -3…
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const DRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';

/**
 * Indica si el almacenamiento en Google Drive está configurado.
 */
function drive_enabled(): bool
{
    return setting('drive_root_folder') !== '' && setting('gcal_sa_json') !== '';
}

/**
 * Llama a la API de Google Drive.
 *
 * @param array<int, string> $headers Encabezados adicionales.
 * @return array{0: int, 1: string} Código HTTP y cuerpo de la respuesta.
 */
function drive_request(string $method, string $url, ?string $body = null, array $headers = [], ?string &$error = null): array
{
    $token = google_token('https://www.googleapis.com/auth/drive', $error);
    if (!$token) {
        return [0, ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $token], $headers),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Errores transitorios de Google: las lecturas y eliminaciones se reintentan.
    static $retries = 0;
    if (($code >= 500 || $code === 429 || $code === 0) && in_array($method, ['GET', 'DELETE'], true) && $retries < 2) {
        $retries++;
        usleep(700000 * $retries);
        $result = drive_request($method, $url, $body, $headers, $error);
        $retries = 0;

        return $result;
    }
    if ($code >= 300) {
        $error = "Google Drive respondió HTTP $code: " . mb_substr((string) $res, 0, 300);
        log_line('gdrive.log', "ERROR $method $url — $error");
    }

    return [$code, (string) $res];
}

/**
 * Busca archivos o carpetas dentro de una carpeta.
 *
 * @return array<int, array{id: string, name: string}>
 */
function drive_list(string $parent, string $extraQuery = ''): array
{
    $q = "'" . $parent . "' in parents and trashed = false" . ($extraQuery !== '' ? " and $extraQuery" : '');
    [$code, $body] = drive_request('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $q,
        'fields' => 'files(id,name)',
        'pageSize' => 1000,
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
    ]));

    return $code === 200 ? (json_decode($body, true)['files'] ?? []) : [];
}

/**
 * Escapa un texto para usarlo dentro de una consulta de Drive.
 */
function drive_quote(string $s): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
}

/**
 * Devuelve el ID de una subcarpeta, creándola si no existe.
 */
function drive_folder(string $name, string $parent, ?string &$error = null): ?string
{
    $found = drive_list($parent, 'name = ' . drive_quote($name) . " and mimeType = '" . DRIVE_FOLDER_MIME . "'");
    if ($found) {
        return $found[0]['id'];
    }
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        [$code, $body] = drive_request(
            'POST',
            'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&fields=id',
            json_encode(['name' => $name, 'mimeType' => DRIVE_FOLDER_MIME, 'parents' => [$parent]], JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json; charset=UTF-8'],
            $error
        );
        if ($code === 200) {
            return json_decode($body, true)['id'] ?? null;
        }
        // Google puede crear la carpeta y fallar al responder: se verifica antes de reintentar.
        usleep(800000 * $attempt);
        $found = drive_list($parent, 'name = ' . drive_quote($name) . " and mimeType = '" . DRIVE_FOLDER_MIME . "'");
        if ($found) {
            return $found[0]['id'];
        }
    }

    return null;
}

/**
 * Carpeta del tipo de licencia para una persona y un año, creando la estructura completa
 * (persona, año y todas las carpetas de tipos de licencia) si todavía no existe.
 */
function drive_leave_folder(string $person, int $year, string $typeName, ?string &$error = null): ?string
{
    $root = setting('drive_root_folder');
    $personId = drive_folder(trim($person), $root, $error);
    $yearId = $personId ? drive_folder((string) $year, $personId, $error) : null;
    if (!$yearId) {
        return null;
    }
    $existing = array_column(drive_list($yearId, "mimeType = '" . DRIVE_FOLDER_MIME . "'"), 'id', 'name');
    foreach (array_column(leave_types(false), 'name') as $name) {
        if (!isset($existing[$name])) {
            $existing[$name] = drive_folder($name, $yearId, $error);
        }
    }

    return $existing[$typeName] ?? drive_folder($typeName, $yearId, $error);
}

/**
 * Nombre de archivo con la fecha de carga (AAAAMMDD), sin repetir uno existente en la carpeta.
 */
function drive_file_name(string $folderId, string $ext): string
{
    $base = date('Ymd');
    $taken = array_map(
        fn ($n) => pathinfo($n, PATHINFO_FILENAME),
        array_column(drive_list($folderId, 'name contains ' . drive_quote($base)), 'name')
    );
    $stem = $base;
    for ($n = 2; in_array($stem, $taken, true); $n++) {
        $stem = "$base-$n";
    }

    return "$stem.$ext";
}

/**
 * Sube un archivo a una carpeta de Drive.
 *
 * @return array{id: string, name: string, webViewLink?: string}|null
 */
function drive_upload(string $path, string $mime, string $name, string $folderId, ?string &$error = null): ?array
{
    $boundary = 'rrhh' . bin2hex(random_bytes(12));
    $meta = json_encode(['name' => $name, 'parents' => [$folderId]], JSON_UNESCAPED_UNICODE);
    $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
        . "--$boundary\r\nContent-Type: $mime\r\n\r\n" . file_get_contents($path) . "\r\n--$boundary--";
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        [$code, $res] = drive_request(
            'POST',
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,webViewLink',
            $body,
            ['Content-Type: multipart/related; boundary=' . $boundary],
            $error
        );
        if ($code === 200) {
            return json_decode($res, true);
        }
        // Si el archivo quedó creado pese al error, se usa ese en lugar de duplicarlo.
        usleep(800000 * $attempt);
        [$c2, $b2] = drive_request('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => "'$folderId' in parents and trashed = false and name = " . drive_quote($name),
            'fields' => 'files(id,name,webViewLink)',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]));
        $existing = $c2 === 200 ? (json_decode($b2, true)['files'][0] ?? null) : null;
        if ($existing) {
            return $existing;
        }
    }

    return null;
}

/**
 * Descarga el contenido de un archivo de Drive.
 */
function drive_download(string $fileId, ?string &$error = null): ?string
{
    [$code, $body] = drive_request('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true', null, [], $error);

    return $code === 200 ? $body : null;
}

/**
 * Elimina un archivo o carpeta de Drive.
 */
function drive_delete(string $fileId): bool
{
    [$code] = drive_request('DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?supportsAllDrives=true');

    return $code === 204 || $code === 200 || $code === 404;
}
