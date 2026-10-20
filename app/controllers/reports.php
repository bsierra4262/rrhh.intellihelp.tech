<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Reportes para administradores: licencias que impactan en vacaciones, exportación a
 * Excel y configuración del envío mensual por correo.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Filtros del reporte tomados de la URL.
 *
 * @return array{0: ?string, 1: ?string, 2: ?int}
 */
function report_filters(): array
{
    $from = (string) ($_GET['desde'] ?? '');
    $to = (string) ($_GET['hasta'] ?? '');
    $user = (int) ($_GET['persona'] ?? 0);

    return [valid_date($from) ? $from : null, valid_date($to) ? $to : null, $user ?: null];
}

/**
 * Pantalla de reportes.
 */
function reports_page(): void
{
    require_role('admin');
    [$from, $to, $user] = report_filters();
    view('admin/reports', [
        'title' => 'Reportes',
        'records' => vacation_records($from, $to, $user),
        'from' => $from,
        'to' => $to,
        'user' => $user,
        'people' => rows('SELECT id, name FROM users WHERE pending_activation = 0 ORDER BY name'),
        'enabled' => setting('report_enabled') === '1',
        'recipients' => setting('report_recipients'),
        'day' => (int) setting('report_day', '1'),
        'lastSent' => setting('report_last_sent'),
    ]);
}

/**
 * Descarga del reporte en Excel con los filtros aplicados.
 */
function reports_excel(): void
{
    require_role('admin');
    [$from, $to, $user] = report_filters();
    $xlsx = build_vacation_xlsx(vacation_records($from, $to, $user), report_filters_label($from, $to, $user));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="vacaciones-' . date('Ymd') . '.xlsx"');
    header('Content-Length: ' . strlen($xlsx));
    header('Cache-Control: private, no-store');
    echo $xlsx;
    exit;
}

/**
 * Guarda la configuración del envío mensual.
 */
function reports_settings_save(): void
{
    require_role('admin');
    $raw = post('report_recipients');
    $list = preg_split('/[\s,;]+/', mb_strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
    $invalid = array_filter($list, fn ($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));
    if ($invalid) {
        flash('error', 'Correos no válidos: ' . implode(', ', $invalid) . '.');
        redirect('/admin/reportes#mensual');
    }
    $enabled = isset($_POST['report_enabled']);
    if ($enabled && !$list) {
        flash('error', 'Agregá al menos una casilla para activar el envío mensual.');
        redirect('/admin/reportes#mensual');
    }
    save_setting('report_recipients', implode(', ', array_unique($list)));
    save_setting('report_enabled', $enabled ? '1' : '0');
    save_setting('report_day', (string) min(28, max(1, (int) post('report_day', '1'))));
    flash('success', 'Configuración del reporte mensual guardada.');
    redirect('/admin/reportes#mensual');
}

/**
 * Envía en el momento el reporte del mes anterior.
 */
function reports_send_now(): void
{
    require_role('admin');
    [$ok, $msg] = send_monthly_report(true);
    flash($ok ? 'success' : 'error', $msg);
    redirect('/admin/reportes#mensual');
}
