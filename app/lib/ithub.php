<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Ingreso con ITHub (sesión única de Intellihelp, OpenID Connect).
 *
 * Ingreso mixto. Con la sección `ithub` en config.php (client_id y client_secret), el personal
 * del dominio interno (@intellihelp.tech) entra solo con ITHub: «Ingresar con ITHub» manda a
 * ITHub, que ya validó la cuenta de Google y el dominio, decide si la persona tiene acceso a
 * Capital Humano y con qué rol (claim `rol`: admin | hr | manager | employee) y vuelve a
 * /ithub-callback con un código. Si ya había sesión en ITHub, entra sin ver ninguna pantalla.
 * Para esos correos, el formulario de contraseña, el alta de cuentas, el recupero, el cambio de
 * contraseña y el ingreso directo con Google se rechazan del lado del servidor.
 *
 * Las personas de otras empresas (contratistas, consultoras) no tienen cuenta del dominio y no
 * pueden pasar por ITHub: siguen con correo y contraseña, alta de cuenta y recupero como siempre,
 * con el rol que les asigna RRHH en el portal.
 *
 * ITHub decide solo el rol y si se entra. La estructura (responsable, área, puesto, legajo,
 * empresa, fechas) y el estado de la persona (activa o desafectada) siguen siendo de RRHH: al
 * ingresar se actualiza únicamente el rol.
 *
 * Salida: «Cerrar sesión» cierra la sesión local y manda a /oauth/logout de ITHub, que cierra la
 * sesión única en todas las apps. Al revés, cuando la sesión se cierra en ITHub (o se le quita
 * Capital Humano a la persona), ITHub avisa por detrás a /ithub-logout (back-channel logout): se
 * anota el `sid` en `ithub_closed_sessions` y current_user() corta la sesión con ese sid en el
 * pedido siguiente. El cierre por inactividad del portal (8 horas) no cierra ITHub.
 *
 * Contrato completo: md/INTEGRAR-APLICACION.md del repositorio de ITHub. Sin dependencias: el
 * PEM de la clave pública se arma desde el JWK (n, e).
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const ITHUB_LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';
const ITHUB_JWKS_TTL = 3600;

/**
 * Configuración de ITHub ('ithub' en config.php), con el emisor por defecto.
 *
 * @return array{issuer: string, client_id: string, client_secret: string}
 */
function ithub_cfg(): array
{
    $c = (array) config('ithub', []);

    return [
        'issuer' => rtrim((string) ($c['issuer'] ?? 'https://ithub.intellihelp.tech'), '/'),
        'client_id' => (string) ($c['client_id'] ?? ''),
        'client_secret' => (string) ($c['client_secret'] ?? ''),
    ];
}

/**
 * Indica si el ingreso es por ITHub (hacen falta client_id y client_secret).
 */
