<?php
declare(strict_types=1);

function notifications_page(): void
{
    $u = require_login();
    view('notifications', [
        'title' => 'Notificaciones',
        'list' => rows('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [$u['id']]),
    ]);
}

function notifications_read(): void
{
    $u = require_login();
    $id = (int) post('id');
    if ($id) {
        $n = row('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $u['id']]);
        if ($n) {
            q('UPDATE notifications SET read_at = NOW() WHERE id = ?', [$id]);
            redirect($n['link'] ?: '/notificaciones');
        }
    }
    q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$u['id']]);
    redirect('/notificaciones');
}

function unread_count(): int
{
    return (int) val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [uid()]);
}

function pending_count(): int
{
    $u = current_user();
    if (!$u || !is_approver()) return 0;
    [$w, $p] = pending_scope($u);
    return (int) val("SELECT COUNT(*) FROM leave_requests r WHERE $w", $p);
}

function profile_form(): void
{
    $u = require_login();
    view('profile', [
        'title' => 'Mi perfil',
        'u' => $u,
        'manager' => $u['manager_id'] ? row('SELECT name, email FROM users WHERE id = ?', [$u['manager_id']]) : null,
        'feed' => url('/ical/' . $u['calendar_token'] . '.ics'),
        'wa_driver' => setting('wa_driver', 'off'),
    ]);
}

function profile_submit(): void
{
    $u = require_login();
    $phone = preg_replace('/[^\d+]/', '', post('phone'));
    q('UPDATE users SET phone = ?, wa_apikey = ?, notify_email = ?, notify_whatsapp = ? WHERE id = ?', [
        $phone ?: null,
        mb_substr(post('wa_apikey'), 0, 60) ?: null,
        isset($_POST['notify_email']) ? 1 : 0,
        !empty($_POST['notify_whatsapp']) ? 1 : 0,
        $u['id'],
    ]);
    flash('success', 'Preferencias guardadas.');
    redirect('/perfil');
}

function profile_password(): void
{
    $u = require_login();
    $current = (string) ($_POST['current'] ?? '');
    $p = (string) ($_POST['password'] ?? '');
    if (!password_verify($current, $u['password_hash'])) {
        flash('error', 'La contraseña actual no es correcta.');
        redirect('/perfil#password');
    }
    if ($problems = password_problems($p)) {
        flash('error', 'La contraseña nueva necesita ' . implode(', ', $problems) . '.');
        redirect('/perfil#password');
    }
    if ($p !== ($_POST['password_confirm'] ?? '')) {
        flash('error', 'Las contraseñas no coinciden.');
        redirect('/perfil#password');
    }
    q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($p, PASSWORD_DEFAULT), $u['id']]);
    session_regenerate_id(true);
    flash('success', 'Contraseña actualizada.');
    redirect('/');
}

function profile_reset_calendar(): void
{
    $u = require_login();
    q('UPDATE users SET calendar_token = ? WHERE id = ?', [bin2hex(random_bytes(20)), $u['id']]);
    flash('success', 'Generamos un enlace nuevo. El anterior dejó de funcionar.');
    redirect('/perfil#calendario');
}
