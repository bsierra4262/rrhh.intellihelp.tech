<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Tarea programada: recordatorios de solicitudes pendientes, resincronización con
 * Google Calendar y limpieza de datos temporales.
 *
 * Ejecución recomendada: cada hora.
 *   /usr/bin/php /home/<usuario>/domains/rrhh.intellihelp.tech/app/cron.php
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$hours = (int) setting('reminder_hours', '24');
if ($hours > 0) {
    $list = rows("SELECT id FROM leave_requests WHERE status = 'pending'
        AND created_at < NOW() - INTERVAL ? HOUR
        AND (reminded_at IS NULL OR reminded_at < NOW() - INTERVAL ? HOUR)", [$hours, $hours]);
    foreach ($list as $x) {
        $r = load_request((int) $x['id']);
        foreach (approvers_for($r) as $a) {
            on_reminder($r, $a);
        }
        q('UPDATE leave_requests SET reminded_at = NOW() WHERE id = ?', [$r['id']]);
        log_event((int) $r['id'], 'reminder', null, null);
    }
    echo count($list) . " recordatorio(s) enviados\n";
}

// Recordatorios y vencimientos de documentación pendiente.
[$docReminders, $docOverdue] = doc_deadline_job();
if ($docReminders || $docOverdue) {
    echo "$docReminders recordatorio(s) y $docOverdue vencimiento(s) de documentación\n";
}

// Reintenta la creación de eventos de Google Calendar pendientes.
if ($n = gcal_backfill()) {
    echo "$n evento(s) sincronizados con Google Calendar\n";
}

// Reporte mensual de vacaciones por correo (si está activado y corresponde).
[$sent, $msg] = send_monthly_report();
if ($sent) {
    echo $msg . PHP_EOL;
}

// Limpieza de datos temporales.
q('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY');
q('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < NOW() - INTERVAL 180 DAY');
foreach (glob(STORAGE . '/sessions/sess_*') ?: [] as $f) {
    if (filemtime($f) < time() - 86400) {
        @unlink($f);
    }
}