function ithub_enabled(): bool
{
    $c = ithub_cfg();

    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

/**
 * Dominio del personal interno, que entra solo con ITHub ('ithub' → 'domain', por defecto
 * intellihelp.tech).
 */
function ithub_internal_domain(): string
{
    return mb_strtolower(trim((string) config('ithub.domain', 'intellihelp.tech'), " @	
"));
}

/**
 * Indica si el correo es del personal interno (dominio exacto, sin subdominios).
 */
function ithub_is_internal(string $email): bool
{
    $email = mb_strtolower(trim($email));
    $at = strrpos($email, '@');

    return $at !== false && substr($email, $at + 1) === ithub_internal_domain();
}

/**
 * Indica si ese correo puede usar el ingreso propio del portal (contraseña, alta, recupero,
 * Google directo): siempre sin ITHub; con ITHub, solo las cuentas de otras empresas.
 */
function local_login_allowed(string $email): bool
{
    return !ithub_enabled() || !ithub_is_internal($email);
}

/**
 * Corta una acción del ingreso propio para un correo del personal interno cuando el ingreso es
 * por ITHub, aunque alguien la llame directamente sin pasar por la pantalla.
 */
function deny_internal_if_ithub(string $email, string $back = '/login'): void
{
    if (!local_login_allowed($email)) {
        flash('info', 'El personal de @' . ithub_internal_domain() . ' ingresa con ITHub, la sesión única de Intellihelp: usá «Ingresar con ITHub». La cuenta y la contraseña se administran ahí, con tu cuenta de Google.');
        redirect($back);
    }
}

/**
 * Dirección de vuelta registrada en ITHub.
 */
function ithub_redirect_uri(): string
{
    return url('/ithub-callback');
}

/**
 * Base64 URL sin relleno.
 */
function ithub_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * Decodifica Base64 URL (con o sin relleno).
 */
function ithub_b64url_dec(string $txt): string
{
    return (string) base64_decode(strtr($txt, '-_', '+/') . str_repeat('=', (4 - strlen($txt) % 4) % 4), true);
}

/**
 * Arma la dirección de /oauth/authorize y deja en la sesión el state, el nonce y el verificador
 * de PKCE (S256), que valida ithub_callback().
 */
function ithub_authorize_url(): string
{
    $c = ithub_cfg();
    $_SESSION['ithub_state'] = bin2hex(random_bytes(16));
    $_SESSION['ithub_nonce'] = bin2hex(random_bytes(16));
    $_SESSION['ithub_ver'] = ithub_b64url(random_bytes(48));

    return $c['issuer'] . '/oauth/authorize?' . http_build_query([
        'client_id' => $c['client_id'],
        'redirect_uri' => ithub_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $_SESSION['ithub_state'],
        'nonce' => $_SESSION['ithub_nonce'],
        'code_challenge' => ithub_b64url(hash('sha256', $_SESSION['ithub_ver'], true)),
        'code_challenge_method' => 'S256',
    ]);
}

/**
 * Pedido HTTP a ITHub. Devuelve [código, cuerpo decodificado]; sin conexión, [0, []].
 * Con $post se autentica con el secreto del portal (client_secret_basic). Nunca lo deja en un log.
 *
 * @return array{0: int, 1: array}
 */
function ithub_http(string $url, ?array $post = null): array
{
    $headers = ['Accept: application/json'];
    if ($post !== null) {
        $c = ithub_cfg();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($c['client_id']) . ':' . rawurlencode($c['client_secret']));
    }
    [$code, $body] = http_request($url, $post !== null ? http_build_query($post) : null, $headers);

    return [$code, is_string($body) ? (json_decode($body, true) ?: []) : []];
}

/**
 * Canjea el código de autorización por los tokens (con el verificador de PKCE).
 */
function ithub_exchange_code(string $code, string $verifier): array
{
    [, $r] = ithub_http(ithub_cfg()['issuer'] . '/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => ithub_redirect_uri(),
        'code_verifier' => $verifier,
    ]);

    return $r;
}

/**
 * Largo DER (forma corta o larga).
 */
function ithub_der_len(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    $b = ltrim(pack('N', $n), "\0");

    return chr(0x80 | strlen($b)) . $b;
}

/**
 * Elemento DER: etiqueta, largo y valor.
 */
function ithub_der(int $tag, string $value): string
{
    return chr($tag) . ithub_der_len(strlen($value)) . $value;
}

/**
 * Entero DER positivo (se antepone un 0 si el primer bit está prendido).
 */
function ithub_der_int(string $bin): string
{
    $bin = ltrim($bin, "\0");
    if ($bin === '' || ord($bin[0]) > 0x7f) {
        $bin = "\0" . $bin;
    }

    return ithub_der(0x02, $bin);
}

/**
 * PEM de una clave pública RSA a partir del JWK (n, e en Base64 URL).
 */
function ithub_jwk_to_pem(array $jwk): string
{
    $rsa = ithub_der(0x30, ithub_der_int(ithub_b64url_dec((string) ($jwk['n'] ?? '')))
        . ithub_der_int(ithub_b64url_dec((string) ($jwk['e'] ?? ''))));
    $alg = ithub_der(0x30, ithub_der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
    $spki = ithub_der(0x30, $alg . ithub_der(0x03, "\0" . $rsa));

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Archivo de caché de las claves de ITHub (en storage/, fuera de la web).
 */
function ithub_jwks_file(): ?string
{
    $dir = STORAGE . '/ithub';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return is_dir($dir) ? $dir . '/jwks.json' : null;
}

/**
 * Claves públicas de ITHub por kid. Se guardan una hora; si el kid pedido no está (ITHub rotó
 * la clave), se vuelven a bajar.
 *
 * @return array<string, array> kid → JWK
 */
function ithub_jwks(string $kid = ''): array
{
    $file = ithub_jwks_file();
    if ($file !== null && is_file($file) && filemtime($file) > time() - ITHUB_JWKS_TTL) {
        $keys = json_decode((string) file_get_contents($file), true) ?: [];
        if ($keys !== [] && ($kid === '' || isset($keys[$kid]))) {
            return $keys;
        }
    }
    [$code, $r] = ithub_http(ithub_cfg()['issuer'] . '/oauth/jwks');
    $keys = [];
    foreach ((array) ($code === 200 ? ($r['keys'] ?? []) : []) as $k) {
        if (is_array($k) && ($k['kty'] ?? '') === 'RSA' && isset($k['kid'], $k['n'], $k['e'])) {
            $keys[(string) $k['kid']] = $k;
        }
    }
    if ($keys !== [] && $file !== null) {
        @file_put_contents($file, json_encode($keys), LOCK_EX);
    }

    return $keys;
}

/**
 * Valida un JWT de ITHub (id_token o logout_token) y devuelve sus claims.
 *
 * Comprueba: RS256, firma con la clave del kid, emisor, destinatario igual a nuestro client_id,
 * vencimiento (60 s de tolerancia) y, si se pasa, el nonce. Un logout_token además tiene que
 * traer el evento de back-channel logout y no puede traer nonce.
 *
 * @param array<string, array>|null $keys Para las pruebas; null = las de ITHub.
 * @throws RuntimeException Con un motivo corto (va al log, nunca a la pantalla).
 */
function ithub_validate_jwt(string $jwt, ?string $nonce = null, bool $isLogout = false, ?array $keys = null): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('formato');
    }
    [$h, $p, $s] = $parts;
    $head = json_decode(ithub_b64url_dec($h), true);
    $c = json_decode(ithub_b64url_dec($p), true);
    if (!is_array($head) || !is_array($c)) {
        throw new RuntimeException('formato');
    }
    if (($head['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('alg');
    }
    $kid = (string) ($head['kid'] ?? '');
    $keys ??= ithub_jwks($kid);
    if (!isset($keys[$kid])) {
        throw new RuntimeException('kid');
    }
    if (openssl_verify("$h.$p", ithub_b64url_dec($s), ithub_jwk_to_pem($keys[$kid]), OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('firma');
    }
    $cfg = ithub_cfg();
    $aud = $c['aud'] ?? '';
    $audOk = is_array($aud) ? in_array($cfg['client_id'], $aud, true) : $aud === $cfg['client_id'];
    if (($c['iss'] ?? '') !== $cfg['issuer'] || !$audOk) {
        throw new RuntimeException('iss/aud');
    }
    if ((int) ($c['exp'] ?? 0) < time() - 60) {
        throw new RuntimeException('vencido');
    }
    if ($isLogout) {
        if (!isset($c['events'][ITHUB_LOGOUT_EVENT]) || isset($c['nonce']) || (empty($c['sid']) && empty($c['sub']))) {
            throw new RuntimeException('logout_token');
        }
    } elseif ($nonce !== null && !hash_equals($nonce, (string) ($c['nonce'] ?? ''))) {
        throw new RuntimeException('nonce');
    }

    return $c;
}

/**
 * Busca (o crea) la persona del portal que mandó ITHub y le aplica el rol del token.
 *
 * Solo se toca el rol: responsable, área, puesto, legajo, empresa, nombre y fechas siguen siendo
 * los que cargó RRHH. Una persona desactivada o desafectada en el portal no entra aunque ITHub
 * la deje pasar (lo decide RRHH). Si todavía no existe, se crea activa con el rol de ITHub y el
 * aprobador por defecto, igual que el ingreso con Google, y se avisa a RRHH para completar su ficha.
 *
 * @throws DomainException Con el mensaje para mostrar en el ingreso.
 */
function ithub_local_user(array $claims): array
{
    $email = mb_strtolower(trim((string) ($claims['email'] ?? '')));
    $role = (string) ($claims['rol'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new DomainException('ITHub no informó un correo válido. Volvé a intentar.');
    }
    if (!isset(ROLE_LABELS[$role])) {
        log_line('auth.log', "ITHub: rol desconocido «{$role}» para $email");
        throw new DomainException('Tu rol en ITHub no corresponde a ninguno del portal. Avisale al administrador.');
    }

    $u = row('SELECT * FROM users WHERE email = ?', [$email]);
    if ($u && !$u['active'] && !$u['pending_activation']) {
        log_line('auth.log', "ITHub: ingreso rechazado de $email (cuenta desactivada en el portal)");
        throw new DomainException('Tu cuenta del portal está desactivada. Comunicate con RRHH.');
    }
    $approver = (int) setting('default_approver_id', '0') ?: null;
    if (!$u) {
        $name = mb_substr(normalize_name(trim((string) ($claims['name'] ?? '')) ?: explode('@', $email)[0]), 0, 120);
        $id = insert('users', [
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'role' => $role,
            'company' => setting('company_name', 'Intellihelp') ?: 'Intellihelp',
            'manager_id' => $approver,
            'active' => 1,
            'calendar_token' => bin2hex(random_bytes(20)),
        ]);
        log_line('auth.log', "ITHub: alta de $email con rol $role");
        defer(fn () => notify_hr_new_user($id, false));
    } else {
        $id = (int) $u['id'];
        if ($u['pending_activation']) {
            // Una cuenta registrada que no había confirmado su correo queda confirmada por ITHub.
            q('UPDATE users SET active = 1, pending_activation = 0, pending_approval = 0, manager_id = COALESCE(manager_id, ?) WHERE id = ?', [$approver, $id]);
            q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$id]);
            defer(fn () => notify_hr_new_user($id, false));
        }
        if ($u['role'] !== $role) {
            q('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
            log_line('auth.log', "ITHub: rol de $email tomado de ITHub: {$u['role']} → $role");
        }
    }

    return row('SELECT * FROM users WHERE id = ?', [$id]);
}

/**
 * Anota que la sesión de ITHub `sid` se cerró: las del portal abiertas con ese sid caen.
 */
function ithub_close_sid(string $sid): void
{
    q('INSERT INTO ithub_closed_sessions (sid, closed_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE closed_at = NOW()', [$sid]);
    // Con la sesión máxima del portal (8 horas de inactividad), nada más viejo que dos días sirve.
    q('DELETE FROM ithub_closed_sessions WHERE closed_at < NOW() - INTERVAL 2 DAY');
}

/**
 * Indica si la sesión de ITHub `sid` ya se cerró.
 */
function ithub_sid_closed(string $sid): bool
{
    return val('SELECT 1 FROM ithub_closed_sessions WHERE sid = ? LIMIT 1', [$sid]) !== null;
}

/**
 * Dirección de salida de ITHub: cierra la sesión única y vuelve al ingreso del portal.
 * Con el id_token como pista, ITHub no pregunta.
 */
function ithub_logout_url(?string $idToken): string
{
    $q = ['post_logout_redirect_uri' => url('/login'), 'client_id' => ithub_cfg()['client_id']];
    if ($idToken) {
        $q['id_token_hint'] = $idToken;
    }

    return ithub_cfg()['issuer'] . '/oauth/logout?' . http_build_query($q);
}
