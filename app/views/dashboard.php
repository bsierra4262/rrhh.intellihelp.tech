<?php
$first = explode(' ', $user['name'])[0];
$h = (int) date('G');
$greet = $h < 12 ? 'Buen día' : ($h < 20 ? 'Buenas tardes' : 'Buenas noches');
$quick = [
    ['vacaciones', 'sun', 'Pedir vacaciones', '#663399'],
    ['estudio', 'book', 'Día de estudio', '#2E86AB'],
    ['enfermedad', 'heart', 'Informar enfermedad', '#D1495B'],
    ['personal', 'clock', 'Día personal', '#E0A100'],
];
?>
<section class="hero">
  <div>
    <h1><?= $greet ?>, <?= e($first) ?></h1>
    <p><?= e(ucfirst(flongdate(date('Y-m-d')))) ?></p>
  </div>
  <div class="quick">
    <?php foreach ($quick as [$code, $ic, $label, $c]): ?>
      <a href="<?= url('/solicitudes/nueva', ['tipo' => $code]) ?>" class="quick-btn" style="--c:<?= $c ?>"><span><?= icon($ic, 20) ?></span><?= e($label) ?></a>
    <?php endforeach ?>
  </div>
</section>

<?php if (is_approver() && !empty($pending)): ?>
<section class="card card-accent">
  <div class="card-head">
    <h2><?= icon('check-circle') ?> Esperando tu autorización <em class="count"><?= $pending_count ?></em></h2>
    <a class="link" href="<?= url('/aprobaciones') ?>">Ver todas <?= icon('arrow-right', 14) ?></a>
  </div>
  <div class="req-list">
    <?php foreach ($pending as $r) partial('request_row', ['r' => $r, 'showUser' => true]); ?>
  </div>
</section>
<?php endif ?>

<?php if (is_hr() && (!empty($docs_to_check) || !empty($docs_missing))): ?>
<section class="grid-2">
  <div class="card">
    <div class="card-head"><h2><?= icon('paperclip') ?> Documentación para verificar</h2></div>
    <?php if ($docs_to_check): ?>
      <div class="req-list"><?php foreach ($docs_to_check as $r) partial('request_row', ['r' => $r, 'showUser' => true]); ?></div>
    <?php else: ?><p class="empty-sm">Todo verificado.</p><?php endif ?>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= icon('alert') ?> Falta documentación</h2></div>
    <?php if ($docs_missing): ?>
      <div class="req-list"><?php foreach ($docs_missing as $r) partial('request_row', ['r' => $r, 'showUser' => true]); ?></div>
    <?php else: ?><p class="empty-sm">No hay licencias sin documentación.</p><?php endif ?>
  </div>
</section>
<?php endif ?>

<div class="grid-main">
  <div class="stack">
    <section class="card">
      <div class="card-head">
        <h2>Mis días disponibles · <?= $year ?></h2>
        <a class="link" href="<?= url('/solicitudes') ?>">Historial <?= icon('arrow-right', 14) ?></a>
      </div>
      <?php partial('balances', ['balances' => $balances]) ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Mis últimas solicitudes</h2></div>
      <?php if ($mine): ?>
        <div class="req-list"><?php foreach ($mine as $r) partial('request_row', ['r' => $r]); ?></div>
      <?php else: ?>
        <div class="empty">
          <?= icon('inbox', 32) ?>
          <p>Todavía no cargaste solicitudes.</p>
          <a class="btn btn-primary btn-sm" href="<?= url('/solicitudes/nueva') ?>"><?= icon('plus', 16) ?> Crear la primera</a>
        </div>
      <?php endif ?>
    </section>
  </div>

  <div class="stack">
    <?php if ($upcoming): ?>
    <section class="card card-soft">
      <div class="card-head"><h2><?= icon('sun') ?> Próximas ausencias</h2></div>
      <?php foreach ($upcoming as $r): ?>
        <a class="next-off" href="<?= url('/solicitudes/' . $r['id']) ?>">
          <span class="date-chip"><b><?= date('j', strtotime($r['start_date'])) ?></b><small><?= substr(MESES[(int) date('n', strtotime($r['start_date']))], 0, 3) ?></small></span>
          <span><b><?= e($r['type_name']) ?></b><small><?= e(frange($r['start_date'], $r['end_date'])) ?> · <?= e(fdays($r['days'])) ?></small></span>
        </a>
      <?php endforeach ?>
    </section>
    <?php endif ?>

    <?php if (is_approver()): ?>
    <section class="card">
      <div class="card-head"><h2><?= icon('users') ?> Ausentes hoy</h2><a class="link" href="<?= url('/calendario') ?>">Calendario <?= icon('arrow-right', 14) ?></a></div>
      <?php if ($out_today): ?>
        <ul class="people">
          <?php foreach ($out_today as $r): ?>
            <li><a href="<?= url('/solicitudes/' . $r['id']) ?>"><?= avatar($r['user_name'], 'sm') ?><span><b><?= e($r['user_name']) ?></b><small><?= type_dot($r['color']) ?><?= e($r['type_name']) ?> · hasta <?= e(fdate($r['end_date'], false)) ?></small></span></a></li>
          <?php endforeach ?>
        </ul>
      <?php else: ?><p class="empty-sm"><?= icon('check', 16) ?> Hoy está todo el equipo.</p><?php endif ?>

      <?php if ($out_week): ?>
        <h3 class="sub">Próximos 14 días</h3>
        <ul class="people compact">
          <?php foreach ($out_week as $r): ?>
            <li><a href="<?= url('/solicitudes/' . $r['id']) ?>"><?= avatar($r['user_name'], 'sm') ?><span><b><?= e($r['user_name']) ?></b><small><?= type_dot($r['color']) ?><?= e($r['type_name']) ?> · <?= e(frange($r['start_date'], $r['end_date'])) ?><?= $r['status'] === 'pending' ? ' · pendiente' : '' ?></small></span></a></li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
    </section>
    <?php endif ?>

    <?php if (is_hr()): ?>
    <section class="card stats">
      <div><b><?= $stats['employees'] ?></b><small>personas activas</small></div>
      <div><b><?= e(rtrim(rtrim(number_format($stats['vac_year'], 1, ',', ''), '0'), ',')) ?></b><small>días de vacaciones aprobados en <?= $year ?></small></div>
      <div><b><?= e(rtrim(rtrim(number_format($stats['sick_month'], 1, ',', ''), '0'), ',')) ?></b><small>días por enfermedad este mes</small></div>
    </section>
    <?php endif ?>
  </div>
</div>
