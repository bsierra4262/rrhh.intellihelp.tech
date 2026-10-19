<?php
declare(strict_types=1);

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

function request_submit(): void
{
    $me = require_login();
    remember_input();
    $type = leave_type((int) post('type_id'));
    if (!$type || !$type['active']) {
        flash('error', 'Elegí un tipo de licencia.');
        redirect('/solicitudes/nueva');
    }

    // RRHH puede cargar en nombre de otra persona
    $onBehalf = is_hr() && (int) post('user_id') && (int) post('user_id') !== (int) $me['id'];
    $owner = $onBehalf ? row('SELECT * FROM users WHERE id = ? AND active = 1', [(int) post('user_id')]) : $me;
    if (!$owner) {
        flash('error', 'Colaborador/a no válido.');
        redirect('/solicitudes/nueva');
    }

    $from = post('start_date');
    $to = post('end_date') ?: $from;
    [$errors, $warnings, $days] = validate_request($owner, $type, $from, $to, null, is_hr());

    if ($type['requires_document'] && !has_uploads('documents')) {
        $errors[] = 'Para ' . mb_strtolower($type['name']) . ' es obligatorio adjuntar: ' . mb_strtolower($type['document_label'] ?: 'documentación') . '.';
    }
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
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
        'days' => $days,
        'reason' => mb_substr(post('reason'), 0, 2000) ?: null,
        'status' => $status,
        'approver_id' => $type['requires_approval'] ? resolve_approver($owner) : null,
        'decided_by' => $autoApprove ? $me['id'] : null,
        'decided_at' => $autoApprove ? date('Y-m-d H:i:s') : null,
    ]);
    [$saved, $upErrors, $paths] = store_uploads($id, (int) $me['id'], $_FILES['documents'] ?? []);
    if ($type['requires_document'] && $saved === 0) {
        db()->rollBack();
        foreach ($paths as $p) @unlink($p);
        foreach ($upErrors ?: ['No se pudo guardar la documentación.'] as $err) flash('error', $err);
        redirect('/solicitudes/nueva?tipo=' . $type['code']);
    }
    if ($saved) update('leave_requests', $id, ['doc_status' => 'uploaded']);
    log_event($id, $onBehalf ? 'created_by_hr' : ($status === 'registered' ? 'registered' : 'created'), $warnings ? implode(' ', $warnings) : null);
    if ($autoApprove) log_event($id, 'approved', 'Aprobada al cargarla RRHH.');
    if ($saved) log_event($id, 'doc_uploaded', $saved . ' archivo(s)');
    db()->commit();
    forget_input();

    foreach ($upErrors as $err) flash('error', $err);
    foreach ($warnings as $w) flash('warning', $w);

    if ($autoApprove) {
        defer(fn() => on_request_decided($id));
        flash('success', 'Licencia cargada y aprobada. Se notificó al colaborador/a.');
    } else {
        defer(fn() => on_request_created($id));
        flash('success', $status === 'pending'
            ? 'Solicitud enviada. Le avisamos a quien tiene que autorizarla y te notificamos cuando la resuelva.'
            : 'Licencia informada. RRHH y tu responsable ya fueron notificados.');
    }
    redirect('/solicitudes/' . $id);
}

