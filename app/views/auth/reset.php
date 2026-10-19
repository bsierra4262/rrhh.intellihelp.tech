<h2>Elegí tu nueva contraseña</h2>
<p class="muted">Mínimo 8 caracteres, con mayúscula, minúscula y número.</p>
<form method="post" action="<?= url('/restablecer') ?>" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <label class="field"><span>Nueva contraseña</span><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
  <label class="field"><span>Repetila</span><input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
  <button class="btn btn-primary btn-block btn-lg">Guardar contraseña</button>
</form>
