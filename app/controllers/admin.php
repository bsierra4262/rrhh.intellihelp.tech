<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Administración: personas, tipos de licencia, feriados y configuración del sistema.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Listado de personas.
 */
function admin_users(): void
{
    require_role('hr', 'admin');
    $q = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT u.*, m.name AS manager_name FROM users u LEFT JOIN users m ON m.id = u.manager_id';
    $p = [];
    if ($q !== '') {
        $sql .= ' WHERE u.name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.company LIKE ?';
        $p = ["%$q%", "%$q%", "%$q%", "%$q%"];
    }
    $users = rows($sql . ' ORDER BY u.pending_approval DESC, u.active DESC, u.name', $p);
    $year = (int) date('Y');
    foreach ($users as &$u) {
        $u['vac_total'] = vacation_entitlement($u, $year);
        $u['vac_used'] = vacation_used((int) $u['id'], $year, "'approved'");
    }
    unset($u);
    view('admin/users', ['title' => 'Personas', 'users' => $users, 'q' => $q]);
}

/**
 * Formulario de alta o edición de una persona.
 */
function admin_user_form(int $id = 0): void
{
    require_role('hr', 'admin');
    $u = $id ? row('SELECT * FROM users WHERE id = ?', [$id]) : null;
    if ($id && !$u) {
        abort(404);
    }
    view('admin/user_form', [
        'title' => $u ? 'Editar persona' : 'Nueva persona',
        'u' => $u,
        'managers' => rows("SELECT id, name FROM users WHERE active = 1 AND role IN ('manager','hr','admin')" . ($id ? ' AND id <> ' . $id : '') . ' ORDER BY name'),
        'companies' => array_column(rows('SELECT DISTINCT company FROM users WHERE company IS NOT NULL AND company <> "" ORDER BY company'), 'company'),
        'departments' => array_column(rows('SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department <> "" ORDER BY department'), 'department'),
    ]);
}

/**
 * Guarda el alta o edición de una persona.
 */
