<h2>Recuperar contraseña</h2>
<p class="muted">Te enviamos un enlace para que elijas una nueva.</p>
<form method="post" action="<?= url('/olvide') ?>" class="form">
  <?= csrf_field() ?>
  <label class="field">
    <span>Email</span>
    <input type="email" name="email" required autofocus placeholder="nombre@intellihelp.tech">
  </label>
  <button class="btn btn-primary btn-block btn-lg">Enviar enlace</button>
  <a class="link-sm center" href="<?= url('/login') ?>"><?= icon('arrow-left', 14) ?> Volver a ingresar</a>
</form>
