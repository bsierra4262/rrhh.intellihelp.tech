<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Verificación pública de documentos (recibos de sueldo firmados).
 *
 * Como los validadores de DocuSign, Acrobat Sign o la constatación de comprobantes de ARCA, solo
 * confirma la autenticidad del documento: tipo, período, emisor y si fue firmado, sin importes ni
 * datos personales (el titular figura solo con sus iniciales).
 *
 * Seguridad:
 * - El código tiene 64 bits (16 caracteres hexadecimales del SHA-256 del recibo): no se puede
 *   adivinar, y además hay límite de consultas por IP y un límite global por hora.
 * - El PDF que se sube se compara por su SHA-256 y se descarta: no se guarda.
 * - Encabezados estrictos (CSP, sin incrustar en otros sitios, sin referer, sin caché, sin indexar).
 * - Cada consulta queda registrada (IP, método y resultado) en storage/logs/verify.log.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const VERIFY_PER_IP = 20;      // consultas por IP cada 15 minutos
const VERIFY_GLOBAL = 300;     // consultas totales por hora
const VERIFY_MAX_MB = 10;

/**
 * Encabezados de seguridad de la página pública.
 */
function verify_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
}

/**
 * Iniciales del titular: «Brian Gabriel Sierra» → «B. G. S.».
 */
function holder_initials(string $name): string
{
    $w = preg_split('/\s+/u', trim($name)) ?: [];

    return implode(' ', array_map(fn ($x) => mb_strtoupper(mb_substr($x, 0, 1)) . '.', array_filter($w)));
}

/**
 * Controla los límites de consultas; registra la consulta. Devuelve false si se superaron.
 */
function verify_allowed(): bool
{
    q('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    $ip = (int) val("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = 'verificar' AND created_at > NOW() - INTERVAL 15 MINUTE", [client_ip()]);
    $all = (int) val("SELECT COUNT(*) FROM login_attempts WHERE email = 'verificar' AND created_at > NOW() - INTERVAL 1 HOUR");
    if ($ip >= VERIFY_PER_IP || $all >= VERIFY_GLOBAL) {
        log_line('verify.log', client_ip() . ' bloqueado por límite de consultas');

        return false;
    }
    insert('login_attempts', ['ip' => client_ip(), 'email' => 'verificar']);

    return true;
}

/**
 * Pantalla de verificación (y resultado de un código recibido en el enlace).
 */
function verify_form(): void
{
    verify_headers();
    $code = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', (string) ($_GET['codigo'] ?? '')));
    $result = null;
    if ($code !== '') {
        if (strlen($code) !== 16) {
            $result = ['found' => false, 'reason' => 'format'];
        } elseif (!verify_allowed()) {
            $result = ['found' => false, 'reason' => 'limit'];
        } else {
            $result = verify_lookup('code', $code);
        }
    }
    view('verify', ['title' => 'Verificación de documentos', 'code' => $code, 'result' => $result], 'public_layout');
}

/**
 * Verificación por código o por archivo.
 */
function verify_submit(): void
{
    verify_headers();
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $code = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', post('codigo')));
        redirect('/verificar' . ($code !== '' ? '?codigo=' . $code : ''));
    }
    $ok = $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']) && $file['size'] <= VERIFY_MAX_MB * 1024 * 1024
        && (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) === 'application/pdf';
    if (!$ok) {
        log_line('verify.log', client_ip() . ' archivo rechazado (error ' . (int) $file['error'] . ', ' . (int) $file['size'] . ' bytes, '
            . ($file['error'] === UPLOAD_ERR_OK && is_file($file['tmp_name']) ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : '-') . ')');
        $result = ['found' => false, 'reason' => 'file'];
    } elseif (!verify_allowed()) {
        $result = ['found' => false, 'reason' => 'limit'];
    } else {
        // Solo se calcula el código del archivo; el archivo temporal se descarta al terminar.
        $result = verify_lookup('file', hash_file('sha256', $file['tmp_name']));
    }
    view('verify', ['title' => 'Verificación de documentos', 'code' => '', 'result' => $result, 'byFile' => true], 'public_layout');
}

/**
 * Busca el recibo por código (16 caracteres) o por el SHA-256 del archivo subido.
 *
 * @return array{found: bool, match?: string, reason?: string, p?: array}
 */
function verify_lookup(string $by, string $value): array
{
    $sql = 'SELECT p.label, p.kind, p.period, p.employer_sig, p.signed_at, p.sha256, p.signed_sha256, u.name AS user_name
        FROM payslips p JOIN users u ON u.id = p.user_id WHERE ';
    $p = $by === 'code'
        ? row($sql . 'UPPER(LEFT(p.sha256, 16)) = ?', [$value])
        : row($sql . '(p.sha256 = ? OR p.signed_sha256 = ?)', [$value, $value]);
    $match = !$p ? null : ($by === 'code' ? 'code' : ($p['signed_sha256'] === $value ? 'signed' : 'original'));
    log_line('verify.log', client_ip() . " $by " . ($p ? "válido ($match, recibo " . substr($p['sha256'], 0, 16) . ')' : 'sin coincidencia'));

    return $p ? ['found' => true, 'match' => $match, 'p' => $p] : ['found' => false, 'reason' => $by === 'code' ? 'code' : 'mismatch'];
}
