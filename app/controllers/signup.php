<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Autoregistro de colaboradores y aprobación de acceso.
 *
 * Cualquier correo puede registrarse y se valida con un enlace. Los dominios de confianza se
 * activan automáticamente; el resto (personal tercerizado) queda pendiente de aprobación por RRHH.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Dominios de correo que se activan sin aprobación.
 *
 * @return string[]
 */
function trusted_domains(): array
{
    $list = array_map(fn ($d) => mb_strtolower(trim(ltrim(trim($d), '@'))), explode(',', setting('signup_domain', 'intellihelp.tech')));

    return array_values(array_filter($list));
}

/**
 * Indica si el correo pertenece a un dominio de confianza.
 */
function is_trusted_email(string $email): bool
{
    $domain = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));

    return $domain !== '' && in_array($domain, trusted_domains(), true);
}

/**
 * Indica si el autoregistro está habilitado.
 */
function signup_open(): bool
{
    return setting('signup_enabled', '1') === '1';
}

/**
 * Formulario de registro.
 */
function register_form(): void
{
    if (current_user()) {
        redirect('/');
    }
    if (!signup_open()) {
        flash('info', 'El registro está deshabilitado. Pedile a RRHH que te dé de alta.');
        redirect('/login');
    }
    view('auth/register', ['title' => 'Crear cuenta', 'domains' => trusted_domains()], 'auth_layout');
}

/**
 * Procesa el registro y envía el enlace de confirmación.
 */
function register_submit(): void
{
    if (!signup_open()) {
        redirect('/login');
    }
    remember_input();
    $name = mb_substr(preg_replace('/\s+/', ' ', post('name')), 0, 120);
    $email = mb_strtolower(post('email'));
    $company = mb_substr(post('company'), 0, 120);
    $trusted = is_trusted_email($email);
    if (mb_strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Ingresá tu nombre completo y un correo válido.');
        redirect('/registro');
    }
    if (!$trusted && mb_strlen($company) < 2) {
        flash('error', 'Indicá la empresa para la que trabajás (por ejemplo, la consultora o empresa contratante).');
        redirect('/registro');
    }
    if (too_many_attempts($email)) {
        flash('error', 'Demasiados intentos. Esperá unos minutos.');
        redirect('/registro');
    }
    insert('login_attempts', ['ip' => client_ip(), 'email' => $email]);

    $u = row('SELECT * FROM users WHERE email = ?', [$email]);
    if ($u && !$u['pending_activation']) {
        // La cuenta ya existe: se informa por correo sin revelarlo en pantalla.
        if ($u['active']) {
            send_mail($email, 'Ya tenés una cuenta en el Portal de RRHH', mail_template(
                'Ya tenés una cuenta',
                'Recibimos un pedido para crear una cuenta con este correo, pero ya estás registrado/a. Si no recordás tu contraseña, podés restablecerla.',
                [],
                ['Restablecer contraseña', url('/olvide')]
            ));
        }
    } else {
        $data = ['name' => $name, 'company' => $trusted ? (setting('company_name', 'Intellihelp') ?: 'Intellihelp') : $company];
        if (!$u) {
            $id = insert('users', $data + [
                'email' => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'role' => 'employee',
                'active' => 0,
                'pending_activation' => 1,
                'calendar_token' => bin2hex(random_bytes(20)),
            ]);
        } else {
            $id = (int) $u['id'];
            update('users', $id, $data);
        }
        send_activation($id);
    }
    forget_input();
    flash('success', "Te enviamos un correo a {$email} para confirmar tu cuenta. Revisá también la carpeta de spam.");
    redirect('/login');
}

/**
 * Envía el enlace para confirmar el correo y elegir la contraseña de una cuenta sin confirmar.
 *
 * Cada envío genera un enlace nuevo con 48 horas de vigencia; los anteriores siguen siendo válidos
 * hasta que la cuenta se active.
 */
