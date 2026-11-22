<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Recibos de sueldo: carga, identificación, guardado en el legajo y firma de conformidad.
 *
 * Toma los recibos tal como los emite el estudio contable (un PDF por persona, como
 * «20.Junio 2026 Brian Sierra.pdf», o uno con todos los del mes). De cada página lee legajo,
 * apellido y nombre, CUIL, período, fecha de pago y neto; identifica a la persona por su CUIL (o
 * por su nombre la primera vez, y entonces le guarda el CUIL en la ficha) y guarda el recibo en
 * su legajo electrónico: LEGAJOS ELECTRONICOS / grupo / NOMBRE / Recibos de Sueldo / AAAA.
 *
 * La persona firma su conformidad (o disconformidad, con un comentario) desde «Mis recibos»
 * (LCT arts. 138 a 146): el portal estampa la firma electrónica en el recuadro «FIRMA DEL
 * EMPLEADO» con fecha, hora y el código del archivo original, y guarda la copia firmada junto al
 * original.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const PAYSLIP_KINDS = [
    'mensual' => 'Sueldo mensual',
    'sac1' => '1er aguinaldo',
    'sac2' => '2do aguinaldo',
    'final' => 'Liquidación final',
    'vacaciones' => 'Vacaciones',
    'otro' => 'Otro',
];

/**
 * Indica si «Mis recibos» está habilitado para la persona: por ahora, solo administradores,
 * salvo que se habilite para todo el personal en Configuración.
 */
function payslips_enabled_for(?array $user): bool
{
    return $user && ($user['role'] === 'admin' || setting('payslips_employees') === '1');
}

/**
 * CUIL con formato 20-12345678-9, o null si no es válido (dígito verificador incluido).
 */
function normalize_cuil(?string $cuil): ?string
{
    $d = preg_replace('/\D/', '', (string) $cuil);
    if (strlen($d) !== 11) {
        return null;
    }
    $w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += (int) $d[$i] * $w[$i];
    }
    $check = 11 - ($sum % 11);
    $check = $check === 11 ? 0 : ($check === 10 ? 9 : $check);
    if ($check !== (int) $d[10]) {
        return null;
    }

    return substr($d, 0, 2) . '-' . substr($d, 2, 8) . '-' . substr($d, 10);
}

/**
 * Importe en formato argentino («1.001.088,00») como número.
 */
function parse_amount(string $s): float
{
    return (float) str_replace(['.', ','], ['', '.'], $s);
}

/**
 * Datos de un recibo a partir del texto de su página.
 *
 * Reconoce los dos formatos que emitió el estudio contable: el actual (un recibo por página, con
 * «TOTAL NETO») y el anterior (original y duplicado en la misma página, con «SON PESOS»). Busca
 * cada dato por su forma y no por su posición.
 *
 * @return array{cuil: ?string, legajo: ?string, name: ?string, pay_date: ?string, period_text: ?string, period: ?string, kind: string, label: ?string, net: ?float}
 */
