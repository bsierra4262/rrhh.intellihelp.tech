<?php
$query = array_filter(['desde' => $from, 'hasta' => $to, 'persona' => $user]);
?>
<div class="page-head">
  <div><h1>Reportes</h1><p class="muted">Licencias aprobadas que impactan en vacaciones.</p></div>
  <a class="btn btn-primary" href="<?= url('/admin/reportes/excel', $query) ?>"><?= icon('download', 16) ?> Descargar Excel</a>
</div>

<section class="card">
  <form class="filters wrap" method="get">
    <label class="field sm"><span>Desde</span><input type="date" name="desde" value="<?= e($from) ?>"></label>
    <label class="field sm"><span>Hasta</span><input type="date" name="hasta" value="<?= e($to) ?>"></label>
    <label class="field sm grow"><span>Persona</span>
      <select name="persona">
        <option value="">Todas las personas</option>
        <?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $user === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach ?>
      </select>
    </label>
    <button class="btn btn-ghost btn-sm"><?= icon('search', 16) ?> Filtrar</button>
    <?php if ($query): ?><a class="link-sm" href="<?= url('/admin/reportes') ?>">Quitar filtros</a><?php endif ?>
  </form>
  <p class="muted report-scope"><?= e(report_filters_label($from, $to, $user)) ?> · <b><?= count($records) ?></b> registro<?= count($records) === 1 ? '' : 's' ?></p>

  <?php if ($records): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nombre</th><th>Fecha de inicio</th><th>Fecha de fin</th><th>Tipo de licencia</th></tr></thead>
      <tbody>
        <?php foreach ($records as $r): ?>
          <tr>
            <td><div class="cell-person"><?= avatar($r['name'], 'sm') ?><b><?= e($r['name']) ?></b></div></td>
            <td class="nowrap"><?= e(date('d/m/Y', strtotime($r['start_date']))) ?></td>
            <td class="nowrap"><?= e(date('d/m/Y', strtotime($r['end_date']))) ?></td>
            <td><?= e($r['type_name']) ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty"><?= icon('chart', 30) ?><p>No hay licencias que impacten en vacaciones con esos filtros.</p></div>
  <?php endif ?>
</section>

<section class="card" id="mensual">
  <div class="card-head"><h2><?= icon('mail') ?> Reporte mensual automático</h2><?= $enabled ? '<span class="badge badge-approved">Activo</span>' : '<span class="badge badge-cancelled">Inactivo</span>' ?></div>
  <p class="muted">Todos los meses se envía por correo el Excel del mes anterior a las casillas indicadas.</p>
  <form method="post" action="<?= url('/admin/reportes/configuracion') ?>" class="form" style="margin-top:14px">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field span-2"><span>Casillas de destino</span>
        <textarea name="report_recipients" rows="2" placeholder="rrhh@intellihelp.tech, direccion@intellihelp.tech"><?= e($recipients) ?></textarea>
        <small class="muted">Una o más direcciones, separadas por coma o en líneas distintas.</small>
      </label>
      <label class="field"><span>Día de envío</span>
        <select name="report_day">
          <?php for ($d = 1; $d <= 28; $d++): ?><option value="<?= $d ?>" <?= $day === $d ? 'selected' : '' ?>>Día <?= $d ?> de cada mes</option><?php endfor ?>
        </select>
      </label>
      <div class="field"><span>Último envío</span><p class="muted" style="padding-top:10px"><?= $lastSent ? e(ucfirst(MESES[(int) substr($lastSent, 5, 2)]) . ' ' . substr($lastSent, 0, 4)) : 'Todavía no se envió' ?></p></div>
    </div>
    <div class="form-foot">
      <label class="switch"><input type="checkbox" name="report_enabled" <?= $enabled ? 'checked' : '' ?>><span></span> Enviar automáticamente</label>
      <button class="btn btn-primary"><?= icon('check', 16) ?> Guardar</button>
    </div>
  </form>
  <form method="post" action="<?= url('/admin/reportes/enviar') ?>" class="btn-row" style="margin-top:12px">
    <?= csrf_field() ?>
    <button class="btn btn-ghost btn-sm" data-confirm="Se enviará ahora el reporte del mes anterior a las casillas configuradas. ¿Continuar?"><?= icon('mail', 16) ?> Enviar ahora el reporte del mes anterior</button>
  </form>
</section>
