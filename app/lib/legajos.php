<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Legajos electrónicos y carpetas de RRHH en Google Drive.
 *
 * Respeta la estructura existente de la carpeta de RRHH:
 * - LEGAJOS ELECTRONICOS / <grupo: 2. ITH, 2. STRIX/LOJACK, 3. OSDIPP…> / <NOMBRE APELLIDO>, y
 *   «1. DESAFECTADOS» para quienes ya no trabajan en la empresa.
 * - LIQUIDACIONES / <AAAA> / <MM - Mes AAAA> para lo que se liquida cada mes.
 * - NOVEDADES / <AAAA> / NOVEDADES INTERNAS / VACACIONES para el reporte mensual de vacaciones.
 *
 * El portal vincula a cada persona con su legajo, controla la documentación de alta, crea la
 * carpeta de quien ingresa y, al egreso, la pasa a «1. DESAFECTADOS» y deja en LIQUIDACIONES el
 * detalle de las vacaciones a liquidar.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

const LEGAJO_ARCHIVE = '1. DESAFECTADOS';

/**
 * Documentación de alta de cada legajo (planilla «Documentos Alta - Empleado») y las palabras
 * con que se reconoce cada documento en los nombres de archivo.
 */
const LEGAJO_DOCS = [
    'Alta en ARCA (ex AFIP)' => ['ALTA ARCA', 'ALTA AFIP', 'ALTA TEMPRANA', 'ALTA-EMPRESA'],
    'Carta de oferta' => ['CARTA DE OFERTA'],
    'Código de ética' => ['CODIGO DE ETICA'],
    'CV' => ['CV', 'CURRICULUM'],
    'Declaración jurada de domicilio' => ['DECLARACION JURADA'],
    'DNI frente y dorso' => ['DNI'],
    'Entrega de equipos' => ['ENTREGA DE EQUIPO'],
    'Ficha de datos personales' => ['FICHA DE DATOS'],
    'Ficha de ingreso' => ['FICHA DE INGRESO'],
    'Filosofía y políticas de RRHH' => ['FILOSOFIA'],
    'Normas de trabajo' => ['NORMAS DE TRABAJO'],
    'Política de ausencias' => ['POLITICA DE AUSENCIA', 'POLITICAS DE AUSENCIA'],
    'Examen preocupacional' => ['PREOCUPACIONAL', 'PREOCUAPCIONAL'],
    'Reserva y confidencialidad' => ['CONFIDENCIALIDAD'],
    'Situación previsional' => ['SITUACION PREVISIONAL'],
];

/**
 * Texto en mayúsculas y sin acentos, para comparar nombres de carpetas y archivos.
 */
function legajo_norm(string $s): string
{
    $s = strtr(mb_strtoupper(trim($s)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

    return preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9 ]/', ' ', $s));
}

/**
 * Indica si la integración con los legajos está configurada.
 */
function legajos_enabled(): bool
{
    return setting('drive_legajos_folder') !== '' && setting('gcal_sa_json') !== '';
}

/**
 * Carpetas de grupo dentro de LEGAJOS ELECTRONICOS (empresa o cliente), por nombre.
 *
 * @return array<string, string> Nombre => ID.
 */
function legajo_groups(bool $withArchive = false): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (legajos_enabled()) {
            // Los grupos son las carpetas numeradas («1. DESAFECTADOS», «2. ITH»…); el resto no son legajos.
            foreach (drive_list(setting('drive_legajos_folder'), "mimeType = '" . DRIVE_FOLDER_MIME . "'") as $f) {
                if (preg_match('/^\d+\s*\./', $f['name'])) {
                    $cache[$f['name']] = $f['id'];
                }
            }
            ksort($cache, SORT_NATURAL);
        }
    }

    return $withArchive ? $cache : array_diff_key($cache, [LEGAJO_ARCHIVE => true]);
}

/**
 * Indica si el nombre de una carpeta corresponde a la persona: todas las palabras de uno están
 * en el otro (por ejemplo «JONATHAN ALEJANDRO AYALA» y «Alejandro Ayala»).
 */
function legajo_name_match(string $folder, string $person): bool
{
    $a = array_unique(explode(' ', legajo_norm($folder)));
    $b = array_unique(explode(' ', legajo_norm($person)));
    $common = count(array_intersect($a, $b));

    return $common >= 2 && ($common === count($a) || $common === count($b));
}

/**
 * Busca la carpeta del legajo de una persona en los grupos (y en desafectados).
 *
 * @return array{id: string, name: string, group: string}|null
 */
function legajo_find(array $user): ?array
{
    foreach (legajo_groups(true) as $group => $gid) {
        foreach (drive_list($gid, "mimeType = '" . DRIVE_FOLDER_MIME . "'") as $f) {
            if (legajo_name_match($f['name'], $user['name'])) {
                return ['id' => $f['id'], 'name' => $f['name'], 'group' => $group];
            }
        }
    }

    return null;
}

/**
 * Legajo vinculado a la persona; si todavía no lo está, lo busca y guarda el vínculo.
 *
 * @return array{id: string, name: string, group: string, url: string}|null
 */