function payslip_parse(string $text): array
{
    $t = preg_replace('/\s+/u', ' ', $text);
    $out = ['cuil' => null, 'legajo' => null, 'name' => null, 'pay_date' => null, 'period_text' => null, 'period' => null, 'kind' => 'otro', 'label' => null, 'net' => null];
    $L = 'A-ZÁÉÍÓÚÜÑ\'';
    // CUIL de la persona (el CUIT de la empresa empieza con 30, 33 o 34).
    if (preg_match_all('/(?<!\d)(\d{2})-(\d{8})-(\d)(?!\d)/', $t, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) {
            if (!in_array($c[1], ['30', '33', '34'], true) && ($cuil = normalize_cuil($c[0]))) {
                $out['cuil'] = $cuil;
                break;
            }
        }
    }
    // Formato actual: «29 SIERRA, BRIAN GABRIEL 20-44334910-4».
    if (preg_match("/(?<![\d.,])(\d{1,5})\s+([$L ]+,\s*[$L ]+?)\s+\d{2}-\d{8}-\d/u", $t, $m)) {
        $out['legajo'] = $m[1];
        $out['name'] = trim($m[2]);
    } elseif (preg_match("/([$L][$L ]*,\s*[$L ]+?)\s*\d{1,2}\/\d{1,2}\/\d{4}\s*FECHA\s*DE\s*INGRESO/u", $t, $m)) {
        // Formato anterior: «SIERRA, BRIAN GABRIEL 9/1/2024 FECHA DE INGRESO … PERÍODO DE PAGO: 5 632.000,00».
        $out['name'] = trim($m[1]);
        if (preg_match('/PER[IÍ]ODO\s*DE\s*PAGO:\s+(\d{1,5})\s+\d/u', $t, $mm)) {
            $out['legajo'] = $mm[1];
        }
    }
    // Período: «MES DE JUNIO 2026», «1º AGUINALDO 2026», liquidación final o vacaciones.
    if (preg_match('/MES\s*DE\s*([A-ZÁÉÍÓÚ]+?)\s*(\d{4})/u', $t, $m) && ($mi = array_search(mb_strtolower($m[1]), MESES, true))) {
        $out['period_text'] = $m[0];
        $out['kind'] = 'mensual';
        $out['period'] = sprintf('%s-%02d', $m[2], $mi);
        $out['label'] = ucfirst(MESES[$mi]) . ' ' . $m[2];
    } elseif (preg_match('/([12])\s*\S{0,2}\s*AGUINALDO\s*(\d{4})/u', $t, $m)) {
        $out['period_text'] = $m[0];
        $out['kind'] = $m[1] === '1' ? 'sac1' : 'sac2';
        $out['period'] = $m[2] . ($m[1] === '1' ? '-06' : '-12');
        $out['label'] = ($m[1] === '1' ? '1er' : '2do') . ' Aguinaldo ' . $m[2];
    } elseif (preg_match('/(LIQUIDACI[OÓ]N FINAL|VACACIONES)[^0-9]{0,20}(\d{4})/u', $t, $m)) {
        $out['period_text'] = $m[0];
        $out['kind'] = str_starts_with($m[1], 'LIQ') ? 'final' : 'vacaciones';
        $out['label'] = ($out['kind'] === 'final' ? 'Liquidación final ' : 'Vacaciones ') . $m[2];
    }
    // Fecha de pago: la que acompaña a «FECHA DE PAGO» (actual) o «LUGAR Y FECHA DE PAGO» (anterior).
    if (preg_match('#(\d{2})/(\d{2})/(\d{4})\s*(?:' . preg_quote((string) $out['period_text'], '#') . '|Sin\s*lugar\s*de\s*pago|LUGAR\s*Y\s*FECHA\s*DE\s*PAGO)#u', $t, $m)) {
        $out['pay_date'] = "$m[3]-$m[2]-$m[1]";
    }
    if (!$out['period'] && $out['pay_date'] && $out['kind'] !== 'otro') {
        $out['period'] = substr($out['pay_date'], 0, 7);
    }
    // Neto: «SON PESOS: 524.560,00» (anterior) o, en el actual, el importe de los totales que cumple
    // remunerativo + no remunerativo − descuentos (el mayor, si hay más de uno).
    if (preg_match('/SON PESOS:\s*(\d{1,3}(?:\.\d{3})*,\d{2})/u', $t, $m)) {
        $out['net'] = parse_amount($m[1]);
    } elseif (($a = strpos($t, 'IMPORTE EN LETRAS')) !== false && ($b = strpos($t, 'TOTAL NETO', $a)) !== false) {
        preg_match_all('/\d{1,3}(?:\.\d{3})*,\d{2}/', substr($t, $a, $b - $a), $mm);
        $v = array_map('parse_amount', $mm[0]);
        if ($v) {
            // El mayor de los totales es el remunerativo; se busca no remunerativo, descuentos y neto.
            $r = array_search(max($v), $v, true);
            $n = count($v);
            for ($j = 0; $j < $n; $j++) {
                for ($k = 0; $k < $n; $k++) {
                    for ($l = 0; $l < $n; $l++) {
                        if (count(array_unique([$r, $j, $k, $l])) === 4 && $v[$l] > 0 && abs($v[$r] + $v[$j] - $v[$k] - $v[$l]) < 0.02 && $v[$l] > (float) $out['net']) {
                            $out['net'] = $v[$l];
                        }
                    }
                }
            }
        }
    }

    return $out;
}

