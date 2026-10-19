<h2>Iniciar sesión</h2>
<p class="muted">Ingresá con tu correo corporativo.</p>
<form method="post" action="<?= url('/login') ?>" class="form">
  <?= csrf_field() ?>
  <label class="field">
    <span>Correo electrónico</span>
    <input type="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="username" placeholder="nombre@intellihelp.tech">
  </label>
  <label class="field">
    <span class="field-row">Contraseña <a class="link-sm" href="<?= url('/olvide') ?>">¿La olvidaste?</a></span>
    <div class="pw">
      <input type="password" name="password" required autocomplete="current-password">
      <button type="button" class="pw-toggle" data-pw aria-label="Mostrar contraseña"><?= icon('eye', 18) ?></button>
    </div>
  </label>
  <button class="btn btn-primary btn-block btn-lg">Iniciar sesión <?= icon('arrow-right', 18) ?></button>
</form>
<?php if (setting('signup_enabled', '1') === '1'): ?>
  <p class="auth-signup">¿Todavía no tenés cuenta? <a href="<?= url('/registro') ?>">Creá tu cuenta</a></p>
<?php endif ?>
