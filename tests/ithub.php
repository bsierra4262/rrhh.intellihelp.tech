<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Pruebas sin base de datos del ingreso con ITHub (app/lib/ithub.php): el PEM armado desde el
 * JWK, la validación del id_token y del logout_token, la URL de autorización (PKCE S256), la de
 * salida y la regla del ingreso mixto (personal interno por ITHub, otras empresas con contraseña). Se firma con una clave RSA generada en el momento; no se llama a ITHub (las claves se le
 * pasan a ithub_validate_jwt directamente).
 *
 * Uso: php tests/ithub.php   (necesita las extensiones openssl y mbstring; sale con 1 si algo falla)
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__) . '/app');
define('STORAGE', sys_get_temp_dir() . '/rrhh-tests-' . getmypid());

$GLOBALS['config'] = ['app_url' => 'https://rrhh.intellihelp.tech'];

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/ithub.php';

$fails = 0;
$total = 0;
function ok(string $name, bool $cond): void
{
    global $fails, $total;
    $total++;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $name . PHP_EOL;
    if (!$cond) {
        $fails++;
    }
}
function eq(string $name, mixed $got, mixed $want): void
{
    ok($name . ($got === $want ? '' : ' (obtenido: ' . var_export($got, true) . ')'), $got === $want);
}

ok('sin la sección ithub, el portal sigue con su ingreso de siempre', !ithub_enabled());
$GLOBALS['config']['ithub'] = ['client_id' => 'rrhh', 'client_secret' => ''];
ok('sin secreto tampoco se activa', !ithub_enabled());
$GLOBALS['config']['ithub'] = ['issuer' => 'https://ithub.intellihelp.tech/', 'client_id' => 'rrhh', 'client_secret' => 'ith_prueba'];
ok('con client_id y secreto, el ingreso es por ITHub', ithub_enabled());
eq('el emisor se usa sin barra final', ithub_cfg()['issuer'], 'https://ithub.intellihelp.tech');

// ---- Autorización ----
$_SESSION = [];
$auth = ithub_authorize_url();
parse_str((string) parse_url($auth, PHP_URL_QUERY), $qs);
ok('la autorización va a /oauth/authorize de ITHub', str_starts_with($auth, 'https://ithub.intellihelp.tech/oauth/authorize?'));
eq('con el client_id del portal', $qs['client_id'] ?? '', 'rrhh');
eq('y la vuelta registrada', $qs['redirect_uri'] ?? '', 'https://rrhh.intellihelp.tech/ithub-callback');
eq('state guardado en la sesión', $qs['state'] ?? '', $_SESSION['ithub_state']);
eq('nonce guardado en la sesión', $qs['nonce'] ?? '', $_SESSION['ithub_nonce']);
eq('PKCE S256', $qs['code_challenge_method'] ?? '', 'S256');
eq('el challenge es el SHA-256 del verificador', $qs['code_challenge'] ?? '', ithub_b64url(hash('sha256', $_SESSION['ithub_ver'], true)));

// ---- Firma ----
// En Windows openssl_pkey_new necesita el openssl.cnf que trae PHP.
$cnf = [];
$cnfPath = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
if (is_file($cnfPath)) {
    $cnf['config'] = $cnfPath;
}
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $cnf);
$det = openssl_pkey_get_details($key);
$jwk = ['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig',
    'n' => ithub_b64url($det['rsa']['n']), 'e' => ithub_b64url($det['rsa']['e'])];
$keys = ['k1' => $jwk];

eq('el PEM armado desde el JWK es la misma clave pública',
    str_replace(["\r", "\n"], '', ithub_jwk_to_pem($jwk)), str_replace(["\r", "\n"], '', $det['key']));

$sign = static function (array $claims, string $kid = 'k1', string $alg = 'RS256') use ($key): string {
    $h = ithub_b64url(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid]));
    $p = ithub_b64url(json_encode($claims));
    openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);

    return "$h.$p." . ithub_b64url($sig);
};
$fail = static function (callable $fn): string {
    try {
        $fn();

        return '';
    } catch (RuntimeException $ex) {
        return $ex->getMessage();
    }
};
$base = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'rrhh', 'sub' => '7', 'sid' => str_repeat('a', 32),
    'email' => 'persona@intellihelp.tech', 'rol' => 'manager', 'nonce' => 'n1',
    'iat' => time(), 'exp' => time() + 300];