/**
 * Firma digital incrustada en el PDF (PAdES): la de la empresa en el recibo que emite el estudio.
 *
 * Verifica que lo firmado no se haya modificado y que la firma cubra el archivo completo (nada
 * agregado después), y lee quién firmó y qué certificador emitió el certificado. Si el certificado
 * encadena con la AC Raíz de la República Argentina (carpeta app/certs), la firma es digital en
 * los términos de la Ley 25.506.
 *
 * @return array{status: string, signer: ?string, signer_id: ?string, issuer: ?string, signed_at: ?string, trusted: bool, detail: string}
 */
function pdf_signature_info(string $content): array
{
    $none = ['status' => 'sin_firma', 'signer' => null, 'signer_id' => null, 'issuer' => null, 'signed_at' => null, 'trusted' => false, 'detail' => 'El PDF no tiene firma digital.'];
    if (!preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $content, $all, PREG_SET_ORDER)) {
        return $none;
    }
    // La última firma es la que cubre más del documento.
    [, $a, $b, $c, $d] = array_map('intval', end($all));
    $hex = substr($content, $a + $b, $c - $a - $b);
    $bin = hex2bin(preg_replace('/[^0-9a-fA-F]/', '', $hex)) ?: '';
    // La firma ocupa un espacio reservado con ceros al final: se toma el largo exacto del DER.
    $der = '';
    if (strlen($bin) > 4 && ord($bin[0]) === 0x30) {
        $l = ord($bin[1]);
        $len = $l < 0x80 ? $l + 2 : 2 + ($l & 0x7f) + (int) hexdec(bin2hex(substr($bin, 2, $l & 0x7f)));
        $der = substr($bin, 0, $len);
    }
    if ($der === '') {
        return ['status' => 'invalida', 'detail' => 'La firma del PDF está dañada.'] + $none;
    }
    $dir = sys_get_temp_dir() . '/pades' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    file_put_contents("$dir/data", substr($content, $a, $b) . substr($content, $c, $d));
    file_put_contents("$dir/sig", $der);
    $out = $none;
    // 1) Integridad: lo firmado no cambió (sin exigir la cadena de confianza).
    $intact = openssl_cms_verify("$dir/data", OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY, "$dir/signer.pem", [], null, null, null, "$dir/sig", OPENSSL_ENCODING_DER);
    $cert = is_file("$dir/signer.pem") ? openssl_x509_parse((string) file_get_contents("$dir/signer.pem")) : false;
    if ($cert) {
        $out['signer'] = $cert['subject']['CN'] ?? null;
        $out['signer_id'] = $cert['subject']['serialNumber'] ?? null;
        $out['issuer'] = $cert['issuer']['CN'] ?? ($cert['issuer']['O'] ?? null);
    }
    // 2) Cadena de confianza: certificados de la AC Raíz y los certificadores licenciados.
    $cas = glob(APP_ROOT . '/certs/*.pem') ?: [];
    $trusted = $intact && $cas && openssl_cms_verify("$dir/data", OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, null, $cas, null, null, null, "$dir/sig", OPENSSL_ENCODING_DER) === true;
    if (preg_match('/\/M\s*\(D:(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})/', substr($content, max(0, $a + $b - 4000), 4000), $m)) {
        $out['signed_at'] = "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:00";
    }
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
    $complete = $c + $d === strlen($content);
    if ($intact !== true) {
        log_line('payslips.log', 'Firma digital no verificada: ' . (openssl_error_string() ?: 'sin detalle'));
        return ['status' => 'invalida', 'detail' => 'El PDF fue modificado después de firmarse: la firma no es válida.'] + $out;
    }
    $out['trusted'] = $trusted;
    $out['status'] = $trusted ? 'valida' : 'no_verificada';
    $out['detail'] = ($trusted ? 'Firma digital válida' : 'Firma íntegra, pero el certificador no está en la lista de certificadores licenciados cargada en el portal')
        . ($complete ? '.' : '; el PDF tiene agregados posteriores a la firma.');

    return $out;
}

