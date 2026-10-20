<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Pantalla de inicio según el rol del usuario.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Consulta base de solicitudes con tipo y colaborador.
 */
const REQ_SELECT = 'SELECT r.*, t.name AS type_name, t.code AS type_code, t.color, t.counting, t.vacation_optional, u.name AS user_name, u.department
    FROM leave_requests r JOIN leave_types t ON t.id = r.type_id JOIN users u ON u.id = r.user_id';

/**
 * Condición SQL de las solicitudes que el usuario puede decidir.
 *
 * @return array{0: string, 1: array} Condición y parámetros.
 */
function pending_scope(array $u): array
{
    if ($u['role'] === 'admin') {
        return ["r.status = 'pending'", []];
    }
    if ($u['role'] === 'hr') {
        return ["r.status = 'pending' AND r.user_id <> ?", [$u['id']]];
    }

    return ["r.status = 'pending' AND r.approver_id = ?", [$u['id']]];
}

/**
 * Condición SQL de las solicitudes que el usuario puede supervisar.
 *
 * @return array{0: string, 1: array} Condición y parámetros.
 */
function team_scope(array $u): array
{
    if (is_hr()) {
        return ['1=1', []];
    }

    return ['(u.manager_id = ? OR r.approver_id = ?)', [$u['id'], $u['id']]];
}

/**
 * Inicio: días disponibles, solicitudes propias y, según el rol, pendientes, ausentes y documentación.
 */
function dashboard(): void
{
    $u = require_login();
    $year = (int) date('Y');
    $today = date('Y-m-d');
    $data = [
        'title' => 'Inicio',
        'user' => $u,
        'year' => $year,
        'balances' => balances($u, $year),
        'mine' => rows(REQ_SELECT . ' WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 5', [$u['id']]),
        'upcoming' => rows(REQ_SELECT . " WHERE r.user_id = ? AND r.status IN ('approved','registered') AND r.end_date >= ? ORDER BY r.start_date LIMIT 3", [$u['id'], $today]),
    ];

    if (is_approver()) {
        [$w, $p] = pending_scope($u);
        $data['pending'] = rows(REQ_SELECT . " WHERE $w ORDER BY r.start_date LIMIT 6", $p);
        $data['pending_count'] = (int) val("SELECT COUNT(*) FROM leave_requests r WHERE $w", $p);
        [$tw, $tp] = team_scope($u);
        $data['out_today'] = rows(REQ_SELECT . " WHERE $tw AND r.status IN ('approved','registered') AND ? BETWEEN r.start_date AND r.end_date ORDER BY u.name", [...$tp, $today]);
        $data['out_week'] = rows(REQ_SELECT . " WHERE $tw AND r.status IN ('approved','registered','pending') AND r.start_date > ? AND r.start_date <= ? ORDER BY r.start_date LIMIT 8", [...$tp, $today, date('Y-m-d', strtotime('+14 days'))]);
    }
    if (is_hr()) {
        $data['docs_to_check'] = rows(REQ_SELECT . " WHERE r.doc_status = 'uploaded' AND r.status <> 'cancelled' ORDER BY r.updated_at DESC LIMIT 6");
        $data['docs_missing'] = rows(REQ_SELECT . " WHERE t.requires_document = 1 AND r.doc_status = 'none' AND r.status IN ('registered','approved') ORDER BY r.start_date DESC LIMIT 6");
        $data['stats'] = [
            'employees' => (int) val('SELECT COUNT(*) FROM users WHERE active = 1'),
            'sick_month' => (float) val("SELECT COALESCE(SUM(r.days),0) FROM leave_requests r JOIN leave_types t ON t.id=r.type_id WHERE t.code='enfermedad' AND r.status='registered' AND r.start_date >= ?", [date('Y-m-01')]),
            'vac_year' => (float) val("SELECT COALESCE(SUM(r.days),0) FROM leave_requests r JOIN leave_types t ON t.id=r.type_id WHERE (t.uses_vacation_balance=1 OR r.deduct_vacation=1) AND r.status='approved' AND YEAR(r.start_date)=?", [$year]),
        ];
    }
    view('dashboard', $data);
}
