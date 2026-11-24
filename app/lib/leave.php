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
 * Analiza un pedido de vacaciones según la regla de semanas completas.
 *
 * Las vacaciones se toman por semanas de lunes a domingo. Una semana está completa cuando cada
 * día de lunes a viernes fue elegido o es feriado: en ese caso la licencia se extiende hasta el
 * domingo, y los feriados posteriores al último día elegido se computan como vacaciones.
 *
 * Las vacaciones comienzan el lunes o, si es feriado, el siguiente día hábil (LCT art. 151). Los
 * feriados del inicio de la semana no se computan, aunque se elijan como primer día: el comienzo
 * pasa al primer día hábil y desde ahí se cuentan semanas de 7 días corridos, por lo que con el
 * lunes feriado el tramo va de martes a lunes (art. 154: ningún tramo puede ser menor a 7 días).
 *
 * Si queda algún día hábil sin elegir, el pedido está fuera del período habitual: se puede cargar
 * como excepción y quien autoriza decide si computa solo esos días o la semana completa.
 *
 * @return array{from: string, to: string, added: int, complete: bool, choice: bool, week_end: ?string, leading: string[], trailing: string[]}
 *         Fechas de inicio y fin ajustadas, días agregados al final, si las semanas quedan completas,
 *         si corresponde la decisión de quien autoriza, cierre de la última semana y feriados
 *         anteriores y posteriores a los días elegidos.
 */
function weekend_adjustment(array $type, string $from, string $to): array
{
    $result = ['from' => $from, 'to' => $to, 'added' => 0, 'complete' => true, 'choice' => false, 'week_end' => null, 'leading' => [], 'trailing' => []];
    if (empty($type['weekend_rule']) || ($type['counting'] ?? '') !== 'corridos' || !empty($type['by_hours']) || $to < $from) {
        return $result;
    }
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    $monday = $start->modify('-' . ((int) $start->format('N') - 1) . ' days');
    $hol = holidays_between($monday->format('Y-m-d'), $end->modify('+14 days')->format('Y-m-d'));
    $weekday = fn (DateTimeImmutable $d) => (int) $d->format('N') < 6;

    // Días hábiles de la semana anteriores al inicio que no se eligieron.
    $missingBefore = false;
    for ($d = $monday; $d < $start; $d = $d->modify('+1 day')) {
        if ($weekday($d) && !isset($hol[$d->format('Y-m-d')])) {
            $missingBefore = true;
        }
    }
    // Si el pedido empieza en un feriado, las vacaciones comienzan el siguiente día hábil (art. 151).
    if (!$missingBefore) {
        while ($start < $end && $weekday($start) && isset($hol[$start->format('Y-m-d')])) {
            $start = $start->modify('+1 day');
        }
    }
    $from = $result['from'] = $start->format('Y-m-d');
    for ($d = $monday; $d < $start; $d = $d->modify('+1 day')) {
        if ($weekday($d) && isset($hol[$d->format('Y-m-d')])) {
            $result['leading'][] = $d->format('Y-m-d');
        }
    }

    // Con feriados al inicio, las semanas se cuentan desde el primer día hábil.
    $anchor = $result['leading'] && !$missingBefore ? $start : $monday;
    $spill = (int) $monday->diff($anchor)->days;
    $endDay = (int) $end->format('N');
    // Un fin elegido dentro de los días que pasan a la semana siguiente cierra la semana anterior.
    $sunday = $endDay <= $spill ? $end->modify("-$endDay days") : $end->modify('+' . (7 - $endDay) . ' days');

    $missingAfter = false;
    for ($d = $end->modify('+1 day'); $d <= $sunday; $d = $d->modify('+1 day')) {
        if (!$weekday($d)) {
            continue;
        }
        if (isset($hol[$d->format('Y-m-d')])) {
            $result['trailing'][] = $d->format('Y-m-d');
        } else {
            $missingAfter = true;
        }
    }
    $weeks = max(1, (int) ceil(($anchor->diff($sunday)->days + 1) / 7));
    $result['week_end'] = $anchor->modify('+' . (7 * $weeks - 1) . ' days')->format('Y-m-d');
    if ($missingBefore || $missingAfter) {
        $result['complete'] = false;
        $result['choice'] = $result['week_end'] > $to;
        $result['trailing'] = [];

        return $result;
    }
    $result['to'] = max($to, $result['week_end']);
    $result['added'] = (int) $end->diff(new DateTimeImmutable($result['to']))->days;

    return $result;
}