/**
 * Persona a la que corresponde un recibo: por CUIL o, si todavía no lo tiene cargado, por nombre.
 */
function payslip_match_user(array $data): ?array
{
    if ($data['cuil'] && ($u = row('SELECT * FROM users WHERE cuil = ?', [$data['cuil']]))) {
        return $u;
    }
    if (!$data['name']) {
        return null;
    }
    // «SIERRA, BRIAN GABRIEL» contra «Brian Gabriel Sierra»: todas las palabras de uno en el otro.
    // Algunos recibos traen el nombre sin espacios («SIERRA,BRIANGABRIEL»): se comparan las letras.
    $compact = str_replace(' ', '', legajo_norm($data['name']));
    $glued = fn (array $u) => ($w = array_filter(explode(' ', legajo_norm($u['name'])))) && strlen(implode('', $w)) === strlen($compact)
        && !array_filter($w, fn ($x) => !str_contains($compact, $x));
    $candidates = array_filter(rows('SELECT * FROM users WHERE cuil IS NULL AND active = 1 AND pending_activation = 0 AND pending_approval = 0'), fn ($u) => legajo_name_match(str_replace(',', ' ', $data['name']), $u['name']) || $glued($u));

    return count($candidates) === 1 ? array_values($candidates)[0] : null;
}

/**
 * Separa un PDF en recibos: cada página (o páginas seguidas del mismo CUIL) es un recibo.
 *
 * @return array<int, array{pages: int[], data: array}>
 */
function payslip_split(string $path): array
{
    $pages = (new \Smalot\PdfParser\Parser())->parseFile($path)->getPages();
    $groups = [];
    foreach ($pages as $i => $page) {
        $data = payslip_parse($page->getText());
        $last = $groups ? count($groups) - 1 : null;
        // Original y duplicado del mismo recibo, o un recibo de más de una página: van juntos.
        if ($last !== null && $data['cuil'] && $groups[$last]['data']['cuil'] === $data['cuil'] && $groups[$last]['data']['period_text'] === $data['period_text']) {
            $groups[$last]['pages'][] = $i + 1;
        } else {
            $groups[] = ['pages' => [$i + 1], 'data' => $data];
        }
    }

    return $groups;
}

/**
 * PDF con algunas páginas de otro PDF.
 */
function pdf_pages(string $path, array $pages): string
{
    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->setSourceFile($path);
    foreach ($pages as $n) {
        $tpl = $pdf->importPage($n);
        $s = $pdf->getTemplateSize($tpl);
        $pdf->AddPage($s['orientation'], [$s['width'], $s['height']]);
        $pdf->useTemplate($tpl);
    }

    return $pdf->Output('S');
}

/**
 * Carpeta de recibos del año dentro del legajo de la persona (la existente o «Recibos de Sueldo»).
 */
function payslip_folder(array $user, string $year, ?string &$error = null): ?string
{
    $leg = legajo_of($user);
    if (!$leg) {
        return null;
    }
    $recibos = null;
    foreach (drive_list($leg['id'], "mimeType = '" . DRIVE_FOLDER_MIME . "'") as $f) {
        if (str_starts_with(legajo_norm($f['name']), 'RECIBOS')) {
            $recibos = $f['id'];
            break;
        }
    }
    $recibos ??= drive_folder('Recibos de Sueldo', $leg['id'], $error);

    return $recibos ? drive_folder($year, $recibos, $error) : null;
}

