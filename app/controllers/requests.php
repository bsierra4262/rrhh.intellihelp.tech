<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Ciclo de vida de las solicitudes: alta, consulta, decisión, cancelación y adjuntos.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Historial de solicitudes propias.
 */
function my_requests(): void
{
    $u = require_login();
    $year = (int) ($_GET['anio'] ?? date('Y'));
    $status = (string) ($_GET['estado'] ?? '');
    $sql = REQ_SELECT . ' WHERE r.user_id = ? AND YEAR(r.start_date) = ?';
    $p = [$u['id'], $year];
    if (isset(STATUS_LABELS[$status])) {
        $sql .= ' AND r.status = ?';
        $p[] = $status;
    }
    view('requests/mine', [
        'title' => 'Mis solicitudes',
        'list' => rows($sql . ' ORDER BY r.start_date DESC', $p),
        'year' => $year,
        'status' => $status,
        'balances' => balances($u, $year),
    ]);
}

/**
 * Formulario de nueva solicitud.
 */
function request_form(): void
{
    $u = require_login();
    $types = leave_types();
    $employees = is_hr() ? rows('SELECT id, name, department FROM users WHERE active = 1 ORDER BY name') : [];
    $pre = (string) ($_GET['tipo'] ?? '');
    view('requests/new', [
        'title' => 'Nueva solicitud',
        'types' => $types,
        'employees' => $employees,
        'preselect' => $pre,
        'balances' => balances($u, (int) date('Y')),
    ]);
}

/**
 * Crea una solicitud (o la carga RRHH en nombre de un colaborador).
 */
function request_submit(): void
{
    $me = require_login();
    remember_input();
    $type = leave_type((int) post('type_id'));
    if (!$type || !$type['active']) {
        flash('error', 'Elegí un tipo de licencia.');
        redirect('/solicitudes/nueva');
    }

    // RRHH puede cargar una licencia en nombre de otro colaborador.
    $onBehalf = is_hr() && (int) post('user_id') && (int) post('user_id') !== (int) $me['id'];
    $owner = $onBehalf ? row('SELECT * FROM users WHERE id = ? AND active = 1', [(int) post('user_id')]) : $me;
    if (!$owner) {
        flash('error', 'Colaborador/a no válido.');
        redirect('/solicitudes/nueva');
    }

    $from = post('start_date');
    $to = post('end_date') ?: $from;
    $startTime = $endTime = $hours = null;
    if ($type['by_hours']) {
        // Trámite por horario: un único día con hora de salida y de regreso; no consume días.
        $to = $from;
        $startTime = post('start_time');
        $endTime = post('end_time');
        [$errors, $warnings, $hours] = validate_hourly_request($owner, $type, $from, $startTime, $endTime);
        $days = 0;
    } else {
        [$errors, $warnings, $days] = validate_request($owner, $type, $from, $to, null, is_hr());
    }

    if ($errors) {
        foreach ($errors as $err) {
            flash('error', $err);
        }
        redirect('/solicitudes/nueva?tipo=' . $type['code']);
    }

    $autoApprove = $onBehalf && $type['requires_approval'] && post('auto_approve') === '1';
    $status = !$type['requires_approval'] ? 'registered' : ($autoApprove ? 'approved' : 'pending');

    db()->beginTransaction();
    $id = insert('leave_requests', [
        'user_id' => $owner['id'],
        'type_id' => $type['id'],
        'start_date' => $from,
        'end_date' => $to,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'days' => $days,
        'hours' => $hours,
        'reason' => mb_substr(post('reason'), 0, 2000) ?: null,
        'status' => $status,
        'approver_id' => $type['requires_approval'] ? resolve_approver($owner) : null,
        'decided_by' => $autoApprove ? $me['id'] : null,
        'decided_at' => $autoApprove ? date('Y-m-d H:i:s') : null,
    ]);
    // La documentación se entrega después de informada o autorizada la licencia, con plazo automático.
    if ($status !== 'pending') {
        $due = doc_auto_due($type + ['created_at' => date('Y-m-d H:i:s'), 'end_date' => $to]);
        if ($due) {
            update('leave_requests', $id, ['doc_due_date' => $due]);
        }
    }
    log_event($id, $onBehalf ? 'created_by_hr' : ($status === 'registered' ? 'registered' : 'created'), $warnings ? implode(' ', $warnings) : null);
    if ($autoApprove) {
        log_event($id, 'approved', 'Aprobada al cargarla RRHH.');
    }
    db()->commit();
    forget_input();

    foreach ($warnings as $w) {
        flash('warning', $w);
    }

    if ($autoApprove) {
        defer(fn () => on_request_decided($id));
        flash('success', 'Licencia cargada y aprobada. Se notificó al colaborador/a.');
    } else {
        defer(fn () => on_request_created($id));
        flash('success', $status === 'pending'
            ? 'Solicitud enviada. Le avisamos a quien tiene que autorizarla y te notificamos cuando la resuelva.'
            : 'Licencia informada. RRHH y tu responsable ya fueron notificados.');
    }
    redirect('/solicitudes/' . $id);
}

