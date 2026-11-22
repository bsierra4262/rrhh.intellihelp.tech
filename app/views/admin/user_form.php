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
      <button class="btn btn-outline-danger btn-sm" formaction="<?= url('/admin/usuarios/' . $u['id'] . '/eliminar') ?>" data-confirm="¿Eliminar la cuenta sin confirmar <?= e($u['email']) ?>? No se puede deshacer."><?= icon('trash', 16) ?> Eliminar cuenta</button>
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
<?php
$dep = (string) $v('department');
$pos = (string) $v('position');
$cct = (string) $v('cct');
$cat = (string) $v('cct_category');
$areas = catalog_areas();
$depOther = $dep !== '' && !isset($areas[$dep]);
$posList = $areas[$dep] ?? [];
$posOther = $pos !== '' && !in_array($pos, $posList, true);
$cctOther = $cct !== '' && !isset(CONVENIOS[$cct]);
$catList = CATEGORIAS[$cct] ?? [];
$catOther = $cat !== '' && !in_array($cat, $catList, true);
?>
    <label class="field"><span>Área</span>
      <select name="department" id="fDep" data-other="#fDepOther">
        <option value="">— Elegí el área —</option>
        <?php foreach (array_keys($areas) as $a): ?><option <?= $dep === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach ?>
        <option value="__otro" <?= $depOther ? 'selected' : '' ?>>Otra (escribir)</option>
      </select>
      <input name="department_otro" id="fDepOther" value="<?= e($depOther ? $dep : '') ?>" maxlength="120" placeholder="Escribí el área" <?= $depOther ? '' : 'hidden' ?>>
    </label>
    <label class="field"><span>Puesto</span>
      <select name="position" id="fPos" data-other="#fPosOther" data-areas='<?= e(json_encode($areas, JSON_UNESCAPED_UNICODE)) ?>'>
        <option value="">— Elegí el puesto —</option>
        <?php foreach ($posList as $p): ?><option <?= $pos === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach ?>
        <option value="__otro" <?= $posOther ? 'selected' : '' ?>>Otro (escribir)</option>
      </select>
      <input name="position_otro" id="fPosOther" value="<?= e($posOther ? $pos : '') ?>" maxlength="120" placeholder="Escribí el puesto" <?= $posOther ? '' : 'hidden' ?>>
    </label>
    <label class="field"><span>Celular (WhatsApp)</span><input name="phone" value="<?= e(fphone($v('phone'))) ?>" placeholder="+54 9 11 1234-5678" inputmode="tel"></label>
    <label class="field"><span>Fecha de ingreso</span><input type="date" name="hire_date" value="<?= e($v('hire_date')) ?>"><small class="muted">Define los días de vacaciones por antigüedad (LCT).</small></label>
    <label class="field"><span>Fecha de egreso</span><input type="date" name="exit_date" value="<?= e($v('exit_date')) ?>"><small class="muted">Solo al terminar la relación laboral: calcula las vacaciones a liquidar.</small></label>
    <label class="field"><span>Convenio colectivo</span>
      <select name="cct" id="fCct" data-other="#fCctOther" data-cats='<?= e(json_encode(CATEGORIAS, JSON_UNESCAPED_UNICODE)) ?>'>
        <?php foreach (CONVENIOS as $k => $l): ?><option value="<?= e($k) ?>" <?= $cct === (string) $k ? 'selected' : '' ?>><?= e($k === '' ? $l : "$k — $l") ?></option><?php endforeach ?>
        <option value="__otro" <?= $cctOther ? 'selected' : '' ?>>Otro convenio (escribir el número)</option>
      </select>
      <input name="cct_otro" id="fCctOther" value="<?= e($cctOther ? $cct : '') ?>" maxlength="20" placeholder="Número oficial, por ejemplo 130/75" <?= $cctOther ? '' : 'hidden' ?>>
      <small class="muted">Según el puesto. Define el aviso de vacaciones: 60 días en Comercio (art. 74 del CCT 130/75), 30 por la LCT. Si no está en la lista, buscalo en el <a href="<?= e(CONVENIOS_BUSCADOR) ?>" target="_blank" rel="noopener">buscador oficial de convenios</a>.</small>
    </label>
    <label class="field" id="fCatWrap" <?= $cct === '' ? 'hidden' : '' ?>><span>Categoría del convenio</span>
      <select name="cct_category" id="fCat" data-other="#fCatOther">
        <option value="">— Elegí la categoría —</option>
        <?php foreach ($catList as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach ?>
        <option value="__otro" <?= $catOther ? 'selected' : '' ?>>Otra (escribir)</option>
      </select>
      <input name="cct_category_otro" id="fCatOther" value="<?= e($catOther ? $cat : '') ?>" maxlength="80" placeholder="Categoría según el convenio" <?= $catOther ? '' : 'hidden' ?>>
    </label>
    <?php if ($groups): ?>
    <label class="field"><span>Legajo electrónico (grupo)</span>
      <select name="legajo_group">
        <option value="">— Sin legajo —</option>
        <?php foreach (array_keys($groups) as $g): ?><option <?= (string) $v('legajo_group') === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach ?>
      </select>
      <small class="muted">Empresa o cliente: es la carpeta de LEGAJOS ELECTRONICOS donde está su legajo.</small>
    </label>
    <?php endif ?>
    <label class="field"><span>Último verano con vacaciones</span>
      <select name="last_summer_season">
        <option value="">Sin dato / según el portal</option>
        <?php for ($s = (int) date('Y'); $s >= (int) date('Y') - 4; $s--): ?><option value="<?= $s ?>" <?= (string) $v('last_summer_season') === (string) $s ? 'selected' : '' ?>><?= $s ?>/<?= substr((string) ($s + 1), 2) ?></option><?php endfor ?>
      </select>
      <small class="muted">Para los veranos anteriores al portal. Cada 3 años las vacaciones tienen que caer en verano.</small>
    </label>
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

<?php if ($u && $groups): ?>
<section class="card">
  <div class="card-head"><h2><?= icon('file') ?> Legajo electrónico</h2>
    <?php if ($legajo): ?><a class="link" href="<?= e($legajo['url']) ?>" target="_blank" rel="noopener">Abrir en Drive <?= icon('arrow-right', 14) ?></a><?php endif ?></div>
  <?php if ($legajo): ?>
    <p class="muted">LEGAJOS ELECTRONICOS / <?= e($legajo['group']) ?> / <b><?= e($legajo['name']) ?></b></p>
    <?php $missing = array_keys(array_filter($checklist, fn ($ok) => !$ok)); ?>
    <p><?= $missing ? 'Faltan <b>' . count($missing) . '</b> de los ' . count($checklist) . ' documentos de alta.' : 'Tiene los ' . count($checklist) . ' documentos de alta.' ?></p>
    <ul class="checklist">
      <?php foreach ($checklist as $doc => $ok): ?><li class="<?= $ok ? 'ok' : 'miss' ?>"><?= icon($ok ? 'check' : 'x', 14) ?> <?= e($doc) ?></li><?php endforeach ?>
    </ul>
    <small class="muted">Se reconoce cada documento por el nombre del archivo en su carpeta.</small>
  <?php else: ?>
    <p class="muted">No se encontró una carpeta con su nombre en LEGAJOS ELECTRONICOS.</p>
    <?php if ($u['legajo_group']): ?>
      <form method="post" action="<?= url('/admin/usuarios/' . $u['id'] . '/legajo') ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= icon('plus', 16) ?> Crear su legajo en «<?= e($u['legajo_group']) ?>»</button></form>
    <?php else: ?>
      <p class="muted">Elegí el grupo del legajo arriba y guardá para poder crearlo.</p>
    <?php endif ?>
  <?php endif ?>
  <?php if ($u['exit_date']): ?><p class="muted">Al llegar la fecha de egreso (<?= e(fdate($u['exit_date'])) ?>) la cuenta se desactiva, el legajo pasa a «1. DESAFECTADOS» y el detalle de las vacaciones a liquidar queda en LIQUIDACIONES.</p><?php endif ?>
</section>
<?php endif ?>

<?php if ($u): ?>
<section class="card">
  <div class="card-head"><h2><?= icon('sun') ?> Vacaciones según la ley</h2></div>
  <?php if ($summerDue): ?>
    <div class="alert alert-warning inline"><?= icon('alert') ?><span>Esta temporada le corresponden vacaciones en verano (del 21/12 al 21/3): no las tuvo en los dos veranos anteriores y la ley pide que caigan en verano al menos una vez cada 3 años (art. 154).</span></div>
  <?php endif ?>
  <p class="muted">Avisos con <?= vacation_notice_days($u) ?> días de anticipación<?= ($u['cct'] ?? '') === '130/75' ? ' (convenio de Comercio)' : '' ?>. Las vacaciones no se pagan en dinero, salvo al terminar la relación laboral (art. 162).</p>

  <h3 style="margin-top:18px">Acumular al año siguiente (art. 164)</h3>
  <p class="muted">Por acuerdo entre las partes se puede pasar hasta un tercio de las vacaciones de <?= $year ?> a <?= $year + 1 ?>. Hoy se pueden acumular hasta <b><?= e(fdays($carryLimit)) ?></b>.</p>
  <?php if ($carryovers): ?>
    <ul class="holiday-list">
      <?php foreach ($carryovers as $c): ?>
        <li>
          <span class="grow"><b><?= e(fdays($c['days'])) ?> de <?= (int) $c['from_year'] ?> a <?= (int) $c['from_year'] + 1 ?></b>
            <small class="muted">Acordado el <?= e(fdate($c['created_at'])) ?><?= $c['agreed_name'] ? ' · registró ' . e($c['agreed_name']) : '' ?><?= $c['note'] ? ' · ' . e($c['note']) : '' ?></small></span>
          <form method="post" action="<?= url('/admin/usuarios/' . $u['id'] . '/acumulacion') ?>"><?= csrf_field() ?><button class="icon-btn" name="delete" value="<?= (int) $c['id'] ?>" aria-label="Eliminar" data-confirm="¿Eliminar este acuerdo de acumulación?"><?= icon('trash', 16) ?></button></form>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
  <?php if ($carryLimit > 0): ?>
    <form method="post" action="<?= url('/admin/usuarios/' . $u['id'] . '/acumulacion') ?>" class="form-grid">
      <?= csrf_field() ?>
      <label class="field"><span>Días a acumular</span><input type="number" name="days" min="1" max="<?= $carryLimit ?>" required></label>
      <label class="field"><span>Detalle del acuerdo</span><input name="note" maxlength="255" placeholder="Ej.: acordado por escrito el 15/12"></label>
      <div><button class="btn btn-ghost btn-sm"><?= icon('check', 16) ?> Registrar acuerdo</button></div>
    </form>
  <?php endif ?>

  <?php if ($settlement): ?>
    <h3 style="margin-top:18px">Vacaciones a liquidar por el egreso (art. 156)</h3>
    <p>Corresponde pagar <b><?= e(fdays($settlement['total'])) ?></b> de vacaciones no gozadas.</p>
    <ul class="muted">
      <li>Proporcional de <?= $settlement['year'] ?>: <?= e(number_format($settlement['proportional'], 2, ',', '')) ?> días (<?= $settlement['entitled'] ?> días del año según el tiempo trabajado hasta el <?= e(fdate($u['exit_date'])) ?>).</li>
      <?php if ($settlement['carried']): ?><li>Acumulados de <?= $settlement['year'] - 1 ?>: <?= e(fdays($settlement['carried'])) ?>.</li><?php endif ?>
      <li>Ya gozados en <?= $settlement['year'] ?>: <?= e(fdays($settlement['used'])) ?>.</li>
      <?php if ($settlement['previous']): ?><li>No gozados de <?= $settlement['year'] - 1 ?>: <?= e(fdays($settlement['previous'])) ?>.</li><?php endif ?>
    </ul>
    <p class="muted">Es el único caso en que las vacaciones se pagan en dinero. Confirmá el importe con quien liquida los sueldos.</p>
  <?php endif ?>
</section>

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
