<?php
$v = fn(string $k, $d = '') => old($k, $u[$k] ?? $d);
$newPass = $_SESSION['new_password'] ?? null;
unset($_SESSION['new_password']);
?>
<div class="page-head">
  <div>
    <a class="back" href="<?= url('/admin/usuarios') ?>"><?= icon('arrow-left', 16) ?> Personas</a>
    <h1><?= $u ? e($u['name']) : 'Nueva persona' ?></h1>
  </div>
</div>

<?php if ($newPass): ?>
  <div class="card card-accent credentials">
    <h2><?= icon('key') ?> Credenciales de acceso</h2>
    <p>Compartí estos datos con <b><?= e($newPass['name']) ?></b>. Al ingresar se le pedirá cambiar la contraseña.<?= $newPass['sent'] ? ' <b>También se los enviamos por email.</b>' : '' ?></p>
    <div class="cred"><span>Portal</span><code><?= e(url('/login')) ?></code></div>
    <div class="cred"><span>Usuario</span><code><?= e($newPass['email']) ?></code></div>
    <div class="cred"><span>Contraseña temporal</span><code id="tmpPass"><?= e($newPass['pass']) ?></code><button class="btn btn-ghost btn-sm" data-copy="#tmpPass"><?= icon('link', 14) ?> Copiar</button></div>
    <small class="muted">Esta contraseña no se vuelve a mostrar.</small>
  </div>
<?php endif ?>

<?php if ($u && $u['pending_approval']): ?>
  <section class="card card-accent">
    <div class="card-head"><h2><?= icon('shield') ?> Solicitud de acceso</h2></div>
    <p><b><?= e($u['name']) ?></b> (<?= e($u['email']) ?><?= $u['company'] ? ' · ' . e($u['company']) : '' ?>) confirmó su correo y pide acceso al portal. Revisá y completá sus datos abajo (responsable, área, fecha de ingreso) y aprobá el acceso.</p>
    <form method="post" action="<?= url('/admin/usuarios/' . $u['id'] . '/aprobar') ?>" class="btn-row" style="margin-top:14px">
      <?= csrf_field() ?>
      <button class="btn btn-positive" name="decision" value="approve"><?= icon('check', 16) ?> Aprobar acceso</button>
      <button class="btn btn-outline-danger" name="decision" value="reject" data-confirm="¿Rechazar el acceso de esta persona?"><?= icon('x', 16) ?> Rechazar</button>
    </form>
  </section>
<?php endif ?>

<?php if ($u && $u['pending_activation']): ?>
  <section class="card card-accent">
    <div class="card-head"><h2><?= icon('mail') ?> Cuenta sin confirmar</h2></div>
    <p><b><?= e($u['name']) ?></b> todavía no confirmó su correo (<?= e($u['email']) ?>) ni eligió su contraseña, por eso no puede ingresar. Podés reenviarle el correo de confirmación o generarle una contraseña temporal desde <b>Acceso</b>, que habilita la cuenta en el momento.</p>
    <form method="post" action="<?= url('/admin/usuarios/' . $u['id'] . '/activacion') ?>" class="btn-row" style="margin-top:14px">
      <?= csrf_field() ?>
      <button class="btn btn-primary btn-sm"><?= icon('mail', 16) ?> Reenviar correo de confirmación</button>
    </form>
  </section>
<?php endif ?>

