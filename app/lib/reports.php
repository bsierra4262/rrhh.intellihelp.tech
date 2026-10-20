<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Reportes de licencias que impactan en vacaciones: consulta, generación de Excel con
 * formato de tabla y envío mensual automático por correo.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Licencias aprobadas que impactan en vacaciones (vacaciones y días descontados).
 *
 * @param string|null $from   Inicio del período (Y-m-d) o null para no filtrar.
 * @param string|null $to     Fin del período (Y-m-d) o null para no filtrar.
 * @param int|null    $userId Colaborador o null para todos.
 * @return array<int, array{name: string, start_date: string, end_date: string, type_name: string, days: string}>
 */
function vacation_records(?string $from, ?string $to, ?int $userId): array
{
    $sql = "SELECT u.name, r.start_date, r.end_date, t.name AS type_name, r.days
        FROM leave_requests r
        JOIN leave_types t ON t.id = r.type_id
        JOIN users u ON u.id = r.user_id
        WHERE r.status = 'approved' AND (t.uses_vacation_balance = 1 OR r.deduct_vacation = 1)";
    $p = [];
    if ($from) {
        $sql .= ' AND r.end_date >= ?';
        $p[] = $from;
    }
    if ($to) {
        $sql .= ' AND r.start_date <= ?';
        $p[] = $to;
    }
    if ($userId) {
        $sql .= ' AND r.user_id = ?';
        $p[] = $userId;
    }

    return rows($sql . ' ORDER BY r.start_date, u.name', $p);
}

/**
 * Descripción legible de los filtros aplicados.
 */
function report_filters_label(?string $from, ?string $to, ?int $userId): string
{
    $period = match (true) {
        $from && $to => fdate($from) . ' al ' . fdate($to),
        (bool) $from => 'desde el ' . fdate($from),
        (bool) $to => 'hasta el ' . fdate($to),
        default => 'todos los períodos',
    };
    $person = $userId ? (string) val('SELECT name FROM users WHERE id = ?', [$userId]) : 'todas las personas';

    return 'Período: ' . $period . ' · Persona: ' . $person;
}

/**
 * Genera el Excel del reporte con formato de tabla.
 *
 * @param array<int, array> $records Resultado de vacation_records().
 * @return string Contenido binario del archivo .xlsx.
 */
function build_vacation_xlsx(array $records, string $subtitle): string
{
    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('Portal de Recursos Humanos — Intellihelp')
        ->setTitle('Licencias que impactan en vacaciones');
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('Vacaciones');
    $sheet->getDefaultRowDimension()->setRowHeight(20);
    $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

    // Encabezado del documento
    $sheet->setCellValue('A1', 'Licencias que impactan en vacaciones');
    $sheet->setCellValue('A2', $subtitle);
    $sheet->setCellValue('A3', 'Generado el ' . fdatetime(date('Y-m-d H:i:s')) . ' · ' . count($records) . ' registro' . (count($records) === 1 ? '' : 's'));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('663399');
    $sheet->getStyle('A2:A3')->getFont()->setSize(10)->getColor()->setRGB('6B6F7B');

    // Tabla
    $headers = ['Nombre', 'Fecha de inicio', 'Fecha de fin', 'Tipo de licencia'];
    $sheet->fromArray($headers, null, 'A5');
    $row = 6;
    foreach ($records as $r) {
        $sheet->setCellValue("A$row", $r['name']);
        $sheet->setCellValue("B$row", ExcelDate::PHPToExcel(new DateTime($r['start_date'])));
        $sheet->setCellValue("C$row", ExcelDate::PHPToExcel(new DateTime($r['end_date'])));
        $sheet->setCellValue("D$row", $r['type_name']);
        $row++;
    }
    $last = max(6, $row - 1);
    if (!$records) {
        $sheet->setCellValue('A6', 'Sin registros para los filtros elegidos');
    }

    $sheet->getStyle("B6:C$last")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    $sheet->getStyle("B5:C$last")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A5:D$last")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle('A5:D5')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '663399']],
    ]);
    for ($i = 6; $i <= $last; $i++) {
        if ($i % 2 === 1) {
            $sheet->getStyle("A$i:D$i")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF6FF');
        }
    }
    $sheet->getStyle("A5:D$last")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E6DCEC');

    $table = new Table("A5:D$last", 'LicenciasVacaciones');
    $table->setStyle((new TableStyle())->setTheme(TableStyle::TABLE_STYLE_LIGHT1)->setShowRowStripes(false));
    $sheet->addTable($table);

    foreach (['A' => 34, 'B' => 18, 'C' => 18, 'D' => 28] as $col => $width) {
        $sheet->getColumnDimension($col)->setWidth($width);
    }
    $sheet->freezePane('A6');
    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);

    $tmp = tempnam(sys_get_temp_dir(), 'rep');
    (new Xlsx($book))->save($tmp);
    $content = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $content;
}

/**
 * Casillas configuradas para el reporte mensual.
 *
 * @return string[]
 */
function report_recipients(): array
{
    $list = preg_split('/[\s,;]+/', mb_strtolower(setting('report_recipients')));

    return array_values(array_unique(array_filter($list, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
}

/**
 * Envía el reporte del mes anterior a las casillas configuradas.
 *
 * Con $force se envía aunque ya se haya enviado en el mes o el envío automático esté desactivado.
 *
 * @return array{0: bool, 1: string} Resultado y mensaje.
 */
function send_monthly_report(bool $force = false): array
{
    $recipients = report_recipients();
    if (!$recipients) {
        return [false, 'No hay casillas configuradas para el reporte.'];
    }
    if (!$force) {
        if (setting('report_enabled') !== '1') {
            return [false, 'El envío automático está desactivado.'];
        }
        if ((int) date('j') < max(1, (int) setting('report_day', '1')) || setting('report_last_sent') === date('Y-m')) {
            return [false, 'No corresponde enviar el reporte todavía.'];
        }
    }

    $from = date('Y-m-01', strtotime('first day of last month'));
    $to = date('Y-m-t', strtotime('last day of last month'));
    $records = vacation_records($from, $to, null);
    $month = MESES[(int) date('n', strtotime($from))] . ' ' . date('Y', strtotime($from));
    $xlsx = build_vacation_xlsx($records, report_filters_label($from, $to, null));

    $error = null;
    $ok = send_mail($recipients, 'Reporte mensual de vacaciones — ' . ucfirst($month), mail_template(
        'Reporte mensual de vacaciones',
        'Adjuntamos el reporte de licencias que impactaron en vacaciones durante <b>' . e($month) . '</b>.',
        [['Período', e(fdate($from) . ' al ' . fdate($to))], ['Registros', (string) count($records)]],
        ['Ver reportes en el portal', url('/admin/reportes')]
    ), [[
        'name' => 'vacaciones-' . date('Y-m', strtotime($from)) . '.xlsx',
        'content' => $xlsx,
        'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]], null, $error);

    if ($ok) {
        save_setting('report_last_sent', date('Y-m'));
        log_line('reports.log', 'Reporte ' . $month . ' enviado a ' . implode(', ', $recipients));

        return [true, 'Reporte de ' . $month . ' enviado a ' . implode(', ', $recipients) . '.'];
    }

    return [false, 'No se pudo enviar el reporte: ' . $error];
}
