<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Ingreso, vuelta y aviso de cierre de ITHub (sesión única de Intellihelp). La lógica del
 * protocolo está en lib/ithub.php.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Manda a ITHub a identificarse. Si la persona ya tiene la sesión única abierta, ITHub devuelve
 * el código al instante y entra al portal sin ver ninguna pantalla.
 */
function ithub_start(): void
{
    if (!ithub_enabled()) {
        redirect('/login');
    }
    if (current_user()) {
        redirect('/');
    }
    redirect(ithub_authorize_url());
}

/**
 * Vuelta de ITHub (código + PKCE): valida el state, canjea el código por los tokens (servidor a
 * servidor, con el secreto del portal), valida la firma y los claims del id_token, busca o crea
 * la persona con el rol que manda ITHub y abre la sesión. Guarda el `sid` (para el aviso de
 * cierre) y el id_token (para salir sin que ITHub pregunte).
 *
 * Si ITHub contesta access_denied es que la persona no tiene Capital Humano habilitado: ITHub ya
 * le mostró su pantalla de «No tenés acceso», acá solo se explica en el ingreso.
 */
function ithub_callback(): void
{
    if (!ithub_enabled()) {
        redirect('/login');
    }
    $state = (string) ($_GET['state'] ?? '');
    $expected = (string) ($_SESSION['ithub_state'] ?? '');
    $nonce = (string) ($_SESSION['ithub_nonce'] ?? '');
    $verifier = (string) ($_SESSION['ithub_ver'] ?? '');
    unset($_SESSION['ithub_state'], $_SESSION['ithub_nonce'], $_SESSION['ithub_ver']);
    $fail = function (string $msg): never {
        flash('error', $msg);
        redirect('/login?manual=1');
    };

    if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
        $fail('La validación de seguridad con ITHub falló. Volvé a intentar.');
    }
    if (isset($_GET['error'])) {
        $fail(($_GET['error'] ?? '') === 'access_denied'
            ? 'Tu usuario de ITHub no tiene acceso a Capital Humano. Si lo necesitás, pedíselo a RRHH o a un administrador de ITHub.'
            : 'No se pudo completar el ingreso con ITHub.');
    }

    $tok = ithub_exchange_code((string) ($_GET['code'] ?? ''), $verifier);
    try {
        $claims = ithub_validate_jwt((string) ($tok['id_token'] ?? ''), $nonce);
    } catch (Throwable $ex) {
        log_line('auth.log', 'ITHub: id_token inválido: ' . $ex->getMessage() . (isset($tok['error']) ? ' (' . $tok['error'] . ')' : ''));
        $fail('No se pudo validar el ingreso con ITHub. Volvé a intentar.');
    }

    try {
        $u = ithub_local_user($claims);
    } catch (DomainException $ex) {
        $fail($ex->getMessage());
    }

    login_user($u);
    $_SESSION['ithub_sid'] = (string) ($claims['sid'] ?? '');
    $_SESSION['ithub_id_token'] = (string) $tok['id_token'];
    log_line('auth.log', 'ITHub: ingreso de ' . $u['email'] . ' (' . $u['role'] . ')');
    $to = $_SESSION['intended'] ?? '/';
    unset($_SESSION['intended']);
    redirect(str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/');
}

/**
 * Aviso de cierre de ITHub (OpenID Connect back-channel logout).
 *
 * Lo llama el servidor de ITHub, no un navegador: no hay cookie ni CSRF (dispatch() no lo pide
 * para esta ruta); lo que se valida es la firma del logout_token. Anota el `sid` como cerrado y
 * current_user() corta en su pedido siguiente toda sesión del portal abierta con él.
 * Responde 200 si se procesó y 400 si el token no es válido (lo pide el estándar).
 */
function ithub_backchannel_logout(): void
{
    header('Cache-Control: no-store');
    if (!ithub_enabled()) {
        http_response_code(400);
        exit;
    }
    try {
        $c = ithub_validate_jwt((string) ($_POST['logout_token'] ?? ''), null, true);
    } catch (Throwable $ex) {
        log_line('auth.log', 'ITHub: aviso de cierre rechazado: ' . $ex->getMessage());
        http_response_code(400);
        exit;
    }
    $sid = (string) ($c['sid'] ?? '');
    if ($sid !== '') {
        ithub_close_sid($sid);
    }
    log_line('auth.log', 'ITHub: aviso de cierre de una sesión única');
    http_response_code(200);
    exit;
}
