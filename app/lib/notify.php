<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Notificaciones por evento de negocio.
 *
 * Cada evento (nueva solicitud, decisión, cancelación, documentación, recordatorio) se informa
 * por los canales configurados: aviso en el portal, email, Google Chat, Google Calendar y,
 * opcionalmente, WhatsApp.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Notifica a un usuario: aviso en el portal, email (según configuración) y WhatsApp.
 *
 * Con la opción "invite" el correo lleva la invitación de calendario con el estado actual de la solicitud.
 *
 * @param array{rows?: array, button?: array, invite?: bool, heading?: string, accent?: string} $opts
 */
function notify_user(array $user, array $r, string $title, string $intro, array $opts = []): void
{
    $link = $opts['button'][1] ?? url('/solicitudes/' . $r['id']);
    insert('notifications', [
        'user_id' => $user['id'],
        'request_id' => $r['id'],
        'title' => mb_substr($title, 0, 200),
        'body' => mb_substr(strip_tags($intro), 0, 500),
        'link' => parse_url($link, PHP_URL_PATH),
    ]);

    if (request_email_allowed($user, $r)) {
        $ics = !empty($opts['invite']) ? invite_for($user, $r) : null;
        $html = mail_template($opts['heading'] ?? $title, $intro, $opts['rows'] ?? request_mail_rows($r), [$opts['button'][0] ?? 'Ver solicitud', $link], null, $opts['accent'] ?? '#663399');
        if (send_mail($user['email'], $title, $html, [], $ics) && $ics) {
            invite_delivered($user, $r);
        }
    }

    $wa = "*{$title}*\n" . strip_tags(str_replace(['<br>', '</p>'], "\n", $intro)) . "\n" . $link;
    send_whatsapp($user, $wa);
}

/**
 * Indica si corresponde enviar por correo los avisos de una solicitud a un usuario.
 *
 * Alcance configurable: off | employees (solo al solicitante) | all.
 */
function request_email_allowed(array $user, array $r): bool
{
    $mode = setting('event_email', 'off');
    $ok = $mode === 'all' || ($mode === 'employees' && (int) $user['id'] === (int) $r['user_id']);

    return $ok && !empty($user['notify_email']);
}

/**
 * Indica si la solicitud debe figurar en los calendarios.
 */
function calendar_active(array $r): bool
{
    return in_array($r['status'], ['pending', 'approved', 'registered'], true);
}

/**
 * Incrementa la secuencia de calendario de una solicitud antes de comunicar un cambio.
 *
 * Los clientes de calendario solo aplican una invitación si su secuencia es mayor a la anterior.
 */
function calendar_bump(int $id): void
{
    q('UPDATE leave_requests SET cal_seq = cal_seq + 1 WHERE id = ?', [$id]);
}

/**
 * Invitación de calendario para un destinatario según el estado actual de la solicitud.
 *
 * @return string|null Invitación (alta, actualización o cancelación) o null si no corresponde enviar nada.
 */
function invite_for(array $user, array $r): ?string
{
    if (calendar_active($r)) {
        return request_invite($r, $user);
    }
    $sent = val('SELECT 1 FROM calendar_invites WHERE request_id = ? AND user_id = ?', [$r['id'], $user['id']]);

    return $sent ? request_invite($r, $user, true) : null;
}

/**
 * Registra que un destinatario recibió la invitación vigente (o su cancelación).
 */
function invite_delivered(array $user, array $r): void
{
    if (calendar_active($r)) {
        q('INSERT IGNORE INTO calendar_invites (request_id, user_id) VALUES (?, ?)', [$r['id'], $user['id']]);
    } else {
        q('DELETE FROM calendar_invites WHERE request_id = ? AND user_id = ?', [$r['id'], $user['id']]);
    }
    invites_round((int) $r['id'], (int) $user['id']);
}

/**
 * Destinatarios que ya recibieron la invitación en la ronda de avisos actual.
 *
 * @return array<int, true>
 */
function invites_round(int $requestId, ?int $userId = null): array
{
    static $done = [];
    if ($userId !== null) {
        $done[$requestId][$userId] = true;
    }

    return $done[$requestId] ?? [];
}

