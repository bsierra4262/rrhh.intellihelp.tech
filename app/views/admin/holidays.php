<div class="page-head">
  <div><h1>Feriados <?= $year ?></h1><p class="muted">Se descuentan al contar días hábiles.</p></div>
  <div class="btn-row">
    <a class="btn btn-ghost btn-sm" href="?anio=<?= $year - 1 ?>"><?= icon('chevron-left', 16) ?> <?= $year - 1 ?></a>
    <a class="btn btn-ghost btn-sm" href="?anio=<?= $year + 1 ?>"><?= $year + 1 ?> <?= icon('chevron-right', 16) ?></a>
  </div>
</div>
<div class="grid-main">
  <section class="card">
    <?php if ($list): ?>
      <ul class="holiday-list">
        <?php foreach ($list as $h): ?>
          <li>
            <span class="date-chip"><b><?= date('j', strtotime($h['date'])) ?></b><small><?= substr(MESES[(int) date('n', strtotime($h['date']))], 0, 3) ?></small></span>
            <span class="grow">
              <b><?= e($h['name']) ?></b>
              <small class="muted"><?= e(ucfirst(DIAS[(int) date('w', strtotime($h['date']))])) ?><?= $h['kind'] ? ' · ' . e(HOLIDAY_KINDS[$h['kind']] ?? $h['kind']) : '' ?><?= $h['region'] ? ' · Feriado regional, no nacional' : '' ?></small>
            </span>
            <?php if ($h['region']): ?><span class="badge badge-pending">Solo <?= e($h['region']) ?></span><?php endif ?>
            <span class="badge <?= $h['source'] === 'auto' ? 'badge-registered' : 'badge-pending' ?>"><?= $h['source'] === 'auto' ? 'Oficial' : 'Cargado a mano' ?></span>
            <form method="post" action="<?= url('/admin/feriados') ?>"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($h['date']) ?>"><button class="icon-btn" name="delete" value="<?= (int) $h['id'] ?>" aria-label="Eliminar" data-confirm="<?= $h['source'] === 'auto' ? '¿Quitar este feriado? No se volverá a agregar en las actualizaciones automáticas.' : '¿Eliminar este feriado?' ?>"><?= icon('trash', 16) ?></button></form>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?><div class="empty"><?= icon('flag', 30) ?><p>Todavía no hay feriados publicados para <?= $year ?>.</p></div><?php endif ?>
    <?php if ($ignored): ?>
      <div class="card-head" style="margin-top:18px"><h2>Quitados por RRHH</h2></div>
      <ul class="holiday-list">
        <?php foreach ($ignored as $d): ?>
          <li>
            <span class="date-chip"><b><?= date('j', strtotime($d)) ?></b><small><?= substr(MESES[(int) date('n', strtotime($d))], 0, 3) ?></small></span>
            <span class="grow"><b>No se computa como feriado</b><small class="muted">Figura en el calendario oficial, pero la empresa trabaja ese día.</small></span>
            <form method="post" action="<?= url('/admin/feriados') ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" name="restore" value="<?= e($d) ?>">Restituir</button></form>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Actualización automática</h2></div>
      <p class="muted">Todos los días se consulta el calendario oficial de feriados nacionales de este año y del próximo. Se agregan solos los días puente y los feriados que se declaren, y los trasladables se reubican cuando se fija su fecha. RRHH recibe un correo con cada cambio.</p>
      <p class="muted"><?= $syncedAt ? 'Última actualización: ' . e(fdatetime($syncedAt)) . '.' : 'Todavía no se actualizó.' ?></p>
      <form method="post" action="<?= url('/admin/feriados/actualizar') ?>"><?= csrf_field() ?><input type="hidden" name="year" value="<?= $year ?>"><button class="btn btn-ghost btn-sm"><?= icon('download', 16) ?> Actualizar ahora</button></form>
    </section>
    <section class="card">
      <div class="card-head"><h2>Agregar feriado</h2></div>
      <p class="muted">Para feriados provinciales o días que la empresa no trabaja.</p>
      <form method="post" action="<?= url('/admin/feriados') ?>" class="form">
        <?= csrf_field() ?>
        <label class="field"><span>Fecha</span><input type="date" name="date" required></label>
        <label class="field"><span>Nombre</span><input name="name" required maxlength="120" placeholder="Ej.: Día no laborable"></label>
        <label class="field"><span>Dónde rige <small class="muted">(vacío si es para toda la empresa)</small></span><input name="region" maxlength="80" placeholder="Ej.: Provincia de Buenos Aires"></label>
        <button class="btn btn-primary"><?= icon('plus', 16) ?> Agregar</button>
      </form>
    </section>
  </div>
</div>
