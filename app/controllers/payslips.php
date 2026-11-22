<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Recibos de sueldo: carga y control (administradores) y «Mis recibos» con la firma de
 * conformidad. Por ahora «Mis recibos» está habilitado solo para administradores; se habilita
 * para todo el personal desde Configuración.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const PAYSLIP_SELECT = 'SELECT p.*, u.name AS user_name, u.email AS user_email, s.name AS uploader FROM payslips p
    LEFT JOIN users u ON u.id = p.user_id LEFT JOIN users s ON s.id = p.uploaded_by';

/**
 * Recibos de sueldo cargados, por período.
 */
function admin_payslips(): void
{
    require_role('admin');
    $periods = array_column(rows('SELECT DISTINCT period FROM payslips ORDER BY period DESC'), 'period');
    $period = (string) ($_GET['periodo'] ?? '');
    $person = (int) ($_GET['persona'] ?? 0);
    $where = [];
    $p = [];
    if (preg_match('/^\d{4}-\d{2}$/', $period)) {
        $where[] = 'p.period = ?';
        $p[] = $period;
    }
    if ($person) {
        $where[] = 'p.user_id = ?';
        $p[] = $person;
    }
    view('admin/payslips', [
        'title' => 'Recibos de sueldo',
        'list' => rows(PAYSLIP_SELECT . ' WHERE p.user_id IS NOT NULL' . ($where ? ' AND ' . implode(' AND ', $where) : '') . ' ORDER BY p.period DESC, u.name', $p),
        'unassigned' => rows(PAYSLIP_SELECT . ' WHERE p.user_id IS NULL ORDER BY p.period DESC'),
        'periods' => $periods,
        'period' => $period,
        'person' => $person,
        'people' => rows('SELECT id, name, cuil FROM users ORDER BY name'),
        'enabled' => setting('payslips_employees') === '1',
    ]);
}

/**
 * Carga de recibos: uno o varios PDF, de una persona o del mes completo.
 */
function admin_payslips_upload(): void
{
    $me = require_login();
    require_role('admin');
    if (!legajos_enabled()) {
        flash('error', 'Configurá las carpetas de Google Drive (legajos y liquidaciones) para guardar los recibos.');
        redirect('/admin/recibos');
    }
    @set_time_limit(300);
    $res = payslips_import($_FILES['files'] ?? [], (int) $me['id']);
    if (!$res['ok'] && !$res['errors']) {
        flash('error', 'Elegí uno o más recibos en PDF.');
    }
    if ($res['ok']) {
        flash('success', count($res['ok']) . ' recibo(s) cargado(s): ' . implode(' ', array_slice($res['ok'], 0, 6)) . (count($res['ok']) > 6 ? ' …' : ''));
    }
    foreach (array_slice($res['errors'], 0, 8) as $e) {
        flash('error', $e);
    }
    log_line('payslips.log', $me['name'] . ' cargó ' . count($res['ok']) . ' recibo(s); ' . count($res['errors']) . ' con problemas.');
    redirect('/admin/recibos');
}

/**
 * Asigna a una persona un recibo que no se pudo identificar y lo pasa a su legajo.
 */
function admin_payslip_assign(int $id): void
{
    require_role('admin');
    $p = row('SELECT * FROM payslips WHERE id = ? AND user_id IS NULL', [$id]) ?: abort(404);
    $u = row('SELECT * FROM users WHERE id = ?', [(int) post('user_id')]);
    if (!$u) {
        flash('error', 'Elegí a quién corresponde el recibo.');
        redirect('/admin/recibos');
    }
    if ($p['cuil'] && $u['cuil'] && $p['cuil'] !== $u['cuil']) {
        flash('error', "El CUIL del recibo ({$p['cuil']}) no coincide con el de {$u['name']} ({$u['cuil']}).");
        redirect('/admin/recibos');
    }
    $err = null;
    $folder = payslip_folder($u, substr($p['period'], 0, 4), $err);
    if (!$folder || !drive_move($p['drive_id'], $folder, $err)) {
        flash('error', 'No se pudo pasar el recibo a su legajo: ' . ($err ?: 'no tiene legajo electrónico vinculado.'));
        redirect('/admin/recibos');
    }
    update('payslips', $id, ['user_id' => $u['id']]);
    $sync = array_filter(['cuil' => $u['cuil'] ? null : $p['cuil'], 'legajo_nro' => $u['legajo_nro'] ? null : $p['legajo']]);
    if ($sync) {
        update('users', (int) $u['id'], $sync);
    }
    flash('success', "Recibo de {$p['label']} asignado a {$u['name']} y guardado en su legajo.");
    redirect('/admin/recibos');
}