function request_show(int $id): void
{
    require_login();
    $r = load_request($id);
    if (!$r || !can_view_request($r)) abort(404, 'La solicitud no existe o no tenés acceso.');
    $owner = row('SELECT * FROM users WHERE id = ?', [$r['user_id']]);
    $year = (int) substr($r['start_date'], 0, 4);
    $overlaps = [];
    if (can_decide($r) || is_hr()) {
        // Quién más del equipo/área falta en esas fechas
        $overlaps = rows(REQ_SELECT . " WHERE r.id <> ? AND r.user_id <> ? AND r.status IN ('approved','registered','pending')
            AND r.start_date <= ? AND r.end_date >= ? AND (u.manager_id = ? OR u.department = ?) ORDER BY r.start_date",
            [$r['id'], $r['user_id'], $r['end_date'], $r['start_date'], $owner['manager_id'], $owner['department']]);
    }
    view('requests/show', [
        'title' => 'Solicitud #' . $r['id'],
        'r' => $r,
        'owner' => $owner,
        'docs' => rows('SELECT d.*, u.name AS uploader FROM documents d JOIN users u ON u.id = d.user_id WHERE d.request_id = ? ORDER BY d.created_at', [$id]),
        'events' => rows('SELECT e.*, u.name AS user_name FROM request_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.request_id = ? ORDER BY e.created_at, e.id', [$id]),
        'balances' => (can_decide($r) || is_hr()) ? balances($owner, $year) : [],
        'overlaps' => $overlaps,
        'can_decide' => can_decide($r),
        'can_cancel' => can_cancel($r),
        'can_upload' => can_upload($r),
    ]);
}

function can_cancel(array $r): bool
{
    if (in_array($r['status'], ['cancelled', 'rejected'], true)) return false;
    if (is_hr()) return true;
    if ((int) $r['user_id'] !== uid()) return false;
    return $r['status'] === 'pending' || $r['start_date'] > date('Y-m-d');
}

function can_upload(array $r): bool
{
    if (in_array($r['status'], ['cancelled', 'rejected'], true)) return false;
    return (int) $r['user_id'] === uid() || is_hr();
}

function request_decide(int $id): void
{
    $me = require_login();
    $r = load_request($id);
    if (!$r || !can_decide($r)) abort(403, 'No podés decidir sobre esta solicitud.');
    $decision = post('decision');
    $comment = mb_substr(post('comment'), 0, 2000);
    if (!in_array($decision, ['approved', 'rejected'], true)) redirect('/solicitudes/' . $id);
    if ($decision === 'rejected' && $comment === '') {
        flash('error', 'Contale al colaborador/a el motivo del rechazo.');
        redirect('/solicitudes/' . $id);
    }
    // Evita doble decisión concurrente
    $n = q("UPDATE leave_requests SET status = ?, decided_by = ?, decided_at = NOW(), decision_comment = ? WHERE id = ? AND status = 'pending'",
        [$decision, $me['id'], $comment ?: null, $id])->rowCount();
    if (!$n) {
        flash('warning', 'Esta solicitud ya había sido resuelta.');
        redirect('/solicitudes/' . $id);
    }
    q("UPDATE notifications SET read_at = NOW() WHERE request_id = ? AND user_id = ? AND read_at IS NULL", [$id, $me['id']]);
    log_event($id, $decision, $comment ?: null);
    defer(fn() => on_request_decided($id));
    flash('success', $decision === 'approved' ? 'Solicitud aprobada. Avisamos al colaborador/a.' : 'Solicitud rechazada. Avisamos al colaborador/a.');
    redirect(post('back') === 'aprobaciones' ? '/aprobaciones' : '/solicitudes/' . $id);
}

function request_cancel(int $id): void
{
    require_login();
    $r = load_request($id);
    if (!$r || !can_view_request($r) || !can_cancel($r)) abort(403, 'No podés cancelar esta solicitud.');
    $prev = $r['status'];
    q("UPDATE leave_requests SET status = 'cancelled' WHERE id = ?", [$id]);
    log_event($id, 'cancelled', mb_substr(post('comment'), 0, 1000) ?: null);
    defer(fn() => on_request_cancelled($id, $prev));
    flash('success', 'Solicitud cancelada.');
    redirect('/solicitudes/' . $id);
}

function request_upload(int $id): void
{
    $me = require_login();
    $r = load_request($id);
    if (!$r || !can_view_request($r) || !can_upload($r)) abort(403);
    [$saved, $errors] = store_uploads($id, (int) $me['id'], $_FILES['documents'] ?? []);
    foreach ($errors as $err) flash('error', $err);
    if ($saved) {
        update('leave_requests', $id, ['doc_status' => 'uploaded']);
        log_event($id, 'doc_uploaded', $saved . ' archivo(s)');
        defer(fn() => on_document_uploaded($id, $saved));
        flash('success', 'Documentación subida. RRHH fue notificado.');
    } elseif (!$errors) {
        flash('error', 'Elegí al menos un archivo.');
    }
    redirect('/solicitudes/' . $id);
}

