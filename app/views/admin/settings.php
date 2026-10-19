<div class="page-head">
  <div><h1>Configuración</h1><p class="muted">Canales de notificación del portal.</p></div>
</div>

<form method="post" action="<?= url('/admin/configuracion') ?>" class="stack">
  <?= csrf_field() ?>
  <section class="card">
    <div class="card-head"><h2><?= icon('settings') ?> General</h2></div>
    <div class="form-grid">
      <label class="field"><span>Nombre de la empresa</span><input name="company_name" value="<?= e($s['company_name']) ?>"></label>
      <label class="field"><span>Correo de soporte de la plataforma</span><input type="email" name="support_email" value="<?= e($s['support_email'] ?: 'soporte@intellihelp.tech') ?>"><small class="muted">Se muestra en el ingreso, en los errores y en el pie de los emails.</small></label>
      <label class="field"><span>Casilla de RRHH (recibe copia de todo)</span><input type="email" name="hr_email" value="<?= e($s['hr_email']) ?>" placeholder="rrhh@intellihelp.tech"></label>
      <label class="field"><span>Aprobador por defecto</span>
        <select name="default_approver_id">
          <option value="0">— Toda la bandeja de RRHH —</option>
          <?php foreach ($approvers as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (string) $s['default_approver_id'] === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?><?= $a['position'] ? ' — ' . e($a['position']) : '' ?></option><?php endforeach ?>
        </select>
        <small class="muted">Autoriza las solicitudes de quienes no tienen responsable directo asignado.</small>
      </label>
      <label class="field"><span>Recordar al aprobador cada (horas)</span><input type="number" min="0" name="reminder_hours" value="<?= e($s['reminder_hours']) ?>"><small class="muted">0 = sin recordatorios. Requiere la tarea programada (cron).</small></label>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= icon('users') ?> Registro de colaboradores</h2></div>
    <div class="form-grid">
      <label class="switch span-2"><input type="checkbox" name="signup_enabled" <?= $s['signup_enabled'] === '1' ? 'checked' : '' ?>><span></span> Permitir que los colaboradores creen su cuenta desde la pantalla de ingreso</label>
      <label class="field span-2"><span>Dominios de confianza (se aprueban solos)</span><input name="signup_domain" value="<?= e($s['signup_domain'] ?: 'intellihelp.tech') ?>" placeholder="intellihelp.tech, intellihelp.com.ar"><small class="muted">Separados por coma. Cualquier otro correo (personal tercerizado) puede registrarse, pero queda pendiente hasta que RRHH apruebe el acceso. Todos los correos se validan con un enlace.</small></label>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= icon('mail') ?> Email</h2></div>
    <div class="form-grid">
      <label class="field span-2"><span>Avisos de solicitudes por email</span>
        <select name="event_email">
          <option value="off" <?= $s['event_email'] === 'off' ? 'selected' : '' ?>>Desactivados (se avisa por Google Chat, Google Calendar y en el portal)</option>
          <option value="employees" <?= $s['event_email'] === 'employees' ? 'selected' : '' ?>>Solo al colaborador/a (resultado de su solicitud)</option>
          <option value="all" <?= $s['event_email'] === 'all' ? 'selected' : '' ?>>A todos (colaborador/a, aprobadores y casilla de RRHH)</option>
        </select>
        <small class="muted">Los correos de alta de cuenta y recuperación de contraseña se envían siempre.</small>
      </label>
      <label class="field"><span>Envío</span>
        <select name="mail_driver" data-show-when>
          <option value="smtp" <?= $s['mail_driver'] === 'smtp' ? 'selected' : '' ?>>SMTP (Google Workspace / Hostinger) — recomendado</option>
          <option value="mail" <?= $s['mail_driver'] === 'mail' ? 'selected' : '' ?>>PHP mail() del servidor</option>
          <option value="off" <?= $s['mail_driver'] === 'off' ? 'selected' : '' ?>>Desactivado</option>
        </select>
      </label>
      <label class="field"><span>Remitente (email)</span><input type="email" name="mail_from" value="<?= e($s['mail_from']) ?>"></label>
      <label class="field"><span>Remitente (nombre)</span><input name="mail_from_name" value="<?= e($s['mail_from_name']) ?>"></label>
      <label class="field"><span>Servidor SMTP</span><input name="smtp_host" value="<?= e($s['smtp_host']) ?>" placeholder="smtp.gmail.com"></label>
      <label class="field"><span>Puerto</span><input type="number" name="smtp_port" value="<?= e($s['smtp_port']) ?>"></label>
      <label class="field"><span>Seguridad</span>
        <select name="smtp_secure">
          <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'Ninguna'] as $k => $l): ?><option value="<?= $k ?>" <?= $s['smtp_secure'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?>
        </select>
      </label>
      <label class="field"><span>Usuario SMTP</span><input name="smtp_user" value="<?= e($s['smtp_user']) ?>" autocomplete="off"></label>
      <label class="field"><span>Contraseña SMTP</span><input type="password" name="smtp_pass" value="<?= e($s['smtp_pass']) ?>" autocomplete="new-password"><small class="muted">Con Google Workspace usá una <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">contraseña de aplicación</a>.</small></label>
    </div>
  </section>

  <details class="card">
    <summary class="card-head" style="cursor:pointer;margin:0"><h2><?= icon('whatsapp') ?> WhatsApp <small class="muted">(<?= $s['wa_driver'] === 'off' ? 'desactivado' : 'activo' ?>)</small></h2></summary>
    <div style="margin-top:14px">
    <div class="form-grid">
      <label class="field span-2"><span>Proveedor</span>
        <select name="wa_driver">
          <option value="off" <?= $s['wa_driver'] === 'off' ? 'selected' : '' ?>>Desactivado</option>
          <option value="callmebot" <?= $s['wa_driver'] === 'callmebot' ? 'selected' : '' ?>>CallMeBot (gratis — cada persona activa su apikey en Mi perfil)</option>
          <option value="evolution" <?= $s['wa_driver'] === 'evolution' ? 'selected' : '' ?>>Evolution API (gratis, servidor propio vinculado por QR)</option>
          <option value="waha" <?= $s['wa_driver'] === 'waha' ? 'selected' : '' ?>>WAHA (gratis, servidor propio vinculado por QR)</option>
          <option value="cloud" <?= $s['wa_driver'] === 'cloud' ? 'selected' : '' ?>>WhatsApp Cloud API de Meta (oficial)</option>
        </select>
      </label>
      <label class="field"><span>Gateway — URL</span><input name="wa_gw_url" value="<?= e($s['wa_gw_url']) ?>" placeholder="https://wa.intellihelp.tech"></label>
      <label class="field"><span>Gateway — API key</span><input type="password" name="wa_gw_key" value="<?= e($s['wa_gw_key']) ?>" autocomplete="new-password"></label>
      <label class="field"><span>Gateway — Instancia / sesión</span><input name="wa_gw_instance" value="<?= e($s['wa_gw_instance']) ?>" placeholder="rrhh"></label>
      <div></div>
      <label class="field"><span>Cloud API — Token de acceso</span><input type="password" name="wa_cloud_token" value="<?= e($s['wa_cloud_token']) ?>" autocomplete="new-password"></label>
      <label class="field"><span>Cloud API — Phone number ID</span><input name="wa_cloud_phone_id" value="<?= e($s['wa_cloud_phone_id']) ?>"></label>
      <label class="field"><span>Cloud API — Plantilla aprobada</span><input name="wa_cloud_template" value="<?= e($s['wa_cloud_template']) ?>" placeholder="aviso_rrhh"><small class="muted">Plantilla con un parámetro {{1}} para el texto. Sin plantilla solo llega si la persona escribió en las últimas 24 h.</small></label>
      <label class="field"><span>Cloud API — Idioma de la plantilla</span><input name="wa_cloud_lang" value="<?= e($s['wa_cloud_lang'] ?: 'es_AR') ?>"></label>
    </div>
  </div>
  </details>

  <section class="card">
    <div class="card-head"><h2><?= icon('calendar') ?> Google Calendar</h2><?= $s['gcal_calendar_id'] && $s['gcal_sa_json'] ? '<span class="badge badge-approved">Configurado</span>' : '' ?></div>
    <p class="muted" style="margin-bottom:14px">Cada solicitud aparece al instante en un calendario compartido: <b>amarillo</b> pendiente, <b>verde</b> aprobada, <b>violeta</b> licencia informada. Si se rechaza o cancela, se borra.</p>
    <div class="form-grid">
      <label class="field span-2"><span>ID del calendario</span><input name="gcal_calendar_id" value="<?= e($s['gcal_calendar_id']) ?>" placeholder="xxxxxxxx@group.calendar.google.com"><small class="muted">Google Calendar → Configuración del calendario → Integrar el calendario → ID del calendario.</small></label>
      <label class="field span-2"><span>Clave JSON de la cuenta de servicio</span><textarea name="gcal_sa_json" rows="4" spellcheck="false" placeholder='{"type": "service_account", "client_email": "...", "private_key": "..."}'><?= e($s['gcal_sa_json']) ?></textarea><small class="muted">Pegá el contenido completo del archivo .json descargado de Google Cloud. Se guarda cifrado.</small></label>
      <label class="field span-2"><span>Actuar en nombre de (delegación de dominio, opcional)</span><input type="email" name="gcal_impersonate" value="<?= e($s['gcal_impersonate']) ?>" placeholder="bsierra@intellihelp.tech"><small class="muted">Usalo si tu organización no permite compartir calendarios con cuentas externas: la cuenta de servicio escribe como este usuario, dueño del calendario. Requiere autorizar el Client ID en la Consola de administración → Seguridad → Controles de API → Delegación de todo el dominio.</small></label>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= icon('bell') ?> Google Chat</h2></div>
    <label class="field"><span>Webhook del espacio de RRHH</span><input type="password" name="gchat_webhook" value="<?= e($s['gchat_webhook']) ?>" autocomplete="off" placeholder="https://chat.googleapis.com/v1/spaces/…/messages?key=…">
      <small class="muted">Gratis con Google Workspace. En Google Chat: creá un espacio (ej. «RRHH – Aprobaciones»), sumá a quienes autorizan → Apps e integraciones → Webhooks → Agregar, y pegá la URL acá. Cada novedad llega como notificación al celular.</small>
    </label>
  </section>

  <details class="card">
    <summary class="card-head" style="cursor:pointer;margin:0"><h2><?= icon('key') ?> Cuenta para funciones con IA <small class="muted">(<?= $s['ai_smtp_user'] ? e($s['ai_smtp_user']) : 'sin configurar' ?>)</small></h2></summary>
    <div class="form-grid" style="margin-top:14px">
      <label class="field"><span>Correo</span><input type="email" name="ai_smtp_user" value="<?= e($s['ai_smtp_user']) ?>" placeholder="ithos@intellihelp.tech"></label>
      <label class="field"><span>Contraseña de aplicación</span><input type="password" name="ai_smtp_pass" value="<?= e($s['ai_smtp_pass']) ?>" autocomplete="new-password"></label>
    </div>
    <small class="muted">Reservada para futuras funciones con IA (asistente, resúmenes automáticos). Se guarda cifrada.</small>
  </details>

  <div class="form-foot end"><button class="btn btn-primary btn-lg"><?= icon('check', 18) ?> Guardar configuración</button></div>
</form>

<section class="card" id="gcal">
  <div class="card-head"><h2>Probar canales</h2></div>
  <?php if ($testEvent = setting('gcal_test_event_id')):
      $calLink = setting('gcal_test_event_link') ?: 'https://calendar.google.com/calendar/r/day/' . date('Y/n/j'); ?>
    <div class="gcal-test">
      <div class="gcal-test-steps">
        <div class="done"><span><?= icon('check', 14) ?></span><b>1. Evento creado</b><small>«✅ Evento de prueba — Portal RRHH» para hoy</small></div>
        <div class="now"><span>2</span><b>2. Verificalo en Google Calendar</b><small>Tiene que aparecer en verde en «Licencias Intellihelp»</small></div>
        <div><span>3</span><b>3. Finalizá el test</b><small>Se borra el evento del calendario</small></div>
      </div>
      <div class="btn-row">
        <a class="btn btn-ghost" href="<?= e($calLink) ?>" target="_blank" rel="noopener"><?= icon('calendar', 16) ?> Abrir Google Calendar</a>
        <form method="post" action="<?= url('/admin/configuracion/prueba') ?>">
          <?= csrf_field() ?><input type="hidden" name="channel" value="gcal_finish">
          <button class="btn btn-positive"><?= icon('check', 16) ?> Finalizar test</button>
        </form>
      </div>
    </div>
  <?php endif ?>
  <div class="btn-row wrap">
    <form method="post" action="<?= url('/admin/configuracion/prueba') ?>" class="btn-row">
      <?= csrf_field() ?><input type="hidden" name="channel" value="mail">
      <input name="to" type="email" placeholder="<?= e(current_user()['email']) ?>" class="input-sm">
      <button class="btn btn-ghost btn-sm"><?= icon('mail', 16) ?> Enviar email de prueba</button>
    </form>
    <form method="post" action="<?= url('/admin/configuracion/prueba') ?>">
      <?= csrf_field() ?><input type="hidden" name="channel" value="gcal">
      <button class="btn btn-ghost btn-sm"><?= icon('calendar', 16) ?> Probar Google Calendar</button>
    </form>
    <form method="post" action="<?= url('/admin/configuracion/prueba') ?>">
      <?= csrf_field() ?><input type="hidden" name="channel" value="gchat">
      <button class="btn btn-ghost btn-sm"><?= icon('bell', 16) ?> Probar Google Chat</button>
    </form>
    <?php if ($s['wa_driver'] !== 'off'): ?>
    <form method="post" action="<?= url('/admin/configuracion/prueba') ?>">
      <?= csrf_field() ?><input type="hidden" name="channel" value="whatsapp">
      <button class="btn btn-ghost btn-sm"><?= icon('whatsapp', 16) ?> Enviarme un WhatsApp de prueba</button>
    </form>
    <?php endif ?>
  </div>
</section>
