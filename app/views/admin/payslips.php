<?php
$money = fn ($n) => $n === null ? '—' : '$ ' . number_format((float) $n, 2, ',', '.');
$periodLabel = function (string $p): string {
    return ucfirst(MESES[(int) substr($p, 5, 2)]) . ' ' . substr($p, 0, 4);
};
$signed = count(array_filter($list, fn ($p) => $p['signed_at']));
?>
<div class="page-head">
  <div><h1>Recibos de sueldo</h1><p class="muted">Se cargan tal como los emite el estudio contable y quedan en el legajo electrónico de cada persona.</p></div>
</div>

<?php if (!$enabled): ?>
  <div class="alert alert-info inline"><?= icon('info') ?><span><b>En prueba:</b> «Mis recibos» está habilitado solo para administradores. El resto del personal no ve los recibos hasta que se habilite en Configuración.</span></div>
<?php endif ?>

<section class="card">
  <div class="card-head"><h2><?= icon('download') ?> Cargar recibos</h2></div>
  <p class="muted">Subí uno o varios PDF: un recibo por persona (por ejemplo, «20.Junio 2026 Brian Sierra.pdf») o el archivo del mes con todos los recibos. El portal lee de cada uno el CUIL, el nombre, el período y el neto, lo asigna a la persona y lo guarda en <b>LEGAJOS ELECTRONICOS / grupo / NOMBRE / Recibos de Sueldo / año</b>.</p>
  <form method="post" action="<?= url('/admin/recibos') ?>" enctype="multipart/form-data" class="form inline-form">
    <?= csrf_field() ?>
    <label class="field grow"><span>Recibos en PDF</span><input type="file" name="files[]" accept="application/pdf" multiple required></label>
    <button class="btn btn-primary"><?= icon('check', 16) ?> Cargar</button>
  </form>
</section>

<?php if ($unassigned): ?>
<section class="card card-accent">
  <div class="card-head"><h2><?= icon('alert') ?> Sin asignar <em class="count"><?= count($unassigned) ?></em></h2></div>
  <p class="muted">No se pudo identificar a la persona (CUIL sin cargar y nombre distinto). Están guardados en LIQUIDACIONES / año / mes / Recibos sin asignar hasta que los asignes.</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Recibo</th><th>En el recibo</th><th>Asignar a</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($unassigned as $p): ?>
        <tr>
          <td><a href="<?= url('/recibos/' . $p['id'] . '/archivo') ?>" target="_blank" rel="noopener"><?= e($p['file_name']) ?></a><br><small class="muted"><?= e($p['label']) ?></small></td>
          <td><?= e($p['employee_name'] ?: '—') ?><br><small class="muted">CUIL <?= e($p['cuil'] ?: '—') ?> · legajo <?= e($p['legajo'] ?: '—') ?></small></td>
          <td>
            <form method="post" action="<?= url('/admin/recibos/' . $p['id'] . '/asignar') ?>" class="inline-form" style="margin:0">
              <?= csrf_field() ?>
              <label class="field sm grow"><select name="user_id" required><option value="">— Elegí la persona —</option><?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?><?= $u['cuil'] ? ' · ' . e($u['cuil']) : '' ?></option><?php endforeach ?></select></label>
              <button class="btn btn-ghost btn-sm"><?= icon('check', 16) ?> Asignar</button>
            </form>
          </td>
          <td><form method="post" action="<?= url('/admin/recibos/' . $p['id'] . '/eliminar') ?>"><?= csrf_field() ?><button class="icon-btn" aria-label="Eliminar" data-confirm="¿Eliminar el recibo «<?= e($p['file_name']) ?>»?"><?= icon('trash', 16) ?></button></form></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table></div>
</section>
<?php endif ?>

<section class="card">
  <div class="card-head"><h2><?= icon('list') ?> Recibos cargados</h2><span class="muted"><?= count($list) ?> recibo(s) · <?= $signed ?> firmado(s)</span></div>
  <form method="get" class="filters">
    <label class="field sm"><span>Período</span><select name="periodo"><option value="">Todos</option><?php foreach ($periods as $pp): ?><option value="<?= e($pp) ?>" <?= $period === $pp ? 'selected' : '' ?>><?= e($periodLabel($pp)) ?></option><?php endforeach ?></select></label>
    <label class="field sm"><span>Persona</span><select name="persona"><option value="0">Todas</option><?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $person === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach ?></select></label>
    <button class="btn btn-ghost btn-sm"><?= icon('search', 16) ?> Filtrar</button>
  </form>
  <?php if ($list): ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Persona</th><th>Recibo</th><th>Fecha de pago</th><th class="num">Neto</th><th>Firma</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($list as $p): ?>
        <tr>
          <td><div class="cell-person"><?= avatar($p['user_name'], 'sm') ?><span><b><?= e($p['user_name']) ?></b><small>CUIL <?= e($p['cuil'] ?: '—') ?></small></span></div></td>
          <td><a href="<?= url('/recibos/' . $p['id'] . '/archivo') ?>" target="_blank" rel="noopener"><?= e($p['label']) ?></a><br><small class="muted"><?= e($p['file_name']) ?></small><br><?php partial('employer_sig', ['p' => $p]) ?></td>
          <td><?= $p['pay_date'] ? e(fdate($p['pay_date'])) : '—' ?></td>
          <td class="num"><?= e($money($p['net'])) ?></td>
          <td>
            <?php if ($p['sign_status'] === 'conforme'): ?><span class="badge badge-approved">Conforme</span><br><small class="muted"><?= e(fdatetime($p['signed_at'])) ?></small>
            <?php elseif ($p['sign_status'] === 'disconforme'): ?><span class="badge badge-pending">En disconformidad</span><br><small class="muted"><?= e($p['sign_comment']) ?></small>
            <?php else: ?><span class="badge badge-cancelled">Sin firmar</span><?php endif ?>
          </td>
          <td class="nowrap">
            <?php if ($p['signed_drive_id']): ?><a class="icon-btn" href="<?= url('/recibos/' . $p['id'] . '/archivo?firmado=1') ?>" target="_blank" rel="noopener" title="Ver firmado"><?= icon('file', 16) ?></a><?php endif ?>
            <?php if (!$p['signed_at']): ?><form method="post" action="<?= url('/admin/recibos/' . $p['id'] . '/eliminar') ?>" style="display:inline"><?= csrf_field() ?><button class="icon-btn" aria-label="Eliminar" data-confirm="¿Eliminar el recibo de <?= e($p['label']) ?> de <?= e($p['user_name']) ?>?"><?= icon('trash', 16) ?></button></form><?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table></div>
  <?php else: ?><div class="empty"><?= icon('file', 30) ?><p>Todavía no hay recibos cargados<?= $period || $person ? ' con estos filtros' : '' ?>.</p></div><?php endif ?>
</section>
