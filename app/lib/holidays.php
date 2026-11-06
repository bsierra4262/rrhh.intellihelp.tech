<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Feriados nacionales: actualización automática a partir del calendario oficial.
 *
 * Una vez por día se consulta el calendario de feriados nacionales del año en curso y del
 * siguiente (ArgentinaDatos, que publica el calendario oficial del Ministerio del Interior):
 * se agregan los que se declaren durante el año (días puente, feriados por decreto), se
 * reubican los trasladables cuando se fija su fecha y se quitan los que dejaron de figurar.
 * Los feriados cargados a mano no se modifican, y los que RRHH quita no se vuelven a agregar.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const HOLIDAYS_SOURCE = 'https://api.argentinadatos.com/v1/feriados/';

const HOLIDAY_KINDS = [
    'inamovible' => 'Inamovible',
    'trasladable' => 'Trasladable',
    'puente' => 'Día puente',
];

/**
 * Feriados nacionales publicados para un año.
 *
 * @return array<string, array{name: string, kind: ?string}>|null Feriados por fecha, o null si la
 *         fuente no respondió.
 */
function holidays_fetch(int $year): ?array
{
    [$code, $body] = http_request(HOLIDAYS_SOURCE . $year, null, ['Accept: application/json']);
    $data = $code === 200 ? json_decode((string) $body, true) : null;
    if (!is_array($data)) {
        log_line('holidays.log', "No se pudo consultar el calendario $year (HTTP $code).");

        return null;
    }
    $out = [];
    foreach ($data as $h) {
        $date = (string) ($h['fecha'] ?? '');
        $name = trim((string) ($h['nombre'] ?? ''));
        if ($name === '' || !valid_date($date) || (int) substr($date, 0, 4) !== $year) {
            continue;
        }
        $kind = (string) ($h['tipo'] ?? '');
        $out[$date] = ['name' => mb_substr($name, 0, 120), 'kind' => isset(HOLIDAY_KINDS[$kind]) ? $kind : null];
    }

    return $out;
}

/**
 * Fechas que RRHH quitó a mano y no deben volver a importarse.
 *
 * @return string[]
 */
function holidays_ignored(): array
{
    // Se lee directo de la base: la lista puede cambiar durante el mismo pedido.
    $v = (string) val("SELECT v FROM settings WHERE k = 'holidays_ignored'");

    return array_values(array_filter(array_map('trim', explode(',', $v))));
}

/**
 * Agrega o quita una fecha de la lista de feriados que no se importan.
 */
function holidays_ignore(string $date, bool $ignore): void
{
    $list = array_diff(holidays_ignored(), [$date]);
    if ($ignore) {
        $list[] = $date;
    }
    sort($list);
    save_setting('holidays_ignored', implode(',', $list));
}

/**
 * Sincroniza los feriados con el calendario oficial.
 *
 * Un año para el que la fuente no responde, o responde con un calendario incompleto, no se
 * modifica. Un feriado cargado a mano en una fecha oficial pasa a actualizarse solo.
 *
 * @param int[]|null $years Años a sincronizar; por defecto, el actual y el siguiente.
 * @return array{added: array<int, array{0: string, 1: string}>, removed: array<int, array{0: string, 1: string}>, updated: int, years: int[]}
 */