/**
 * Textos explicativos del cómputo de vacaciones para mostrar al pedirlas.
 *
 * @return array{notes: string[], exception: ?string} Aclaraciones y, si el pedido está fuera del
 *         período habitual, el aviso correspondiente.
 */
function week_messages(array $adj, int $days): array
{
    $day = fn (string $d) => DIAS[(int) date('w', strtotime($d))] . ' ' . (int) substr($d, 8, 2);
    $list = function (array $dates) use ($day): string {
        $names = array_map($day, $dates);
        $last = array_pop($names);

        return $names ? implode(', ', $names) . ' y el ' . $last : $last;
    };
    $notes = [];
    if ($adj['leading']) {
        $note = count($adj['leading']) > 1
            ? 'El ' . $list($adj['leading']) . ' son feriados: no se computan como vacaciones.'
            : 'El ' . $day($adj['leading'][0]) . ' es feriado: no se computa como vacaciones.';
        if ($adj['complete']) {
            $note .= ' Las vacaciones empiezan el ' . $day($adj['from']) . ' y se cuentan por días corridos: '
                . fdays($days) . ', hasta el ' . flongdate($adj['to']) . '.';
        }
        $notes[] = $note;
    }
    if ($adj['trailing']) {
        $notes[] = (count($adj['trailing']) > 1 ? 'Como el ' . $list($adj['trailing']) . ' son feriados' : 'Como el ' . $day($adj['trailing'][0]) . ' es feriado')
            . ', se computa la semana completa: ' . fdays($days) . ', hasta el ' . flongdate($adj['to']) . '.';
    } elseif ($adj['added'] && !$adj['leading']) {
        $notes[] = 'Al tomar la semana completa se suma el fin de semana: ' . fdays($days) . ', hasta el ' . flongdate($adj['to']) . '.';
    }
    $exception = $adj['complete'] ? null
        : 'El período habitual de vacaciones es de 7 días corridos (de lunes a domingo). Podés pedir estos días como excepción, pero antes tenés que hablarlo con '
            . implode(' o ', array_column(vacation_contacts(), 'name')) . '.';

    return ['notes' => $notes, 'exception' => $exception];
}

/**
 * Personas con quienes se acuerdan las excepciones al período habitual de vacaciones.
 *
 * @return array<int, array{name: string, email: string, phone: ?string}>
 */
function vacation_contacts(): array
{
    $emails = array_filter(array_map('trim', explode(',', mb_strtolower(setting('vacation_contacts')))));
    if (!$emails) {
        return array_map(fn ($u) => ['name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'] ?: null], hr_users());
    }
    $in = implode(',', array_fill(0, count($emails), '?'));

    return rows("SELECT name, email, phone FROM users WHERE active = 1 AND email IN ($in) ORDER BY FIELD(email, $in)", [...$emails, ...$emails]);
}

/**
 * Indica si quien autoriza puede computar la solicitud como semana completa.
 */
function week_choice(array $r): bool
{
    if ($r['status'] !== 'pending') {
        return false;
    }
    $adj = weekend_adjustment($r, $r['start_date'], $r['end_date']);

    // También si la semana quedó completa después de cargada (por ejemplo, por un feriado nuevo).
    return $adj['choice'] || $adj['to'] > $r['end_date'];
}

