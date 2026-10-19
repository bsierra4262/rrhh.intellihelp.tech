<?php
declare(strict_types=1);

// Sincronización con un calendario compartido de Google Calendar mediante una cuenta de servicio.
// Configuración: gcal_calendar_id + gcal_sa_json (clave JSON de la cuenta de servicio, guardada cifrada).
// Opcional gcal_impersonate: con delegación de dominio, la cuenta de servicio actúa como ese usuario de Workspace
// (evita compartir el calendario con una cuenta externa).

const GCAL_COLORS = ['pending' => '5', 'approved' => '10', 'registered' => '3']; // amarillo, verde, violeta

function gcal_enabled(): bool
{
    return setting('gcal_calendar_id') !== '' && setting('gcal_sa_json') !== '';
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** Token OAuth de la cuenta de servicio (cacheado ~55 min en storage). */
function gcal_token(?string &$error = null): ?string
{
    $cache = STORAGE . '/gcal_token.json';
    $sa = json_decode(setting('gcal_sa_json'), true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        $error = 'La clave JSON de la cuenta de servicio no es válida.';
        return null;
    }
    $sub = trim(setting('gcal_impersonate'));
    $who = $sa['client_email'] . '|' . $sub;
    if (is_file($cache)) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (($c['email'] ?? '') === $who && ($c['exp'] ?? 0) > time() + 60) return $c['token'];
    }
    $now = time();
    $claims = [
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    if ($sub !== '') $claims['sub'] = $sub;
    $unsigned = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . b64url(json_encode($claims));
    if (!openssl_sign($unsigned, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        $error = 'No se pudo firmar con la clave privada.';
        return null;
    }
    [$code, $body] = http_request('https://oauth2.googleapis.com/token', http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $unsigned . '.' . b64url($sig),
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    $j = json_decode((string) $body, true);
    if ($code !== 200 || empty($j['access_token'])) {
        $error = 'Google rechazó la autenticación: ' . mb_substr((string) $body, 0, 300);
        log_line('gcal.log', 'ERROR token ' . $error);
        return null;
    }
    @file_put_contents($cache, json_encode(['email' => $who, 'token' => $j['access_token'], 'exp' => $now + (int) $j['expires_in']]));
    @chmod($cache, 0600);
    return $j['access_token'];
}

function gcal_api(string $method, string $path, ?array $payload = null, ?string &$error = null): array
{
    $token = gcal_token($error);
    if (!$token) return [0, null];
    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(setting('gcal_calendar_id')) . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 300) {
        $error = "HTTP $code: " . mb_substr((string) $body, 0, 300);
        log_line('gcal.log', "ERROR $method $path — $error");
    }
    return [$code, json_decode((string) $body, true)];
}

function gcal_event_payload(array $r): array
{
    $pending = $r['status'] === 'pending';
    $lines = [
        $r['user_name'] . ' — ' . $r['type_name'],
        fdays_kind($r['days'], $r['counting']),
        'Estado: ' . (STATUS_LABELS[$r['status']] ?? $r['status']) . ($r['decider_name'] && !$pending ? ' por ' . $r['decider_name'] : ''),
    ];
    if ($pending) $lines[] = 'Autoriza: ' . ($r['approver_name'] ?: 'RRHH');
    $lines[] = '';
    $lines[] = url('/solicitudes/' . $r['id']);
    return [
        'summary' => ($pending ? '⏳ [Pendiente] ' : '') . $r['type_name'] . ' — ' . $r['user_name'],
        'description' => implode("\n", $lines),
        'start' => ['date' => $r['start_date']],
        'end' => ['date' => date('Y-m-d', strtotime($r['end_date'] . ' +1 day'))],
        'colorId' => GCAL_COLORS[$r['status']] ?? '8',
        'transparency' => 'transparent',
        'source' => ['title' => 'Portal RRHH Intellihelp', 'url' => url('/solicitudes/' . $r['id'])],
    ];
}

/** Crea/actualiza/borra el evento según el estado de la solicitud. */
function gcal_sync(int $requestId, ?string &$error = null): bool
{
    if (!gcal_enabled()) return false;
    $r = load_request($requestId);
    if (!$r) return false;
    $eventId = $r['gcal_event_id'] ?? null;
    $active = in_array($r['status'], ['pending', 'approved', 'registered'], true);

    if (!$active) {
        if ($eventId) {
            [$code] = gcal_api('DELETE', '/events/' . rawurlencode($eventId), null, $error);
            if ($code < 300 || $code === 404 || $code === 410) q('UPDATE leave_requests SET gcal_event_id = NULL WHERE id = ?', [$requestId]);
        }
        return true;
    }
    $payload = gcal_event_payload($r);
    if ($eventId) {
        [$code] = gcal_api('PATCH', '/events/' . rawurlencode($eventId), $payload, $error);
        if ($code < 300) return true;
        if ($code !== 404 && $code !== 410) return false; // si lo borraron a mano, se vuelve a crear
    }
    [$code, $ev] = gcal_api('POST', '/events', $payload, $error);
    if ($code < 300 && !empty($ev['id'])) {
        q('UPDATE leave_requests SET gcal_event_id = ? WHERE id = ?', [$ev['id'], $requestId]);
        return true;
    }
    return false;
}

/** Sincroniza lo que haya quedado sin evento (por ejemplo, si Google falló). Lo llama el cron. */
function gcal_backfill(): int
{
    if (!gcal_enabled()) return 0;
    $n = 0;
    foreach (rows("SELECT id FROM leave_requests WHERE status IN ('pending','approved','registered') AND gcal_event_id IS NULL AND end_date >= CURDATE() - INTERVAL 60 DAY LIMIT 50") as $x) {
        if (gcal_sync((int) $x['id'])) $n++;
    }
    return $n;
}