/**
 * Carpeta de LIQUIDACIONES del mes para los recibos que todavía no tienen persona asignada.
 */
function payslip_unassigned_folder(string $period, ?string &$error = null): ?string
{
    $month = liquidaciones_month_folder($period . '-01', $error);

    return $month ? drive_folder('Recibos sin asignar', $month, $error) : null;
}

/**
 * Registra un recibo y lo guarda en Drive (en el legajo de la persona o, sin persona, en LIQUIDACIONES).
 *
 * @return array{0: ?int, 1: string} ID del recibo y mensaje.
 */
function payslip_store(string $content, array $data, string $fileName, ?array $user, int $uploadedBy): array
{
    $hash = hash('sha256', $content);
    if ($dup = row('SELECT id FROM payslips WHERE sha256 = ?', [$hash])) {
        return [null, "«{$fileName}»: ya estaba cargado (recibo #{$dup['id']})."];
    }
    if (!$data['period']) {
        return [null, "«{$fileName}»: no se reconoce el período del recibo."];
    }
    if ($user && ($prev = row("SELECT id, signed_at FROM payslips WHERE user_id = ? AND period = ? AND kind = ?", [$user['id'], $data['period'], $data['kind']]))) {
        if ($prev['signed_at']) {
            return [null, "«{$fileName}»: {$user['name']} ya firmó el recibo de {$data['label']}. Para reemplazarlo, eliminalo primero."];
        }
    }
    // Firma digital de la empresa: un recibo alterado después de firmado no se carga.
    $sig = $data['_sig'] ?? pdf_signature_info($content);
    if ($sig['status'] === 'invalida') {
        return [null, "«{$fileName}»: {$sig['detail']}"];
    }
    // Recibos que el estudio contable emite con la firma de la empresa (sin certificado incrustado):
    // se dan por firmados por quien se indica en Configuración.
    if ($sig['status'] === 'sin_firma' && setting('payslips_signer') !== '') {
        $sig = ['status' => 'emitido', 'signer' => setting('payslips_signer'), 'signer_id' => null, 'issuer' => 'Estudio contable', 'signed_at' => null] + $sig;
    }
    $err = null;
    $folder = $user ? payslip_folder($user, substr($data['period'], 0, 4), $err) : null;
    $folder ??= payslip_unassigned_folder($data['period'], $err);
    if (!$folder) {
        return [null, "«{$fileName}»: no se pudo guardar en Drive. " . $err];
    }
    $file = drive_put($content, 'application/pdf', $fileName, $folder, $err);
    if (!$file) {
        return [null, "«{$fileName}»: no se pudo guardar en Drive. " . $err];
    }
    if (!empty($prev)) {
        $old = row('SELECT drive_id FROM payslips WHERE id = ?', [$prev['id']]);
        if ($old['drive_id'] !== $file['id']) {
            drive_delete($old['drive_id']);
        }
        q('DELETE FROM payslips WHERE id = ?', [$prev['id']]);
    }
    $id = insert('payslips', [
        'user_id' => $user['id'] ?? null,
        'period' => $data['period'],
        'kind' => $data['kind'],
        'label' => mb_substr((string) $data['label'], 0, 80),
        'cuil' => $data['cuil'],
        'legajo' => $data['legajo'],
        'employee_name' => $data['name'],
        'pay_date' => $data['pay_date'],
        'net' => $data['net'],
        'file_name' => mb_substr($fileName, 0, 190),
        'drive_id' => $file['id'],
        'sha256' => $hash,
        'uploaded_by' => $uploadedBy,
        'employer_sig' => $sig['status'],
        'employer_signer' => $sig['signer'] ? mb_substr($sig['signer'] . ($sig['signer_id'] ? ' (' . $sig['signer_id'] . ')' : ''), 0, 160) : null,
        'employer_sig_issuer' => $sig['issuer'] ? mb_substr($sig['issuer'], 0, 160) : null,
        'employer_signed_at' => $sig['signed_at'],
    ]);
    // La primera vez que se identifica a la persona por su nombre, se le guarda el CUIL y el legajo.
    if ($user) {
        $sync = [];
        if (empty($user['cuil']) && $data['cuil']) {
            $sync['cuil'] = $data['cuil'];
        }
        // El número de legajo es el del recibo más reciente (cambia si cambió el sistema de liquidación).
        if ($data['legajo'] && $data['legajo'] !== ($user['legajo_nro'] ?? null)
            && !val('SELECT 1 FROM payslips WHERE user_id = ? AND period > ?', [$user['id'], $data['period']])) {
            $sync['legajo_nro'] = $data['legajo'];
        }
        if ($sync) {
            update('users', (int) $user['id'], $sync);
        }
    }

    return [$id, $user ? "«{$fileName}»: {$data['label']} de {$user['name']}." : "«{$fileName}»: {$data['label']} sin asignar ({$data['name']}, CUIL {$data['cuil']})."];
}

