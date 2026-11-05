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
<?php if (google_login_enabled()): ?>
  <div class="auth-or"><span>o</span></div>
  <a class="btn btn-google btn-block btn-lg" href="<?= url('/auth/google') ?>"><svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg> Continuar con Google</a>
  <p class="auth-google-note">Para cuentas @<?= e(implode(', @', trusted_domains())) ?></p>
<?php endif ?>
<?php if (setting('signup_enabled', '1') === '1'): ?>
  <p class="auth-signup">¿Todavía no tenés cuenta? <a href="<?= url('/registro') ?>">Creá tu cuenta</a></p>
<?php endif ?>
