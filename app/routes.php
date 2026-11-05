<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Tabla de rutas y despachador de peticiones HTTP.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

require APP_ROOT . '/controllers/auth.php';
require APP_ROOT . '/controllers/signup.php';
require APP_ROOT . '/controllers/dashboard.php';
require APP_ROOT . '/controllers/requests.php';
require APP_ROOT . '/controllers/calendar.php';
require APP_ROOT . '/controllers/profile.php';
require APP_ROOT . '/controllers/admin.php';
require APP_ROOT . '/controllers/reports.php';

$routes = [
    ['GET',  '/login', 'login_form'],
    ['POST', '/login', 'login_submit'],
    ['POST', '/logout', 'logout_submit'],
    ['GET',  '/olvide', 'forgot_form'],
    ['POST', '/olvide', 'forgot_submit'],
    ['GET',  '/registro', 'register_form'],
    ['POST', '/registro', 'register_submit'],
    ['GET',  '/activar', 'activate_form'],
    ['POST', '/activar', 'activate_submit'],
    ['GET',  '/restablecer', 'reset_form'],
    ['POST', '/restablecer', 'reset_submit'],

    ['GET',  '/', 'dashboard'],
    ['GET',  '/solicitudes', 'my_requests'],
    ['GET',  '/solicitudes/nueva', 'request_form'],
    ['POST', '/solicitudes/nueva', 'request_submit'],
    ['GET',  '/solicitudes/(\d+)', 'request_show'],
    ['POST', '/solicitudes/(\d+)/decidir', 'request_decide'],
    ['POST', '/solicitudes/(\d+)/cancelar', 'request_cancel'],
    ['POST', '/solicitudes/(\d+)/documentos', 'request_upload'],
    ['POST', '/solicitudes/(\d+)/revisar-doc', 'request_review_doc'],
    ['POST', '/solicitudes/(\d+)/plazo', 'request_set_due'],
    ['GET',  '/archivos/(\d+)', 'file_download'],
    ['GET',  '/aprobaciones', 'approvals'],
    ['GET',  '/equipo', 'team_requests'],
    ['GET',  '/calendario', 'calendar_page'],
    ['GET',  '/ical/([a-f0-9]{40})(-pendientes)?\.ics', 'ical_feed'],
    ['GET',  '/api/dias', 'api_days'],

    ['GET',  '/notificaciones', 'notifications_page'],
    ['POST', '/notificaciones/leer', 'notifications_read'],
    ['GET',  '/perfil', 'profile_form'],
    ['POST', '/perfil', 'profile_submit'],
    ['POST', '/perfil/password', 'profile_password'],
    ['POST', '/perfil/calendario', 'profile_reset_calendar'],

    ['GET',  '/admin/usuarios', 'admin_users'],
    ['GET',  '/admin/usuarios/nuevo', 'admin_user_form'],
    ['POST', '/admin/usuarios/nuevo', 'admin_user_save'],
    ['GET',  '/admin/usuarios/(\d+)', 'admin_user_form'],
    ['POST', '/admin/usuarios/(\d+)', 'admin_user_save'],
    ['POST', '/admin/usuarios/(\d+)/password', 'admin_user_reset_password'],
    ['POST', '/admin/usuarios/(\d+)/aprobar', 'admin_user_approve'],
    ['POST', '/admin/usuarios/(\d+)/activacion', 'admin_user_resend_activation'],
    ['POST', '/admin/usuarios/(\d+)/eliminar', 'admin_user_delete_unconfirmed'],
    ['GET',  '/admin/reportes', 'reports_page'],
    ['GET',  '/admin/reportes/excel', 'reports_excel'],
    ['POST', '/admin/reportes/configuracion', 'reports_settings_save'],
    ['POST', '/admin/reportes/enviar', 'reports_send_now'],
    ['GET',  '/admin/tipos', 'admin_types'],
    ['POST', '/admin/tipos', 'admin_types_save'],
    ['GET',  '/admin/feriados', 'admin_holidays'],
    ['POST', '/admin/feriados', 'admin_holidays_save'],
    ['GET',  '/admin/configuracion', 'admin_settings'],
    ['POST', '/admin/configuracion', 'admin_settings_save'],
    ['POST', '/admin/configuracion/prueba', 'admin_settings_test'],
];

/**
 * Resuelve la ruta de la petición actual y ejecuta su controlador.
 *
 * @param array<int, array{0: string, 1: string, 2: string}> $routes
 */
function dispatch(array $routes): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    $path = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    foreach ($routes as [$m, $pattern, $handler]) {
        if ($m === $method && preg_match('#^' . $pattern . '$#', $path, $mt)) {
            if ($method === 'POST') {
                verify_csrf();
            }
            $handler(...array_map('intval_if_numeric', array_slice($mt, 1)));

            return;
        }
    }
    abort(404, 'La página que buscás no existe.');
}

/**
 * Convierte a entero los parámetros de ruta numéricos.
 */
function intval_if_numeric(string $v): int|string
{
    return ctype_digit($v) ? (int) $v : $v;
}