function legajo_of(array $user): ?array
{
    if (!legajos_enabled()) {
        return null;
    }
    $found = null;
    if (!empty($user['legajo_folder_id'])) {
        [$code, $body] = drive_request('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($user['legajo_folder_id'])
            . '?fields=id,name,parents,trashed&supportsAllDrives=true');
        $f = $code === 200 ? json_decode($body, true) : null;
        if ($f && empty($f['trashed'])) {
            $group = array_search($f['parents'][0] ?? '', legajo_groups(true), true);
            $found = ['id' => $f['id'], 'name' => $f['name'], 'group' => $group ?: ''];
        }
    }
    if (!$found) {
        $found = legajo_find($user);
    }
    if (!$found) {
        return null;
    }
    $sync = ['legajo_folder_id' => $found['id']];
    if ($found['group'] !== '' && $found['group'] !== LEGAJO_ARCHIVE) {
        $sync['legajo_group'] = $found['group'];
    }
    if (($user['legajo_folder_id'] ?? null) !== $found['id'] || (isset($sync['legajo_group']) && ($user['legajo_group'] ?? null) !== $sync['legajo_group'])) {
        update('users', (int) $user['id'], $sync);
    }

    return $found + ['url' => 'https://drive.google.com/drive/folders/' . $found['id']];
}

/**
 * Nombres de todos los archivos de una carpeta y sus subcarpetas (hasta tres niveles).
 *
 * @return string[]
 */
function drive_tree_names(string $folderId, int $depth = 3): array
{
    $names = [];
    foreach (drive_list($folderId) as $f) {
        $names[] = $f['name'];
    }
    if ($depth > 1) {
        foreach (drive_list($folderId, "mimeType = '" . DRIVE_FOLDER_MIME . "'") as $sub) {
            array_push($names, ...drive_tree_names($sub['id'], $depth - 1));
        }
    }

    return $names;
}

/**
 * Control de la documentación de alta del legajo.
 *
 * @return array<string, bool> Documento => si está en el legajo.
 */
function legajo_checklist(string $folderId): array
{
    $files = array_map(fn ($n) => ' ' . legajo_norm(pathinfo($n, PATHINFO_FILENAME)) . ' ', drive_tree_names($folderId));
    $out = [];
    foreach (LEGAJO_DOCS as $doc => $keys) {
        $out[$doc] = false;
        foreach ($keys as $k) {
            foreach ($files as $f) {
                if (str_contains($f, ' ' . $k . ' ') || str_contains($f, ' ' . $k)) {
                    $out[$doc] = true;
                    break 2;
                }
            }
        }
    }

    return $out;
}

/**
 * Crea la carpeta del legajo de quien ingresa, en el grupo elegido y con el nombre en mayúsculas.
 */
function legajo_create(array $user, ?string &$error = null): ?string
{
    $gid = legajo_groups()[$user['legajo_group'] ?? ''] ?? null;
    if (!$gid) {
        $error = 'Elegí el grupo del legajo (empresa o cliente).';

        return null;
    }
    $id = drive_folder(mb_strtoupper(trim($user['name'])), $gid, $error);
    if ($id) {
        update('users', (int) $user['id'], ['legajo_folder_id' => $id]);
    }

    return $id;
}

/**
 * Mueve una carpeta de Drive a otra carpeta.
 */
function drive_move(string $fileId, string $toFolder, ?string &$error = null): bool
{
    [$code, $body] = drive_request('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?fields=parents&supportsAllDrives=true', null, [], $error);
    $parents = $code === 200 ? (json_decode($body, true)['parents'] ?? []) : [];
    [$code] = drive_request('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?' . http_build_query([
        'addParents' => $toFolder,
        'removeParents' => implode(',', $parents),
        'supportsAllDrives' => 'true',
    ]), '{}', ['Content-Type: application/json'], $error);

    return $code === 200;
}

/**
 * Carpeta del mes en LIQUIDACIONES (por ejemplo «2026 / 11 - Noviembre 2026»), creándola si falta.
 */
function liquidaciones_month_folder(string $date, ?string &$error = null): ?string
{
    $root = setting('drive_liquidaciones_folder');
    if ($root === '') {
        return null;
    }
    $y = substr($date, 0, 4);
    $m = (int) substr($date, 5, 2);
    $yearId = drive_folder($y, $root, $error);

    return $yearId ? drive_folder(sprintf('%02d - %s %s', $m, ucfirst(MESES[$m]), $y), $yearId, $error) : null;
}

/**
 * Carpeta NOVEDADES / AAAA / NOVEDADES INTERNAS / VACACIONES de la carpeta de RRHH.
 */
function novedades_vacaciones_folder(int $year, ?string &$error = null): ?string
{
    $rrhh = setting('drive_rrhh_folder');
    if ($rrhh === '') {
        return null;
    }
    $nov = drive_list($rrhh, "name = 'NOVEDADES' and mimeType = '" . DRIVE_FOLDER_MIME . "'")[0]['id'] ?? null;
    $y = $nov ? drive_folder((string) $year, $nov, $error) : null;
    $int = $y ? drive_folder('NOVEDADES INTERNAS', $y, $error) : null;

    return $int ? drive_folder('VACACIONES', $int, $error) : null;
}