<form method="post" class="card form" action="<?= url($u ? '/admin/usuarios/' . $u['id'] : '/admin/usuarios/nuevo') ?>">
  <?= csrf_field() ?>
  <div class="form-grid">
    <label class="field"><span>Nombre y apellido *</span><input name="name" value="<?= e($v('name')) ?>" required maxlength="120"></label>
    <label class="field"><span>Email *</span><input type="email" name="email" value="<?= e($v('email')) ?>" required placeholder="nombre@intellihelp.tech"></label>
    <label class="field"><span>Rol</span>
      <select name="role">
        <?php foreach (ROLE_LABELS as $k => $l): if ($k === 'admin' && !has_role('admin')) continue; ?>
          <option value="<?= $k ?>" <?= $v('role', 'employee') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach ?>
      </select>
      <small class="muted">Responsable: autoriza a su equipo. RRHH: ve y autoriza todo.</small>
    </label>
    <label class="field"><span>Responsable directo (quien autoriza)</span>
      <select name="manager_id">
        <option value="">— Recursos Humanos —</option>
        <?php foreach ($managers as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (string) $v('manager_id') === (string) $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach ?>
      </select>
    </label>
    <label class="field"><span>Empresa</span><input name="company" value="<?= e($v('company', 'Intellihelp')) ?>" list="companies" maxlength="120" placeholder="Intellihelp o empresa del tercerizado">
      <datalist id="companies"><?php foreach ($companies as $c): ?><option value="<?= e($c) ?>"><?php endforeach ?></datalist>
    </label>
    <label class="field"><span>Área</span><input name="department" value="<?= e($v('department')) ?>" list="deps" maxlength="120" placeholder="Soporte, Desarrollo, Comercial…">
      <datalist id="deps"><?php foreach ($departments as $d): ?><option value="<?= e($d) ?>"><?php endforeach ?></datalist>
    </label>
    <label class="field"><span>Puesto</span><input name="position" value="<?= e($v('position')) ?>" maxlength="120"></label>
    <label class="field"><span>Celular (WhatsApp)</span><input name="phone" value="<?= e($v('phone')) ?>" placeholder="+54 9 11 1234 5678"></label>
    <label class="field"><span>Fecha de ingreso</span><input type="date" name="hire_date" value="<?= e($v('hire_date')) ?>"><small class="muted">Define los días de vacaciones por antigüedad (LCT).</small></label>
<?php $manualYear = (int) ($u['vacation_override_year'] ?? 0); $manualActive = $u && $u['vacation_days_override'] !== null && (!$manualYear || $manualYear === (int) date('Y')); ?>
    <label class="field"><span>Días de vacaciones <?= date('Y') ?> (manual)</span><input type="number" name="vacation_days_override" min="0" max="60" value="<?= e($manualActive ? $v('vacation_days_override') : '') ?>" placeholder="Automático según antigüedad">
      <small class="muted">Solo para <?= date('Y') ?>. El año siguiente vuelve a calcularse por antigüedad.</small></label>
  </div>
  <div class="form-foot">
    <label class="switch"><input type="checkbox" name="active" <?= (!$u || $u['active']) ? 'checked' : '' ?>><span></span> Activa</label>
    <?php if (!$u): ?><label class="switch"><input type="checkbox" name="send_welcome" checked><span></span> Enviar email de bienvenida con la contraseña</label><?php endif ?>
    <button class="btn btn-primary"><?= icon('check', 16) ?> Guardar</button>
  </div>
</form>

<?php if ($u): ?>
<section class="card">
  <div class="card-head"><h2><?= icon('key') ?> Acceso</h2></div>
  <p class="muted">Último ingreso: <?= e(fdatetime($u['last_login'])) ?></p>
  <form method="post" action="<?= url('/admin/usuarios/' . $u['id'] . '/password') ?>" class="btn-row">
    <?= csrf_field() ?>
    <label class="switch"><input type="checkbox" name="send" checked><span></span> Enviar por email</label>
    <?php if ($u['pending_activation']): ?>
      <button class="btn btn-ghost btn-sm" data-confirm="Se generará una contraseña temporal y la cuenta quedará habilitada. ¿Continuar?"><?= icon('key', 16) ?> Generar contraseña y habilitar</button>
    <?php else: ?>
      <button class="btn btn-ghost btn-sm" data-confirm="Se generará una contraseña temporal nueva. ¿Continuar?"><?= icon('key', 16) ?> Restablecer contraseña</button>
    <?php endif ?>
  </form>
</section>
<?php endif ?>