function admin_user_save(int $id = 0): void
{
    $me = require_login();
    require_role('hr', 'admin');
    remember_input();
    $back = $id ? "/admin/usuarios/$id" : '/admin/usuarios/nuevo';
    $email = mb_strtolower(post('email'));
    $role = post('role');
    $data = [
        'name' => mb_substr(post('name'), 0, 120),
        'email' => $email,
        'role' => isset(ROLE_LABELS[$role]) ? $role : 'employee',
        'manager_id' => (int) post('manager_id') ?: null,
        'department' => mb_substr(post('department'), 0, 120) ?: null,
        'company' => mb_substr(post('company'), 0, 120) ?: null,
        'position' => mb_substr(post('position'), 0, 120) ?: null,
        'phone' => preg_replace('/[^\d+]/', '', post('phone')) ?: null,
        'hire_date' => valid_date(post('hire_date')) ? post('hire_date') : null,
        'vacation_days_override' => post('vacation_days_override') === '' ? null : max(0, (int) post('vacation_days_override')),
        'vacation_override_year' => post('vacation_days_override') === '' ? null : (int) date('Y'),
        'active' => isset($_POST['active']) ? 1 : 0,
    ];
    if ($data['name'] === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Completá nombre y un email válido.');
        redirect($back);
    }
    if (val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
        flash('error', 'Ya existe una persona con ese email.');
        redirect($back);
    }
    // Solo un administrador puede otorgar el rol de administrador.
    if ($data['role'] === 'admin' && $me['role'] !== 'admin') {
        flash('error', 'Solo un administrador puede asignar el rol Administrador/a.');
        redirect($back);
    }
    if ($id && $id === (int) $me['id'] && (!$data['active'] || $data['role'] !== $me['role'])) {
        flash('error', 'No podés desactivarte ni cambiar tu propio rol.');
        redirect($back);
    }

    if ($id) {
        $target = row('SELECT role FROM users WHERE id = ?', [$id]);
        if ($target['role'] === 'admin' && $me['role'] !== 'admin') {
            abort(403, 'Solo un administrador puede editar a otro administrador.');
        }
        $prev = row('SELECT email, pending_approval, pending_activation FROM users WHERE id = ?', [$id]);
        // Una cuenta pendiente se activa al confirmar el correo o desde «Aprobar acceso».
        if ($prev['pending_approval'] || $prev['pending_activation']) {
            unset($data['active']);
        }
        update('users', $id, $data);
        // Si se corrigió el correo de una cuenta sin confirmar, el enlace va a la dirección nueva.
        if ($prev['pending_activation'] && $prev['email'] !== $email) {
            send_activation($id);
            flash('success', "Datos actualizados. Enviamos el correo de confirmación a {$email}.");
        } else {
            flash('success', 'Datos actualizados.');
        }
    } else {
        $pass = random_password();
        $data['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        $data['must_change_password'] = 1;
        $data['calendar_token'] = bin2hex(random_bytes(20));
        $id = insert('users', $data);
        $sent = isset($_POST['send_welcome']) && send_welcome($data, $pass);
        $_SESSION['new_password'] = ['name' => $data['name'], 'email' => $email, 'pass' => $pass, 'sent' => $sent];
        flash('success', 'Persona creada.');
    }
    forget_input();
    redirect("/admin/usuarios/$id");
}

/**
 * Envía el correo de bienvenida con la contraseña temporal.
 */
function send_welcome(array $u, string $pass): bool
{
    $intro = 'Hola ' . e($u['name']) . ', desde ahora podés pedir vacaciones, días de estudio e informar licencias desde el portal.';
    if (in_array($u['role'] ?? '', ['manager', 'hr', 'admin'], true)) {
        $intro .= '<br><br>Además, sos responsable de <b>autorizar solicitudes</b>: cada pedido te va a llegar por email y al espacio '
            . '<b>RRHH – Aprobaciones</b> de Google Chat, y las ausencias se registran en el calendario <b>Licencias Intellihelp</b> de Google Calendar. '
            . 'Las pendientes las encontrás en la sección <b>Por autorizar</b>.';
    }
    $intro .= '<br><br>Al ingresar por primera vez te vamos a pedir que cambies la contraseña.';

    return send_mail($u['email'], 'Tu acceso al Portal de RRHH de Intellihelp', mail_template(
        '¡Bienvenido/a al Portal de RRHH!',
        $intro,
        [['Usuario', e($u['email'])], ['Contraseña temporal', '<code style="font-size:15px">' . e($pass) . '</code>']],
        ['Ingresar al portal', url('/login')]
    ));
}

/**
 * Genera una contraseña temporal nueva para una persona.
 */
function admin_user_reset_password(int $id): void
{
    $me = require_login();
    require_role('hr', 'admin');
    $u = row('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) {
        abort(404);
    }
    if ($u['role'] === 'admin' && $me['role'] !== 'admin') {
        abort(403);
    }
    $pass = random_password();
    q('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
    // Generar la contraseña desde RRHH equivale a confirmar la cuenta: queda habilitada para ingresar.
    if ($u['pending_activation']) {
        q('UPDATE users SET pending_activation = 0, pending_approval = 0, active = 1 WHERE id = ?', [$id]);
        q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$id]);
    }
    $sent = isset($_POST['send']) && send_welcome($u, $pass);
    $_SESSION['new_password'] = ['name' => $u['name'], 'email' => $u['email'], 'pass' => $pass, 'sent' => $sent];
    flash('success', 'Contraseña restablecida.');
    redirect("/admin/usuarios/$id");
}

/**
 * Listado de tipos de licencia.
 */
function admin_types(): void
{
    require_role('hr', 'admin');
    view('admin/types', ['title' => 'Tipos de licencia', 'types' => leave_types(false)]);
}

/**
 * Alta o edición de un tipo de licencia.
 */