/**
 * Procesa los archivos subidos: separa los recibos, identifica a cada persona y los guarda.
 *
 * @return array{ok: string[], errors: string[]}
 */
function payslips_import(array $files, int $uploadedBy): array
{
    $res = ['ok' => [], 'errors' => []];
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
    for ($i = 0; $i < $count; $i++) {
        $name = basename((string) $files['name'][$i]);
        $tmp = $files['tmp_name'][$i];
        if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            $res['errors'][] = "«{$name}»: no se pudo subir el archivo.";
            continue;
        }
        if ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'application/pdf') {
            $res['errors'][] = "«{$name}»: no es un PDF.";
            continue;
        }
        try {
            $groups = payslip_split($tmp);
        } catch (Throwable $e) {
            $res['errors'][] = "«{$name}»: no se pudo leer el PDF.";
            log_line('payslips.log', "Error al leer $name: " . $e->getMessage());
            continue;
        }
        $single = count($groups) === 1;
        // Archivo del mes con varios recibos: la firma digital se verifica sobre el archivo completo
        // (al separarlo se pierde) y el original firmado queda en LIQUIDACIONES como constancia.
        $sigAll = null;
        if (!$single) {
            $sigAll = pdf_signature_info((string) file_get_contents($tmp));
            if ($sigAll['status'] === 'invalida') {
                $res['errors'][] = "«{$name}»: {$sigAll['detail']}";
                continue;
            }
            $period = $groups[0]['data']['period'] ?? null;
            if ($sigAll['status'] !== 'sin_firma' && $period && ($folder = liquidaciones_month_folder($period . '-01'))) {
                drive_put((string) file_get_contents($tmp), 'application/pdf', $name, $folder);
            }
        }
        foreach ($groups as $g) {
            if ($sigAll) {
                $g['data']['_sig'] = $sigAll;
            }
            $user = payslip_match_user($g['data']);
            // Un recibo por archivo conserva el nombre del estudio; si venían varios, se nombra cada uno.
            $fileName = $single ? $name : trim(($g['data']['label'] ?: 'Recibo') . ' ' . ($user['name'] ?? $g['data']['name'] ?? '')) . '.pdf';
            $content = $single ? (string) file_get_contents($tmp) : pdf_pages($tmp, $g['pages']);
            [$id, $msg] = payslip_store($content, $g['data'], $fileName, $user, $uploadedBy);
            $res[$id ? 'ok' : 'errors'][] = $msg;
        }
    }

    return $res;
}

/**
 * Firma de la persona: estampa la firma electrónica en el recuadro «FIRMA DEL EMPLEADO» de la
 * primera página y guarda la copia firmada junto al original.
 */
