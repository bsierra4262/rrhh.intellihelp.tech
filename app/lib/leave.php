<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Reglas de negocio de licencias: conteo de días, días disponibles según la LCT, validaciones,
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
 * Cantidad de días de lunes a viernes de un rango (inclusive), sin descontar feriados.
 */
function weekdays_between(string $from, string $to): int
{
    $n = 0;
    for ($d = new DateTimeImmutable($from), $b = new DateTimeImmutable($to); $d <= $b; $d = $d->modify('+1 day')) {
        $n += (int) $d->format('N') < 6 ? 1 : 0;
    }

    return $n;
}

/**
 * Aplica la regla del fin de semana a un rango de días corridos.
 *
 * Si el tipo tiene la regla activa y la ausencia cubre al menos una semana laboral completa
 * (cinco días de lunes a viernes) terminando el viernes o el sábado, se suma el fin de semana
 * siguiente: la licencia pasa a terminar el domingo. Si la ausencia tiene menos de cinco días
 * y no sale de esa semana, quien autoriza decide si se computan solo esos días o la semana
 * completa (siete días).
 *
 * @return array{to: string, added: int, choice: bool, week_end: ?string}
 *         Fecha de fin ajustada, días agregados, si corresponde la decisión de quien autoriza
 *         y el domingo con el que cerraría la semana completa.
 */
function weekend_adjustment(array $type, string $from, string $to): array
{
    $result = ['to' => $to, 'added' => 0, 'choice' => false, 'week_end' => null];
    if (empty($type['weekend_rule']) || ($type['counting'] ?? '') !== 'corridos' || !empty($type['by_hours']) || $to < $from) {
        return $result;
    }
    $end = new DateTimeImmutable($to);
    $dow = (int) $end->format('N');
    $sunday = $end->modify('+' . (7 - $dow) . ' days')->format('Y-m-d');
    $weekdays = weekdays_between($from, $to);
    if ($weekdays >= 5 && in_array($dow, [5, 6], true)) {
        $result['to'] = $sunday;
        $result['added'] = 7 - $dow;
    } elseif ($weekdays < 5 && $dow < 6 && (new DateTimeImmutable($from))->format('o-W') === $end->format('o-W')) {
        $result['choice'] = true;
        $result['week_end'] = $sunday;
    }

    return $result;
}

/**
 * Indica si quien autoriza puede computar la solicitud como semana completa.
 */
function week_choice(array $r): bool
{
    return $r['status'] === 'pending' && weekend_adjustment($r, $r['start_date'], $r['end_date'])['choice'];
}

/**
 * Días de vacaciones del año según antigüedad al 31/12 (LCT art. 150 y 153).
 *
 * Menos de 5 años: 14 días; 5 a 10: 21; 10 a 20: 28; más de 20: 35. Con menos de seis meses
 * trabajados en el año corresponde un día cada veinte trabajados. Admite un valor manual por
 * persona que rige solo para el año en que se cargó; los demás años se calculan por antigüedad.
 */
