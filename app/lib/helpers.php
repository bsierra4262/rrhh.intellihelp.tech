<?php
declare(strict_types=1);

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/', array $query = []): string
{
    $u = rtrim(config('app_url'), '/') . '/' . ltrim($path, '/');
    return $query ? $u . '?' . http_build_query($query) : $u;
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function old(string $key, mixed $default = ''): string
{
    return (string) ($_SESSION['old'][$key] ?? $default);
}

function remember_input(): void
{
    $_SESSION['old'] = array_diff_key($_POST, ['password' => 1, 'csrf' => 1, 'password_confirm' => 1]);
}

function forget_input(): void
{
    unset($_SESSION['old']);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        flash('error', 'La sesión expiró. Volvé a intentarlo.');
        redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }
}

function view(string $__view, array $__data = [], string $__layout = 'layout'): void
{
    extract($__data, EXTR_SKIP); // nombres con __ para que los datos no pisen los parámetros
    ob_start();
    require APP_ROOT . '/views/' . $__view . '.php';
    $content = ob_get_clean();
    require APP_ROOT . '/views/' . $__layout . '.php';
    forget_input(); // los valores viejos solo sobreviven a una vista
}

function partial(string $__partial, array $__data = []): void
{
    extract($__data, EXTR_SKIP);
    require APP_ROOT . '/views/partials/' . $__partial . '.php';
}

function abort(int $code, string $msg = ''): never
{
    http_response_code($code);
    $titles = [403 => 'Acceso denegado', 404 => 'Página no encontrada', 500 => 'Error del servidor'];
    try {
        $layout = current_user() ? 'layout' : 'auth_layout';
    } catch (Throwable) {
        $layout = 'auth_layout';
    }
    view('error', ['code' => $code, 'title' => $titles[$code] ?? 'Error', 'msg' => $msg], $layout);
    exit;
}

function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ---------- fechas ----------
const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

function fdate(?string $d, bool $withYear = true): string
{
    if (!$d) return '—';
    $t = strtotime($d);
    return date('j', $t) . ' ' . substr(MESES[(int) date('n', $t)], 0, 3) . ($withYear ? ' ' . date('Y', $t) : '');
}

function flongdate(string $d): string
{
    $t = strtotime($d);
    return DIAS[(int) date('w', $t)] . ' ' . date('j', $t) . ' de ' . MESES[(int) date('n', $t)] . ' de ' . date('Y', $t);
}

function frange(string $a, string $b): string
{
    if ($a === $b) return fdate($a);
    if (substr($a, 0, 4) === substr($b, 0, 4)) return fdate($a, false) . ' → ' . fdate($b);
    return fdate($a) . ' → ' . fdate($b);
}

function fdatetime(?string $d): string
{
    if (!$d) return '—';
    return fdate($d) . ' ' . date('H:i', strtotime($d));
}

function ago(string $d): string
{
    $s = time() - strtotime($d);
    if ($s < 60) return 'recién';
    if ($s < 3600) return 'hace ' . intdiv($s, 60) . ' min';
    if ($s < 86400) return 'hace ' . intdiv($s, 3600) . ' h';
    if ($s < 172800) return 'ayer';
    if ($s < 604800) return 'hace ' . intdiv($s, 86400) . ' días';
    return fdate($d);
}

function fdays(float|string $n): string
{
    $n = (float) $n;
    $s = rtrim(rtrim(number_format($n, 1, ',', ''), '0'), ',');
    return $s . ' ' . ($n == 1.0 ? 'día' : 'días');
}

