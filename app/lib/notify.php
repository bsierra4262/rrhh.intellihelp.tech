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
 * @param array{rows?: array, button?: array, ics?: bool|string, gcal?: ?string, heading?: string, accent?: string} $opts
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

    // Alcance de los emails de solicitudes: off | employees (solo al solicitante) | all.
    $mode = setting('event_email', 'off');
    $emailOk = $mode === 'all' || ($mode === 'employees' && (int) $user['id'] === (int) $r['user_id']);
    if ($emailOk && !empty($user['notify_email'])) {
        $ics = null;
        if (!empty($opts['ics'])) {
            $cancel = $opts['ics'] === 'CANCEL';
            $ev = request_ics_event($r, $cancel ? 'CANCELLED' : 'CONFIRMED', $cancel ? 1 : 0);
            $ev['organizer'] = [setting('mail_from', 'rrhh@intellihelp.tech'), setting('mail_from_name', 'RRHH Intellihelp')];
            $ev['attendees'] = [[$user['email'], $user['name']]];
            $ics = ics_calendar([$ev], $cancel ? 'CANCEL' : 'REQUEST');
        }
        $footer = '';
        if (!empty($opts['gcal'])) {
            $footer = '📅 <a href="' . e($opts['gcal']) . '" style="color:#663399">Agregar a Google Calendar</a>';
        }
        $html = mail_template($opts['heading'] ?? $title, $intro, $opts['rows'] ?? request_mail_rows($r), [$opts['button'][0] ?? 'Ver solicitud', $link], $footer ?: null, $opts['accent'] ?? '#663399');
        send_mail($user['email'], $title, $html, [], $ics);
    }

    $wa = "*{$title}*\n" . strip_tags(str_replace(['<br>', '</p>'], "\n", $intro)) . "\n" . $link;
    send_whatsapp($user, $wa);
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
                    'gcal' => gcal_link("[Pendiente] {$r['type_name']} — {$r['user_name']}", $r['start_date'], $r['end_date'], url('/solicitudes/' . $r['id']), $r['start_time'], $r['end_time']),
                ]
            );
        }
        if ($owner) {
            insert('notifications', ['user_id' => $owner['id'], 'request_id' => $r['id'],
                'title' => 'Tu solicitud fue enviada', 'body' => "{$r['type_name']} · {$range}. Te avisamos cuando se resuelva.",
                'link' => '/solicitudes/' . $r['id']]);
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
                'ics' => true,
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
            ['heading' => 'Licencia registrada', 'ics' => true]
        );
    }
}

/**
 * Solicitud aprobada o rechazada.
 */
function on_request_decided(int $id): void
{
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
                'ics' => $approved,
                'gcal' => $approved ? gcal_link("{$r['type_name']}", $r['start_date'], $r['end_date'], url('/solicitudes/' . $r['id']), $r['start_time'], $r['end_time']) : null,
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
}

/**
 * Solicitud cancelada por el colaborador o por RRHH.
 */
function on_request_cancelled(int $id, string $prevStatus): void
{
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
            ['heading' => 'Solicitud cancelada', 'ics' => in_array($prevStatus, ['approved', 'registered'], true) ? 'CANCEL' : false, 'accent' => '#161922']
        );
    }
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
