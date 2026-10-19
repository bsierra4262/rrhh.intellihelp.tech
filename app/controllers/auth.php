<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Ingreso, cierre de sesión y recuperación de contraseña.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Pantalla de ingreso.
 */
function login_form(): void
{
    if (current_user()) {
        redirect('/');
    }
    view('auth/login', ['title' => 'Ingresar'], 'auth_layout');
}

/**
 * Procesa el ingreso.
 */
function login_submit(): void
{
    $email = mb_strtolower(post('email'));
    if (too_many_attempts($email)) {
        flash('error', 'Demasiados intentos fallidos. Esperá 15 minutos e intentá de nuevo.');
        redirect('/login');
    }
    $u = attempt_login($email, (string) ($_POST['password'] ?? ''));
    if (!$u) {
        remember_input();
        $pending = row('SELECT password_hash FROM users WHERE email = ? AND pending_approval = 1', [$email]);
        if ($pending && password_verify((string) ($_POST['password'] ?? ''), $pending['password_hash'])) {
            flash('info', 'Tu cuenta está pendiente de aprobación por RRHH. Te avisaremos por correo cuando puedas ingresar.');
            redirect('/login');
        }
        flash('error', 'Email o contraseña incorrectos.');
        redirect('/login');
    }
    forget_input();
    login_user($u);
    $to = $_SESSION['intended'] ?? '/';
    unset($_SESSION['intended']);
    if ($u['must_change_password']) {
        flash('info', 'Por seguridad, elegí una contraseña nueva antes de continuar.');
        redirect('/perfil#password');
    }
    redirect(str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/');
}

/**
 * Cierra la sesión.
 */
function logout_submit(): void
{
    logout_user();
    redirect('/login');
}

/**
 * Pantalla de recuperación de contraseña.
 */
function forgot_form(): void
{
    view('auth/forgot', ['title' => 'Recuperar contraseña'], 'auth_layout');
}

/**
 * Envía el enlace de recuperación (sin revelar si el email existe).
 */
function forgot_submit(): void
{
    $email = mb_strtolower(post('email'));
    if (too_many_attempts($email)) {
        flash('error', 'Demasiados intentos. Esperá unos minutos.');
        redirect('/olvide');
    }
    insert('login_attempts', ['ip' => client_ip(), 'email' => $email]);
    $u = row('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
    if ($u) {
        $token = bin2hex(random_bytes(32));
        insert('password_resets', [
            'user_id' => $u['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $link = url('/restablecer', ['t' => $token]);
        send_mail(
            $u['email'],
            'Restablecer tu contraseña — Portal RRHH',
            mail_template(
                'Restablecé tu contraseña',
                'Hola ' . e($u['name']) . ', recibimos un pedido para cambiar tu contraseña. El enlace vence en 1 hora.',
                [],
                ['Elegir nueva contraseña', $link],
                'Si no lo pediste, ignorá este mensaje.'
            )
        );
    }
    flash('success', 'Si el email está registrado, te enviamos un enlace para restablecer la contraseña.');
    redirect('/login');
}

/**
 * Token de recuperación vigente y sin usar.
 */
function find_reset(string $token): ?array
{
    return row('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $token)]);
}

/**
 * Pantalla para elegir una contraseña nueva.
 */
function reset_form(): void
{
    $t = (string) ($_GET['t'] ?? '');
    if (!find_reset($t)) {
        flash('error', 'El enlace no es válido o ya venció. Pedí uno nuevo.');
        redirect('/olvide');
    }
    view('auth/reset', ['title' => 'Nueva contraseña', 'token' => $t], 'auth_layout');
}

/**
 * Guarda la contraseña nueva.
 */
function reset_submit(): void
{
    $t = post('token');
    $pr = find_reset($t);
    if (!$pr) {
        flash('error', 'El enlace no es válido o ya venció.');
        redirect('/olvide');
    }
    $p = (string) ($_POST['password'] ?? '');
    $problems = password_problems($p);
    if ($problems || $p !== ($_POST['password_confirm'] ?? '')) {
        flash('error', $problems ? 'La contraseña necesita ' . implode(', ', $problems) . '.' : 'Las contraseñas no coinciden.');
        redirect('/restablecer?t=' . urlencode($t));
    }
    q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($p, PASSWORD_DEFAULT), $pr['user_id']]);
    q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$pr['user_id']]);
    flash('success', 'Listo, ya podés ingresar con tu nueva contraseña.');
    redirect('/login');
}