/**
 * Días de vacaciones del año según antigüedad al 31/12 (LCT art. 150 y 153).
 *
 * Menos de 5 años: 14 días; 5 a 10: 21; 10 a 20: 28; más de 20: 35. Quien ingresó en el año y no
 * llega a trabajar la mitad de los días hábiles del año tiene un día cada veinte días hábiles
 * trabajados (art. 153). Admite un valor manual por persona que rige solo para el año en que se
 * cargó; los demás años se calculan por antigüedad.
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
    // Menos de la mitad de los días hábiles del año: un día cada veinte trabajados (LCT art. 153).
    if ((int) $hire->format('Y') === $year) {
        $worked = count_days($hire->format('Y-m-d'), "$year-12-31", 'habiles');
        if ($worked * 2 < count_days("$year-01-01", "$year-12-31", 'habiles')) {
            return intdiv($worked, 20);
        }
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
 * Anticipación mínima con que se notifica la fecha de inicio de las vacaciones (art. 154): 30 días
 * por la LCT y 60 para quienes están en el convenio de Comercio (art. 74 del CCT 130/75; el art. 154
 * permite que los convenios fijen otro plazo).
 */
function vacation_notice_days(array $user): int
{
    return ($user['cct'] ?? '') === '130/75' ? 60 : 30;
}

/**
 * Días de vacaciones acumulados de un año al siguiente por acuerdo (art. 164).
 */
function vacation_carryover(int $userId, int $fromYear): int
{
    return (int) val('SELECT COALESCE(SUM(days),0) FROM vacation_carryovers WHERE user_id = ? AND from_year = ?', [$userId, $fromYear]);
}

/**
 * Días de vacaciones del año: los que corresponden por antigüedad más los acumulados del año anterior.
 */
function vacation_total(array $user, int $year): int
{
    return vacation_entitlement($user, $year) + vacation_carryover((int) $user['id'], $year - 1);
}

/**
 * Máximo que se puede acumular al año siguiente: hasta un tercio de las vacaciones del año, sin
 * superar los días propios que quedan sin usar (art. 164). Los días que ya vienen acumulados del
 * año anterior se usan primero y no se vuelven a acumular.
 */
function vacation_carry_limit(array $user, int $year): int
{
    $ent = vacation_entitlement($user, $year);
    $used = vacation_used((int) $user['id'], $year, "'approved','pending'");
    $ownUsed = max(0, $used - vacation_carryover((int) $user['id'], $year - 1));

    return (int) max(0, min(intdiv($ent, 3), floor($ent - $ownUsed)));
}

/**
 * Temporada de verano (del 21 de diciembre al 21 de marzo) a la que pertenece una fecha.
 *
 * @return int|null Año en que empieza la temporada, o null si la fecha no cae en verano.
 */
function summer_season(string $date): ?int
{
    $md = substr($date, 5);
    $y = (int) substr($date, 0, 4);

    return $md >= '12-21' ? $y : ($md <= '03-21' ? $y - 1 : null);
}

/**
 * Temporada de verano vigente o la próxima a partir de hoy.
 */
function current_summer_season(): int
{
    return date('m-d') <= '03-21' ? (int) date('Y') - 1 : (int) date('Y');
}

/**
 * Indica si un rango de fechas incluye días de la temporada de verano indicada.
 */
function overlaps_summer(string $from, string $to, int $season): bool
{
    return $from <= ($season + 1) . '-03-21' && $to >= "$season-12-21";
}

/**
 * Indica si unas vacaciones se toman en la temporada de verano indicada: al menos la mitad de
 * sus días tiene que caer entre el 21 de diciembre y el 21 de marzo.
 */
function in_summer(string $from, string $to, int $season): bool
{
    if (!overlaps_summer($from, $to, $season)) {
        return false;
    }
    $inside = count_days(max($from, "$season-12-21"), min($to, ($season + 1) . '-03-21'), 'corridos');

    return $inside * 2 >= count_days($from, $to, 'corridos');
}

/**
 * Temporadas de verano en las que la persona tuvo (o tiene autorizadas o pedidas) vacaciones,
 * con al menos la mitad de los días en verano.
 *
 * Suma la última temporada que RRHH registró a mano, para los veranos anteriores al portal.
 *
 * @return int[]
 */
