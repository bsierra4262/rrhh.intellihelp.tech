<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Sesiones, autenticación, roles y política de contraseñas.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Inicia la sesión con cookies seguras y expiración por inactividad (8 horas).
 */
function start_session(): void
{
    $secure = str_starts_with(config('app_url'), 'https');
    session_name('ithrrhh');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_save_path(STORAGE . '/sessions');
    ini_set('session.gc_maxlifetime', '28800');
    session_start();

    // Expiración por inactividad: 8 horas.
    if (isset($_SESSION['uid'], $_SESSION['seen']) && time() - $_SESSION['seen'] > 28800) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
}

/**
 * Usuario autenticado de la petición actual, o null si no hay sesión.
 */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $user = row('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']]);
            if (!$user) {
                unset($_SESSION['uid']);
            }
        }
    }

    return $user;
}

/**
 * ID del usuario autenticado (0 si no hay sesión).
 */
function uid(): int
{
    return (int) (current_user()['id'] ?? 0);
}

/**
 * Exige sesión iniciada; si no la hay, redirige al ingreso recordando la URL pedida.
 */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('/login');
    }

    return $u;
}

/**
 * Indica si el usuario actual tiene alguno de los roles indicados.
 */
function has_role(string ...$roles): bool
{
    $u = current_user();

    return $u && in_array($u['role'], $roles, true);
}

/**
 * Indica si el usuario actual pertenece a Recursos Humanos o es administrador.
 */
function is_hr(): bool
{
    return has_role('hr', 'admin');
}

/**
 * Indica si el usuario actual puede autorizar solicitudes.
 */
function is_approver(): bool
{
    return has_role('manager', 'hr', 'admin');
}

/**
 * Exige alguno de los roles indicados; responde 403 en caso contrario.
 */
function require_role(string ...$roles): void
{
    require_login();
    if (!has_role(...$roles)) {
        abort(403, 'No tenés permisos para ver esta sección.');
    }
}

/**
 * Limita los intentos fallidos por IP o email (8 cada 15 minutos).
 */
function too_many_attempts(string $email): bool
{
    q('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    $n = (int) val('SELECT COUNT(*) FROM login_attempts WHERE (ip = ? OR email = ?) AND created_at > NOW() - INTERVAL 15 MINUTE', [client_ip(), $email]);

    return $n >= 8;
}

/**
 * Valida credenciales y registra el intento. Devuelve el usuario o null.
 */
function attempt_login(string $email, string $password): ?array
{
    $u = row('SELECT * FROM users WHERE email = ? AND active = 1', [mb_strtolower($email)]);
    if ($u && password_verify($password, $u['password_hash'])) {
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        q('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower($email)]);

        return $u;
    }
    insert('login_attempts', ['ip' => client_ip(), 'email' => mb_strtolower($email)]);

    return null;
}

/**
 * Abre la sesión del usuario regenerando el identificador de sesión.
 */
function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
}

/**
 * Cierra la sesión actual.
 */
function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/**
 * Valida la política de contraseñas.
 *
 * @return string[] Requisitos no cumplidos (vacío si es válida).
 */
function password_problems(string $p): array
{
    $err = [];
    if (mb_strlen($p) < 8) {
        $err[] = 'al menos 8 caracteres';
    }
    if (!preg_match('/[A-Z]/', $p)) {
        $err[] = 'una mayúscula';
    }
    if (!preg_match('/[a-z]/', $p)) {
        $err[] = 'una minúscula';
    }
    if (!preg_match('/\d/', $p)) {
        $err[] = 'un número';
    }

    return $err;
}

/**
 * Genera una contraseña temporal legible (sin caracteres ambiguos).
 */
function random_password(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $p = '';
    for ($i = 0; $i < 10; $i++) {
        $p .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $p . random_int(10, 99);
}
