<?php
// Tarea programada (Hostinger → Avanzado → Cron Jobs), cada hora:
//   /usr/bin/php /home/u566451014/domains/rrhh.intellihelp.tech/app/cron.php
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

// Google Calendar: reintenta eventos que no se pudieron crear
if ($n = gcal_backfill()) echo "$n evento(s) sincronizados con Google Calendar\n";

// Limpieza
q('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY');
q('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < NOW() - INTERVAL 180 DAY');
foreach (glob(STORAGE . '/sessions/sess_*') ?: [] as $f) {
    if (filemtime($f) < time() - 86400) @unlink($f);
}