function payslip_sign(array $p, array $user, bool $agree, string $comment, ?string &$error = null): bool
{
    $original = drive_download($p['drive_id'], $error);
    if ($original === null || hash('sha256', $original) !== $p['sha256']) {
        $error = $error ?: 'El archivo del recibo no coincide con el cargado.';

        return false;
    }
    $when = date('d/m/Y H:i');
    // Recibo con firma digital de la empresa: no se modifica (se invalidaría la firma); la
    // conformidad queda en una constancia aparte que identifica el archivo por su código SHA-256.
    if (in_array($p['employer_sig'] ?? 'sin_firma', ['valida', 'no_verificada'], true)) {
        return payslip_sign_record($p, $user, $agree, $comment, payslip_constancia($p, $user, $agree, $comment, $when), ' - constancia de conformidad.pdf', $error);
    }
    $stamped = payslip_stamp($original, [
        'Firmado electrónicamente en el Portal de RRHH',
        mb_strtoupper($user['name']) . ($user['cuil'] ? ' · CUIL ' . $user['cuil'] : ''),
        $when . ' · ' . ($agree ? 'CONFORME' : 'EN DISCONFORMIDAD'),
        'Código de verificación: ' . strtoupper(substr($p['sha256'], 0, 16)),
    ]);

    return payslip_sign_record($p, $user, $agree, $comment, $stamped, ' (firmado).pdf', $error);
}

/**
 * Estampa la firma electrónica del empleado en el recuadro «FIRMA DEL EMPLEADO» de la primera
 * página (abajo a la derecha del recibo apaisado), entre la leyenda «Recibí el importe neto…» y
 * la línea de firma.
 *
 * @param string[] $lines Líneas del sello.
 */
function payslip_stamp(string $original, array $lines): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'rec');
    file_put_contents($tmp, $original);
    // Formato anterior del estudio (original y duplicado en la misma hoja, con «SON PESOS»): la
    // firma va en el duplicado del trabajador, en la franja libre sobre «FIRMA DEL EMPLEADO».
    // Formato actual: dentro del recuadro «FIRMA DEL EMPLEADO», bajo la leyenda «Recibí…».
    try {
        $old = (bool) preg_match('/SON\s*PESOS/', (new \Smalot\PdfParser\Parser())->parseFile($tmp)->getPages()[0]->getText());
    } catch (Throwable) {
        $old = false;
    }
    // [x, ancho, y] relativos a la hoja, alto de línea (mm) y cuerpo de letra: siempre sobre la línea
    // de «FIRMA DEL EMPLEADO», como la firma del empleador en su copia.
    [$rx, $rw, $ry, $lh, $fs] = $old ? [0.855, 0.118, 0.937, 2.0, 4.8] : [0.735, 0.25, 0.893, 2.9, 6.2];
    if ($old) {
        // En el formato anterior el espacio sobre la línea es angosto: textos más cortos.
        $lines = str_replace(['Firmado electrónicamente en el Portal de RRHH', 'Código de verificación:'], ['Firmado electrónicamente · Portal RRHH', 'Verificación:'], $lines);
    }
    $pdf = new \setasign\Fpdi\Fpdi();
    // El sello va cerca del borde inferior: sin salto de página automático.
    $pdf->SetAutoPageBreak(false);
    $n = $pdf->setSourceFile($tmp);
    for ($i = 1; $i <= $n; $i++) {
        $tpl = $pdf->importPage($i);
        $s = $pdf->getTemplateSize($tpl);
        $pdf->AddPage($s['orientation'], [$s['width'], $s['height']]);
        $pdf->useTemplate($tpl);
        if ($i === 1) {
            $x = $s['width'] * $rx;
            $y = $s['height'] * $ry;
            $pdf->SetTextColor(61, 31, 92);
            foreach ($lines as $k => $line) {
                $text = mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
                $pdf->SetFont('Helvetica', $k === 2 ? 'B' : '', $fs);
                // Un nombre largo achica su línea para no salirse del espacio de la firma.
                $w = $pdf->GetStringWidth($text);
                if ($w > $s['width'] * $rw) {
                    $pdf->SetFontSize($fs * $s['width'] * $rw / $w);
                }
                $pdf->SetXY($x, $y + $k * $lh);
                $pdf->Cell($s['width'] * $rw, $lh, $text, 0, 0, 'C');
            }
        }
    }
    @unlink($tmp);

    return $pdf->Output('S');
}

