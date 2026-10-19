<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Reglas de negocio de licencias: conteo de días, saldos según la LCT, validaciones,
 * permisos sobre solicitudes y gestión de adjuntos.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Tipos de licencia ordenados para mostrar.
 */
function leave_types(bool $onlyActive = true): array
{
    return rows('SELECT * FROM leave_types' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort, name');
}

/**
 * Tipo de licencia por ID.
 */
function leave_type(int $id): ?array
{
    return row('SELECT * FROM leave_types WHERE id = ?', [$id]);
}

/**
 * Feriados de un rango, indexados por fecha.
 *
 * @return array<string, true>
 */
function holidays_between(string $from, string $to): array
{
    $set = [];
    foreach (rows('SELECT date FROM holidays WHERE date BETWEEN ? AND ?', [$from, $to]) as $r) {
        $set[$r['date']] = true;
    }

    return $set;
}

/**
 * Cuenta los días de un rango (inclusive).
 *
 * @param string $counting "corridos" o "habiles" (excluye fines de semana y feriados).
 */
function count_days(string $from, string $to, string $counting): int
{
    $a = new DateTimeImmutable($from);
    $b = new DateTimeImmutable($to);
    if ($b < $a) {
        return 0;
    }
    if ($counting === 'corridos') {
        return $a->diff($b)->days + 1;
    }
    $hol = holidays_between($from, $to);
    $n = 0;
    for ($d = $a; $d <= $b; $d = $d->modify('+1 day')) {
        if ((int) $d->format('N') < 6 && !isset($hol[$d->format('Y-m-d')])) {
            $n++;
        }
    }

    return $n;
}

/**
 * Días de vacaciones del año según antigüedad al 31/12 (LCT art. 150 y 153).
 *
 * Menos de 5 años: 14 días; 5 a 10: 21; 10 a 20: 28; más de 20: 35. Con menos de seis meses
 * trabajados en el año corresponde un día cada veinte trabajados. Admite un valor manual por persona.
 */
function vacation_entitlement(array $user, int $year): int
{
    if ($user['vacation_days_override'] !== null && $user['vacation_days_override'] !== '') {
        return (int) $user['vacation_days_override'];
    }
    if (empty($user['hire_date'])) {
        return 14;
    }
    $hire = new DateTimeImmutable($user['hire_date']);
    $cut = new DateTimeImmutable("$year-12-31");
    if ($hire > $cut) {
        return 0;
    }
    // Menos de seis meses trabajados en el año: un día cada veinte trabajados (LCT art. 153).
    $start = max($hire, new DateTimeImmutable("$year-01-01"));
    $worked = $start->diff($cut)->days + 1;
    if ((int) $hire->format('Y') === $year && $worked < 183) {
        return intdiv($worked, 20);
    }
    $years = $hire->diff($cut)->y;

    return match (true) {
        $years < 5 => 14,
        $years < 10 => 21,
        $years < 20 => 28,
        default => 35,
    };
}

/**
 * Días consumidos (aprobados, informados o pendientes) de un tipo en el año.
 */
function days_used(int $userId, int $typeId, int $year, ?int $excludeId = null): float
{
    $sql = "SELECT COALESCE(SUM(days),0) FROM leave_requests
            WHERE user_id = ? AND type_id = ? AND YEAR(start_date) = ?
              AND status IN ('pending','approved','registered')";
    $params = [$userId, $typeId, $year];
    if ($excludeId) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    return (float) val($sql, $params);
}

/**
 * Días de vacaciones del año en los estados indicados.
 */
function vacation_used(int $userId, int $year, string $statuses = "'approved'"): float
{
    return (float) val("SELECT COALESCE(SUM(r.days),0) FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
        WHERE r.user_id = ? AND t.uses_vacation_balance = 1 AND YEAR(r.start_date) = ? AND r.status IN ($statuses)", [$userId, $year]);
}

/**
 * Saldos del colaborador para el año: vacaciones y tipos con tope anual.
 *
 * @return array<int, array{code: string, name: string, color: string, total: int, used: float, pending: float, left: float}>
 */
function balances(array $user, int $year): array
{
    $out = [];
    $entitled = vacation_entitlement($user, $year);
    $approved = vacation_used((int) $user['id'], $year);
    $pending = vacation_used((int) $user['id'], $year, "'pending'");
    $out[] = [
        'code' => 'vacaciones', 'name' => 'Vacaciones', 'color' => '#663399',
        'total' => $entitled, 'used' => $approved, 'pending' => $pending,
        'left' => max(0, $entitled - $approved - $pending),
    ];
    foreach (rows('SELECT * FROM leave_types WHERE active = 1 AND max_days_per_year IS NOT NULL AND uses_vacation_balance = 0 ORDER BY sort') as $t) {
        $used = (float) val("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id = ? AND type_id = ? AND YEAR(start_date) = ? AND status IN ('approved','registered')", [$user['id'], $t['id'], $year]);
        $pend = (float) val("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id = ? AND type_id = ? AND YEAR(start_date) = ? AND status = 'pending'", [$user['id'], $t['id'], $year]);
        $out[] = [
            'code' => $t['code'], 'name' => $t['name'], 'color' => $t['color'],
            'total' => (int) $t['max_days_per_year'], 'used' => $used, 'pending' => $pend,
            'left' => max(0, (int) $t['max_days_per_year'] - $used - $pend),
        ];
    }

    return $out;
}

/**
 * Determina quién autoriza: el responsable directo, el aprobador por defecto o,
 * si no hay ninguno, toda la bandeja de RRHH (null).
 */
function resolve_approver(array $user): ?int
{
    if (!empty($user['manager_id']) && (int) $user['manager_id'] !== (int) $user['id']) {
        $m = row('SELECT id FROM users WHERE id = ? AND active = 1', [$user['manager_id']]);
        if ($m) {
            return (int) $m['id'];
        }
    }
    // Aprobador por defecto definido en Configuración.
    $def = (int) setting('default_approver_id', '0');
    if ($def && $def !== (int) $user['id'] && val('SELECT id FROM users WHERE id = ? AND active = 1', [$def])) {
        return $def;
    }

    return null;
}

/**
 * Usuarios activos con rol de RRHH o administrador.
 */
function hr_users(): array
{
    return rows("SELECT * FROM users WHERE role IN ('hr','admin') AND active = 1");
}

/**
 * Valida una solicitud de licencia.
 *
 * Con $byHr las excepciones de saldo y topes se informan como advertencias en lugar de errores.
 *
 * @return array{0: string[], 1: string[], 2: int} Errores, advertencias y cantidad de días.
 */
function validate_request(array $user, array $type, string $from, string $to, ?int $excludeId = null, bool $byHr = false): array
{
    $errors = [];
    $warnings = [];
    if (!valid_date($from) || !valid_date($to)) {
        return [['Las fechas no son válidas.'], [], 0];
    }
    if ($to < $from) {
        return [['La fecha de fin no puede ser anterior a la de inicio.'], [], 0];
    }
    if (substr($from, 0, 4) !== substr($to, 0, 4)) {
        $errors[] = 'La solicitud no puede abarcar dos años distintos. Cargá una solicitud por año.';
    }
    $days = count_days($from, $to, $type['counting']);
    if ($days <= 0) {
        $errors[] = 'El rango elegido no tiene días hábiles.';
    }

    $overlap = row("SELECT r.id, t.name FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
        WHERE r.user_id = ? AND r.status IN ('pending','approved','registered')
          AND r.start_date <= ? AND r.end_date >= ?" . ($excludeId ? ' AND r.id <> ' . (int) $excludeId : ''), [$user['id'], $to, $from]);
    if ($overlap) {
        $errors[] = "Ya tenés una solicitud de {$overlap['name']} que se superpone con esas fechas (#{$overlap['id']}).";
    }

    if ($type['max_days_per_request'] && $days > (int) $type['max_days_per_request']) {
        $errors[] = "{$type['name']} permite hasta " . fdays($type['max_days_per_request']) . ' por solicitud.';
    }
    $year = (int) substr($from, 0, 4);
    if ($type['uses_vacation_balance']) {
        $ent = vacation_entitlement($user, $year);
        $used = vacation_used((int) $user['id'], $year, "'approved','pending'");
        if ($excludeId) {
            $used -= (float) val('SELECT days FROM leave_requests WHERE id = ?', [$excludeId]);
        }
        if ($used + $days > $ent) {
            $msg = 'Superás tu saldo de vacaciones ' . $year . ': te quedan ' . fdays(max(0, $ent - $used)) . '.';
            $byHr ? $warnings[] = $msg : $errors[] = $msg;
        }
    } elseif ($type['max_days_per_year']) {
        $used = days_used((int) $user['id'], (int) $type['id'], $year, $excludeId);
        if ($used + $days > (int) $type['max_days_per_year']) {
            $msg = "{$type['name']}: el máximo anual es " . fdays($type['max_days_per_year']) . ' y ya usaste ' . fdays($used) . '.';
            $byHr ? $warnings[] = $msg : $errors[] = $msg;
        }
    }
    if ($type['min_notice_days'] > 0 && $type['requires_approval']) {
        $notice = (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($from));
        $ahead = $notice->invert ? -$notice->days : $notice->days;
        if ($ahead < (int) $type['min_notice_days']) {
            $warnings[] = "Se recomienda pedir {$type['name']} con al menos " . fdays($type['min_notice_days']) . ' de anticipación.';
        }
    }
    if (!$type['requires_approval'] && $from > date('Y-m-d', strtotime('+30 days')) && $type['code'] === 'enfermedad') {
        $errors[] = 'Una licencia por enfermedad no puede informarse con tanta anticipación.';
    }

    return [$errors, $warnings, $days];
}

/**
 * Solicitud con datos de tipo, colaborador, aprobador y decisor.
 */
function load_request(int $id): ?array
{
    return row('SELECT r.*, t.name AS type_name, t.code AS type_code, t.color, t.requires_approval, t.requires_document, t.document_label, t.counting,
            u.name AS user_name, u.email AS user_email, u.department, u.position, u.manager_id,
            a.name AS approver_name, d.name AS decider_name
        FROM leave_requests r
        JOIN leave_types t ON t.id = r.type_id
        JOIN users u ON u.id = r.user_id
        LEFT JOIN users a ON a.id = r.approver_id
        LEFT JOIN users d ON d.id = r.decided_by
        WHERE r.id = ?', [$id]);
}

/**
 * Indica si el usuario actual puede ver la solicitud.
 */
function can_view_request(array $r): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    if (is_hr()) {
        return true;
    }
    if ((int) $r['user_id'] === (int) $u['id']) {
        return true;
    }
    if ((int) $r['approver_id'] === (int) $u['id']) {
        return true;
    }

    return (int) $r['manager_id'] === (int) $u['id'];
}

/**
 * Indica si el usuario actual puede autorizar o rechazar la solicitud.
 * Nadie decide sobre sus propias solicitudes, salvo un administrador.
 */
function can_decide(array $r): bool
{
    $u = current_user();
    if (!$u || $r['status'] !== 'pending') {
        return false;
    }
    // Nadie decide sobre sus propias solicitudes, salvo un administrador.
    if ((int) $r['user_id'] === (int) $u['id'] && !has_role('admin')) {
        return false;
    }
    if (is_hr()) {
        return true;
    }

    return (int) $r['approver_id'] === (int) $u['id'];
}

/**
 * Registra un evento en el historial de la solicitud.
 */
function log_event(int $requestId, string $action, ?string $comment = null, ?int $userId = null): void
{
    insert('request_events', [
        'request_id' => $requestId,
        'user_id' => $userId ?? (uid() ?: null),
        'action' => $action,
        'comment' => $comment,
    ]);
}

/**
 * Textos del historial de una solicitud.
 */
const EVENT_LABELS = [
    'created' => 'creó la solicitud',
    'registered' => 'informó la licencia',
    'approved' => 'aprobó la solicitud',
    'rejected' => 'rechazó la solicitud',
    'cancelled' => 'canceló la solicitud',
    'doc_uploaded' => 'subió documentación',
    'doc_verified' => 'verificó la documentación',
    'doc_observed' => 'observó la documentación',
    'reminder' => 'se envió un recordatorio al aprobador',
    'created_by_hr' => 'cargó la licencia en nombre del colaborador',
];

/**
 * Tipos de archivo admitidos como adjuntos y su extensión.
 */
const ALLOWED_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heic',
];

/**
 * Valida y guarda archivos adjuntos fuera del docroot.
 *
 * @return array{0: int, 1: string[], 2: string[]} Cantidad guardada, errores y rutas almacenadas.
 */
function store_uploads(int $requestId, int $userId, array $files): array
{
    $errors = [];
    $saved = 0;
    $paths = [];
    $max = (int) config('upload_max_mb', 10) * 1024 * 1024;
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
    $dir = STORAGE . '/uploads/' . date('Y/m');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $name = basename((string) $files['name'][$i]);
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "No se pudo subir «{$name}».";
            continue;
        }
        if ($files['size'][$i] > $max) {
            $errors[] = "«{$name}» supera los " . config('upload_max_mb', 10) . ' MB.';
            continue;
        }
        $mime = $finfo->file($files['tmp_name'][$i]) ?: '';
        if (!isset(ALLOWED_MIME[$mime])) {
            $errors[] = "«{$name}» no es un formato permitido (PDF, JPG, PNG, WEBP o HEIC).";
            continue;
        }
        $stored = date('Y/m/') . bin2hex(random_bytes(16)) . '.' . ALLOWED_MIME[$mime];
        if (!move_uploaded_file($files['tmp_name'][$i], STORAGE . '/uploads/' . $stored)) {
            $errors[] = "No se pudo guardar «{$name}».";
            continue;
        }
        insert('documents', [
            'request_id' => $requestId,
            'user_id' => $userId,
            'original_name' => mb_substr($name, 0, 255),
            'stored_name' => $stored,
            'mime' => $mime,
            'size' => (int) $files['size'][$i],
        ]);
        $paths[] = STORAGE . '/uploads/' . $stored;
        $saved++;
    }

    return [$saved, $errors, $paths];
}

/**
 * Indica si el formulario incluye al menos un archivo en el campo indicado.
 */
function has_uploads(string $field): bool
{
    foreach ((array) ($_FILES[$field]['error'] ?? []) as $err) {
        if ($err !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }

    return false;
}
