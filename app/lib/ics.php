<?php
declare(strict_types=1);

function ics_escape(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
}

function ics_fold(string $line): string
{
    $out = '';
    while (strlen($line) > 74) {
        $cut = 74;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--; // no cortar UTF-8
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line;
}

/**
 * Evento de día completo. $ev: uid, start (Y-m-d), end (Y-m-d inclusive), summary, description, url,
 * opcional: status (CONFIRMED|TENTATIVE|CANCELLED), sequence, organizer [email,name], attendees [[email,name]].
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
    if (!empty($ev['url'])) $l[] = 'URL:' . $ev['url'];
    if (!empty($ev['organizer'])) {
        $l[] = 'ORGANIZER;CN=' . ics_escape($ev['organizer'][1]) . ':mailto:' . $ev['organizer'][0];
    }
    foreach ($ev['attendees'] ?? [] as [$mail, $name]) {
        $l[] = 'ATTENDEE;CN=' . ics_escape($name) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:' . $mail;
    }
    $l[] = 'END:VEVENT';
    return $l;
}

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

/** Link "Agregar a Google Calendar" (evento de día completo). */
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
