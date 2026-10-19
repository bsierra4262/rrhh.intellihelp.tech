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
            <span class="grow"><b><?= e($h['name']) ?></b><small class="muted"><?= e(ucfirst(DIAS[(int) date('w', strtotime($h['date']))])) ?></small></span>
            <form method="post" action="<?= url('/admin/feriados') ?>"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($h['date']) ?>"><button class="icon-btn" name="delete" value="<?= (int) $h['id'] ?>" aria-label="Eliminar" data-confirm="¿Eliminar este feriado?"><?= icon('trash', 16) ?></button></form>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?><div class="empty"><?= icon('flag', 30) ?><p>No hay feriados cargados para <?= $year ?>.</p></div><?php endif ?>
  </section>
  <section class="card">
    <div class="card-head"><h2>Agregar feriado</h2></div>
    <form method="post" action="<?= url('/admin/feriados') ?>" class="form">
      <?= csrf_field() ?>
      <label class="field"><span>Fecha</span><input type="date" name="date" required></label>
      <label class="field"><span>Nombre</span><input name="name" required maxlength="120" placeholder="Ej.: Día no laborable"></label>
      <button class="btn btn-primary"><?= icon('plus', 16) ?> Agregar</button>
    </form>
  </section>
</div>