eq('un id_token bien firmado pasa y trae el rol', ithub_validate_jwt($sign($base), 'n1', false, $keys)['rol'] ?? '', 'manager');
eq('el destinatario también puede venir como lista', ithub_validate_jwt($sign(['aud' => ['rrhh']] + $base), 'n1', false, $keys)['sub'] ?? '', '7');
$parts = explode('.', $sign($base));
$parts[1] = ithub_b64url(json_encode(['rol' => 'admin'] + $base));
eq('cambiar el contenido rompe la firma', $fail(fn () => ithub_validate_jwt(implode('.', $parts), 'n1', false, $keys)), 'firma');
eq('otro destinatario (aud) no sirve', $fail(fn () => ithub_validate_jwt($sign(['aud' => 'crm'] + $base), 'n1', false, $keys)), 'iss/aud');
eq('otro emisor (iss) no sirve', $fail(fn () => ithub_validate_jwt($sign(['iss' => 'https://evil.tld'] + $base), 'n1', false, $keys)), 'iss/aud');
eq('vencido no sirve', $fail(fn () => ithub_validate_jwt($sign(['exp' => time() - 120] + $base), 'n1', false, $keys)), 'vencido');
eq('con otro nonce no sirve (repetición)', $fail(fn () => ithub_validate_jwt($sign($base), 'otro', false, $keys)), 'nonce');
eq('solo RS256', $fail(fn () => ithub_validate_jwt($sign($base, 'k1', 'none'), 'n1', false, $keys)), 'alg');
eq('un kid desconocido no sirve', $fail(fn () => ithub_validate_jwt($sign($base, 'k9'), 'n1', false, $keys)), 'kid');
eq('basura no es un token', $fail(fn () => ithub_validate_jwt('no.es-un.token.jwt', null, false, $keys)), 'formato');

// ---- Aviso de cierre ----
$logout = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'rrhh', 'sub' => '7', 'sid' => str_repeat('b', 32),
    'iat' => time(), 'exp' => time() + 120, 'jti' => 'x', 'events' => [ITHUB_LOGOUT_EVENT => new stdClass()]];
eq('el aviso de cierre válido trae el sid', ithub_validate_jwt($sign($logout), null, true, $keys)['sid'] ?? '', str_repeat('b', 32));
eq('un id_token no sirve como aviso de cierre', $fail(fn () => ithub_validate_jwt($sign($base), null, true, $keys)), 'logout_token');
eq('un aviso de cierre con nonce se rechaza', $fail(fn () => ithub_validate_jwt($sign(['nonce' => 'x'] + $logout), null, true, $keys)), 'logout_token');

// ---- Salida ----
$out = ithub_logout_url('tok.en.x');
ok('la salida va a /oauth/logout de ITHub', str_starts_with($out, 'https://ithub.intellihelp.tech/oauth/logout?'));
ok('con el id_token como pista (no pregunta)', str_contains($out, 'id_token_hint=tok.en.x'));
ok('y vuelve al ingreso del portal', str_contains($out, 'post_logout_redirect_uri=' . rawurlencode('https://rrhh.intellihelp.tech/login')));

// ---- Ingreso mixto: personal interno por ITHub, otras empresas con contraseña ----
eq('el dominio interno por defecto es intellihelp.tech', ithub_internal_domain(), 'intellihelp.tech');
ok('un correo @intellihelp.tech es interno (sin importar mayúsculas)', ithub_is_internal(' JPerez@IntelliHelp.tech '));
ok('un correo de otra empresa no es interno', !ithub_is_internal('spasqua@strix.com.ar'));
ok('un subdominio no es el dominio interno', !ithub_is_internal('x@mail.intellihelp.tech'));
ok('un dominio que solo termina parecido no es interno', !ithub_is_internal('x@notintellihelp.tech'));
ok('con ITHub, el formulario de contraseña rechaza un correo interno', !local_login_allowed('bsierra@intellihelp.tech'));
ok('con ITHub, el formulario de contraseña acepta un correo externo', local_login_allowed('tlombardo@strix.com.ar'));
$GLOBALS['config']['ithub']['domain'] = '@Otra.com ';
ok('el dominio interno se puede configurar', ithub_is_internal('a@otra.com') && !ithub_is_internal('a@intellihelp.tech'));
unset($GLOBALS['config']['ithub']['domain']);
$cfgIthub = $GLOBALS['config']['ithub'];
unset($GLOBALS['config']['ithub']);
ok('sin ITHub, el correo interno sigue entrando con contraseña', local_login_allowed('bsierra@intellihelp.tech'));
$GLOBALS['config']['ithub'] = $cfgIthub;

// ---- Roles ----
eq('los roles del portal son los del catálogo de ITHub (de mayor a menor)', array_reverse(array_keys(ROLE_LABELS)), ['admin', 'hr', 'manager', 'employee']);

echo PHP_EOL . ($fails ? "✗ $fails de $total pruebas fallaron" : "✓ $total pruebas OK") . PHP_EOL;
exit($fails ? 1 : 0);