function request_review_doc(int $id): void
{
    require_role('hr', 'admin');
    $r = load_request($id);
    $result = post('result');
    if (!$r || !in_array($result, ['verified', 'observed'], true)) abort(400);
    $comment = mb_substr(post('comment'), 0, 1000);
    if ($result === 'observed' && $comment === '') {
        flash('error', 'Indicá qué hay que corregir en la documentación.');
        redirect('/solicitudes/' . $id);
    }
    update('leave_requests', $id, ['doc_status' => $result]);
    log_event($id, $result === 'verified' ? 'doc_verified' : 'doc_observed', $comment ?: null);
    defer(fn() => on_document_reviewed($id, $result, $comment));
    flash('success', $result === 'verified' ? 'Documentación verificada.' : 'Documentación observada. Avisamos al colaborador/a.');
    redirect('/solicitudes/' . $id);
}

function file_download(int $id): void
{
    require_login();
    $d = row('SELECT * FROM documents WHERE id = ?', [$id]);
    $r = $d ? load_request((int) $d['request_id']) : null;
    if (!$r || !can_view_request($r)) abort(404);
    $path = STORAGE . '/uploads/' . $d['stored_name'];
    if (!is_file($path)) abort(404, 'El archivo ya no está disponible.');
    $inline = in_array($d['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) && !isset($_GET['descargar']);
    header('Content-Type: ' . $d['mime']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($d['original_name']));
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf");
    readfile($path);
    exit;
}

function approvals(): void
{
    $u = require_login();
    if (!is_approver()) abort(403, 'Esta sección es para responsables y RRHH.');
    [$w, $p] = pending_scope($u);
    view('requests/approvals', [
        'title' => 'Por autorizar',
        'pending' => rows(REQ_SELECT . " WHERE $w ORDER BY r.start_date", $p),
        'decided' => rows(REQ_SELECT . ' WHERE r.decided_by = ? ORDER BY r.decided_at DESC LIMIT 15', [$u['id']]),
    ]);
}

function team_requests(): void
{
    $u = require_login();
    if (!is_approver()) abort(403, 'Esta sección es para responsables y RRHH.');
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
        fputcsv($out, ['ID', 'Colaborador/a', 'Área', 'Tipo', 'Desde', 'Hasta', 'Días', 'Estado', 'Documentación', 'Decidió', 'Fecha decisión', 'Motivo'], ';');
        foreach ($list as $r) {
            $dec = $r['decided_by'] ? val('SELECT name FROM users WHERE id = ?', [$r['decided_by']]) : '';
            fputcsv($out, [$r['id'], $r['user_name'], $r['department'], $r['type_name'], $r['start_date'], $r['end_date'],
                str_replace('.', ',', (string) (float) $r['days']), STATUS_LABELS[$r['status']], DOC_LABELS[$r['doc_status']], $dec, $r['decided_at'], $r['reason']], ';');
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
    [$errors, $warnings, $days] = validate_request($owner, $type, $from, $to, null, is_hr());
    $hol = array_map(fn($h) => fdate($h['date'], false) . ' ' . $h['name'], rows('SELECT * FROM holidays WHERE date BETWEEN ? AND ? ORDER BY date', [$from, $to]));
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

function next_working_day(string $date): string
{
    $d = new DateTimeImmutable($date);
    for ($i = 0; $i < 20; $i++) {
        $d = $d->modify('+1 day');
        if ((int) $d->format('N') < 6 && !val('SELECT 1 FROM holidays WHERE date = ?', [$d->format('Y-m-d')])) break;
    }
    return $d->format('Y-m-d');
}
