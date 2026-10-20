<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Envío de correo (PHPMailer) y plantilla HTML institucional.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envía un correo HTML.
 *
 * Si se incluye $ics (METHOD:REQUEST), Gmail y Google Calendar ofrecen agregar el evento.
 *
 * @param array<int, array{name: string, content: string, type?: string}> $attachments
 */
function send_mail(string|array $to, string $subject, string $html, array $attachments = [], ?string $ics = null, ?string &$error = null): bool
{
    $to = array_filter((array) $to);
    if (!$to) {
        return false;
    }
    $driver = setting('mail_driver', 'mail');
    if ($driver === 'off') {
        return false;
    }

    if (!class_exists(PHPMailer::class)) {
        $error = 'PHPMailer no está instalado (composer install).';
        log_line('mail.log', $error);

        return false;
    }

    $m = new PHPMailer(true);
    try {
        $m->CharSet = 'UTF-8';
        $m->Encoding = 'base64';
        if ($driver === 'smtp') {
            $m->isSMTP();
            $m->Host = setting('smtp_host');
            $m->Port = (int) setting('smtp_port', '587');
            $m->SMTPAuth = setting('smtp_user') !== '';
            $m->Username = setting('smtp_user');
            $m->Password = setting('smtp_pass');
            $sec = setting('smtp_secure', 'tls');
            $m->SMTPSecure = $sec === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($sec === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
            $m->SMTPAutoTLS = $sec !== 'none';
            $m->Timeout = 15;
        } else {
            $m->isMail();
        }
        $m->setFrom(setting('mail_from', 'rrhh@intellihelp.tech'), setting('mail_from_name', 'RRHH Intellihelp'));
        foreach ($to as $addr) {
            $m->addAddress($addr);
        }
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body = $html;
        $m->AltBody = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/tr>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        if ($ics) {
            // Parte text/calendar (METHOD:REQUEST): Gmail ofrece agregar el evento al calendario.
            $m->Ical = $ics;
            $m->addStringAttachment($ics, 'evento.ics', PHPMailer::ENCODING_BASE64, 'application/ics');
        }
        foreach ($attachments as $a) {
            $m->addStringAttachment($a['content'], $a['name'], PHPMailer::ENCODING_BASE64, $a['type'] ?? 'application/octet-stream');
        }
        $m->send();
        log_line('mail.log', 'OK ' . implode(',', $to) . ' — ' . $subject);

        return true;
    } catch (Throwable $ex) {
        $error = $m->ErrorInfo ?: $ex->getMessage();
        log_line('mail.log', 'ERROR ' . implode(',', $to) . ' — ' . $subject . ' — ' . $error);

        return false;
    }
}

/**
 * Plantilla HTML de correo con la identidad visual de Intellihelp.
 *
 * @param array<int, array{0: string, 1: string}> $rows Filas de detalle (etiqueta, valor HTML).
 * @param array{0: string, 1: string}|null $button Texto y URL del botón principal.
 */
function mail_template(string $heading, string $intro, array $rows = [], ?array $button = null, ?string $footerHtml = null, string $accent = '#663399'): string
{
    $logo = url('/assets/img/logo-ith.png');
    $tr = '';
    foreach ($rows as [$k, $v]) {
        $tr .= '<tr><td style="padding:8px 0;color:#6b6f7b;font-size:13px;width:38%;vertical-align:top">' . e($k) . '</td>'
            . '<td style="padding:8px 0;color:#161922;font-size:14px;font-weight:600">' . $v . '</td></tr>';
    }
    $btn = '';
    if ($button) {
        $btn = '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:28px 0 8px"><tr><td style="border-radius:10px;background:' . $accent . '">'
            . '<a href="' . e($button[1]) . '" style="display:inline-block;padding:13px 26px;font-family:Saira,Segoe UI,Arial,sans-serif;font-size:15px;font-weight:600;color:#fff;text-decoration:none;border-radius:10px">' . e($button[0]) . '</a></td></tr></table>';
    }

    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
        . '<body style="margin:0;padding:0;background:#fff6ff;font-family:Saira,Segoe UI,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fff6ff;padding:32px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #ebe2ef">'
        . '<tr><td style="background:#663399;padding:20px 28px"><img src="' . $logo . '" alt="ITH Intellihelp" height="36" style="display:block;height:36px"></td></tr>'
        . '<tr><td style="padding:28px 28px 8px"><h1 style="margin:0 0 10px;font-size:21px;line-height:1.3;color:#161922">' . e($heading) . '</h1>'
        . '<p style="margin:0 0 18px;font-size:15px;line-height:1.55;color:#3d4150">' . $intro . '</p>'
        . ($tr ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #eee;border-bottom:1px solid #eee;margin:4px 0">' . $tr . '</table>' : '')
        . $btn . ($footerHtml ? '<p style="margin:16px 0 0;font-size:13px;color:#6b6f7b;line-height:1.5">' . $footerHtml . '</p>' : '')
        . '</td></tr><tr><td style="padding:22px 28px 26px;font-size:12px;color:#9a9daa;border-top:1px solid #f5eef7;margin-top:16px">'
        . 'Portal de Recursos Humanos · Intellihelp<br>Este es un mensaje automático, no respondas a este correo.<br>'
        . '¿Problemas con la plataforma? Escribí a <a href="mailto:' . e(support_email()) . '" style="color:#663399">' . e(support_email()) . '</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}