/**
 * Sube un contenido en memoria a una carpeta de Drive, reemplazando un archivo con el mismo nombre.
 */
function drive_put(string $content, string $mime, string $name, string $folderId, ?string &$error = null): ?array
{
    foreach (drive_list($folderId, 'name = ' . drive_quote($name)) as $old) {
        drive_delete($old['id']);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'rrhh');
    file_put_contents($tmp, $content);
    $res = drive_upload($tmp, $mime, $name, $folderId, $error);
    @unlink($tmp);

    return $res;
}

/**
 * Planilla con el detalle de las vacaciones a liquidar por el egreso de una persona.
 */
function build_exit_settlement_xlsx(array $user, array $s): string
{
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sh = $book->getActiveSheet();
    $sh->setTitle('Vacaciones a liquidar');
    $rows = [
        ['Vacaciones no gozadas a liquidar por egreso (LCT arts. 156 y 162)'],
        [],
        ['Nombre y apellido', $user['name']],
        ['Fecha de ingreso', $user['hire_date'] ? fdate($user['hire_date']) : 'Sin cargar'],
        ['Fecha de egreso', fdate($user['exit_date'])],
        ['Convenio', convenio_label($user['cct'] ?? null)],
        [],
        ['Concepto', 'Días'],
        ['Vacaciones del año ' . $s['year'] . ' según antigüedad', $s['entitled']],
        ['Proporcional al tiempo trabajado en ' . $s['year'], $s['proportional']],
        ['Acumulados de ' . ($s['year'] - 1) . ' por acuerdo', $s['carried']],
        ['Ya gozados en ' . $s['year'], -$s['used']],
        ['No gozados de ' . ($s['year'] - 1), $s['previous']],
        ['Total a liquidar', $s['total']],
        [],
        ['Generado por el Portal de RRHH el ' . date('d/m/Y H:i') . '. Confirmar el importe con quien liquida los sueldos.'],
    ];
    $sh->fromArray($rows, null, 'A1');
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $sh->getStyle('A8:B8')->getFont()->setBold(true);
    $sh->getStyle('A14:B14')->getFont()->setBold(true);
    $sh->getColumnDimension('A')->setWidth(52);
    $sh->getColumnDimension('B')->setWidth(18);
    $sh->getStyle('B9:B14')->getNumberFormat()->setFormatCode('0.00');
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($tmp);
    $out = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $out;
}

/**
 * Egresos: al llegar la fecha de egreso se desactiva la cuenta, el legajo pasa a «1. DESAFECTADOS»
 * y queda en LIQUIDACIONES el detalle de las vacaciones a liquidar. Se avisa a RRHH.
 *
 * @return string[] Novedades para el registro de la tarea programada.
 */
function exit_job(): array
{
    $out = [];
    foreach (rows('SELECT * FROM users WHERE exit_date IS NOT NULL AND exit_date <= CURDATE() AND exit_processed_at IS NULL') as $u) {
        $notes = [];
        q('UPDATE users SET active = 0, exit_processed_at = NOW() WHERE id = ?', [$u['id']]);
        $notes[] = 'Cuenta desactivada.';
        if (legajos_enabled()) {
            $err = null;
            $leg = legajo_of($u);
            $archive = legajo_groups(true)[LEGAJO_ARCHIVE] ?? null;
            if ($leg && $archive && $leg['group'] !== LEGAJO_ARCHIVE) {
                $notes[] = drive_move($leg['id'], $archive, $err)
                    ? 'Legajo movido de «' . $leg['group'] . '» a «' . LEGAJO_ARCHIVE . '».'
                    : 'No se pudo mover el legajo: ' . $err;
            } elseif (!$leg) {
                $notes[] = 'No se encontró su legajo electrónico.';
            }
            $s = vacation_exit_settlement($u);
            $folder = $s ? liquidaciones_month_folder($u['exit_date'], $err) : null;
            if ($folder) {
                $name = 'Vacaciones a liquidar - ' . mb_strtoupper($u['name']) . ' - egreso ' . date('d-m-Y', strtotime($u['exit_date'])) . '.xlsx';
                $notes[] = drive_put(build_exit_settlement_xlsx($u, $s), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $name, $folder, $err)
                    ? 'Detalle de vacaciones a liquidar (' . fdays($s['total']) . ') guardado en LIQUIDACIONES.'
                    : 'No se pudo guardar el detalle en LIQUIDACIONES: ' . $err;
            }
        }
        log_line('legajos.log', $u['name'] . ': ' . implode(' ', $notes));
        foreach (hr_users() as $h) {
            insert('notifications', ['user_id' => $h['id'], 'title' => 'Egreso de ' . $u['name'], 'body' => mb_substr(implode(' ', $notes), 0, 500), 'link' => '/admin/usuarios/' . $u['id']]);
        }
        $out[] = $u['name'] . ': ' . implode(' ', $notes);
    }

    return $out;
}