/**
 * Detalle de una solicitud.
 */
function request_show(int $id): void
{
    require_login();
    $r = load_request($id);
    if (!$r || !can_view_request($r)) {
        abort(404, 'La solicitud no existe o no tenés acceso.');
    }
    $owner = row('SELECT * FROM users WHERE id = ?', [$r['user_id']]);
    $year = (int) substr($r['start_date'], 0, 4);
    $overlaps = [];
    if (can_decide($r) || is_hr()) {
        // Otras ausencias del mismo equipo o área en esas fechas.
        $overlaps = rows(
            REQ_SELECT . " WHERE r.id <> ? AND r.user_id <> ? AND r.status IN ('approved','registered','pending')
            AND r.start_date <= ? AND r.end_date >= ? AND (u.manager_id = ? OR u.department = ?) ORDER BY r.start_date",
            [$r['id'], $r['user_id'], $r['end_date'], $r['start_date'], $owner['manager_id'], $owner['department']]
        );
    }
    view('requests/show', [
        'title' => 'Solicitud #' . $r['id'],
        'r' => $r,
        'owner' => $owner,
        'docs' => rows('SELECT d.*, u.name AS uploader FROM documents d JOIN users u ON u.id = d.user_id WHERE d.request_id = ? ORDER BY d.created_at', [$id]),
        'events' => rows('SELECT e.*, u.name AS user_name FROM request_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.request_id = ? ORDER BY e.created_at, e.id', [$id]),
        'balances' => (can_decide($r) || is_hr()) ? balances($owner, $year) : [],
        'vacation_left' => (can_decide($r) && !empty($r['vacation_optional'])) ? vacation_left($owner, $year, $r['id']) : null,
        'overlaps' => $overlaps,
        'can_decide' => can_decide($r),
        'can_cancel' => can_cancel($r),
        'can_upload' => can_upload($r),
    ]);
}

/**
 * Indica si el usuario actual puede cancelar la solicitud.
 */
function can_cancel(array $r): bool
{
    if (in_array($r['status'], ['cancelled', 'rejected'], true)) {
        return false;
    }
    if (is_hr()) {
        return true;
    }
    if ((int) $r['user_id'] !== uid()) {
        return false;
    }

    return $r['status'] === 'pending' || $r['start_date'] > date('Y-m-d');
}

/**
 * Indica si el usuario actual puede adjuntar documentación.
 */
function can_upload(array $r): bool
{
    if (!accepts_documents($r)) {
        return false;
    }
    if ($r['requires_approval'] && $r['status'] !== 'approved') {
        return false;
    }
    if (in_array($r['status'], ['cancelled', 'rejected'], true)) {
        return false;
    }

    return (int) $r['user_id'] === uid() || is_hr();
}

/**
 * Autoriza o rechaza una solicitud.
 */
