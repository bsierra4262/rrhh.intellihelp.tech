<?php
$prev = $first->modify('-1 month')->format('Y-m');
$next = $first->modify('+1 month')->format('Y-m');
$today = date('Y-m-d');
$month = $first->format('m');
?>
<div class="page-head">
  <div><h1><?= e(ucfirst(MESES[(int) $first->format('n')])) ?> <?= $first->format('Y') ?></h1><p class="muted">Ausencias <?= is_hr() ? 'de toda la empresa' : (is_approver() ? 'de tu equipo' : 'de tu equipo y tuyas') ?>.</p></div>
  <div class="btn-row">
    <a class="btn btn-ghost btn-sm" href="?mes=<?= $prev ?>" aria-label="Mes anterior"><?= icon('chevron-left', 16) ?></a>
    <a class="btn btn-ghost btn-sm" href="?mes=<?= date('Y-m') ?>">Hoy</a>
    <a class="btn btn-ghost btn-sm" href="?mes=<?= $next ?>" aria-label="Mes siguiente"><?= icon('chevron-right', 16) ?></a>
  </div>
</div>

<section class="card cal-card">
  <div class="cal">
    <?php foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $d): ?><div class="cal-h"><?= $d ?></div><?php endforeach ?>
    <?php for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')):
        $k = $d->format('Y-m-d');
        $cls = ['cal-d'];
        if ($d->format('m') !== $month) $cls[] = 'out';
        if ($k === $today) $cls[] = 'today';
        if ((int) $d->format('N') >= 6) $cls[] = 'weekend';
        if (isset($holidays[$k])) $cls[] = 'holiday';
        $items = $byDay[$k] ?? [];
    ?>
      <div class="<?= implode(' ', $cls) ?>">
        <div class="cal-n"><span><?= $d->format('j') ?></span><?php if (isset($holidays[$k])): ?><small title="<?= e($holidays[$k]) ?>"><?= e($holidays[$k]) ?></small><?php endif ?></div>
        <?php foreach (array_slice($items, 0, 3) as $r): ?>
          <?php if ($r['private']): ?>
            <span class="cal-ev" style="--c:<?= e($r['color']) ?>" title="<?= e($r['user_name']) ?> — Ausente"><?= e(explode(' ', $r['user_name'])[0]) ?></span>
          <?php else: ?>
            <a class="cal-ev <?= $r['status'] === 'pending' ? 'pend' : '' ?>" style="--c:<?= e($r['color']) ?>" href="<?= url('/solicitudes/' . $r['id']) ?>" title="<?= e($r['user_name'] . ' — ' . $r['type_name'] . ($r['status'] === 'pending' ? ' (pendiente)' : '')) ?>"><?= is_hourly($r) ? e(substr($r['start_time'], 0, 5)) . ' ' : '' ?><?= e(explode(' ', $r['user_name'])[0]) ?></a>
          <?php endif ?>
        <?php endforeach ?>
        <?php if (count($items) > 3): ?><small class="cal-more">+<?= count($items) - 3 ?> más</small><?php endif ?>
      </div>
    <?php endfor ?>
  </div>
  <div class="cal-legend">
    <span><i class="lg-solid"></i> Aprobada / informada</span>
    <span><i class="lg-pend"></i> Pendiente</span>
    <span><i class="lg-hol"></i> Feriado</span>
  </div>
</section>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Ausencias del mes</h2></div>
    <?php
    $monthList = array_filter($list, fn($r) => $r['end_date'] >= $first->format('Y-m-01') && $r['start_date'] <= $first->format('Y-m-t'));
    if ($monthList): ?>
      <ul class="people compact">
        <?php foreach ($monthList as $r): ?>
          <li><?= $r['private'] ? '<div>' : '<a href="' . url('/solicitudes/' . $r['id']) . '">' ?><?= avatar($r['user_name'], 'sm') ?><span><b><?= e($r['user_name']) ?></b><small><?= type_dot($r['color']) ?><?= e($r['type_name']) ?> · <?= e(fwhen($r)) ?><?= $r['status'] === 'pending' ? ' · pendiente' : '' ?></small></span><?= $r['private'] ? '</div>' : '</a>' ?></li>
        <?php endforeach ?>
      </ul>
    <?php else: ?><p class="empty-sm">No hay ausencias este mes.</p><?php endif ?>
  </section>
  <section class="card card-soft">
    <div class="card-head"><h2><?= icon('calendar') ?> Verlo en Google Calendar</h2></div>
    <p class="muted">Suscribite a este calendario y las ausencias aparecen solas en tu Google Calendar (se actualiza automáticamente).</p>
    <div class="copy-field"><input readonly value="<?= e($feed) ?>" id="feedUrl"><button class="btn btn-ghost btn-sm" data-copy="#feedUrl"><?= icon('link', 16) ?> Copiar</button></div>
    <ol class="howto">
      <li>Abrí <a href="https://calendar.google.com/calendar/u/0/r/settings/addbyurl" target="_blank" rel="noopener">Google Calendar → Agregar calendario → Desde URL</a>.</li>
      <li>Pegá el enlace y tocá <b>Agregar calendario</b>.</li>
    </ol>
    <small class="muted">Este enlace es personal: no lo compartas.</small>
  </section>
</div>
