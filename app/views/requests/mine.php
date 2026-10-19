<div class="page-head">
  <div><h1>Mis solicitudes</h1><p class="muted">Historial y saldos de <?= $year ?>.</p></div>
  <a class="btn btn-primary" href="<?= url('/solicitudes/nueva') ?>"><?= icon('plus', 16) ?> Nueva solicitud</a>
</div>

<section class="card"><?php partial('balances', ['balances' => $balances]) ?></section>

<section class="card">
  <form class="filters" method="get">
    <label class="field sm"><span>Año</span>
      <select name="anio" data-autosubmit>
        <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 4; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor ?>
      </select>
    </label>
    <div class="chips">
      <a class="chip <?= $status === '' ? 'on' : '' ?>" href="?anio=<?= $year ?>">Todas</a>
      <?php foreach (STATUS_LABELS as $k => $l): ?>
        <a class="chip <?= $status === $k ? 'on' : '' ?>" href="?anio=<?= $year ?>&estado=<?= $k ?>"><?= e($l) ?></a>
      <?php endforeach ?>
    </div>
  </form>
  <?php if ($list): ?>
    <div class="req-list"><?php foreach ($list as $r) partial('request_row', ['r' => $r]); ?></div>
  <?php else: ?>
    <div class="empty"><?= icon('inbox', 32) ?><p>No hay solicitudes para mostrar.</p></div>
  <?php endif ?>
</section>
