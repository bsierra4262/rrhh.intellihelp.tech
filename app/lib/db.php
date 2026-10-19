<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Acceso a datos (PDO) y configuración persistente con cifrado de secretos.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Conexión PDO compartida (UTF-8 y zona horaria de la aplicación).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '" . (new DateTime())->format('P') . "'");
    }

    return $pdo;
}

/**
 * Ejecuta una consulta preparada.
 */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);

    return $st;
}

/**
 * Devuelve la primera fila del resultado o null.
 */
function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();

    return $r === false ? null : $r;
}

/**
 * Devuelve todas las filas del resultado.
 */
function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/**
 * Devuelve el valor de la primera columna de la primera fila, o null.
 */
function val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();

    return $v === false ? null : $v;
}

/**
 * Inserta una fila y devuelve su ID.
 */
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));

    return (int) db()->lastInsertId();
}

/**
 * Actualiza una fila por ID.
 */
function update(string $table, int $id, array $data): void
{
    $set = implode(',', array_map(fn ($c) => "$c = ?", array_keys($data)));
    q("UPDATE $table SET $set WHERE id = ?", [...array_values($data), $id]);
}

/**
 * Parámetros de configuración que se almacenan cifrados.
 */
const SECRET_SETTINGS = ['smtp_pass', 'wa_cloud_token', 'wa_gw_key', 'gchat_webhook', 'gcal_sa_json', 'ai_smtp_pass'];

/**
 * Lee un parámetro de configuración; los secretos se devuelven descifrados.
 */
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (rows('SELECT k, v FROM settings') as $r) {
            $cache[$r['k']] = (string) $r['v'];
        }
    }
    $v = $cache[$key] ?? $default;
    if (in_array($key, SECRET_SETTINGS, true) && $v !== '') {
        $v = decrypt_secret($v);
    }

    return $v;
}

/**
 * Guarda un parámetro de configuración; los secretos se almacenan cifrados.
 */
function save_setting(string $key, string $value): void
{
    if (in_array($key, SECRET_SETTINGS, true) && $value !== '') {
        $value = encrypt_secret($value);
    }
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
}

/**
 * Cifra un valor con AES-256-GCM usando la app_key.
 */
function encrypt_secret(string $plain): string
{
    $key = hex2bin(config('app_key'));
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    return 'enc:' . base64_encode($iv . $tag . $ct);
}

/**
 * Descifra un valor cifrado con encrypt_secret(); devuelve los valores en claro tal cual.
 */
function decrypt_secret(string $stored): string
{
    if (!str_starts_with($stored, 'enc:')) {
        return $stored;
    }
    $raw = base64_decode(substr($stored, 4));
    $key = hex2bin(config('app_key'));
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

    return $plain === false ? '' : $plain;
}