function holidays_sync(?array $years = null): array
{
    $years ??= [(int) date('Y'), (int) date('Y') + 1];
    $ignored = holidays_ignored();
    $result = ['added' => [], 'removed' => [], 'updated' => 0, 'years' => []];
    foreach ($years as $year) {
        $official = holidays_fetch($year);
        // Cada año tiene más de diez feriados nacionales: menos que eso es un error de la fuente.
        if ($official === null || count($official) < 10) {
            continue;
        }
        $result['years'][] = $year;
        $current = [];
        foreach (rows('SELECT * FROM holidays WHERE YEAR(date) = ?', [$year]) as $h) {
            $current[$h['date']] = $h;
        }
        foreach ($official as $date => $h) {
            $have = $current[$date] ?? null;
            if (in_array($date, $ignored, true)) {
                continue;
            }
            if (!$have) {
                insert('holidays', ['date' => $date, 'name' => $h['name'], 'kind' => $h['kind'], 'source' => 'auto']);
                $result['added'][] = [$date, $h['name']];
            } elseif ($have['source'] !== 'auto' || $have['name'] !== $h['name'] || $have['kind'] !== $h['kind']) {
                q("UPDATE holidays SET name = ?, kind = ?, source = 'auto' WHERE id = ?", [$h['name'], $h['kind'], $have['id']]);
                $result['updated']++;
            }
        }
        // Importados que ya no figuran: trasladables que cambiaron de fecha o puentes sin efecto.
        foreach ($current as $date => $have) {
            if ($have['source'] === 'auto' && !isset($official[$date])) {
                q('DELETE FROM holidays WHERE id = ?', [$have['id']]);
                $result['removed'][] = [$date, $have['name']];
            }
        }
    }
    save_setting('holidays_synced_at', date('Y-m-d H:i:s'));
    foreach (['added' => 'Agregado', 'removed' => 'Quitado'] as $k => $label) {
        foreach ($result[$k] as [$date, $name]) {
            log_line('holidays.log', "$label: $date $name");
        }
    }

    return $result;
}

/**
 * Licencias vigentes que incluyen alguna de las fechas, para revisar su cómputo de días.
 *
 * @param string[] $dates
 */
function holidays_affected_requests(array $dates): array
{
    if (!$dates) {
        return [];
    }
    $cond = implode(' OR ', array_fill(0, count($dates), '? BETWEEN r.start_date AND r.end_date'));

    return rows("SELECT r.id, r.start_date, r.end_date, u.name user_name, t.name type_name, t.counting
        FROM leave_requests r JOIN users u ON u.id = r.user_id JOIN leave_types t ON t.id = r.type_id
        WHERE r.status IN ('pending','approved','registered') AND ($cond) ORDER BY r.start_date", $dates);
}

/**
 * Tarea diaria: sincroniza los feriados y avisa a RRHH si hubo altas o bajas.
 *
 * @return string|null Resumen de los cambios, o null si no correspondía ejecutarla o no hubo cambios.
 */
function holidays_sync_job(bool $force = false): ?string
{
    $last = setting('holidays_synced_at');
    if (!$force && $last !== '' && strtotime($last) > time() - 20 * 3600) {
        return null;
    }
    $res = holidays_sync();
    if (!$res['added'] && !$res['removed']) {
        return null;
    }

    $rows = [];
    foreach ($res['added'] as [$date, $name]) {
        $rows[] = ['Nuevo feriado', e(ucfirst(flongdate($date))) . ' — ' . e($name)];
    }
    foreach ($res['removed'] as [$date, $name]) {
        $rows[] = ['Ya no es feriado', e(ucfirst(flongdate($date))) . ' — ' . e($name)];
    }
    $affected = holidays_affected_requests(array_merge(array_column($res['added'], 0), array_column($res['removed'], 0)));
    foreach ($affected as $r) {
        $rows[] = ['Revisar solicitud #' . $r['id'], e($r['user_name']) . ' · ' . e($r['type_name']) . ' · ' . e(frange($r['start_date'], $r['end_date']))
            . ($r['counting'] === 'habiles' ? ' (se cuenta en días hábiles)' : '')];
    }
    $to = array_column(hr_users(), 'email');
    if ($to) {
        send_mail($to, 'Feriados actualizados', mail_template(
            'Feriados actualizados',
            'El calendario oficial de feriados nacionales cambió y el portal se actualizó solo.'
                . ($affected ? ' Hay solicitudes cargadas que incluyen esas fechas: revisá si corresponde ajustar sus días.' : ''),
            $rows,
            ['Ver feriados', url('/admin/feriados?anio=' . substr(($res['added'][0] ?? $res['removed'][0])[0], 0, 4))]
        ));
    }

    return count($res['added']) . ' feriado(s) agregados y ' . count($res['removed']) . ' quitados';
}