/**
 * Constancia de conformidad del recibo (para los que tienen firma digital de la empresa).
 */
function payslip_constancia(array $p, array $user, bool $agree, string $comment, string $when): string
{
    $enc = fn ($t) => mb_convert_encoding($t, 'Windows-1252', 'UTF-8');
    $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetTextColor(22, 25, 34);
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->Cell(0, 10, $enc('Constancia de conformidad del recibo de sueldo'), 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(61, 31, 92);
    $pdf->Cell(0, 6, $enc('INTELLIHELP SA · Portal de Recursos Humanos'), 0, 1);
    $pdf->Ln(6);
    $pdf->SetTextColor(22, 25, 34);
    $rows = [
        ['Trabajador/a', mb_strtoupper($user['name'])],
        ['CUIL', (string) ($user['cuil'] ?: '—')],
        ['Recibo', $p['label'] . ' — ' . $p['file_name']],
        ['Neto', $p['net'] !== null ? '$ ' . number_format((float) $p['net'], 2, ',', '.') : '—'],
        ['Firma digital de la empresa', ($p['employer_signer'] ?: '—') . ($p['employer_sig_issuer'] ? ' · ' . $p['employer_sig_issuer'] : '')],
        ['Código SHA-256 del recibo', $p['sha256']],
        ['Decisión', $agree ? 'CONFORME' : 'EN DISCONFORMIDAD'],
        ['Fecha y hora', $when],
        ['Dirección IP', (string) ($_SERVER['REMOTE_ADDR'] ?? '—')],
    ];
    if (!$agree) {
        $rows[] = ['Motivo', $comment];
    }
    foreach ($rows as [$k, $v]) {
        $pdf->SetFont('Helvetica', 'B', 9.5);
        $pdf->Cell(58, 7, $enc($k), 0, 0);
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->MultiCell(0, 7, $enc($v), 0, 'L');
    }
    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->MultiCell(0, 5, $enc(($agree
        ? 'Recibí el importe neto de esta liquidación en pago de mi remuneración correspondiente al período indicado, conforme a la ley vigente.'
        : 'Firmo el recibo en disconformidad por el motivo indicado.')
        . ' Firmado electrónicamente en el Portal de RRHH con mi usuario personal (Ley 25.506, art. 5; LCT arts. 138 a 146). El recibo queda identificado por su código SHA-256: cualquier cambio en el archivo cambia el código.'));

    return $pdf->Output('S');
}

/**
 * Guarda la copia firmada (o la constancia) junto al recibo y registra la firma.
 */
function payslip_sign_record(array $p, array $user, bool $agree, string $comment, string $signed, string $suffix, ?string &$error = null): bool
{
    $folder = json_decode(drive_request('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($p['drive_id']) . '?fields=parents&supportsAllDrives=true')[1], true)['parents'][0] ?? null;
    $name = preg_replace('/\.pdf$/i', '', $p['file_name']) . $suffix;
    $file = $folder ? drive_put($signed, 'application/pdf', $name, $folder, $error) : null;
    if (!$file) {
        $error = $error ?: 'No se pudo guardar la copia firmada en Drive.';

        return false;
    }
    q('UPDATE payslips SET signed_at = NOW(), sign_status = ?, sign_comment = ?, sign_ip = ?, sign_agent = ?, signed_drive_id = ? WHERE id = ? AND signed_at IS NULL', [
        $agree ? 'conforme' : 'disconforme', $comment ?: null, $_SERVER['REMOTE_ADDR'] ?? null,
        mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $file['id'], $p['id'],
    ]);

    return true;
}