function summer_seasons_taken(array $user): array
{
    $seasons = [];
    if (!empty($user['last_summer_season'])) {
        $seasons[(int) $user['last_summer_season']] = true;
    }
    foreach (rows("SELECT r.start_date, r.end_date FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
        WHERE r.user_id = ? AND t.uses_vacation_balance = 1 AND r.status IN ('approved','pending')", [$user['id']]) as $r) {
        for ($s = (int) substr($r['start_date'], 0, 4) - 1; $s <= (int) substr($r['end_date'], 0, 4); $s++) {
            if (in_summer($r['start_date'], $r['end_date'], $s)) {
                $seasons[$s] = true;
            }
        }
    }

    return array_keys($seasons);
}

/**
 * Indica si a la persona le corresponden vacaciones en la temporada de verano: la ley exige que
 * las tenga en verano al menos una vez cada tres años (art. 154). Corresponde cuando trabajó las
 * dos temporadas anteriores sin vacaciones en verano y todavía no las tiene en esta.
 */
function summer_due(array $user, ?int $season = null): bool
{
    $season ??= current_summer_season();
    if (!empty($user['exit_date']) && $user['exit_date'] < "$season-12-21") {
        return false;
    }
    if (!empty($user['hire_date']) && $user['hire_date'] > ($season - 2) . '-12-21') {
        return false;
    }

    return !array_intersect([$season - 2, $season - 1, $season], summer_seasons_taken($user));
}

/**
 * Días de vacaciones de un año interrumpidas por enfermedad que todavía no se reprogramaron.
 */
function vacation_reschedulable(int $userId, int $year): float
{
    $returned = (float) val("SELECT COALESCE(SUM(interrupted_days),0) FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
        WHERE r.user_id = ? AND t.uses_vacation_balance = 1 AND YEAR(r.start_date) = ?", [$userId, $year]);
    $done = (float) val("SELECT COALESCE(SUM(days),0) FROM leave_requests
        WHERE user_id = ? AND reschedule = 1 AND COALESCE(vacation_year, YEAR(start_date)) = ? AND status IN ('approved','pending')", [$userId, $year]);

    return max(0, $returned - $done);
}

/**
 * Año de las vacaciones interrumpidas que se reprograman con un pedido, o null si no corresponde.
 *
 * Las vacaciones de un año se pueden gozar hasta el 30 de abril del siguiente (art. 154), así que
 * los días interrumpidos se pueden reprogramar hasta esa fecha y se imputan al año original.
 */
function vacation_reschedule_year(array $user, array $type, string $from, float $days): ?int
{
    if (empty($type['uses_vacation_balance']) || $days <= 0) {
        return null;
    }
    $year = (int) substr($from, 0, 4);
    if ($days <= vacation_reschedulable((int) $user['id'], $year)) {
        return $year;
    }
    if (substr($from, 5) <= '04-30' && $days <= vacation_reschedulable((int) $user['id'], $year - 1)) {
        return $year - 1;
    }

    return null;
}

/**
 * Indica si un pedido de vacaciones puede cargarse como reprogramación de días interrumpidos por
 * enfermedad, sin completar la semana.
 */
function vacation_reschedule_ok(array $user, array $type, string $from, float $days): bool
{
    return vacation_reschedule_year($user, $type, $from, $days) !== null;
}

/**
 * Aviso para quien autoriza unas vacaciones que empiezan antes de la anticipación legal (art. 154).
 */
function vacation_short_notice(array $r): ?string
{
    $owner = row('SELECT * FROM users WHERE id = ?', [$r['user_id']]);
    $notice = vacation_notice_days($owner ?: []);
    $ahead = (int) floor((strtotime($r['start_date']) - strtotime(date('Y-m-d'))) / 86400);
    if ($ahead >= $notice) {
        return null;
    }

    return "Se autorizaron con $ahead días de anticipación: la ley pide notificar la fecha de inicio con $notice días (art. 154 de la LCT). "
        . 'Queda registrado que la fecha la pidió la persona.';
}

/**
 * Interrumpe las vacaciones durante las que la persona se enfermó.
 *
 * Las vacaciones terminan el día anterior a la enfermedad (o se cancelan si la enfermedad empieza
 * antes) y los días que faltaban vuelven a quedar disponibles para reprogramarlos, sin la
 * exigencia de la semana completa.
 *
 * @return array<int, array{id: int, returned: int}> Vacaciones interrumpidas y días devueltos.
 */
function vacation_interrupt(int $sickId): array
{
    $s = row('SELECT * FROM leave_requests WHERE id = ?', [$sickId]);
    $out = [];
    foreach (rows("SELECT r.* FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
        WHERE r.user_id = ? AND t.uses_vacation_balance = 1 AND r.status IN ('approved','pending')
          AND r.start_date <= ? AND r.end_date >= ?", [$s['user_id'], $s['end_date'], $s['start_date']]) as $v) {
        $newEnd = date('Y-m-d', strtotime($s['start_date'] . ' -1 day'));
        if ($newEnd < $v['start_date']) {
            $returned = (int) $v['days'];
            q("UPDATE leave_requests SET status = 'cancelled', interrupted_days = ?, interrupted_by = ? WHERE id = ?", [$returned, $sickId, $v['id']]);
            $text = 'Cancelada por enfermedad (solicitud #' . $sickId . '): los ' . fdays($returned) . ' vuelven a estar disponibles para reprogramar.';
        } else {
            $days = count_days($v['start_date'], $newEnd, 'corridos');
            $returned = (int) $v['days'] - $days;
            q('UPDATE leave_requests SET end_date = ?, days = ?, interrupted_days = ?, interrupted_by = ? WHERE id = ?', [$newEnd, $days, $returned, $sickId, $v['id']]);
            $text = 'Interrumpida por enfermedad (solicitud #' . $sickId . '): termina el ' . fdate($newEnd) . ' y los '
                . fdays($returned) . ' restantes vuelven a estar disponibles para reprogramar.';
        }
        log_event((int) $v['id'], 'interrupted', $text);
        log_event($sickId, 'interrupts_vacation', 'Interrumpe las vacaciones #' . $v['id'] . ': ' . fdays($returned) . ' para reprogramar.');
        $out[] = ['id' => (int) $v['id'], 'returned' => $returned];
    }

    return $out;
}

/**
 * Vacaciones a liquidar al terminar la relación laboral, lo único que se paga en dinero (arts. 156 y 162).
 *
 * Corresponde la parte proporcional al tiempo trabajado en el año del egreso y, si el egreso es
 * antes del 31 de mayo, los días del año anterior que no se gozaron.
 *
 * @return array{year: int, entitled: int, proportional: float, carried: int, used: float, previous: float, total: float}|null
 */
function vacation_exit_settlement(array $user): ?array
{
    if (empty($user['exit_date'])) {
        return null;
    }
    $exit = $user['exit_date'];
    $year = (int) substr($exit, 0, 4);
    $start = max("$year-01-01", (string) ($user['hire_date'] ?: "$year-01-01"));
    $worked = count_days($start, $exit, 'corridos');
    $yearDays = count_days("$year-01-01", "$year-12-31", 'corridos');
    $entitled = vacation_entitlement($user, $year);
    $proportional = round($entitled * $worked / $yearDays, 2);
    $carried = vacation_carryover((int) $user['id'], $year - 1);
    $used = vacation_used((int) $user['id'], $year, "'approved'");
    $previous = 0.0;
    if ($exit <= "$year-05-31") {
        $previous = max(0, vacation_total($user, $year - 1) - vacation_used((int) $user['id'], $year - 1, "'approved'") - vacation_carryover((int) $user['id'], $year - 1));
    }

    return [
        'year' => $year, 'entitled' => $entitled, 'proportional' => $proportional, 'carried' => $carried,
        'used' => $used, 'previous' => $previous, 'total' => max(0, round($proportional + $carried - $used, 2)) + $previous,
    ];
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
function vacation_used(int $userId, int $year, string $statuses = "'approved'", ?int $excludeId = null): float
{
    $wanted = array_map(fn ($s) => trim($s, " '"), explode(',', $statuses));
    $sum = 0.0;
    foreach (vacation_allocation($userId, $excludeId) as $a) {
        if (in_array($a['status'], $wanted, true)) {
            $sum += $a['years'][$year] ?? 0;
        }
    }

    return $sum;
}

/**
 * Reparte los pedidos de vacaciones (aprobados y pendientes) entre los períodos anuales.
 *
 * Las vacaciones de cada año se gozan entre el 1 de octubre y el 30 de abril del año siguiente
 * (art. 154 de la LCT). Por eso un pedido que empieza entre el 1 de enero y el 30 de abril se
 * descuenta primero de los días del año anterior que quedan sin usar y el resto, del año nuevo.
 * Los pedidos se toman en orden de fecha, y los días reprogramados por enfermedad se imputan al
 * año que indica el pedido.
 *
 * @return array<int, array{status: string, years: array<int, float>}> Por pedido, días por año.
 */
function vacation_allocation(int $userId, ?int $excludeId = null): array
{
    $user = row('SELECT * FROM users WHERE id = ?', [$userId]);
    if (!$user) {
        return [];
    }
    $drawn = [];
    $capacity = [];
    $out = [];
    foreach (rows("SELECT r.id, r.start_date, r.days, r.status, r.vacation_year FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
        WHERE r.user_id = ? AND (t.uses_vacation_balance = 1 OR r.deduct_vacation = 1) AND r.status IN ('approved','pending')
        ORDER BY r.start_date, r.id", [$userId]) as $r) {
        if ($excludeId && (int) $r['id'] === $excludeId) {
            continue;
        }
        $days = (float) $r['days'];
        $year = (int) substr($r['start_date'], 0, 4);
        if ($r['vacation_year'] !== null) {
            $years = [(int) $r['vacation_year'] => $days];
        } elseif (substr($r['start_date'], 5) <= '04-30') {
            $prev = $year - 1;
            $capacity[$prev] ??= vacation_total($user, $prev) - vacation_carryover($userId, $prev);
            $fromPrev = min($days, max(0, $capacity[$prev] - ($drawn[$prev] ?? 0)));
            $years = array_filter([$prev => $fromPrev, $year => $days - $fromPrev]);
        } else {
            $years = [$year => $days];
        }
        foreach ($years as $y => $d) {
            $drawn[$y] = ($drawn[$y] ?? 0) + $d;
        }
        $out[(int) $r['id']] = ['status' => $r['status'], 'years' => $years];
    }

    return $out;
}

/**
 * Días del año anterior que todavía se pueden usar con vacaciones que empiezan en la fecha
 * indicada: solo hasta el 30 de abril (art. 154).
 */
function vacation_previous_left(array $user, string $from, ?int $excludeId = null): float
{
    if (substr($from, 5) > '04-30') {
        return 0.0;
    }
    $prev = (int) substr($from, 0, 4) - 1;

    return max(0, vacation_total($user, $prev) - vacation_carryover((int) $user['id'], $prev)
        - vacation_used((int) $user['id'], $prev, "'approved','pending'", $excludeId));
}

/**
 * Días disponibles del colaborador en el año: vacaciones y tipos con tope anual.
 *
 * @return array<int, array{code: string, name: string, color: string, total: int, used: float, pending: float, left: float}>
 */
function balances(array $user, int $year): array
{
    $out = [];
    $entitled = vacation_total($user, $year);
    $approved = vacation_used((int) $user['id'], $year);
    $pending = vacation_used((int) $user['id'], $year, "'pending'");
    $out[] = [
        'code' => 'vacaciones', 'name' => 'Vacaciones', 'color' => '#663399',
        'total' => $entitled, 'used' => $approved, 'pending' => $pending,
        'left' => max(0, $entitled - $approved - $pending),
        'carried' => vacation_carryover((int) $user['id'], $year - 1),
        // Hasta el 30 de abril todavía se pueden usar los días del año anterior (art. 154).
        'previous' => $year === (int) date('Y') ? vacation_previous_left($user, date('Y-m-d')) : 0,
        'previous_year' => $year - 1,
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
 * Aplica la regla de semanas completas del tipo, por lo que el inicio puede pasar al primer día
 * hábil y el fin puede extenderse. Una solicitud puede cruzar de un año al siguiente (por ejemplo,
 * de diciembre a enero): los días se imputan al año en que empieza.
 *
 * @return array{0: string[], 1: string[], 2: int, 3: string, 4: string} Errores, advertencias,
 *         cantidad de días, fecha de fin y fecha de inicio.
 */
function validate_request(array $user, array $type, string $from, string $to, ?int $excludeId = null, bool $byHr = false): array
{
    $errors = [];
    $warnings = [];
    if (!valid_date($from) || !valid_date($to)) {
        return [['Las fechas no son válidas.'], [], 0, $to, $from];
    }
    if ($to < $from) {
        return [['La fecha de fin no puede ser anterior a la de inicio.'], [], 0, $to, $from];
    }
    if ($from < date('Y-m-d')) {
        return [['No se pueden cargar licencias con fechas pasadas: elegí hoy o una fecha futura.'], [], 0, $to, $from];
    }
    ['from' => $from, 'to' => $to] = weekend_adjustment($type, $from, $to);
    $days = count_days($from, $to, $type['counting']);
    if ($days <= 0) {
        $errors[] = 'El rango elegido no tiene días hábiles.';
    }

    // Una enfermedad puede informarse durante las vacaciones: las interrumpe y los días restantes
    // se reprograman (ver vacation_interrupt).
    $sick = ($type['code'] ?? '') === 'enfermedad';
    $overlap = row("SELECT r.id, t.name FROM leave_requests r JOIN leave_types t ON t.id = r.type_id
        WHERE r.user_id = ? AND r.status IN ('pending','approved','registered')
          AND r.start_date <= ? AND r.end_date >= ?" . ($sick ? ' AND t.uses_vacation_balance = 0' : '')
        . ($excludeId ? ' AND r.id <> ' . (int) $excludeId : ''), [$user['id'], $to, $from]);
    if ($overlap) {
        $errors[] = "Ya tenés una solicitud de {$overlap['name']} que se superpone con esas fechas (#{$overlap['id']}).";
    }

    if ($type['max_days_per_request'] && $days > (int) $type['max_days_per_request']) {
        $errors[] = "{$type['name']} permite hasta " . fdays($type['max_days_per_request']) . ' por solicitud.';
    }
    $year = (int) substr($from, 0, 4);
    if ($type['uses_vacation_balance']) {
        $current = vacation_left($user, $year, $excludeId);
        $previous = vacation_previous_left($user, $from, $excludeId);
        if ($days > $current + $previous) {
            $msg = $previous > 0
                ? 'No alcanzan los días de vacaciones disponibles: quedan ' . fdays($previous) . ' de ' . ($year - 1) . ' (hasta el 30 de abril) y ' . fdays($current) . ' de ' . $year . '.'
                : 'No alcanzan los días de vacaciones disponibles de ' . $year . ': quedan ' . fdays($current) . '.';
            $byHr ? $warnings[] = $msg : $errors[] = $msg;
        }
    } elseif ($type['max_days_per_year']) {
        $used = days_used((int) $user['id'], (int) $type['id'], $year, $excludeId);
        if ($used + $days > (int) $type['max_days_per_year']) {
            $msg = "{$type['name']}: el máximo anual es " . fdays($type['max_days_per_year']) . ' y ya usaste ' . fdays($used) . '.';
            $byHr ? $warnings[] = $msg : $errors[] = $msg;
        }
    }
    if ($type['uses_vacation_balance']) {
        [$vErrors, $vWarnings] = vacation_legal_checks($user, $from, $to, $byHr);
        array_push($errors, ...$vErrors);
        array_push($warnings, ...$vWarnings);
    } elseif ($type['min_notice_days'] > 0 && $type['requires_approval']) {
        $notice = (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($from));
        $ahead = $notice->invert ? -$notice->days : $notice->days;
        if ($ahead < (int) $type['min_notice_days']) {
            $warnings[] = "Se recomienda pedir {$type['name']} con al menos " . fdays($type['min_notice_days']) . ' de anticipación.';
        }
    }
    if (!$type['requires_approval'] && $from > date('Y-m-d', strtotime('+30 days')) && $type['code'] === 'enfermedad') {
        $errors[] = 'Una licencia por enfermedad no puede informarse con tanta anticipación.';
    }

    return [$errors, $warnings, $days, $to, $from];
}

/**
 * Reglas de la LCT para un pedido de vacaciones (art. 154).
 *
 * - La fecha de inicio se notifica por escrito con 30 días de anticipación (60 en Comercio): el
 *   pedido tiene que hacerse con esa antelación. RRHH puede cargarlas con menos, con advertencia.
 * - Se otorgan entre el 1 de octubre y el 30 de abril; fuera de ese período, de común acuerdo.
 * - Al menos una vez cada tres años tienen que caer en la temporada de verano.
 *
 * @return array{0: string[], 1: string[]} Errores y advertencias.
 */
function vacation_legal_checks(array $user, string $from, string $to, bool $byHr = false): array
{
    $errors = [];
    $warnings = [];
    $notice = vacation_notice_days($user);
    $earliest = date('Y-m-d', strtotime("+$notice days"));
    $cct = ($user['cct'] ?? '') === '130/75' ? ' (convenio de Comercio)' : '';
    if ($from < $earliest) {
        $byHr
            ? $warnings[] = "Faltan menos de $notice días para el inicio: la ley pide notificar la fecha de las vacaciones con $notice días de anticipación$cct (art. 154 de la LCT)."
            : $errors[] = "Las vacaciones se piden con al menos $notice días de anticipación$cct, porque la fecha de inicio se notifica por escrito con esa antelación (art. 154 de la LCT). Podés pedirlas a partir del " . flongdate($earliest) . '.';
    }
    $md = substr($from, 5);
    if ($md > '04-30' && $md < '10-01') {
        $warnings[] = 'Las vacaciones se otorgan entre el 1 de octubre y el 30 de abril; fuera de ese período se pueden tomar de común acuerdo, y al autorizarlas queda registrado ese acuerdo (art. 154 de la LCT).';
    }
    $season = current_summer_season();
    if (summer_due($user, $season) && !in_summer($from, $to, $season) && $from <= ($season + 1) . '-04-30') {
        $warnings[] = 'Esta temporada corresponden vacaciones en verano (entre el 21 de diciembre y el 21 de marzo de ' . ($season + 1)
            . '): la ley pide que caigan en verano al menos una vez cada tres años (art. 154 de la LCT).';
    }

    return [$errors, $warnings];
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
    'outside_period' => 'pidió vacaciones fuera del período habitual',
    'doc_due_set' => 'definió el plazo de entrega de la documentación',
    'doc_reminder' => 'se recordó la entrega de la documentación',
    'doc_overdue' => 'venció el plazo de entrega de la documentación',
    'created_by_hr' => 'cargó la licencia en nombre del colaborador',
    'interrupted' => 'informó una enfermedad que interrumpe estas vacaciones',
    'interrupts_vacation' => 'informó la enfermedad durante sus vacaciones',
    'rescheduled' => 'reprogramó días de vacaciones interrumpidas por enfermedad',
    'short_notice' => 'autorizó con menos anticipación que la que pide la ley',
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
    return max(0, vacation_total($user, $year) - vacation_used((int) $user['id'], $year, "'approved','pending'", $excludeId));
}

/**
 * Días de vacaciones disponibles para un pedido que empieza en la fecha indicada: los del año y,
 * hasta el 30 de abril, los que quedan del año anterior.
 */
function vacation_available(array $user, string $from, ?int $excludeId = null): float
{
    return vacation_left($user, (int) substr($from, 0, 4), $excludeId) + vacation_previous_left($user, $from, $excludeId);
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