function admin_types_save(): void
{
    require_role('hr', 'admin');
    $id = (int) post('id');
    $data = [
        'name' => mb_substr(post('name'), 0, 80),
        'description' => mb_substr(post('description'), 0, 255) ?: null,
        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', post('color')) ? post('color') : '#663399',
        'requires_approval' => isset($_POST['requires_approval']) ? 1 : 0,
        'requires_document' => isset($_POST['requires_document']) ? 1 : 0,
        'document_label' => mb_substr(post('document_label'), 0, 120) ?: null,
        'doc_due_days' => post('doc_due_days') === '' ? null : max(1, (int) post('doc_due_days')),
        'doc_due_from' => post('doc_due_from') === 'created' ? 'created' : 'end',
        'max_days_per_year' => post('max_days_per_year') === '' ? null : max(1, (int) post('max_days_per_year')),
        'max_days_per_request' => post('max_days_per_request') === '' ? null : max(1, (int) post('max_days_per_request')),
        'counting' => post('counting') === 'habiles' ? 'habiles' : 'corridos',
        'weekend_rule' => isset($_POST['weekend_rule']) && post('counting') !== 'habiles' ? 1 : 0,
        'uses_vacation_balance' => isset($_POST['uses_vacation_balance']) ? 1 : 0,
        'vacation_optional' => isset($_POST['vacation_optional']) && !isset($_POST['uses_vacation_balance']) ? 1 : 0,
        'min_notice_days' => max(0, (int) post('min_notice_days')),
        'active' => isset($_POST['active']) ? 1 : 0,
        'sort' => (int) post('sort'),
    ];
    if ($data['name'] === '') {
        flash('error', 'El nombre es obligatorio.');
        redirect('/admin/tipos');
    }
    if ($id) {
        update('leave_types', $id, $data);
    } else {
        $data['code'] = substr(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $data['name']))), 0, 24) . '-' . random_int(100, 999);
        insert('leave_types', $data);
    }
    flash('success', 'Tipo de licencia guardado.');
    redirect('/admin/tipos');
}

/**
 * Feriados del año.
 */
function admin_holidays(): void
{
    require_role('hr', 'admin');
    $year = (int) ($_GET['anio'] ?? date('Y'));
    view('admin/holidays', [
        'title' => 'Feriados',
        'year' => $year,
        'list' => rows('SELECT * FROM holidays WHERE YEAR(date) = ? ORDER BY date', [$year]),
        'ignored' => array_values(array_filter(holidays_ignored(), fn ($d) => (int) substr($d, 0, 4) === $year)),
        'syncedAt' => setting('holidays_synced_at'),
    ]);
}

/**
 * Alta o baja de un feriado.
 *
 * Un feriado importado que se quita no vuelve a agregarse en las actualizaciones automáticas
 * (por ejemplo, un día puente que la empresa decide trabajar); cargarlo a mano lo restituye.
 */
function admin_holidays_save(): void
{
    require_role('hr', 'admin');
    if ($del = (int) post('delete')) {
        $h = row('SELECT * FROM holidays WHERE id = ?', [$del]);
        q('DELETE FROM holidays WHERE id = ?', [$del]);
        if ($h && $h['source'] === 'auto') {
            holidays_ignore($h['date'], true);
        }
        flash('success', 'Feriado eliminado.' . ($h && $h['source'] === 'auto' ? ' No se volverá a agregar en las actualizaciones automáticas.' : ''));
    } elseif (valid_date(post('restore'))) {
        holidays_ignore(post('restore'), false);
        holidays_sync([(int) substr(post('restore'), 0, 4)]);
        flash('success', 'Feriado restituido desde el calendario oficial.');
        redirect('/admin/feriados?anio=' . substr(post('restore'), 0, 4));
    } elseif (valid_date(post('date')) && post('name') !== '') {
        $region = mb_substr(trim((string) post('region')), 0, 80) ?: null;
        q('INSERT INTO holidays (date, name, region) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), region = VALUES(region)', [post('date'), mb_substr(post('name'), 0, 120), $region]);
        holidays_ignore(post('date'), false);
        flash('success', 'Feriado guardado.');
    } else {
        flash('error', 'Completá fecha y nombre.');
    }
    redirect('/admin/feriados?anio=' . substr(post('date') ?: date('Y'), 0, 4));
}

/**
 * Actualiza los feriados desde el calendario oficial en el momento.
 */
