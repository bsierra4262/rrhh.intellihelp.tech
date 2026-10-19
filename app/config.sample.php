<?php
// Copiar como config.php y completar. config.php NO se versiona.
return [
    'app_url'  => 'https://rrhh.intellihelp.tech',
    'timezone' => 'America/Argentina/Buenos_Aires',
    'debug'    => false,
    'db' => [
        'host' => 'localhost',
        'name' => 'u566451014_rrhh',
        'user' => 'u566451014_rrhh',
        'pass' => '',
    ],
    // Clave para cifrar secretos guardados en la tabla settings (SMTP, WhatsApp). 64 hex.
    'app_key' => '',
    'upload_max_mb' => 10,
];
