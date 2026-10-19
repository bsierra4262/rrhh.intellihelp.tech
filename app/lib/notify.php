<?php
declare(strict_types=1);

/**
 * Notifica a un usuario por los 3 canales: aviso en el portal, email y WhatsApp.
 * $opts: rows (detalle para el email), button [texto,url], ics (bool|'CANCEL'), gcal (url), heading, accent
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

    // Emails de solicitudes: off | employees (solo a quien pidió) | all
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

function request_mail_rows(array $r): array
{
    $rows = [
        ['Colaborador/a', e($r['user_name'])],
        ['Tipo', e($r['type_name'])],
        ['Fechas', e(frange($r['start_date'], $r['end_date']))],
        ['Duración', e(fdays_kind($r['days'], $r['counting']))],
    ];
    if (!empty($r['reason'])) $rows[] = ['Motivo', nl2br(e($r['reason']))];
    $docs = (int) val('SELECT COUNT(*) FROM documents WHERE request_id = ?', [$r['id']]);
    if ($docs) $rows[] = ['Documentación', e($docs . ' archivo' . ($docs > 1 ? 's' : '') . ' adjunto' . ($docs > 1 ? 's' : ''))];
    return $rows;
}

function user_by_id(?int $id): ?array
{
    return $id ? row('SELECT * FROM users WHERE id = ? AND active = 1', [$id]) : null;
}

/** Destinatarios que deben decidir/enterarse: el aprobador, o todo RRHH si no hay. */
function approvers_for(array $r): array
{
    if ($r['approver_id']) {
        $a = user_by_id((int) $r['approver_id']);
        if ($a) return [$a];
    }
    return array_values(array_filter(hr_users(), fn($u) => (int) $u['id'] !== (int) $r['user_id']));
}

function hr_copy(array $r, string $subject, string $intro): void
{
    $box = setting('hr_email');
    if ($box !== '' && setting('event_email', 'off') === 'all') {
        send_mail($box, $subject, mail_template($subject, $intro, request_mail_rows($r), ['Ver en el portal', url('/solicitudes/' . $r['id'])]));
    }
}

function notify_unique(array $users, int $excludeId): array
{
    $seen = [];
    $out = [];
    foreach ($users as $u) {
        if (!$u || (int) $u['id'] === $excludeId || isset($seen[$u['id']])) continue;
        $seen[$u['id']] = true;
        $out[] = $u;
    }
    return $out;
}

// ---------------- eventos ----------------

