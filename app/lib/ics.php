<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Generación de eventos, invitaciones y calendarios iCalendar (RFC 5545) para Google, Outlook, Teams y Apple.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Escapa texto para un valor iCalendar.
 */
function ics_escape(string $s): string
{
    return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $s);
}

/**
 * Pliega líneas iCalendar a 75 octetos sin cortar caracteres UTF-8.
 */
function ics_fold(string $line): string
{
    $out = '';
    while (strlen($line) > 74) {
        $cut = 74;
        // Retrocede hasta un límite de carácter para no partir secuencias UTF-8.
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }

    return $out . $line;
}

/**
 * Líneas de un evento de día completo.
 *
 * @param array{uid: string, start: string, end: string, summary: string, description?: string, url?: string,
 *     status?: string, sequence?: int, organizer?: array{0: string, 1: string}, attendees?: array<int, array{0: string, 1: string}>} $ev
 *     Las fechas van en formato Y-m-d; "end" es inclusive.
 * @return string[]
 */
function ics_event(array $ev): array
{
    $l = [
        'BEGIN:VEVENT',
        'UID:' . $ev['uid'],
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        ...(!empty($ev['start_time'])
            ? [
                'DTSTART;TZID=' . config('timezone') . ':' . str_replace('-', '', $ev['start']) . 'T' . str_replace(':', '', substr($ev['start_time'], 0, 5)) . '00',
                'DTEND;TZID=' . config('timezone') . ':' . str_replace('-', '', $ev['start']) . 'T' . str_replace(':', '', substr($ev['end_time'], 0, 5)) . '00',
            ]
            : [
                'DTSTART;VALUE=DATE:' . str_replace('-', '', $ev['start']),
                'DTEND;VALUE=DATE:' . date('Ymd', strtotime($ev['end'] . ' +1 day')),
            ]),
        'SUMMARY:' . ics_escape($ev['summary']),
        'DESCRIPTION:' . ics_escape($ev['description'] ?? ''),
        'STATUS:' . ($ev['status'] ?? 'CONFIRMED'),
        'SEQUENCE:' . ($ev['sequence'] ?? 0),
        // Estado de disponibilidad: Outlook y Teams lo usan para mostrar "Fuera de la oficina" o "Provisional".
        'TRANSP:' . (($ev['busy'] ?? 'FREE') === 'FREE' ? 'TRANSPARENT' : 'OPAQUE'),
        'X-MICROSOFT-CDO-BUSYSTATUS:' . ($ev['busy'] ?? 'FREE'),
        'X-MICROSOFT-CDO-INTENDEDSTATUS:' . ($ev['busy'] ?? 'FREE'),
    ];
    if (!empty($ev['categories'])) {
        $l[] = 'CATEGORIES:' . ics_escape($ev['categories']);
    }
    if (!empty($ev['url'])) {
        $l[] = 'URL:' . $ev['url'];
    }
    if (!empty($ev['organizer'])) {
        $l[] = 'ORGANIZER;CN=' . ics_escape($ev['organizer'][1]) . ':mailto:' . $ev['organizer'][0];
    }
    foreach ($ev['attendees'] ?? [] as [$mail, $name]) {
        $l[] = 'ATTENDEE;CN=' . ics_escape($name) . ';ROLE=OPT-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:' . $mail;
    }
    $l[] = 'END:VEVENT';

    return $l;
}

/**
 * Documento iCalendar completo.
 */
function ics_calendar(array $events, string $method = 'PUBLISH', string $name = 'RRHH Intellihelp', ?string $color = null): string
{
    $l = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Intellihelp//Portal RRHH//ES',
        'CALSCALE:GREGORIAN',
        'METHOD:' . $method,
        'X-WR-CALNAME:' . ics_escape($name),
        'X-WR-TIMEZONE:' . config('timezone'),
    ];
    if ($method === 'PUBLISH') {
        // Frecuencia de actualización sugerida a los clientes suscriptos (Outlook la respeta).
        array_push($l, 'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H');
    }
    if ($color) {
        array_push($l, 'COLOR:' . $color, 'X-APPLE-CALENDAR-COLOR:' . $color);
    }
    foreach ($events as $ev) {
        array_push($l, ...ics_event($ev));
    }
    $l[] = 'END:VCALENDAR';

    return implode("\r\n", array_map('ics_fold', $l)) . "\r\n";
}

/**
 * Datos de evento iCalendar para una solicitud.
 */
function request_ics_event(array $r, string $status = 'CONFIRMED', int $sequence = 0): array
{
    return [
        'uid' => 'solicitud-' . $r['id'] . '@' . parse_url(config('app_url'), PHP_URL_HOST),
        'start' => $r['start_date'],
        'end' => $r['end_date'],
        'start_time' => $r['start_time'] ?? null,
        'end_time' => $r['end_time'] ?? null,
        'summary' => $r['type_name'] . ' — ' . $r['user_name'],
        'description' => fduration($r, false) . ' · ' . (STATUS_LABELS[$r['status']] ?? '') . "\n" . url('/solicitudes/' . $r['id']),
        'url' => url('/solicitudes/' . $r['id']),
        'status' => $status,
        'sequence' => $sequence,
    ];
}

/**
 * Invitación de calendario de una solicitud para enviar por correo (Google, Outlook, Teams y Apple).
 *
 * Todas las invitaciones de una solicitud comparten el UID y llevan la secuencia vigente de la
 * solicitud, por lo que cada envío actualiza el mismo evento en el calendario del destinatario.
 * Las pendientes van como provisionales y marcadas como tales; las autorizadas, confirmadas, y en
 * el calendario del propio colaborador figuran como "Fuera de la oficina". Con $cancel el evento
 * se elimina del calendario.
 *
 * @param array{email: string, name: string, id: int|string} $to Destinatario.
 */
function request_invite(array $r, array $to, bool $cancel = false): string
{
    $pending = $r['status'] === 'pending';
    $own = (int) $to['id'] === (int) $r['user_id'];
    $ev = request_ics_event($r, $cancel ? 'CANCELLED' : ($pending ? 'TENTATIVE' : 'CONFIRMED'), (int) ($r['cal_seq'] ?? 0));
    if ($pending) {
        $ev['summary'] = '[Pendiente] ' . $ev['summary'];
    }
    $ev['busy'] = $cancel ? 'FREE' : ($pending ? 'TENTATIVE' : ($own ? 'OOF' : 'FREE'));
    $ev['categories'] = $pending ? 'Licencia pendiente' : 'Licencia autorizada';
    $ev['organizer'] = [setting('mail_from', 'rrhh@intellihelp.tech'), setting('mail_from_name', 'RRHH Intellihelp')];
    $ev['attendees'] = [[$to['email'], $to['name']]];

    return ics_calendar([$ev], $cancel ? 'CANCEL' : 'REQUEST');
}

/**
 * Enlaces para suscribirse a un calendario publicado desde cada servicio.
 *
 * @return array<string, string> Servicio => enlace.
 */
function calendar_subscribe_links(string $feedUrl, string $name): array
{
    $webcal = preg_replace('#^https?://#', 'webcal://', $feedUrl);

    return [
        'Google Calendar' => 'https://calendar.google.com/calendar/r?cid=' . rawurlencode($webcal),
        'Outlook · Microsoft 365 y Teams' => 'https://outlook.office.com/calendar/0/addfromweb?' . http_build_query(['url' => $feedUrl, 'name' => $name]),
        'Outlook.com' => 'https://outlook.live.com/calendar/0/addfromweb?' . http_build_query(['url' => $feedUrl, 'name' => $name]),
        'Apple y otros' => $webcal,
    ];
}