/** "1 día hábil", "3 días corridos"… */
function fdays_kind(float|string $n, string $counting): string
{
    $one = (float) $n == 1.0;
    return fdays($n) . ' ' . ($counting === 'habiles' ? ($one ? 'hábil' : 'hábiles') : ($one ? 'corrido' : 'corridos'));
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

// ---------- etiquetas ----------
const STATUS_LABELS = [
    'pending' => 'Pendiente',
    'approved' => 'Aprobada',
    'rejected' => 'Rechazada',
    'cancelled' => 'Cancelada',
    'registered' => 'Informada',
];

const DOC_LABELS = [
    'none' => 'Sin documentación',
    'uploaded' => 'Documentación subida',
    'verified' => 'Documentación verificada',
    'observed' => 'Documentación observada',
];

const ROLE_LABELS = [
    'employee' => 'Colaborador/a',
    'manager' => 'Responsable de equipo',
    'hr' => 'Recursos Humanos',
    'admin' => 'Administrador/a',
];

function status_badge(string $s): string
{
    return '<span class="badge badge-' . e($s) . '">' . e(STATUS_LABELS[$s] ?? $s) . '</span>';
}

function doc_badge(string $s): string
{
    if ($s === 'none') return '';
    return '<span class="badge badge-doc-' . e($s) . '">' . icon('paperclip', 12) . ' ' . e(DOC_LABELS[$s]) . '</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $i = mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[count($parts) - 1] ?? '', 0, 1);
    return mb_strtoupper(count($parts) > 1 ? $i : mb_substr($name, 0, 2));
}

function avatar(string $name, string $size = ''): string
{
    $hue = crc32($name) % 360;
    return '<span class="avatar ' . e($size) . '" style="--h:' . $hue . '">' . e(initials($name)) . '</span>';
}

function type_dot(string $color): string
{
    return '<span class="dot" style="background:' . e($color) . '"></span>';
}

// Íconos SVG en línea (trazo estilo Lucide)
function icon(string $name, int $size = 18): string
{
    static $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
        'calendar' => '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
        'check' => '<path d="m4.5 12.5 5 5 10-11"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.5-3.5 3.2-5.5 6.5-5.5s6 2 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14.8c1.8.8 2.8 2.6 3 5.2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.6-4 3.8-6.5 8-6.5s7.4 2.5 8 6.5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'bell' => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'paperclip' => '<path d="m21.4 11.1-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
        'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'book' => '<path d="M4 19.5V5a2 2 0 0 1 2-2h14v16H6.5A2.5 2.5 0 0 0 4 21.5v-2z"/><path d="M8 7h8"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
        'tag' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'flag' => '<path d="M4 22V4s1-1 4-1 5 2 8 2 4-1 4-1v11s-1 1-4 1-5-2-8-2-4 1-4 1"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'arrow-right' => '<path d="M5 12h14M13 5l7 7-7 7"/>',
        'arrow-left' => '<path d="M19 12H5M11 19l-7-7 7-7"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'trash' => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'whatsapp' => '<path d="M3 21l1.6-4.8A8.5 8.5 0 1 1 8 19.6z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8c-1-.4-1.9-1.3-2.3-2.3l.8-1-1-2z"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 8.8-8.8M16 7l3 3M19 4l2 2"/>',
    ];
    $p = $paths[$name] ?? $paths['info'];
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function type_icon(string $code): string
{
    return match ($code) {
        'vacaciones' => 'sun',
        'estudio' => 'book',
        'enfermedad', 'maternidad', 'donacion' => 'heart',
        'personal', 'mudanza' => 'clock',
        default => 'calendar',
    };
}

/** Ejecuta tareas lentas (emails, WhatsApp) después de enviar la respuesta al navegador. */
function defer(callable $fn): void
{
    static $queue = null;
    if ($queue === null) {
        $queue = [];
        register_shutdown_function(function () use (&$queue) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            if (function_exists('litespeed_finish_request')) litespeed_finish_request();
            elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            ignore_user_abort(true);
            @set_time_limit(180);
            foreach ($queue as $task) {
                try {
                    $task();
                } catch (Throwable $ex) {
                    error_log('defer: ' . $ex);
                }
            }
        });
    }
    $queue[] = $fn;
}

function support_email(): string
{
    try {
        return setting('support_email', 'soporte@intellihelp.tech') ?: 'soporte@intellihelp.tech';
    } catch (Throwable) {
        return 'soporte@intellihelp.tech';
    }
}

function log_line(string $file, string $msg): void
{
    @file_put_contents(STORAGE . '/logs/' . $file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}