/**
 * Actualiza el evento en el calendario de quienes tienen la invitación y no recibieron otro aviso.
 *
 * Mantiene coherentes los calendarios de todos los involucrados: al autorizarse la solicitud el
 * evento deja de figurar como pendiente, y al rechazarse o cancelarse se quita.
 */
function invites_refresh(array $r): void
{
    $active = calendar_active($r);
    $done = invites_round((int) $r['id']);
    foreach (rows('SELECT u.* FROM calendar_invites c JOIN users u ON u.id = c.user_id WHERE c.request_id = ?', [$r['id']]) as $u) {
        if (isset($done[$u['id']]) || !request_email_allowed($u, $r)) {
            continue;
        }
        $state = mb_strtolower(STATUS_LABELS[$r['status']] ?? $r['status']);
        $subject = ($active ? 'Calendario actualizado: ' : 'Quitado del calendario: ') . "{$r['type_name']} — {$r['user_name']}";
        $intro = $active
            ? 'La solicitud de ' . e($r['type_name']) . ' de ' . e($r['user_name']) . ' del ' . e(fwhen($r)) . ' quedó <b>' . e($state) . '</b>. Actualizamos el evento en tu calendario.'
            : 'La solicitud de ' . e($r['type_name']) . ' de ' . e($r['user_name']) . ' del ' . e(fwhen($r)) . ' fue <b>' . e($state) . '</b>, así que la quitamos de tu calendario.';
        $ics = invite_for($u, $r);
        $html = mail_template($active ? 'Calendario actualizado' : 'Evento quitado del calendario', $intro, request_mail_rows($r), ['Ver solicitud', url('/solicitudes/' . $r['id'])], null, $active ? '#663399' : '#161922');
        if ($ics && send_mail($u['email'], $subject, $html, [], $ics)) {
            invite_delivered($u, $r);
        }
    }
}

/**
 * Filas de detalle de una solicitud para los correos.
 */
function request_mail_rows(array $r): array
{
    $rows = [
        ['Colaborador/a', e($r['user_name'])],
        ['Tipo', e($r['type_name'])],
        [is_hourly($r) ? 'Día y horario' : 'Fechas', e(fwhen($r))],
        ['Duración', e(fduration($r)) . (!empty($r['deduct_vacation']) ? ' · descontados de vacaciones' : '')],
    ];
    if (!empty($r['reason'])) {
        $rows[] = ['Motivo', nl2br(e($r['reason']))];
    }
    $docs = (int) val('SELECT COUNT(*) FROM documents WHERE request_id = ?', [$r['id']]);
    if ($docs) {
        $rows[] = ['Documentación', e($docs . ' archivo' . ($docs > 1 ? 's' : '') . ' adjunto' . ($docs > 1 ? 's' : ''))];
    }

    return $rows;
}

/**
 * Usuario activo por ID.
 */
function user_by_id(?int $id): ?array
{
    return $id ? row('SELECT * FROM users WHERE id = ? AND active = 1', [$id]) : null;
}

/**
 * Usuarios que deben decidir sobre la solicitud: su aprobador o, si no tiene, RRHH.
 */
function approvers_for(array $r): array
{
    if ($r['approver_id']) {
        $a = user_by_id((int) $r['approver_id']);
        if ($a) {
            return [$a];
        }
    }

    return array_values(array_filter(hr_users(), fn ($u) => (int) $u['id'] !== (int) $r['user_id']));
}

/**
 * Envía copia a la casilla de RRHH, si está configurada.
 */
function hr_copy(array $r, string $subject, string $intro): void
{
    $box = setting('hr_email');
    if ($box !== '' && setting('event_email', 'off') === 'all') {
        send_mail($box, $subject, mail_template($subject, $intro, request_mail_rows($r), ['Ver en el portal', url('/solicitudes/' . $r['id'])]));
    }
}

/**
 * Elimina duplicados y excluye a un usuario de una lista de destinatarios.
 */