function request_decide(int $id): void
{
    $me = require_login();
    $r = load_request($id);
    if (!$r || !can_decide($r)) {
        abort(403, 'No podés decidir sobre esta solicitud.');
    }
    $decision = post('decision');
    $comment = mb_substr(post('comment'), 0, 2000);
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        redirect('/solicitudes/' . $id);
    }
    if ($decision === 'rejected' && $comment === '') {
        flash('error', 'Contale al colaborador/a el motivo del rechazo.');
        redirect('/solicitudes/' . $id);
    }
    // Tipos con descuento opcional (día personal): quien autoriza decide si impacta en vacaciones.
    $deduct = $decision === 'approved' && !empty($r['vacation_optional']) && post('deduct_vacation') === '1';
    if ($deduct) {
        $owner = row('SELECT * FROM users WHERE id = ?', [$r['user_id']]);
        $left = vacation_left($owner, (int) substr($r['start_date'], 0, 4), $id);
        if ($left < (float) $r['days']) {
            flash('error', "No se puede descontar de vacaciones: a {$r['user_name']} le quedan " . fdays($left) . ' disponibles.');
            redirect('/solicitudes/' . $id);
        }
    }
    // La condición sobre el estado evita decisiones duplicadas concurrentes.
    $n = q(
        "UPDATE leave_requests SET status = ?, decided_by = ?, decided_at = NOW(), decision_comment = ?, deduct_vacation = ? WHERE id = ? AND status = 'pending'",
        [$decision, $me['id'], $comment ?: null, $deduct ? 1 : 0, $id]
    )->rowCount();
    if (!$n) {
        flash('warning', 'Esta solicitud ya había sido resuelta.');
        redirect('/solicitudes/' . $id);
    }
    q('UPDATE notifications SET read_at = NOW() WHERE request_id = ? AND user_id = ? AND read_at IS NULL', [$id, $me['id']]);
    log_event($id, $decision, $comment ?: null);
    if ($decision === 'approved' && accepts_documents($r)) {
        $fresh = load_request($id);
        apply_doc_due($id, $fresh, post('doc_due_mode'), post('doc_due_date')) ?? apply_doc_due($id, $fresh, 'auto', '');
    }
    if ($deduct) {
        log_event($id, 'vacation_deducted', fdays($r['days']) . ' descontados de las vacaciones.');
    }
    defer(fn () => on_request_decided($id));
    flash('success', $decision === 'approved'
        ? 'Solicitud aprobada' . ($deduct ? ' y descontada de las vacaciones' : '') . '. Avisamos al colaborador/a.'
        : 'Solicitud rechazada. Avisamos al colaborador/a.');
    redirect(post('back') === 'aprobaciones' ? '/aprobaciones' : '/solicitudes/' . $id);
}

/**
 * Cancela una solicitud.
 */
function request_cancel(int $id): void
{
    require_login();
    $r = load_request($id);
    if (!$r || !can_view_request($r) || !can_cancel($r)) {
        abort(403, 'No podés cancelar esta solicitud.');
    }
    $prev = $r['status'];
    q("UPDATE leave_requests SET status = 'cancelled' WHERE id = ?", [$id]);
    log_event($id, 'cancelled', mb_substr(post('comment'), 0, 1000) ?: null);
    defer(fn () => on_request_cancelled($id, $prev));
    flash('success', 'Solicitud cancelada.');
    redirect('/solicitudes/' . $id);
}

/**
 * Adjunta documentación a una solicitud existente.
 */
function request_upload(int $id): void
{
    $me = require_login();
    $r = load_request($id);
    if (!$r || !can_view_request($r) || !can_upload($r)) {
        abort(403);
    }
    [$saved, $errors] = store_uploads($id, (int) $me['id'], $_FILES['documents'] ?? []);
    foreach ($errors as $err) {
        flash('error', $err);
    }
    if ($saved) {
        update('leave_requests', $id, ['doc_status' => 'uploaded']);
        log_event($id, 'doc_uploaded', $saved . ' archivo(s)');
        defer(fn () => on_document_uploaded($id, $saved));
        flash('success', 'Documentación subida. RRHH fue notificado.');
    } elseif (!$errors) {
        flash('error', 'Elegí al menos un archivo.');
    }
    redirect('/solicitudes/' . $id);
}

/**
 * Verificación u observación de la documentación por RRHH.
 */
function request_review_doc(int $id): void
{
    require_role('hr', 'admin');
    $r = load_request($id);
    $result = post('result');
    if (!$r || !in_array($result, ['verified', 'observed'], true)) {
        abort(400);
    }
    $comment = mb_substr(post('comment'), 0, 1000);
    if ($result === 'observed' && $comment === '') {
        flash('error', 'Indicá qué hay que corregir en la documentación.');
        redirect('/solicitudes/' . $id);
    }
    update('leave_requests', $id, ['doc_status' => $result]);
    if ($result === 'observed') {
        update('leave_requests', $id, [
            'doc_due_date' => add_working_days(date('Y-m-d'), 2),
            'doc_reminded_at' => null,
            'doc_overdue_at' => null,
        ]);
    }
    log_event($id, $result === 'verified' ? 'doc_verified' : 'doc_observed', $comment ?: null);
    defer(fn () => on_document_reviewed($id, $result, $comment));
    flash('success', $result === 'verified' ? 'Documentación verificada.' : 'Documentación observada. Avisamos al colaborador/a.');
    redirect('/solicitudes/' . $id);
}