function send_activation(int $id): bool
{
    $u = row('SELECT * FROM users WHERE id = ? AND pending_activation = 1', [$id]);
    if (!$u) {
        return false;
    }
    $token = bin2hex(random_bytes(32));
    insert('password_resets', ['user_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 172800)]);

    return send_mail($u['email'], 'Confirmá tu correo — Portal de RRHH Intellihelp', mail_template(
        'Confirmá tu correo',
        'Hola ' . e($u['name']) . ', para continuar con el alta en el Portal de Recursos Humanos de Intellihelp confirmá tu correo y elegí una contraseña. El enlace vence en 48 horas.'
        . (is_trusted_email($u['email']) ? '' : '<br><br>Como tu correo no pertenece a Intellihelp, RRHH va a revisar y aprobar tu acceso.'),
        [],
        ['Confirmar correo', url('/activar', ['t' => $token])],
        'Si no fuiste vos, ignorá este mensaje.'
    ));
}

/**
 * Reenvía el correo de confirmación a una cuenta sin confirmar.
 */
function admin_user_resend_activation(int $id): void
{
    require_role('hr', 'admin');
    $email = val('SELECT email FROM users WHERE id = ? AND pending_activation = 1', [$id]);
    if (!$email) {
        redirect("/admin/usuarios/$id");
    }
    if (send_activation($id)) {
        flash('success', "Reenviamos el correo de confirmación a {$email}. El enlace vence en 48 horas.");
    } else {
        flash('error', 'No se pudo enviar el correo. Revisá la configuración de correo o generá una contraseña temporal.');
    }
    redirect("/admin/usuarios/$id");
}

/**
 * Elimina una cuenta que nunca se confirmó (registros duplicados o con el correo mal escrito).
 */
function admin_user_delete_unconfirmed(int $id): void
{
    require_role('hr', 'admin');
    $u = row('SELECT * FROM users WHERE id = ? AND pending_activation = 1', [$id]);
    if (!$u || val('SELECT COUNT(*) FROM leave_requests WHERE user_id = ?', [$id])) {
        flash('error', 'Solo se pueden eliminar cuentas sin confirmar y sin solicitudes.');
        redirect("/admin/usuarios/$id");
    }
    q('DELETE FROM users WHERE id = ?', [$id]);
    flash('success', "Se eliminó la cuenta sin confirmar {$u['email']}.");
    redirect('/admin/usuarios');
}

/**
 * Token de activación vigente de una cuenta pendiente.
 */
function find_activation(string $token): ?array
{
    $pr = find_reset($token);
    if (!$pr) {
        return null;
    }
    $u = row('SELECT * FROM users WHERE id = ? AND pending_activation = 1', [$pr['user_id']]);

    return $u ? ['reset' => $pr, 'user' => $u] : null;
}

/**
 * Pantalla para elegir la contraseña al confirmar el correo.
 */
function activate_form(): void
{
    $t = (string) ($_GET['t'] ?? '');
    $a = find_activation($t);
    if (!$a) {
        flash('error', 'El enlace de activación no es válido o ya fue usado.');
        redirect('/login');
    }
    view('auth/activate', ['title' => 'Activar cuenta', 'token' => $t, 'name' => $a['user']['name'], 'trusted' => is_trusted_email($a['user']['email'])], 'auth_layout');
}

/**
 * Confirma la cuenta: la activa (dominio de confianza) o la deja pendiente de aprobación.
 */
function activate_submit(): void
{
    $t = post('token');
    $a = find_activation($t);
    if (!$a) {
        flash('error', 'El enlace de activación no es válido o ya fue usado.');
        redirect('/login');
    }
    $p = (string) ($_POST['password'] ?? '');
    $problems = password_problems($p);
    if ($problems || $p !== ($_POST['password_confirm'] ?? '')) {
        flash('error', $problems ? 'La contraseña necesita ' . implode(', ', $problems) . '.' : 'Las contraseñas no coinciden.');
        redirect('/activar?t=' . urlencode($t));
    }
    $id = (int) $a['user']['id'];
    $trusted = is_trusted_email($a['user']['email']);
    $approver = (int) setting('default_approver_id', '0') ?: null;
    q(
        'UPDATE users SET password_hash = ?, active = ?, pending_activation = 0, pending_approval = ?, manager_id = COALESCE(manager_id, ?) WHERE id = ?',
        [password_hash($p, PASSWORD_DEFAULT), $trusted ? 1 : 0, $trusted ? 0 : 1, $approver, $id]
    );
    q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$id]);

    defer(fn () => notify_hr_new_user($id, !$trusted));
    if ($trusted) {
        login_user(row('SELECT * FROM users WHERE id = ?', [$id]));
        flash('success', '¡Bienvenido/a! Tu cuenta quedó activa.');
        redirect('/');
    }
    flash('success', 'Correo confirmado. RRHH va a revisar tu acceso y te avisaremos por correo cuando esté aprobado.');
    redirect('/login');
}