function vacation_entitlement(array $user, int $year): int
{
    $manual = $user['vacation_days_override'] ?? null;
    $manualYear = $user['vacation_override_year'] ?? null;
    if ($manual !== null && $manual !== '' && ($manualYear === null || (int) $manualYear === $year)) {
        return (int) $manual;
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
        WHERE r.user_id = ? AND (t.uses_vacation_balance = 1 OR r.deduct_vacation = 1)
          AND YEAR(r.start_date) = ? AND r.status IN ($statuses)", [$userId, $year]);
}

/**
 * Días disponibles del colaborador en el año: vacaciones y tipos con tope anual.
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
 * Con $byHr las excepciones de días disponibles y topes se informan como advertencias en lugar de errores.
 * Aplica la regla del fin de semana del tipo, por lo que la fecha de fin puede extenderse.
 *
 * @return array{0: string[], 1: string[], 2: int, 3: string} Errores, advertencias, cantidad de días y fecha de fin.
 */
function validate_request(array $user, array $type, string $from, string $to, ?int $excludeId = null, bool $byHr = false): array
{
    $errors = [];
    $warnings = [];
    if (!valid_date($from) || !valid_date($to)) {
        return [['Las fechas no son válidas.'], [], 0, $to];
    }
    if ($to < $from) {
        return [['La fecha de fin no puede ser anterior a la de inicio.'], [], 0, $to];
    }
    if ($from < date('Y-m-d')) {
        return [['No se pueden cargar licencias con fechas pasadas: elegí hoy o una fecha futura.'], [], 0, $to];
    }
    if (substr($from, 0, 4) !== substr($to, 0, 4)) {
        $errors[] = 'La solicitud no puede abarcar dos años distintos. Cargá una solicitud por año.';
    }
    $to = weekend_adjustment($type, $from, $to)['to'];
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
            $msg = 'No alcanzan los días de vacaciones disponibles de ' . $year . ': quedan ' . fdays(max(0, $ent - $used)) . '.';
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

    return [$errors, $warnings, $days, $to];
}

/**
 * Valida un trámite por horario dentro de un día hábil.
 *
 * @return array{0: string[], 1: string[], 2: float} Errores, advertencias y cantidad de horas.
 */
function validate_hourly_request(array $user, array $type, string $date, string $start, string $end, ?int $excludeId = null): array
{
    $errors = [];
    $warnings = [];
    if (!valid_date($date)) {
        return [['Elegí el día del trámite.'], [], 0.0];
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
        return [['Elegí el horario de salida y de regreso.'], [], 0.0];
    }
    if ($end <= $start) {
        return [['El horario de regreso tiene que ser posterior al de salida.'], [], 0.0];
    }
    if ($date < date('Y-m-d')) {
        return [['No se pueden cargar trámites en días pasados: elegí hoy o una fecha futura.'], [], 0.0];
    }
    if ($date === date('Y-m-d') && $start <= date('H:i')) {
        return [['El horario de salida ya pasó: elegí un horario posterior a las ' . date('H:i') . '.'], [], 0.0];
    }
    $hours = (strtotime("$date $end") - strtotime("$date $start")) / 3600;

    if ((int) date('N', strtotime($date)) >= 6 || isset(holidays_between($date, $date)[$date])) {
        $errors[] = 'El día elegido no es hábil (fin de semana o feriado).';
    }
    if ($hours > 8) {
        $warnings[] = 'El trámite ocupa más de una jornada: considerá pedir un día personal.';
    }

    $exclude = $excludeId ? ' AND r.id <> ' . (int) $excludeId : '';
    $fullDay = row("SELECT r.id, t.name FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
        WHERE r.user_id = ? AND r.status IN ('pending','approved','registered') AND r.start_time IS NULL
          AND r.start_date <= ? AND r.end_date >= ?$exclude", [$user['id'], $date, $date]);
    if ($fullDay) {
        $errors[] = "Ese día ya tenés una solicitud de {$fullDay['name']} (#{$fullDay['id']}).";
    }
    $sameTime = row("SELECT r.id, r.start_time, r.end_time FROM leave_requests r
        WHERE r.user_id = ? AND r.status IN ('pending','approved','registered') AND r.start_time IS NOT NULL
          AND r.start_date = ? AND r.start_time < ? AND r.end_time > ?$exclude", [$user['id'], $date, $end . ':00', $start . ':00']);
    if ($sameTime) {
        $errors[] = 'Se superpone con otro trámite de ese día (' . substr($sameTime['start_time'], 0, 5) . '–' . substr($sameTime['end_time'], 0, 5) . ", #{$sameTime['id']}).";
    }
    if ($type['min_notice_days'] > 0 && $type['requires_approval'] && $date < date('Y-m-d', strtotime('+' . (int) $type['min_notice_days'] . ' days'))) {
        $warnings[] = "Se recomienda pedir {$type['name']} con al menos " . fdays($type['min_notice_days']) . ' de anticipación.';
    }

    return [$errors, $warnings, round($hours, 2)];
}

/**
 * Solicitud con datos de tipo, colaborador, aprobador y decisor.
 */
function load_request(int $id): ?array
{
    return row('SELECT r.*, t.name AS type_name, t.code AS type_code, t.color, t.requires_approval, t.requires_document, t.document_label, t.doc_due_days, t.doc_due_from, t.counting, t.by_hours, t.vacation_optional, t.weekend_rule, t.uses_vacation_balance,
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
    'vacation_deducted' => 'descontó los días de las vacaciones',
    'full_week' => 'computó la semana completa',
    'doc_due_set' => 'definió el plazo de entrega de la documentación',
    'doc_reminder' => 'se recordó la entrega de la documentación',
    'doc_overdue' => 'venció el plazo de entrega de la documentación',
    'created_by_hr' => 'cargó la licencia en nombre del colaborador',
];

/**
 * Tipos de archivo admitidos como adjuntos y su extensión.
 */
const ALLOWED_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

/**
 * Texto con los formatos de documentación admitidos.
 */
const ALLOWED_FORMATS_LABEL = 'PDF, JPG o PNG';

/**
 * Tipo de archivo real según su contenido (no según la extensión), o null si no está admitido.
 */
function detect_upload_mime(string $tmpPath): ?string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';

    return isset(ALLOWED_MIME[$mime]) ? $mime : null;
}

/**
 * Valida los archivos adjuntos y los guarda en Google Drive, en la carpeta
 * Persona / Año de la licencia / Tipo de licencia, renombrados con la fecha de carga.
 *
 * @return array{0: int, 1: string[], 2: string[]} Cantidad guardada, errores e IDs de Drive.
 */
function store_uploads(int $requestId, int $userId, array $files): array
{
    $errors = [];
    $saved = 0;
    $ids = [];
    $max = (int) config('upload_max_mb', 10) * 1024 * 1024;
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
    if (!$count) {
        return [0, [], []];
    }
    if (!drive_enabled()) {
        return [0, ['La carga de documentación no está disponible: falta configurar la carpeta de Google Drive.'], []];
    }
    $r = load_request($requestId);
    $folder = null;

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
        $mime = detect_upload_mime($files['tmp_name'][$i]);
        if ($mime === null) {
            $errors[] = "«{$name}» no es un formato permitido. Se aceptan " . ALLOWED_FORMATS_LABEL . '.';
            continue;
        }
        $error = null;
        $folder ??= drive_leave_folder($r['user_name'], (int) substr($r['start_date'], 0, 4), $r['type_name'], $error);
        $file = $folder ? drive_upload($files['tmp_name'][$i], $mime, drive_file_name($folder, ALLOWED_MIME[$mime]), $folder, $error) : null;
        if (!$file) {
            $errors[] = "No se pudo guardar «{$name}» en Google Drive. Intentá nuevamente en unos minutos.";
            continue;
        }
        insert('documents', [
            'request_id' => $requestId,
            'user_id' => $userId,
            'original_name' => mb_substr($name, 0, 255),
            'stored_name' => '',
            'drive_id' => $file['id'],
            'drive_name' => $file['name'],
            'drive_link' => $file['webViewLink'] ?? null,
            'mime' => $mime,
            'size' => (int) $files['size'][$i],
        ]);
        $ids[] = $file['id'];
        $saved++;
    }

    return [$saved, $errors, $ids];
}

/**
 * Días de vacaciones que le quedan al colaborador en el año (descuenta aprobadas y pendientes).
 */
function vacation_left(array $user, int $year, ?int $excludeId = null): float
{
    $used = vacation_used((int) $user['id'], $year, "'approved','pending'");
    if ($excludeId) {
        $used -= (float) val('SELECT days FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
            WHERE r.id = ? AND (t.uses_vacation_balance = 1 OR r.deduct_vacation = 1)', [$excludeId]);
    }

    return max(0, vacation_entitlement($user, $year) - $used);
}

/**
 * Primer día hábil igual o posterior a la fecha indicada.
 */
function working_day_from(string $date): string
{
    $d = new DateTimeImmutable($date);
    for ($i = 0; $i < 20; $i++) {
        if ((int) $d->format('N') < 6 && !isset(holidays_between($d->format('Y-m-d'), $d->format('Y-m-d'))[$d->format('Y-m-d')])) {
            break;
        }
        $d = $d->modify('+1 day');
    }

    return $d->format('Y-m-d');
}

/**
 * Suma días hábiles a una fecha (sin contar fines de semana ni feriados).
 */
function add_working_days(string $date, int $days): string
{
    $d = new DateTimeImmutable($date);
    $hol = holidays_between($date, $d->modify('+' . ($days * 3 + 15) . ' days')->format('Y-m-d'));
    while ($days > 0) {
        $d = $d->modify('+1 day');
        if ((int) $d->format('N') < 6 && !isset($hol[$d->format('Y-m-d')])) {
            $days--;
        }
    }

    return $d->format('Y-m-d');
}

/**
 * Fecha mínima admitida como plazo de entrega: hoy, y nunca antes de que termine la licencia
 * cuando la documentación se genera al finalizarla (por ejemplo, la constancia de examen).
 */
function doc_due_min(array $r): string
{
    $today = date('Y-m-d');
    if (($r['doc_due_from'] ?? 'end') === 'end' && $r['end_date'] > $today) {
        return $r['end_date'];
    }

    return $today;
}

/**
 * Fecha límite automática para entregar la documentación, según el tipo de licencia.
 *
 * El plazo se cuenta en días hábiles desde el último día de la licencia o desde que se
 * informa, según el tipo. Devuelve null si el tipo no admite documentación.
 */
function doc_auto_due(array $r): ?string
{
    if (!accepts_documents($r)) {
        return null;
    }
    $days = (int) ($r['doc_due_days'] ?? 0) ?: 2;
    $base = ($r['doc_due_from'] ?? 'end') === 'created' ? substr((string) $r['created_at'], 0, 10) : $r['end_date'];

    return add_working_days($base, $days);
}

/**
 * Indica si la documentación de la solicitud está pendiente de entrega.
 */
function doc_pending(array $r): bool
{
    return accepts_documents($r) && in_array($r['doc_status'], ['none', 'observed'], true)
        && in_array($r['status'], ['approved', 'registered'], true);
}

/**
 * Indica si el usuario actual puede modificar el plazo de entrega de la documentación.
 */
function can_set_doc_due(array $r): bool
{
    $u = current_user();
    if (!$u || !accepts_documents($r) || in_array($r['status'], ['rejected', 'cancelled'], true) || $r['doc_status'] === 'verified') {
        return false;
    }

    return is_hr() || (int) $r['approver_id'] === (int) $u['id'] || (int) $r['manager_id'] === (int) $u['id'];
}

/**
 * Etapas de seguimiento de una solicitud, según su tipo y estado.
 *
 * Orden: solicitud, autorización (si corresponde), registro de la ausencia y, después,
 * documentación y verificación de RRHH (solo en los tipos que admiten adjuntos). La
 * documentación se entrega una vez autorizada o informada la licencia, dentro del plazo.
 *
 * @param array<int, array> $docs   Adjuntos de la solicitud.
 * @param array<int, array> $events Historial de la solicitud (orden cronológico).
 * @return array<int, array{title: string, detail: string, state: string, note?: string}>
 *         state: done | current | todo | optional | failed
 */
function request_stages(array $r, array $docs, array $events): array
{
    $last = function (string $action) use ($events): ?array {
        $found = null;
        foreach ($events as $ev) {
            if ($ev['action'] === $action) {
                $found = $ev;
            }
        }

        return $found;
    };
    $who = fn (?array $ev) => trim(($ev['user_name'] ?? '') . ' · ' . fdatetime($ev['created_at'] ?? null), ' ·');

    $stages = [];
    $approval = (bool) $r['requires_approval'];
    $status = $r['status'];
    $closed = in_array($status, ['approved', 'registered'], true);

    $stages[] = [
        'title' => $approval ? 'Solicitada' : 'Informada',
        'detail' => $r['user_name'] . ' · ' . fdatetime($r['created_at']),
        'state' => 'done',
    ];

    if ($approval) {
        $auth = ['title' => 'Autorización', 'detail' => '', 'state' => 'todo'];
        if ($status === 'pending') {
            $auth['state'] = 'current';
            $auth['detail'] = 'En revisión: ' . ($r['approver_name'] ?: 'Recursos Humanos');
        } elseif ($status === 'approved' || ($status === 'cancelled' && $r['decided_by'])) {
            $auth['state'] = 'done';
            $auth['title'] = 'Autorizada';
            $auth['detail'] = $r['decider_name'] . ' · ' . fdatetime($r['decided_at']);
            if ($r['deduct_vacation']) {
                $auth['detail'] .= ' · descontada de vacaciones';
            }
        } elseif ($status === 'rejected') {
            $auth['state'] = 'failed';
            $auth['title'] = 'Rechazada';
            $auth['detail'] = $r['decider_name'] . ' · ' . fdatetime($r['decided_at']);
        } elseif ($status === 'cancelled') {
            $auth['detail'] = 'Sin decisión';
        }
        if ($r['decision_comment']) {
            $auth['note'] = $r['decision_comment'];
        }
        $stages[] = $auth;
    }

    if ($status === 'rejected') {
        return $stages;
    }
    if ($status === 'cancelled') {
        $stages[] = ['title' => 'Cancelada', 'detail' => $who($last('cancelled')), 'state' => 'failed', 'note' => $last('cancelled')['comment'] ?? ''];

        return $stages;
    }

    $stages[] = [
        'title' => 'Ausencia registrada',
        'detail' => $closed ? 'En el calendario del equipo' : 'Al ser autorizada',
        'state' => $closed ? 'done' : 'todo',
    ];

    if (accepts_documents($r)) {
        $label = $r['document_label'] ?: 'Documentación';
        $due = $r['doc_due_date'] ? 'vence el ' . fdate($r['doc_due_date']) : '';
        $overdue = $r['doc_due_date'] && $r['doc_due_date'] < date('Y-m-d') && doc_pending($r);
        if ($docs && $r['doc_status'] !== 'observed') {
            $lastDoc = end($docs);
            $stages[] = [
                'title' => $label,
                'detail' => count($docs) . ' archivo' . (count($docs) > 1 ? 's' : '') . ' · ' . fdatetime($lastDoc['created_at']),
                'state' => 'done',
            ];
        } elseif (!$closed) {
            $stages[] = ['title' => $label, 'detail' => 'Se entrega una vez autorizada', 'state' => 'todo'];
        } elseif ($overdue) {
            $stages[] = ['title' => $label, 'detail' => 'Plazo vencido el ' . fdate($r['doc_due_date']), 'state' => 'failed'];
        } elseif ($r['requires_document'] || $r['doc_status'] === 'observed') {
            $stages[] = ['title' => $label, 'detail' => 'Pendiente de entrega' . ($due ? ' · ' . $due : ''), 'state' => 'current'];
        } else {
            $stages[] = ['title' => $label, 'detail' => 'Opcional' . ($due ? ' · ' . $due : ''), 'state' => 'optional'];
        }

        $review = ['title' => 'Verificación de RRHH', 'detail' => 'Al recibir la documentación', 'state' => 'todo'];
        if ($r['doc_status'] === 'verified') {
            $review = ['title' => 'Documentación verificada', 'detail' => $who($last('doc_verified')), 'state' => 'done'];
        } elseif ($r['doc_status'] === 'uploaded') {
            $review = ['title' => 'Verificación de RRHH', 'detail' => 'Pendiente de revisión', 'state' => 'current'];
        } elseif ($r['doc_status'] === 'observed') {
            $ev = $last('doc_observed');
            $review = ['title' => 'Documentación observada', 'detail' => $who($ev) . ' · requiere una nueva entrega', 'state' => 'failed', 'note' => $ev['comment'] ?? ''];
        }
        if ($r['requires_document'] || $docs) {
            $stages[] = $review;
        }
    }

    return $stages;
}

/**
 * Indica si el tipo de licencia admite documentación respaldatoria.
 */
function accepts_documents(array $typeOrRequest): bool
{
    return !empty($typeOrRequest['requires_document']) || trim((string) ($typeOrRequest['document_label'] ?? '')) !== '';
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