/**
 * Define el plazo de entrega de la documentación: automático según el tipo o una fecha elegida.
 *
 * @return string|null Fecha aplicada, o null si la fecha elegida no es válida.
 */
function apply_doc_due(int $id, array $r, string $mode, string $date): ?string
{
    $custom = $mode === 'custom';
    if ($custom && (!valid_date($date) || $date < doc_due_min($r))) {
        return null;
    }
    $due = $custom ? $date : doc_auto_due($r);
    update('leave_requests', $id, ['doc_due_date' => $due, 'doc_due_custom' => $custom ? 1 : 0, 'doc_reminded_at' => null, 'doc_overdue_at' => null]);
    log_event($id, 'doc_due_set', fdate($due) . ($custom ? ' (fecha elegida)' : ' (automático)'));

    return $due;
}

/**
 * Cambia el plazo de entrega de la documentación de una solicitud.
 */
function request_set_due(int $id): void
{
    require_login();
    $r = load_request($id);
    if (!$r || !can_set_doc_due($r)) {
        abort(403, 'No podés modificar el plazo de esta solicitud.');
    }
    $due = apply_doc_due($id, $r, post('doc_due_mode'), post('doc_due_date'));
    if (!$due) {
        flash('error', 'Elegí una fecha válida, a partir del ' . fdate(doc_due_min($r)) . '.');
        redirect('/solicitudes/' . $id);
    }
    defer(fn () => on_doc_due_changed($id));
    flash('success', 'Plazo de entrega actualizado al ' . fdate($due) . '. Avisamos al colaborador/a.');
    redirect('/solicitudes/' . $id);
}

/**
 * Sirve un adjunto a usuarios autorizados.
 */
function file_download(int $id): void
{
    require_login();
    $d = row('SELECT * FROM documents WHERE id = ?', [$id]);
    $r = $d ? load_request((int) $d['request_id']) : null;
    if (!$r || !can_view_request($r)) {
        abort(404);
    }
    if ($d['drive_id']) {
        $content = drive_download($d['drive_id']);
        if ($content === null) {
            abort(404, 'No se pudo obtener el archivo desde Google Drive.');
        }
    } else {
        $path = STORAGE . '/uploads/' . $d['stored_name'];
        if (!is_file($path)) {
            abort(404, 'El archivo ya no está disponible.');
        }
        $content = (string) file_get_contents($path);
    }
    $fileName = $d['drive_name'] ?: $d['original_name'];
    $inline = in_array($d['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true) && !isset($_GET['descargar']);
    header('Content-Type: ' . $d['mime']);
    header('Content-Length: ' . strlen($content));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($fileName));
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf");
    echo $content;
    exit;
}

/**
 * Bandeja de solicitudes por autorizar.
 */
function approvals(): void
{
    $u = require_login();
    if (!is_approver()) {
        abort(403, 'Esta sección es para responsables y RRHH.');
    }
    [$w, $p] = pending_scope($u);
    view('requests/approvals', [
        'title' => 'Por autorizar',
        'pending' => rows(REQ_SELECT . " WHERE $w ORDER BY r.start_date", $p),
        'decided' => rows(REQ_SELECT . ' WHERE r.decided_by = ? ORDER BY r.decided_at DESC LIMIT 15', [$u['id']]),
    ]);
}

/**
 * Listado de licencias del equipo o de toda la empresa, con filtros y exportación CSV.
 */
function team_requests(): void
{
    $u = require_login();
    if (!is_approver()) {
        abort(403, 'Esta sección es para responsables y RRHH.');
    }
    [$w, $p] = team_scope($u);
    $f = [
        'q' => trim((string) ($_GET['q'] ?? '')),
        'tipo' => (int) ($_GET['tipo'] ?? 0),
        'estado' => (string) ($_GET['estado'] ?? ''),
        'desde' => (string) ($_GET['desde'] ?? date('Y-01-01')),
        'hasta' => (string) ($_GET['hasta'] ?? date('Y-12-31')),
        'doc' => (string) ($_GET['doc'] ?? ''),
    ];
    $sql = REQ_SELECT . " WHERE $w";
    if ($f['q'] !== '') {
        $sql .= ' AND (u.name LIKE ? OR u.department LIKE ?)';
        array_push($p, "%{$f['q']}%", "%{$f['q']}%");
    }
    if ($f['tipo']) {
        $sql .= ' AND r.type_id = ?';
        $p[] = $f['tipo'];
    }
    if (isset(STATUS_LABELS[$f['estado']])) {
        $sql .= ' AND r.status = ?';
        $p[] = $f['estado'];
    }
    if (isset(DOC_LABELS[$f['doc']])) {
        $sql .= ' AND r.doc_status = ?';
        $p[] = $f['doc'];
    }
    if (valid_date($f['desde'])) {
        $sql .= ' AND r.end_date >= ?';
        $p[] = $f['desde'];
    }
    if (valid_date($f['hasta'])) {
        $sql .= ' AND r.start_date <= ?';
        $p[] = $f['hasta'];
    }
    $list = rows($sql . ' ORDER BY r.start_date DESC LIMIT 1000', $p);

    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="licencias-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Colaborador/a', 'Área', 'Tipo', 'Desde', 'Hasta', 'Horario', 'Días', 'Horas', 'Estado', 'Documentación', 'Decidió', 'Fecha decisión', 'Motivo'], ';');
        foreach ($list as $r) {
            $dec = $r['decided_by'] ? val('SELECT name FROM users WHERE id = ?', [$r['decided_by']]) : '';
            fputcsv($out, [$r['id'], $r['user_name'], $r['department'], $r['type_name'], $r['start_date'], $r['end_date'], is_hourly($r) ? substr($r['start_time'], 0, 5) . '-' . substr($r['end_time'], 0, 5) : '',
                str_replace('.', ',', (string) (float) $r['days']), is_hourly($r) ? str_replace('.', ',', (string) (float) $r['hours']) : '', STATUS_LABELS[$r['status']], DOC_LABELS[$r['doc_status']], $dec, $r['decided_at'], $r['reason']], ';');
        }
        exit;
    }

    view('requests/team', [
        'title' => is_hr() ? 'Todas las licencias' : 'Mi equipo',
        'list' => $list,
        'f' => $f,
        'types' => leave_types(false),
    ]);
}

