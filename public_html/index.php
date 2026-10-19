<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

try {
    start_session();
    require APP_ROOT . '/routes.php';
    dispatch($routes);
} catch (Throwable $ex) {
    error_log((string) $ex);
    if (config('debug')) {
        throw $ex;
    }
    abort(500, 'Ocurrió un error inesperado.');
}
