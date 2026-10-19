<h2>Crear cuenta</h2>
<p class="muted">Para colaboradores de Intellihelp, incluido el personal tercerizado. Te enviaremos un enlace para confirmar tu correo.</p>
<form method="post" action="<?= url('/registro') ?>" class="form" id="regForm" data-domains="<?= e(implode(',', $domains)) ?>">
  <?= csrf_field() ?>
  <label class="field">
    <span>Nombre y apellido</span>
    <input name="name" value="<?= e(old('name')) ?>" required minlength="3" maxlength="120" autocomplete="name" autofocus>
  </label>
  <label class="field">
    <span>Correo electrónico</span>
    <input type="email" name="email" id="regEmail" value="<?= e(old('email')) ?>" required autocomplete="email" placeholder="nombre@empresa.com">
  </label>
  <label class="field" id="regCompany">
    <span>Empresa para la que trabajás</span>
    <input name="company" value="<?= e(old('company')) ?>" maxlength="120" placeholder="Ej.: consultora o empresa contratante">
    <small class="muted">Si tu correo no es de Intellihelp, RRHH aprobará tu acceso antes de que puedas ingresar.</small>
  </label>
  <button class="btn btn-primary btn-block btn-lg">Continuar <?= icon('arrow-right', 18) ?></button>
  <a class="link-sm center" href="<?= url('/login') ?>"><?= icon('arrow-left', 14) ?> Ya tengo cuenta</a>
</form>
<script>
(() => {
  const f = document.getElementById('regForm'), mail = document.getElementById('regEmail'), box = document.getElementById('regCompany');
  const domains = f.dataset.domains.split(',').filter(Boolean);
  const sync = () => {
    const d = (mail.value.split('@')[1] || '').toLowerCase();
    const trusted = domains.includes(d);
    box.hidden = trusted;
    box.querySelector('input').required = !trusted && d !== '';
  };
  mail.addEventListener('input', sync); sync();
})();
</script>