function notify_unique(array $users, int $excludeId): array
{
    $seen = [];
    $out = [];
    foreach ($users as $u) {
        if (!$u || (int) $u['id'] === $excludeId || isset($seen[$u['id']])) {
            continue;
        }
        $seen[$u['id']] = true;
        $out[] = $u;
    }

    return $out;
}

/**
 * Nueva solicitud: aviso a quien autoriza o, si es informada, a RRHH y al responsable.
 */
function on_request_created(int $id): void
{
    calendar_bump($id);
    gcal_sync($id);
    $r = load_request($id);
    $owner = user_by_id((int) $r['user_id']);
    $range = fwhen($r);

    if ($r['status'] === 'pending') {
        foreach (approvers_for($r) as $a) {
            notify_user(
                $a,
                $r,
                "Nueva solicitud de {$r['user_name']}: {$r['type_name']}",
                e($r['user_name']) . ' solicitó <b>' . e($r['type_name']) . '</b> del ' . e($range) . ' (' . e(fduration($r, false)) . '). Entrá al portal para autorizarla o rechazarla.',
                [
                    'heading' => 'Tenés una solicitud para autorizar',
                    'button' => ['Revisar y decidir', url('/solicitudes/' . $r['id'])],
                    'invite' => true,
                ]
            );
        }
        if ($owner) {
            notify_user(
                $owner,
                $r,
                'Tu solicitud fue enviada',
                'Recibimos tu solicitud de <b>' . e($r['type_name']) . '</b> del ' . e($range) . '. Ya figura en tu calendario como pendiente y te avisamos cuando se resuelva.',
                ['heading' => 'Solicitud enviada', 'invite' => true]
            );
        }
        hr_copy($r, "Nueva solicitud: {$r['user_name']} — {$r['type_name']}", e($r['user_name']) . ' cargó una solicitud pendiente de autorización.');
        send_gchat(chat_message(
            '🟣 *Nueva solicitud para autorizar*',
            "{$r['user_name']} pidió *{$r['type_name']}* del {$range} (" . fduration($r, false) . ')',
            '👉 ' . url('/solicitudes/' . $r['id'])
        ));

        return;
    }

    // Licencias que no requieren autorización: se informa al responsable y a RRHH.
    $dueTxt = doc_due_text($r);
    $docTxt = $dueTxt ? ' ' . e($dueTxt) . '.' : '';
    $targets = notify_unique([user_by_id((int) $r['manager_id']), ...hr_users()], (int) $r['user_id']);
    foreach ($targets as $t) {
        notify_user(
            $t,
            $r,
            "{$r['user_name']} informó {$r['type_name']}",
            e($r['user_name']) . ' informó <b>' . e($r['type_name']) . '</b> del ' . e($range) . ' (' . e(fduration($r, false)) . '). No requiere autorización.' . $docTxt,
            [
                'heading' => 'Licencia informada',
                'button' => ['Ver detalle', url('/solicitudes/' . $r['id'])],
                'invite' => true,
                'accent' => '#663399',
            ]
        );
    }
    hr_copy($r, "Licencia informada: {$r['user_name']} — {$r['type_name']}", e($r['user_name']) . ' informó una licencia.' . $docTxt);
    send_gchat(chat_message(
        '📋 *Licencia informada*',
        "{$r['user_name']} informó *{$r['type_name']}* del {$range} (" . fduration($r, false) . ')',
        $dueTxt ? '📎 ' . $dueTxt : '',
        '👉 ' . url('/solicitudes/' . $r['id'])
    ));
    if ($owner) {
        notify_user(
            $owner,
            $r,
            "Registramos tu licencia: {$r['type_name']}",
            'Tu licencia de <b>' . e($r['type_name']) . '</b> del ' . e($range) . ' quedó registrada y RRHH fue notificado.'
            . ($dueTxt ? '<br><br><b>' . e(doc_due_text($r, true)) . '</b> desde la solicitud en el portal.' : ''),
            ['heading' => 'Licencia registrada', 'invite' => true]
        );
    }
}

/**
 * Solicitud aprobada o rechazada.
 */
