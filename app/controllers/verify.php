<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Verificación pública de recibos de sueldo firmados.
 *
 * Cualquiera que tenga el recibo (la persona, un inspector, un abogado) puede comprobar que es
 * auténtico sin ingresar al portal: con el código de verificación impreso en la firma o subiendo el
 * PDF, que se compara byte a byte por su código SHA-256 con el original emitido y con la copia
 * firmada. Se muestran solo los datos necesarios, con nombre y CUIL parcialmente ocultos.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Nombre parcialmente oculto: «Brian Gabriel Sierra» → «Brian G. S.».
 */
function mask_name(string $name): string
{
    $w = preg_split('/\s+/u', trim($name)) ?: [];
    $first = array_shift($w) ?? '';

    return trim($first . ' ' . implode(' ', array_map(fn ($x) => mb_strtoupper(mb_substr($x, 0, 1)) . '.', $w)));
}

/**
 * CUIL parcialmente oculto: «20-44334910-4» → «20-****4910-4».
 */
function mask_cuil(?string $cuil): string
{
    return $cuil && preg_match('/^(\d{2})-\d{4}(\d{4})-(\d)$/', $cuil, $m) ? "$m[1]-****$m[2]-$m[3]" : '—';
}

/**
 * Pantalla de verificación.
 */
function verify_form(): void
{
    $code = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', (string) ($_GET['codigo'] ?? '')));
    $result = strlen($code) === 16 ? verify_lookup('code', $code) : null;
    view('verify', ['title' => 'Verificar recibo', 'code' => $code, 'result' => $result], 'auth_layout');
}

/**
 * Verificación por código o por archivo.
 */
function verify_submit(): void
{
    // Límite por dirección IP para evitar búsquedas masivas de códigos.
    q('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    if ((int) val("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = 'verificar' AND created_at > NOW() - INTERVAL 15 MINUTE", [client_ip()]) >= 30) {
        flash('error', 'Hiciste muchas verificaciones seguidas. Probá de nuevo en unos minutos.');
        redirect('/verificar');
    }
    insert('login_attempts', ['ip' => client_ip(), 'email' => 'verificar']);
    $file = $_FILES['file'] ?? null;
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
        if ($file['size'] > 15 * 1024 * 1024 || (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) !== 'application/pdf') {
            flash('error', 'Subí el recibo en PDF (hasta 15 MB).');
            redirect('/verificar');
        }
        $result = verify_lookup('file', hash_file('sha256', $file['tmp_name']));
        view('verify', ['title' => 'Verificar recibo', 'code' => '', 'result' => $result, 'byFile' => true], 'auth_layout');

        return;
    }
    $code = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', post('codigo')));
    if (strlen($code) !== 16) {
        flash('error', 'El código de verificación tiene 16 caracteres (números y letras de la A a la F).');
        redirect('/verificar');
    }
    redirect('/verificar?codigo=' . $code);
}

/**
 * Busca el recibo por código (16 caracteres) o por el SHA-256 del archivo subido.
 *
 * @return array{found: bool, match?: string, p?: array}
 */
function verify_lookup(string $by, string $value): array
{
    if ($by === 'code') {
        $p = row('SELECT p.*, u.name AS user_name FROM payslips p LEFT JOIN users u ON u.id = p.user_id WHERE UPPER(LEFT(p.sha256, 16)) = ? AND p.user_id IS NOT NULL', [$value]);

        return $p ? ['found' => true, 'match' => 'code', 'p' => $p] : ['found' => false];
    }
    $p = row('SELECT p.*, u.name AS user_name FROM payslips p LEFT JOIN users u ON u.id = p.user_id WHERE (p.sha256 = ? OR p.signed_sha256 = ?) AND p.user_id IS NOT NULL', [$value, $value]);
    if (!$p) {
        return ['found' => false];
    }

    return ['found' => true, 'match' => $p['signed_sha256'] === $value ? 'signed' : 'original', 'p' => $p];
}
