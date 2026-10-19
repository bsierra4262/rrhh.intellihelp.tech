<div class="page-head">
  <div class="person big"><?= avatar($u['name'], 'xl') ?><div><h1><?= e($u['name']) ?></h1><p class="muted"><?= e($u['email']) ?> · <?= e(ROLE_LABELS[$u['role']]) ?></p></div></div>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2><?= icon('user') ?> Mis datos</h2></div>
    <dl class="kv-list">
      <dt>Área</dt><dd><?= e($u['department'] ?: '—') ?></dd>
      <dt>Puesto</dt><dd><?= e($u['position'] ?: '—') ?></dd>
      <dt>Responsable</dt><dd><?= $manager ? e($manager['name']) : 'Recursos Humanos' ?></dd>
      <dt>Fecha de ingreso</dt><dd><?= e(fdate($u['hire_date'])) ?></dd>
    </dl>
    <small class="muted">Si algún dato está mal, avisale a RRHH.</small>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= icon('bell') ?> ¿Cómo querés que te avisemos?</h2></div>
    <form method="post" action="<?= url('/perfil') ?>" class="form">
      <?= csrf_field() ?>
      <label class="switch"><input type="checkbox" name="notify_email" <?= $u['notify_email'] ? 'checked' : '' ?>><span></span> <?= icon('mail', 16) ?> Email</label>
      <?php if ($wa_driver !== 'off'): ?>
      <label class="switch"><input type="checkbox" name="notify_whatsapp" <?= $u['notify_whatsapp'] ? 'checked' : '' ?>><span></span> <?= icon('whatsapp', 16) ?> WhatsApp</label>
      <?php else: ?><input type="hidden" name="notify_whatsapp" value="<?= $u['notify_whatsapp'] ? '1' : '' ?>"><?php endif ?>
      <label class="field"><span>Celular</span><input name="phone" value="<?= e($u['phone']) ?>" placeholder="+54 9 11 1234 5678" inputmode="tel"></label>
      <?php if ($wa_driver === 'callmebot'): ?>
        <label class="field"><span>Apikey de CallMeBot</span><input name="wa_apikey" value="<?= e($u['wa_apikey']) ?>" placeholder="123456"></label>
        <details class="help"><summary>¿Cómo obtengo la apikey?</summary>
          <ol>
            <li>Entrá a <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" rel="noopener">callmebot.com</a> y agendá el número que indican.</li>
            <li>Mandale por WhatsApp: <code>I allow callmebot to send me messages</code></li>
            <li>Te responde con tu apikey: pegala acá y guardá.</li>
          </ol>
        </details>
      <?php else: ?>
        <input type="hidden" name="wa_apikey" value="<?= e($u['wa_apikey']) ?>">
      <?php endif ?>
      <button class="btn btn-primary">Guardar preferencias</button>
    </form>
  </section>

  <section class="card" id="password">
    <div class="card-head"><h2><?= icon('key') ?> Cambiar contraseña</h2></div>
    <?php if ($u['must_change_password']): ?><div class="alert alert-warning inline"><?= icon('alert') ?><span>Estás usando una contraseña temporal. Cambiala ahora.</span></div><?php endif ?>
    <form method="post" action="<?= url('/perfil/password') ?>" class="form">
      <?= csrf_field() ?>
      <label class="field"><span>Contraseña actual</span><input type="password" name="current" required autocomplete="current-password"></label>
      <label class="field"><span>Nueva contraseña</span><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label class="field"><span>Repetir nueva contraseña</span><input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
      <small class="muted">Mínimo 8 caracteres, con mayúscula, minúscula y número.</small>
      <button class="btn btn-primary">Actualizar contraseña</button>
    </form>
  </section>

  <section class="card card-soft" id="calendario">
    <div class="card-head"><h2><?= icon('calendar') ?> Google Calendar</h2></div>
    <p class="muted">Suscribite a tu calendario de licencias<?= is_approver() ? ' (incluye a tu equipo)' : '' ?> desde Google Calendar → <b>Otros calendarios → Desde URL</b>.</p>
    <div class="copy-field"><input readonly value="<?= e($feed) ?>" id="feedUrl"><button class="btn btn-ghost btn-sm" data-copy="#feedUrl"><?= icon('link', 16) ?> Copiar</button></div>
    <form method="post" action="<?= url('/perfil/calendario') ?>"><?= csrf_field() ?><button class="link-sm" data-confirm="El enlace actual dejará de funcionar. ¿Continuar?">Generar un enlace nuevo</button></form>
  </section>
</div>