/**
 * API JSON: cálculo de días, validaciones y fecha de regreso para el formulario.
 */
function api_days(): void
{
    $me = require_login();
    header('Content-Type: application/json; charset=utf-8');
    $type = leave_type((int) ($_GET['type'] ?? 0));
    $from = (string) ($_GET['from'] ?? '');
    $to = (string) ($_GET['to'] ?? '') ?: $from;
    $owner = $me;
    if (is_hr() && (int) ($_GET['user'] ?? 0)) {
        $owner = row('SELECT * FROM users WHERE id = ?', [(int) $_GET['user']]) ?: $me;
    }
    if (!$type || !valid_date($from) || !valid_date($to)) {
        echo json_encode(['days' => 0, 'errors' => [], 'warnings' => []]);
        exit;
    }
    if ($type['by_hours']) {
        [$errors, $warnings, $hours] = validate_hourly_request($owner, $type, $from, (string) ($_GET['start_time'] ?? ''), (string) ($_GET['end_time'] ?? ''));
        echo json_encode([
            'days' => 0,
            'hours' => $hours,
            'label' => $hours > 0 ? fhours($hours) : '—',
            'return' => null,
            'holidays' => [],
            'errors' => $errors,
            'warnings' => $warnings,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    [$errors, $warnings, $days] = validate_request($owner, $type, $from, $to, null, is_hr());
    $hol = array_map(fn ($h) => fdate($h['date'], false) . ' ' . $h['name'], rows('SELECT * FROM holidays WHERE date BETWEEN ? AND ? ORDER BY date', [$from, $to]));
    echo json_encode([
        'days' => $days,
        'label' => fdays_kind($days, $type['counting']),
        'return' => $to >= $from ? flongdate(next_working_day($to)) : null,
        'holidays' => $type['counting'] === 'habiles' ? $hol : [],
        'errors' => $errors,
        'warnings' => $warnings,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Próximo día hábil posterior a una fecha.
 */
function next_working_day(string $date): string
{
    $d = new DateTimeImmutable($date);
    for ($i = 0; $i < 20; $i++) {
        $d = $d->modify('+1 day');
        if ((int) $d->format('N') < 6 && !val('SELECT 1 FROM holidays WHERE date = ?', [$d->format('Y-m-d')])) {
            break;
        }
    }

    return $d->format('Y-m-d');
}
