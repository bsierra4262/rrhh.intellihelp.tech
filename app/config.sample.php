<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Plantilla de configuración. Copiar como config.php y completar los valores.
 * config.php contiene credenciales y no se versiona.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

return [
    'app_url' => 'https://rrhh.intellihelp.tech',
    'timezone' => 'America/Argentina/Buenos_Aires',
    'debug' => false,
    'db' => [
        'host' => 'localhost',
        'name' => 'u566451014_rrhh',
        'user' => 'u566451014_rrhh',
        'pass' => '',
    ],
    // Clave de cifrado de secretos almacenados (SMTP, Google, webhooks): 64 caracteres hexadecimales.
    // Generar con: php -r "echo bin2hex(random_bytes(32));"
    'app_key' => '',
    'upload_max_mb' => 10,
    // Ingreso con ITHub (sesión única de Intellihelp), mixto. Con client_id y client_secret, el
    // personal del dominio interno entra solo con ITHub (el rol lo decide ITHub) y se le rechazan
    // contraseña, alta, recupero y Google directo; las personas de otras empresas siguen con correo
    // y contraseña. Sin esta sección, todo sigue como antes.
    // 'ithub' => [
    //     'issuer' => 'https://ithub.intellihelp.tech',
    //     'client_id' => 'rrhh',
    //     'client_secret' => 'ith_...', // NUNCA subir a git
    //     'domain' => 'intellihelp.tech', // dominio del personal interno (opcional)
    // ],
];
