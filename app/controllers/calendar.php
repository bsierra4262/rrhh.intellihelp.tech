<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Calendario de ausencias y feed iCal suscribible.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Ausencias visibles para un usuario en un rango.
 *
 * RRHH ve todo; los responsables, su equipo y área; los colaboradores ven las propias y,
 * de sus compañeros, solo que están ausentes (sin el motivo).
 */
function visible_absences(array $u, string $from, string $to, bool $withPending = true): array
{
    $statuses = $withPending ? "'approved','registered','pending'" : "'approved','registered'";
    if (in_array($u['role'], ['hr', 'admin'], true)) {
        $where = '1=1';
        $p = [];
    } elseif ($u['role'] === 'manager') {
        $where = '(u.manager_id = ? OR r.approver_id = ? OR r.user_id = ? OR (u.department IS NOT NULL AND u.department = ?))';
        $p = [$u['id'], $u['id'], $u['id'], $u['department']];
    } else {
        $where = '(r.user_id = ? OR (u.manager_id IS NOT NULL AND u.manager_id = ?) OR (u.department IS NOT NULL AND u.department = ?))';
        $p = [$u['id'], $u['manager_id'], $u['department']];
    }
    $list = rows(
        REQ_SELECT . " WHERE $where AND r.status IN ($statuses) AND r.start_date <= ? AND r.end_date >= ? ORDER BY r.start_date, u.name",
        [...$p, $to, $from]
    );
    $full = in_array($u['role'], ['manager', 'hr', 'admin'], true);
    foreach ($list as &$r) {
        $mine = (int) $r['user_id'] === (int) $u['id'];
        $r['private'] = !$full && !$mine;
        if ($r['private']) {
            $r['type_name'] = 'Ausente';
            $r['color'] = '#8a8d99';
            $r['reason'] = null;
        }
    }
    unset($r);

    return $list;
}

/**
 * Calendario mensual de ausencias.
 */
function calendar_page(): void
{
    $u = require_login();
    $ym = (string) ($_GET['mes'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        $ym = date('Y-m');
    }
    $first = new DateTimeImmutable($ym . '-01');
    $gridStart = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
    $last = $first->modify('last day of this month');
    $gridEnd = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');

    $abs = visible_absences($u, $gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));
    $byDay = [];
    foreach ($abs as $r) {
        $d = max($r['start_date'], $gridStart->format('Y-m-d'));
        $end = min($r['end_date'], $gridEnd->format('Y-m-d'));
        while ($d <= $end) {
            $byDay[$d][] = $r;
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
    }
    $hol = [];
    foreach (rows('SELECT * FROM holidays WHERE date BETWEEN ? AND ?', [$gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d')]) as $h) {
        $hol[$h['date']] = $h['name'];
    }
    view('calendar', [
        'title' => 'Calendario',
        'first' => $first,
        'gridStart' => $gridStart,
        'gridEnd' => $gridEnd,
        'byDay' => $byDay,
        'holidays' => $hol,
        'list' => $abs,
        'feed' => url('/ical/' . $u['calendar_token'] . '.ics'),
    ]);
}

/**
 * Feed iCal personal para suscribirse desde Google Calendar u otros clientes.
 */
function ical_feed(string $token): void
{
    $u = row('SELECT * FROM users WHERE calendar_token = ? AND active = 1', [$token]);
    if (!$u) {
        abort(404);
    }
    $from = date('Y-m-d', strtotime('-3 months'));
    $to = date('Y-m-d', strtotime('+12 months'));
    $events = [];
    foreach (visible_absences($u, $from, $to) as $r) {
        $ev = request_ics_event($r, $r['status'] === 'pending' ? 'TENTATIVE' : 'CONFIRMED');
        if ($r['status'] === 'pending') {
            $ev['summary'] = '[Pendiente] ' . $ev['summary'];
        }
        if ($r['private']) {
            $ev['summary'] = 'Ausente — ' . $r['user_name'];
            $ev['description'] = '';
            $ev['url'] = '';
        }
        $events[] = $ev;
    }
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="rrhh-intellihelp.ics"');
    echo ics_calendar($events, 'PUBLISH', 'Licencias · Intellihelp');
    exit;
}