/**
 * Elimina un recibo cargado por error (los firmados no se eliminan).
 */
function admin_payslip_delete(int $id): void
{
    require_role('admin');
    $p = row('SELECT * FROM payslips WHERE id = ?', [$id]) ?: abort(404);
    if ($p['signed_at']) {
        flash('error', 'Un recibo firmado no se puede eliminar: es la constancia de pago.');
        redirect('/admin/recibos');
    }
    drive_delete($p['drive_id']);
    q('DELETE FROM payslips WHERE id = ?', [$id]);
    flash('success', "Recibo «{$p['file_name']}» eliminado.");
    redirect('/admin/recibos');
}

/**
 * Mis recibos de sueldo.
 */
function my_payslips(): void
{
    $me = require_login();
    if (!payslips_enabled_for($me)) {
        abort(404);
    }
    view('payslips/mine', [
        'title' => 'Mis recibos',
        'list' => rows('SELECT * FROM payslips WHERE user_id = ? ORDER BY period DESC, kind', [$me['id']]),
        'testing' => setting('payslips_employees') !== '1',
    ]);
}

/**
 * Recibo en PDF (original o firmado): lo ve su titular y los administradores.
 */
function payslip_file(int $id): void
{
    $me = require_login();
    $p = row('SELECT * FROM payslips WHERE id = ?', [$id]);
    $own = $p && (int) $p['user_id'] === (int) $me['id'] && payslips_enabled_for($me);
    if (!$p || (!$own && $me['role'] !== 'admin')) {
        abort(404);
    }
    $signed = isset($_GET['firmado']) && $p['signed_drive_id'];
    $content = drive_download($signed ? $p['signed_drive_id'] : $p['drive_id']);
    if ($content === null) {
        abort(404, 'No se pudo obtener el recibo desde Google Drive.');
    }
    $name = $signed ? preg_replace('/\.pdf$/i', '', $p['file_name']) . (in_array($p['employer_sig'], ['valida', 'no_verificada'], true) ? ' - constancia de conformidad.pdf' : ' (firmado).pdf') : $p['file_name'];
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($content));
    header('Content-Disposition: ' . (isset($_GET['descargar']) ? 'attachment' : 'inline') . "; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, no-store');
    echo $content;
    exit;
}

/**
 * Firma de conformidad (o disconformidad) del recibo por su titular.
 */
function payslip_sign_action(int $id): void
{
    $me = require_login();
    $p = row('SELECT * FROM payslips WHERE id = ? AND user_id = ?', [$id, $me['id']]);
    if (!$p || !payslips_enabled_for($me)) {
        abort(404);
    }
    if ($p['signed_at']) {
        flash('warning', 'Este recibo ya estaba firmado.');
        redirect('/recibos');
    }
    $agree = post('decision') === 'conforme';
    $comment = mb_substr(trim(post('comment')), 0, 500);
    if (!$agree && $comment === '') {
        flash('error', 'Contá el motivo de la disconformidad.');
        redirect('/recibos');
    }
    $err = null;
    if (payslip_sign($p, $me, $agree, $comment, $err)) {
        log_line('payslips.log', "{$me['name']} firmó el recibo #{$id} ({$p['label']}) " . ($agree ? 'conforme' : 'en disconformidad'));
        flash('success', 'Recibo de ' . $p['label'] . ' firmado ' . ($agree ? 'de conformidad' : 'en disconformidad') . '. La copia firmada quedó en tu legajo.');
    } else {
        flash('error', 'No se pudo firmar el recibo: ' . $err);
    }
    redirect('/recibos');
}