function on_request_decided(int $id): void
{
    calendar_bump($id);
    gcal_sync($id);
    $r = load_request($id);
    $owner = user_by_id((int) $r['user_id']);
    $range = fwhen($r);
    $approved = $r['status'] === 'approved';
    $comment = $r['decision_comment'] ? '<br><br><i>«' . nl2br(e($r['decision_comment'])) . '»</i>' : '';

    if ($owner) {
        notify_user(
            $owner,
            $r,
            $approved ? "✅ Aprobada: {$r['type_name']} ({$range})" : "Rechazada: {$r['type_name']} ({$range})",
            e($r['decider_name']) . ($approved ? ' <b>aprobó</b>' : ' <b>rechazó</b>') . ' tu solicitud de ' . e($r['type_name']) . ' del ' . e($range) . '.'
            . ($approved && $r['deduct_vacation'] ? ' Los días se descuentan de tus vacaciones.' : '')
            . ($approved && doc_due_text($r) ? '<br><br><b>' . e(doc_due_text($r, true)) . '</b> desde la solicitud en el portal.' : '') . $comment,
            [
                'heading' => $approved ? '¡Tu solicitud fue aprobada!' : 'Tu solicitud fue rechazada',
                'invite' => true,
                'accent' => $approved ? '#663399' : '#161922',
            ]
        );
    }
    // El aprobador y RRHH reciben el aviso en el portal (salvo quien tomó la decisión).
    foreach (notify_unique([user_by_id((int) $r['approver_id']), ...hr_users()], (int) $r['decided_by']) as $t) {
        if ((int) $t['id'] === (int) $r['user_id']) {
            continue;
        }
        insert('notifications', ['user_id' => $t['id'], 'request_id' => $r['id'],
            'title' => "{$r['decider_name']} " . ($approved ? 'aprobó' : 'rechazó') . " la solicitud de {$r['user_name']}",
            'body' => "{$r['type_name']} · {$range}", 'link' => '/solicitudes/' . $r['id']]);
    }
    send_gchat(chat_message(
        ($approved ? '✅' : '❌') . " {$r['decider_name']} " . ($approved ? 'aprobó' : 'rechazó') . " *{$r['type_name']}* de {$r['user_name']} ({$range})"
            . ($approved && $r['deduct_vacation'] ? ' — descontado de vacaciones' : ''),
        '👉 ' . url('/solicitudes/' . $r['id'])
    ));
    hr_copy($r, ($approved ? 'Aprobada' : 'Rechazada') . ": {$r['user_name']} — {$r['type_name']}", e($r['decider_name']) . ($approved ? ' aprobó' : ' rechazó') . ' la solicitud.' . $comment);
    invites_refresh($r);
}

/**
 * Solicitud cancelada por el colaborador o por RRHH.
 */
function on_request_cancelled(int $id, string $prevStatus): void
{
    calendar_bump($id);
    gcal_sync($id);
    $r = load_request($id);
    send_gchat(chat_message("🚫 {$r['user_name']} canceló *{$r['type_name']}* (" . fwhen($r) . ')', '👉 ' . url('/solicitudes/' . $r['id'])));
    $targets = notify_unique([...approvers_for($r), user_by_id((int) $r['manager_id'])], (int) $r['user_id']);
    foreach ($targets as $t) {
        notify_user(
            $t,
            $r,
            "{$r['user_name']} canceló su solicitud de {$r['type_name']}",
            e($r['user_name']) . ' canceló la solicitud de ' . e($r['type_name']) . ' del ' . e(fwhen($r)) . '.',
            ['heading' => 'Solicitud cancelada', 'invite' => true, 'accent' => '#161922']
        );
    }
    invites_refresh($r);
}

/**
 * Documentación agregada a una solicitud existente.
 */