function on_request_created(int $id): void
{
    gcal_sync($id);
    $r = load_request($id);
    $owner = user_by_id((int) $r['user_id']);
    $range = frange($r['start_date'], $r['end_date']);

    if ($r['status'] === 'pending') {
        foreach (approvers_for($r) as $a) {
            notify_user($a, $r,
                "Nueva solicitud de {$r['user_name']}: {$r['type_name']}",
                e($r['user_name']) . ' solicitó <b>' . e($r['type_name']) . '</b> del ' . e($range) . ' (' . e(fdays($r['days'])) . '). Entrá al portal para autorizarla o rechazarla.',
                [
                    'heading' => 'Tenés una solicitud para autorizar',
                    'button' => ['Revisar y decidir', url('/solicitudes/' . $r['id'])],
                    'gcal' => gcal_link("[Pendiente] {$r['type_name']} — {$r['user_name']}", $r['start_date'], $r['end_date'], url('/solicitudes/' . $r['id'])),
                ]);
        }
        if ($owner) {
            insert('notifications', ['user_id' => $owner['id'], 'request_id' => $r['id'],
                'title' => 'Tu solicitud fue enviada', 'body' => "{$r['type_name']} · {$range}. Te avisamos cuando se resuelva.",
                'link' => '/solicitudes/' . $r['id']]);
        }
        hr_copy($r, "Nueva solicitud: {$r['user_name']} — {$r['type_name']}", e($r['user_name']) . ' cargó una solicitud pendiente de autorización.');
        send_gchat("🟣 *Nueva solicitud para autorizar*
{$r['user_name']} pidió *{$r['type_name']}* del {$range} (" . fdays($r['days']) . ")
👉 " . url('/solicitudes/' . $r['id']));
        return;
    }

    // Licencias que no requieren autorización (enfermedad, etc.): se informa a responsable y RRHH.
    $docs = (int) val('SELECT COUNT(*) FROM documents WHERE request_id = ?', [$r['id']]);
    $docTxt = $docs ? ' Ya subió ' . ($r['document_label'] ? e(mb_strtolower($r['document_label'])) : 'la documentación') . ' (' . $docs . ' archivo' . ($docs > 1 ? 's' : '') . ').' : '';
    $targets = notify_unique([user_by_id((int) $r['manager_id']), ...hr_users()], (int) $r['user_id']);
    foreach ($targets as $t) {
        notify_user($t, $r,
            "{$r['user_name']} informó {$r['type_name']}",
            e($r['user_name']) . ' informó <b>' . e($r['type_name']) . '</b> del ' . e($range) . ' (' . e(fdays($r['days'])) . '). No requiere autorización.' . $docTxt,
            [
                'heading' => 'Licencia informada',
                'button' => [$docs ? 'Ver certificado y detalle' : 'Ver detalle', url('/solicitudes/' . $r['id'])],
                'ics' => true,
                'accent' => '#663399',
            ]);
    }
    hr_copy($r, "Licencia informada: {$r['user_name']} — {$r['type_name']}", e($r['user_name']) . ' informó una licencia.' . $docTxt);
    send_gchat("📋 *Licencia informada*
{$r['user_name']} informó *{$r['type_name']}* del {$range} (" . fdays($r['days']) . ')' . ($docs ? "
📎 Ya subió " . mb_strtolower($r['document_label'] ?: 'la documentación') : '') . "
👉 " . url('/solicitudes/' . $r['id']));
    if ($owner) {
        notify_user($owner, $r, "Registramos tu licencia: {$r['type_name']}",
            'Tu licencia de <b>' . e($r['type_name']) . '</b> del ' . e($range) . ' quedó registrada y RRHH fue notificado.' . ($docs ? ' Recibimos tu documentación.' : ''),
            ['heading' => '¡Que te mejores!' , 'ics' => true]);
    }
}

function on_request_decided(int $id): void
{
    gcal_sync($id);
    $r = load_request($id);
    $owner = user_by_id((int) $r['user_id']);
    $range = frange($r['start_date'], $r['end_date']);
    $approved = $r['status'] === 'approved';
    $comment = $r['decision_comment'] ? '<br><br><i>«' . nl2br(e($r['decision_comment'])) . '»</i>' : '';

    if ($owner) {
        notify_user($owner, $r,
            $approved ? "✅ Aprobada: {$r['type_name']} ({$range})" : "Rechazada: {$r['type_name']} ({$range})",
            e($r['decider_name']) . ($approved ? ' <b>aprobó</b>' : ' <b>rechazó</b>') . ' tu solicitud de ' . e($r['type_name']) . ' del ' . e($range) . '.' . $comment,
            [
                'heading' => $approved ? '¡Tu solicitud fue aprobada!' : 'Tu solicitud fue rechazada',
                'ics' => $approved,
                'gcal' => $approved ? gcal_link("{$r['type_name']}", $r['start_date'], $r['end_date'], url('/solicitudes/' . $r['id'])) : null,
                'accent' => $approved ? '#663399' : '#161922',
            ]);
    }
    // El aprobador (si no es RRHH quien decidió) y RRHH reciben aviso en el portal
    foreach (notify_unique([user_by_id((int) $r['approver_id']), ...hr_users()], (int) $r['decided_by']) as $t) {
        if ((int) $t['id'] === (int) $r['user_id']) continue;
        insert('notifications', ['user_id' => $t['id'], 'request_id' => $r['id'],
            'title' => "{$r['decider_name']} " . ($approved ? 'aprobó' : 'rechazó') . " la solicitud de {$r['user_name']}",
            'body' => "{$r['type_name']} · {$range}", 'link' => '/solicitudes/' . $r['id']]);
    }
    send_gchat(($approved ? '✅' : '❌') . " {$r['decider_name']} " . ($approved ? 'aprobó' : 'rechazó') . " *{$r['type_name']}* de {$r['user_name']} ({$range})
👉 " . url('/solicitudes/' . $r['id']));
    hr_copy($r, ($approved ? 'Aprobada' : 'Rechazada') . ": {$r['user_name']} — {$r['type_name']}", e($r['decider_name']) . ($approved ? ' aprobó' : ' rechazó') . ' la solicitud.' . $comment);
}

function on_request_cancelled(int $id, string $prevStatus): void
{
    gcal_sync($id);
    $r = load_request($id);
    send_gchat("🚫 {$r['user_name']} canceló *{$r['type_name']}* (" . frange($r['start_date'], $r['end_date']) . ")\n👉 " . url('/solicitudes/' . $r['id']));
    $targets = notify_unique([...approvers_for($r), user_by_id((int) $r['manager_id'])], (int) $r['user_id']);
    foreach ($targets as $t) {
        notify_user($t, $r, "{$r['user_name']} canceló su solicitud de {$r['type_name']}",
            e($r['user_name']) . ' canceló la solicitud de ' . e($r['type_name']) . ' del ' . e(frange($r['start_date'], $r['end_date'])) . '.',
            ['heading' => 'Solicitud cancelada', 'ics' => in_array($prevStatus, ['approved', 'registered'], true) ? 'CANCEL' : false, 'accent' => '#161922']);
    }
}

function on_document_uploaded(int $id, int $count): void
{
    $r = load_request($id);
    $label = $r['document_label'] ?: 'documentación';
    send_gchat("📎 {$r['user_name']} subió " . mb_strtolower($label) . " ({$r['type_name']})
👉 " . url('/solicitudes/' . $r['id']));
    foreach (notify_unique([...hr_users(), user_by_id((int) $r['manager_id'])], uid()) as $t) {
        notify_user($t, $r, "{$r['user_name']} subió {$label}",
            e($r['user_name']) . ' subió ' . $count . ' archivo' . ($count > 1 ? 's' : '') . ' (' . e(mb_strtolower($label)) . ') a su solicitud de ' . e($r['type_name']) . ' del ' . e(frange($r['start_date'], $r['end_date'])) . '.',
            ['heading' => 'Nueva documentación recibida', 'button' => ['Ver documentación', url('/solicitudes/' . $r['id'])]]);
    }
}

function on_document_reviewed(int $id, string $result, string $comment): void
{
    $r = load_request($id);
    $owner = user_by_id((int) $r['user_id']);
    if (!$owner) return;
    $ok = $result === 'verified';
    notify_user($owner, $r,
        $ok ? "Documentación verificada: {$r['type_name']}" : "Documentación observada: {$r['type_name']}",
        $ok ? 'RRHH verificó la documentación de tu ' . e(mb_strtolower($r['type_name'])) . '. ¡Gracias!'
            : 'RRHH observó la documentación de tu ' . e(mb_strtolower($r['type_name'])) . '. Revisá el comentario y volvé a subirla.' . ($comment ? '<br><br><i>«' . nl2br(e($comment)) . '»</i>' : ''),
        ['heading' => $ok ? 'Documentación verificada' : 'Necesitamos que revises tu documentación']);
}

function on_reminder(array $r, array $approver): void
{
    static $chatSent = [];
    if (!isset($chatSent[$r['id']])) {
        $chatSent[$r['id']] = true;
        send_gchat("⏰ *Recordatorio:* la solicitud de {$r['user_name']} (*{$r['type_name']}*, " . frange($r['start_date'], $r['end_date']) . ") sigue esperando autorización de " . ($r['approver_name'] ?: 'RRHH') . "\n👉 " . url('/solicitudes/' . $r['id']));
    }
    notify_user($approver, $r, "Recordatorio: solicitud pendiente de {$r['user_name']}",
        'La solicitud de <b>' . e($r['type_name']) . '</b> de ' . e($r['user_name']) . ' del ' . e(frange($r['start_date'], $r['end_date'])) . ' sigue esperando tu decisión.',
        ['heading' => 'Solicitud pendiente de autorización', 'button' => ['Revisar y decidir', url('/solicitudes/' . $r['id'])]]);
}
