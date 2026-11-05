<div class="page-head">
  <div><h1>Por autorizar</h1><p class="muted"><?= count($pending) ?> solicitud<?= count($pending) === 1 ? '' : 'es' ?> esperando tu decisión.</p></div>
</div>

<?php if ($pending): ?>
  <div class="approval-list">
    <?php foreach ($pending as $r): ?>
      <article class="approval card">
        <a class="approval-main" href="<?= url('/solicitudes/' . $r['id']) ?>">
          <?= avatar($r['user_name']) ?>
          <div>
            <b><?= e($r['user_name']) ?></b>
            <small><?= type_dot($r['color']) ?><?= e($r['type_name']) ?> · <?= e(fwhen($r)) ?> · <b><?= e(fduration($r, false)) ?></b><?= !empty($r['outside_period']) ? ' <span class="badge badge-pending">Fuera del período habitual</span>' : '' ?></small>
            <?php if ($r['reason']): ?><p class="muted clamp">«<?= e($r['reason']) ?>»</p><?php endif ?>
            <small class="muted">Pedida <?= e(ago($r['created_at'])) ?></small>
          </div>
        </a>
        <form method="post" action="<?= url('/solicitudes/' . $r['id'] . '/decidir') ?>" class="approval-actions">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="aprobaciones">
          <?php if (!empty($r['vacation_optional']) || week_choice($r) || !empty($r['outside_period'])): ?>
            <a class="btn btn-positive btn-sm" href="<?= url('/solicitudes/' . $r['id']) ?>#decidir"><?= icon('check', 16) ?> Revisar y decidir</a>
          <?php else: ?>
            <button class="btn btn-positive btn-sm" name="decision" value="approved"><?= icon('check', 16) ?> Autorizar</button>
            <a class="btn btn-ghost btn-sm" href="<?= url('/solicitudes/' . $r['id']) ?>#decidir">Ver / rechazar</a>
          <?php endif ?>
        </form>
      </article>
    <?php endforeach ?>
  </div>
<?php else: ?>
  <section class="card"><div class="empty"><?= icon('check-circle', 36) ?><p><b>¡Estás al día!</b><br>No hay solicitudes pendientes.</p></div></section>
<?php endif ?>

<?php if ($decided): ?>
<section class="card">
  <div class="card-head"><h2>Resueltas por vos recientemente</h2></div>
  <div class="req-list"><?php foreach ($decided as $r) partial('request_row', ['r' => $r, 'showUser' => true]); ?></div>
</section>
<?php endif ?>