function admin_holidays_sync(): void
{
    require_role('hr', 'admin');
    $res = holidays_sync();
    if (!$res['years']) {
        flash('error', 'No se pudo consultar el calendario oficial. Se vuelve a intentar automáticamente.');
    } elseif ($res['added'] || $res['removed']) {
        flash('success', 'Feriados actualizados: ' . count($res['added']) . ' agregado(s) y ' . count($res['removed']) . ' quitado(s).');
    } else {
        flash('success', 'Los feriados ya estaban al día con el calendario oficial.');
    }
    redirect('/admin/feriados?anio=' . (int) post('year', date('Y')));
}

/**
 * Parámetros editables desde la pantalla de configuración.
 */
const SETTING_KEYS = ['company_name', 'hr_email', 'mail_driver', 'mail_from', 'mail_from_name', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass',
    'wa_driver', 'wa_cloud_token', 'wa_cloud_phone_id', 'wa_cloud_template', 'wa_cloud_lang', 'reminder_hours',
    'wa_gw_url', 'wa_gw_key', 'wa_gw_instance', 'gchat_webhook', 'default_approver_id', 'signup_enabled', 'signup_domain',
    'event_email', 'gcal_calendar_id', 'gcal_sa_json', 'support_email', 'ai_smtp_user', 'ai_smtp_pass', 'gcal_impersonate', 'drive_root_folder',
    'google_client_id', 'google_client_secret', 'vacation_contacts'];

/**
 * Pantalla de configuración del sistema.
 */
function admin_settings(): void
{
    require_role('admin', 'hr');
    $s = [];
    foreach (SETTING_KEYS as $k) {
        $s[$k] = in_array($k, SECRET_SETTINGS, true) ? (setting($k) !== '' ? '••••••••' : '') : setting($k);
    }
    view('admin/settings', [
        'title' => 'Configuración',
        's' => $s,
        'approvers' => rows("SELECT id, name, position FROM users WHERE active = 1 AND role IN ('manager','hr','admin') ORDER BY name"),
    ]);
}

/**
 * Guarda la configuración del sistema.
 */
function admin_settings_save(): void
{
    require_role('admin', 'hr');
    foreach (SETTING_KEYS as $k) {
        if (!array_key_exists($k, $_POST)) {
            continue;
        }
        $v = trim((string) $_POST[$k]);
        if ($k === 'gcal_impersonate') {
            array_map('unlink', glob(STORAGE . '/gtoken-*.json') ?: []);
        }
        if ($k === 'gcal_sa_json' && $v !== '' && $v !== '••••••••') {
            $j = json_decode($v, true);
            if (!is_array($j) || empty($j['client_email']) || empty($j['private_key'])) {
                flash('error', 'La clave de Google Calendar no es un JSON de cuenta de servicio válido. No se guardó.');
                continue;
            }
            array_map('unlink', glob(STORAGE . '/gtoken-*.json') ?: []);
        }
        // Los secretos se muestran enmascarados; si no se modificaron, se conservan.
        if (in_array($k, SECRET_SETTINGS, true) && ($v === '••••••••')) {
            continue;
        }
        save_setting($k, $v);
    }
    save_setting('signup_enabled', isset($_POST['signup_enabled']) ? '1' : '0');
    flash('success', 'Configuración guardada.');
    redirect('/admin/configuracion');
}

/**
 * Pruebas de canales: email, Google Calendar (crear y finalizar), Google Chat y WhatsApp.
 */