function on_document_uploaded(int $id, int $count): void
{
    $r = load_request($id);
    $label = $r['document_label'] ?: 'documentación';
    send_gchat(chat_message(
        "📎 {$r['user_name']} subió " . mb_strtolower($label) . " ({$r['type_name']})",
        '👉 ' . url('/solicitudes/' . $r['id'])
    ));
    foreach (notify_unique([...hr_users(), user_by_id((int) $r['manager_id'])], uid()) as $t) {
        notify_user(
            $t,
            $r,
            "{$r['user_name']} subió {$label}",
            e($r['user_name']) . ' subió ' . $count . ' archivo' . ($count > 1 ? 's' : '') . ' (' . e(mb_strtolower($label)) . ') a su solicitud de ' . e($r['type_name']) . ' del ' . e(fwhen($r)) . '.',
            ['heading' => 'Nueva documentación recibida', 'button' => ['Ver documentación', url('/solicitudes/' . $r['id'])]]
        );
    }
}

/**
 * Documentación verificada u observada por RRHH.
 */
function on_document_reviewed(int $id, string $result, string $comment): void
{
    $r = load_request($id);
    $owner = user_by_id((int) $r['user_id']);
    if (!$owner) {
        return;
    }
    $ok = $result === 'verified';
    notify_user(
        $owner,
        $r,
        $ok ? "Documentación verificada: {$r['type_name']}" : "Documentación observada: {$r['type_name']}",
        $ok ? 'RRHH verificó la documentación de tu ' . e(mb_strtolower($r['type_name'])) . '. ¡Gracias!'
            : 'RRHH observó la documentación de tu ' . e(mb_strtolower($r['type_name'])) . '. Revisá el comentario y volvé a subirla.' . ($comment ? '<br><br><i>«' . nl2br(e($comment)) . '»</i>' : ''),
        ['heading' => $ok ? 'Documentación verificada' : 'Necesitamos que revises tu documentación']
    );
}

/**
 * Texto del plazo de entrega de la documentación, o cadena vacía si no corresponde.
 *
 * Con $forOwner se redacta en segunda persona para el colaborador.
 */
function doc_due_text(array $r, bool $forOwner = false): string
{
    if (!$r['doc_due_date'] || !accepts_documents($r)) {
        return '';
    }
    $label = mb_strtolower($r['document_label'] ?: 'documentación');
    $optional = !$r['requires_document'];

    return $forOwner
        ? ($optional ? 'Si corresponde, podés entregar ' : 'Entregá ') . $label . ' hasta el ' . fdate($r['doc_due_date'])
        : ($optional ? 'Puede entregar ' : 'Debe entregar ') . $label . ' hasta el ' . fdate($r['doc_due_date']);
}

/**
 * Plazo de entrega modificado por quien autoriza o por RRHH.
 */
function on_doc_due_changed(int $id): void
{
    $r = load_request($id);
    $owner = user_by_id((int) $r['user_id']);
    if ($owner) {
        notify_user(
            $owner,
            $r,
            'Nuevo plazo para tu ' . mb_strtolower($r['document_label'] ?: 'documentación') . ': ' . fdate($r['doc_due_date']),
            e(doc_due_text($r, true)) . ', desde tu solicitud de ' . e($r['type_name']) . ' del ' . e(fwhen($r)) . '.',
            ['heading' => 'Plazo de entrega de documentación', 'button' => ['Entregar documentación', url('/solicitudes/' . $r['id'])]]
        );
    }
}

/**
 * Recordatorios y vencimientos de la documentación pendiente (tarea programada).
 *
 * Un día antes del vencimiento se recuerda al colaborador; vencido el plazo se avisa al
 * colaborador, a quien autorizó o a su responsable, y a RRHH. Cada aviso se envía una vez.
 *
 * @return array{0: int, 1: int} Recordatorios y vencimientos notificados.
 */
