<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('STORAGE', APP_ROOT . '/storage');

$GLOBALS['config'] = require APP_ROOT . '/config.php';
date_default_timezone_set(config('timezone', 'America/Argentina/Buenos_Aires'));

if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', STORAGE . '/logs/php-error.log');
}

if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
}

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/leave.php';
require APP_ROOT . '/lib/ics.php';
require APP_ROOT . '/lib/mailer.php';
require APP_ROOT . '/lib/whatsapp.php';
require APP_ROOT . '/lib/gcal.php';
require APP_ROOT . '/lib/notify.php';

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}