function admin_settings_test(): void
{
    $me = require_login();
    require_role('admin', 'hr');
    $channel = post('channel');
    $err = null;
    if ($channel === 'mail') {
        $to = post('to') ?: $me['email'];
        $ok = send_mail($to, 'Prueba de email — Portal RRHH', mail_template('¡El email funciona!', 'Este es un correo de prueba enviado desde el Portal de RRHH de Intellihelp.', [['Enviado', e(fdatetime(date('Y-m-d H:i:s')))]], ['Abrir el portal', url('/')]), [], null, $err);
        flash($ok ? 'success' : 'error', $ok ? "Email de prueba enviado a $to." : 'No se pudo enviar: ' . $err);
    } elseif ($channel === 'gcal') {
        // Paso 1 del test: crea un evento real de prueba en el calendario compartido
        [$code, $cal] = gcal_enabled() ? gcal_api('GET', '', null, $err) : [0, null];
        if ($code !== 200) {
            flash('error', 'No se pudo conectar con Google Calendar: ' . ($err ?: 'cargá el ID del calendario y la clave JSON.'));
            redirect('/admin/configuracion#gcal');
        }
        if ($old = setting('gcal_test_event_id')) {
            gcal_api('DELETE', '/events/' . rawurlencode($old));
        }
        [$code, $ev] = gcal_api('POST', '/events', [
            'summary' => '✅ Evento de prueba — Portal RRHH',
            'description' => 'Evento creado desde Configuración → Probar Google Calendar por ' . $me['name'] . ".\nSe borra al tocar «Finalizar test» en el portal.",
            'start' => ['date' => date('Y-m-d')],
            'end' => ['date' => date('Y-m-d', strtotime('+1 day'))],
            'colorId' => '10',
            'transparency' => 'transparent',
        ], $err);
        if ($code >= 300 || empty($ev['id'])) {
            flash('error', 'Conectado, pero no se pudo crear el evento de prueba: ' . $err);
            redirect('/admin/configuracion#gcal');
        }
        save_setting('gcal_test_event_id', $ev['id']);
        save_setting('gcal_test_event_link', (string) ($ev['htmlLink'] ?? ''));
        flash('success', "Se creó el evento «✅ Evento de prueba — Portal RRHH» para hoy en el calendario «{$cal['summary']}». Revisalo en Google Calendar y, si está todo bien, tocá «Finalizar test» para borrarlo.");
        redirect('/admin/configuracion#gcal');
    } elseif ($channel === 'gdrive') {
        if (!drive_enabled()) {
            flash('error', 'Cargá el ID de la carpeta de Google Drive y la clave de la cuenta de servicio.');
            redirect('/admin/configuracion#gcal');
        }
        [$code, $body] = drive_request('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode(setting('drive_root_folder')) . '?fields=name,capabilities(canAddChildren)&supportsAllDrives=true', null, [], $err);
        $f = json_decode($body, true) ?: [];
        $ok = $code === 200 && !empty($f['capabilities']['canAddChildren']);
        flash($ok ? 'success' : 'error', $ok
            ? "Conectado a la carpeta «{$f['name']}» de Google Drive. La documentación se guardará ahí."
            : 'No se pudo acceder a la carpeta de Google Drive: ' . ($err ?: 'sin permiso para crear contenido.'));
        redirect('/admin/configuracion#gcal');
    } elseif ($channel === 'gcal_finish') {
        // Paso 2 del test: borra el evento de prueba
        $id = setting('gcal_test_event_id');
        if ($id) {
            [$code] = gcal_api('DELETE', '/events/' . rawurlencode($id), null, $err);
            $ok = $code < 300 || $code === 404 || $code === 410;
            if ($ok) {
                save_setting('gcal_test_event_id', '');
                save_setting('gcal_test_event_link', '');
            }
            flash($ok ? 'success' : 'error', $ok ? 'Test finalizado: el evento de prueba se borró del calendario. Ciclo completo OK (crear → ver → borrar).' : 'No se pudo borrar el evento de prueba: ' . $err);
        }
        redirect('/admin/configuracion#gcal');
    } elseif ($channel === 'gchat') {
        $ok = send_gchat("✅ Prueba del Portal RRHH Intellihelp: las notificaciones llegan a este espacio.\n" . url('/'), $err);
        flash($ok ? 'success' : 'error', $ok ? 'Mensaje de prueba enviado al espacio de Google Chat.' : 'No se pudo enviar: ' . ($err ?: 'cargá la URL del webhook.'));
    } else {
        $u = $me;
        $u['notify_whatsapp'] = 1;
        $ok = send_whatsapp($u, "✅ Prueba de WhatsApp del Portal RRHH Intellihelp\n" . url('/'), $err);
        flash($ok ? 'success' : 'error', $ok ? 'WhatsApp de prueba enviado a ' . $me['phone'] . '.' : 'No se pudo enviar: ' . ($err ?: 'revisá driver, tu teléfono y apikey en Mi perfil.'));
    }
    redirect('/admin/configuracion');
}
