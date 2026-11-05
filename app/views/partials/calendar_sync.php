<?php
/**
 * Suscripción a los calendarios publicados (Google, Outlook, Teams y Apple).
 *
 * @var array{approved: string, pending: string} $feeds
 * @var bool $team Indica si el calendario incluye al equipo.
 */
$calendars = [
    ['key' => 'approved', 'name' => 'Licencias autorizadas · Intellihelp', 'label' => 'Licencias autorizadas', 'dot' => '#663399',
        'hint' => 'Vacaciones y licencias autorizadas o registradas.'],
    ['key' => 'pending', 'name' => 'Licencias pendientes · Intellihelp', 'label' => 'Pendientes de autorización', 'dot' => '#E0A100',
        'hint' => 'Solicitudes que todavía esperan la decisión de quien autoriza.'],
];
?>
<div class="cal-sync">
  <p class="muted">Agregá tus calendarios de licencias<?= !empty($team) ? ' (incluyen a tu equipo)' : '' ?> a Google Calendar, Outlook, Teams o Apple. Se actualizan solos: lo que se autoriza aparece, y lo cancelado o rechazado se quita. Elegí un color distinto para cada uno.</p>
  <?php foreach ($calendars as $c): $feed = $feeds[$c['key']]; ?>
    <div class="cal-sync-item">
      <div class="cal-sync-head"><span class="cal-dot" style="background:<?= e($c['dot']) ?>"></span><b><?= e($c['label']) ?></b><small><?= e($c['hint']) ?></small></div>
      <div class="cal-sync-links">
        <?php foreach (calendar_subscribe_links($feed, $c['name']) as $service => $href): ?>
          <a class="btn btn-ghost btn-sm" href="<?= e($href) ?>" target="_blank" rel="noopener"><?= icon('calendar', 15) ?> <?= e($service) ?></a>
        <?php endforeach ?>
      </div>
      <div class="copy-field"><input readonly value="<?= e($feed) ?>" id="feed-<?= e($c['key']) ?>" aria-label="Enlace del calendario"><button class="btn btn-ghost btn-sm" data-copy="#feed-<?= e($c['key']) ?>"><?= icon('link', 16) ?> Copiar</button></div>
    </div>
  <?php endforeach ?>
  <details class="cal-sync-help">
    <summary>¿Cómo funciona?</summary>
    <ul class="howto">
      <li><b>Por correo:</b> cada aviso de una solicitud trae la invitación de calendario. Al autorizarse se actualiza, y si se cancela o rechaza se quita sola de Google Calendar y de Outlook. En Outlook y Teams tus licencias autorizadas figuran como <b>Fuera de la oficina</b>.</li>
      <li><b>Por suscripción:</b> con los botones de arriba. Google Calendar actualiza estos calendarios cada algunas horas; Outlook, en general, cada hora.</li>
      <li><b>Teams</b> muestra el calendario de Outlook de tu cuenta de Microsoft 365.</li>
      <li><b>Manual:</b> en Google Calendar, <i>Otros calendarios → Desde URL</i>; en Outlook, <i>Agregar calendario → Suscribirse desde la web</i>. Pegá el enlace copiado.</li>
    </ul>
  </details>
  <small class="muted">Estos enlaces son personales: no los compartas.</small>
</div>