/**
 * Avisa a RRHH sobre una cuenta nueva o una solicitud de acceso.
 */
function notify_hr_new_user(int $id, bool $needsApproval): void
{
    $nu = row('SELECT * FROM users WHERE id = ?', [$id]);
    $title = $needsApproval ? "{$nu['name']} solicita acceso al portal" : "{$nu['name']} creó su cuenta";
    $intro = $needsApproval
        ? e($nu['name']) . ' (' . e($nu['company'] ?: 'empresa no indicada') . ') confirmó su correo y solicita acceso al Portal de RRHH. Revisá sus datos y aprobá o rechazá el acceso.'
        : e($nu['name']) . ' activó su cuenta en el Portal de RRHH. Completá su área, puesto, fecha de ingreso (define los días de vacaciones) y responsable.';
    foreach (hr_users() as $h) {
        insert('notifications', ['user_id' => $h['id'], 'title' => $title,
            'body' => $needsApproval ? 'Aprobá o rechazá el acceso.' : 'Completá su área, puesto, fecha de ingreso y responsable.', 'link' => '/admin/usuarios/' . $id]);
        if ($h['notify_email']) {
            send_mail($h['email'], $title, mail_template(
                $needsApproval ? 'Solicitud de acceso al portal' : 'Se registró un nuevo colaborador',
                $intro,
                [['Nombre', e($nu['name'])], ['Correo', e($nu['email'])], ['Empresa', e($nu['company'] ?: '—')]],
                [$needsApproval ? 'Revisar y aprobar' : 'Completar datos', url('/admin/usuarios/' . $id)]
            ));
        }
    }
    send_gchat(($needsApproval ? "🔐 *Solicitud de acceso*\n" : "👤 *Nueva cuenta*\n") . "{$nu['name']} · {$nu['email']} · " . ($nu['company'] ?: '—') . "\n👉 " . url('/admin/usuarios/' . $id));
}

/**
 * Aprueba o rechaza la solicitud de acceso de una cuenta externa.
 */
function admin_user_approve(int $id): void
{
    require_role('hr', 'admin');
    $u = row('SELECT * FROM users WHERE id = ? AND pending_approval = 1', [$id]);
    if (!$u) {
        redirect("/admin/usuarios/$id");
    }
    if (post('decision') === 'reject') {
        $hasData = (int) val('SELECT COUNT(*) FROM leave_requests WHERE user_id = ?', [$id]);
        $hasData ? q('UPDATE users SET pending_approval = 0, active = 0 WHERE id = ?', [$id]) : q('DELETE FROM users WHERE id = ?', [$id]);
        defer(fn () => send_mail($u['email'], 'Tu solicitud de acceso al Portal de RRHH', mail_template(
            'Solicitud de acceso no aprobada',
            'Hola ' . e($u['name']) . ', RRHH no aprobó tu acceso al Portal de Recursos Humanos de Intellihelp. Si creés que es un error, comunicate con tu referente.'
        )));
        flash('success', 'Solicitud de acceso rechazada.');
        redirect('/admin/usuarios');
    }
    q('UPDATE users SET pending_approval = 0, active = 1 WHERE id = ?', [$id]);
    defer(fn () => send_mail($u['email'], 'Tu acceso al Portal de RRHH fue aprobado', mail_template(
        '¡Tu acceso fue aprobado!',
        'Hola ' . e($u['name']) . ', ya podés ingresar al Portal de Recursos Humanos de Intellihelp con tu correo y la contraseña que elegiste.',
        [],
        ['Ingresar al portal', url('/login')]
    )));
    flash('success', 'Acceso aprobado. Le avisamos por correo.');
    redirect("/admin/usuarios/$id");
}