function doc_deadline_job(): array
{
    $base = "SELECT r.id FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
        WHERE r.status IN ('approved','registered') AND r.doc_status IN ('none','observed')
          AND t.requires_document = 1 AND r.doc_due_date IS NOT NULL";
    $reminded = 0;
    foreach (rows("$base AND r.doc_reminded_at IS NULL AND r.doc_due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 1 DAY") as $x) {
        $r = load_request((int) $x['id']);
        $owner = user_by_id((int) $r['user_id']);
        if ($owner) {
            notify_user(
                $owner,
                $r,
                'Recordatorio: entregá tu ' . mb_strtolower($r['document_label'] ?: 'documentación') . ' hasta el ' . fdate($r['doc_due_date']),
                'Tu solicitud de <b>' . e($r['type_name']) . '</b> del ' . e(fwhen($r)) . ' todavía no tiene ' . e(mb_strtolower($r['document_label'] ?: 'documentación')) . '. El plazo vence el <b>' . e(fdate($r['doc_due_date'])) . '</b>.',
                ['heading' => 'Documentación pendiente', 'button' => ['Entregar documentación', url('/solicitudes/' . $r['id'])]]
            );
        }
        q('UPDATE leave_requests SET doc_reminded_at = NOW() WHERE id = ?', [$r['id']]);
        log_event((int) $r['id'], 'doc_reminder');
        $reminded++;
    }

    $overdue = 0;
    foreach (rows("$base AND r.doc_overdue_at IS NULL AND r.doc_due_date < CURDATE()") as $x) {
        $r = load_request((int) $x['id']);
        $label = mb_strtolower($r['document_label'] ?: 'documentación');
        $owner = user_by_id((int) $r['user_id']);
        if ($owner) {
            notify_user(
                $owner,
                $r,
                "Venció el plazo para entregar tu $label",
                'El plazo para entregar ' . e($label) . ' de tu solicitud de <b>' . e($r['type_name']) . '</b> del ' . e(fwhen($r)) . ' venció el <b>' . e(fdate($r['doc_due_date'])) . '</b>. Entregala cuanto antes; RRHH y tu responsable fueron notificados.',
                ['heading' => 'Documentación vencida', 'button' => ['Entregar documentación', url('/solicitudes/' . $r['id'])], 'accent' => '#c0392b']
            );
        }
        $staff = notify_unique([user_by_id((int) ($r['decided_by'] ?: $r['approver_id'] ?: $r['manager_id'])), user_by_id((int) $r['manager_id']), ...hr_users()], (int) $r['user_id']);
        foreach ($staff as $t) {
            notify_user(
                $t,
                $r,
                "{$r['user_name']} no entregó $label",
                e($r['user_name']) . ' no entregó ' . e($label) . ' de su licencia de <b>' . e($r['type_name']) . '</b> del ' . e(fwhen($r)) . '. El plazo venció el <b>' . e(fdate($r['doc_due_date'])) . '</b>.',
                ['heading' => 'Documentación vencida', 'button' => ['Ver solicitud', url('/solicitudes/' . $r['id'])]]
            );
        }
        send_gchat(chat_message(
            '⚠️ *Documentación vencida*',
            "{$r['user_name']} no entregó $label de *{$r['type_name']}* (" . fwhen($r) . ') · venció el ' . fdate($r['doc_due_date']),
            '👉 ' . url('/solicitudes/' . $r['id'])
        ));
        q('UPDATE leave_requests SET doc_overdue_at = NOW() WHERE id = ?', [$r['id']]);
        log_event((int) $r['id'], 'doc_overdue');
        $overdue++;
    }

    return [$reminded, $overdue];
}

/**
 * Recordatorio de solicitud pendiente (tarea programada).
 */
function on_reminder(array $r, array $approver): void
{
    static $chatSent = [];
    if (!isset($chatSent[$r['id']])) {
        $chatSent[$r['id']] = true;
        send_gchat("⏰ *Recordatorio:* la solicitud de {$r['user_name']} (*{$r['type_name']}*, " . fwhen($r) . ') sigue esperando autorización de ' . ($r['approver_name'] ?: 'RRHH') . "\n👉 " . url('/solicitudes/' . $r['id']));
    }
    notify_user(
        $approver,
        $r,
        "Recordatorio: solicitud pendiente de {$r['user_name']}",
        'La solicitud de <b>' . e($r['type_name']) . '</b> de ' . e($r['user_name']) . ' del ' . e(fwhen($r)) . ' sigue esperando tu decisión.',
        ['heading' => 'Solicitud pendiente de autorización', 'button' => ['Revisar y decidir', url('/solicitudes/' . $r['id'])]]
    );
}
