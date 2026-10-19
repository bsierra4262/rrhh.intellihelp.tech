<h2>Elegí tu contraseña</h2>
<p class="muted">Hola <?= e(explode(' ', $name)[0]) ?>, elegí la contraseña con la que vas a ingresar al portal. Mínimo 8 caracteres, con mayúscula, minúscula y número.</p>
<?php if (!$trusted): ?>
  <div class="alert alert-info"><?= icon('info') ?><span>Después de este paso, RRHH revisará tu acceso. Te avisamos por correo cuando esté aprobado.</span></div>
<?php endif ?>
<form method="post" action="<?= url('/activar') ?>" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <label class="field"><span>Contraseña</span><input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus></label>
  <label class="field"><span>Repetila</span><input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
  <button class="btn btn-primary btn-block btn-lg"><?= $trusted ? 'Activar e ingresar' : 'Confirmar' ?></button>
</form>
