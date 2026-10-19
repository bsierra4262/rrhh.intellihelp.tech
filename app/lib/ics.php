<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Generación de eventos y calendarios iCalendar (RFC 5545) y enlaces a Google Calendar.
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
        'DTSTART;VALUE=DATE:' . str_replace('-', '', $ev['start']),
        'DTEND;VALUE=DATE:' . date('Ymd', strtotime($ev['end'] . ' +1 day')),
        'SUMMARY:' . ics_escape($ev['summary']),
        'DESCRIPTION:' . ics_escape($ev['description'] ?? ''),
        'STATUS:' . ($ev['status'] ?? 'CONFIRMED'),
        'SEQUENCE:' . ($ev['sequence'] ?? 0),
        'TRANSP:TRANSPARENT',
    ];
    if (!empty($ev['url'])) {
        $l[] = 'URL:' . $ev['url'];
    }
    if (!empty($ev['organizer'])) {
        $l[] = 'ORGANIZER;CN=' . ics_escape($ev['organizer'][1]) . ':mailto:' . $ev['organizer'][0];
    }
    foreach ($ev['attendees'] ?? [] as [$mail, $name]) {
        $l[] = 'ATTENDEE;CN=' . ics_escape($name) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:' . $mail;
    }
    $l[] = 'END:VEVENT';

    return $l;
}

/**
 * Documento iCalendar completo.
 */
function ics_calendar(array $events, string $method = 'PUBLISH', string $name = 'RRHH Intellihelp'): string
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
        'summary' => $r['type_name'] . ' — ' . $r['user_name'],
        'description' => fdays($r['days']) . ' · ' . (STATUS_LABELS[$r['status']] ?? '') . "\n" . url('/solicitudes/' . $r['id']),
        'url' => url('/solicitudes/' . $r['id']),
        'status' => $status,
        'sequence' => $sequence,
    ];
}

/**
 * Enlace "Agregar a Google Calendar" para un evento de día completo.
 */
function gcal_link(string $title, string $from, string $to, string $details = ''): string
{
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE',
        'text' => $title,
        'dates' => str_replace('-', '', $from) . '/' . date('Ymd', strtotime($to . ' +1 day')),
        'details' => $details,
        'ctz' => config('timezone'),
    ]);
}
