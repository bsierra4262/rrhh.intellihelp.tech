<?php $money = fn ($n) => $n === null ? '—' : '$ ' . number_format((float) $n, 2, ',', '.'); ?>
<div class="page-head">
  <div><h1>Mis recibos</h1><p class="muted">Tus recibos de sueldo. Revisalos y firmá tu conformidad; si algo no coincide, firmalo en disconformidad y contá el motivo.</p></div>
</div>
<?php if ($testing): ?>
  <div class="alert alert-info inline"><?= icon('info') ?><span><b>En prueba:</b> por ahora esta sección la ven solo los administradores.</span></div>
<?php endif ?>

<?php if (!$list): ?>
  <section class="card"><div class="empty"><?= icon('file', 30) ?><p>Todavía no hay recibos cargados.</p></div></section>
<?php endif ?>

<div class="payslip-list">
  <?php foreach ($list as $p): ?>
    <section class="card payslip">
      <div class="payslip-head">
        <span class="req-icon" style="--c:#663399"><?= icon('file', 18) ?></span>
        <span class="grow"><b><?= e($p['label']) ?></b><small class="muted"><?= $p['pay_date'] ? 'Pagado el ' . e(fdate($p['pay_date'])) : '' ?></small><?php partial('employer_sig', ['p' => $p]) ?></span>
        <span class="payslip-net"><small>Neto</small><b><?= e($money($p['net'])) ?></b></span>
      </div>
      <div class="payslip-foot">
        <?php if ($p['sign_status'] === 'conforme'): ?>
          <span class="badge badge-approved">Firmado conforme · <?= e(fdatetime($p['signed_at'])) ?></span>
        <?php elseif ($p['sign_status'] === 'disconforme'): ?>
          <span class="badge badge-pending">Firmado en disconformidad · <?= e(fdatetime($p['signed_at'])) ?></span>
        <?php else: ?>
          <span class="badge badge-cancelled">Pendiente de firma</span>
        <?php endif ?>
        <span class="grow"></span>
        <a class="btn btn-ghost btn-sm" href="<?= url('/recibos/' . $p['id'] . '/archivo') ?>" target="_blank" rel="noopener"><?= icon('file', 16) ?> Ver</a>
        <a class="btn btn-ghost btn-sm" href="<?= url('/recibos/' . $p['id'] . '/archivo?descargar=1' . ($p['signed_drive_id'] ? '&firmado=1' : '')) ?>"><?= icon('download', 16) ?> Descargar</a>
        <?php if (!$p['signed_at']): ?>
          <form method="post" action="<?= url('/recibos/' . $p['id'] . '/firmar') ?>"><?= csrf_field() ?>
            <button class="btn btn-primary btn-sm" name="decision" value="conforme" data-confirm="Vas a firmar de conformidad el recibo de <?= e($p['label']) ?>: declarás haber recibido el importe neto de esta liquidación. La firma queda registrada con la fecha y la hora."><?= icon('check', 16) ?> Firmar conforme</button>
          </form>
          <details class="disagree">
            <summary class="btn btn-ghost btn-sm">En disconformidad</summary>
            <form method="post" action="<?= url('/recibos/' . $p['id'] . '/firmar') ?>" class="form">
              <?= csrf_field() ?><input type="hidden" name="decision" value="disconforme">
              <label class="field"><span>Motivo</span><textarea name="comment" rows="2" maxlength="500" required placeholder="Ej.: falta liquidar las horas extra de mayo"></textarea></label>
              <button class="btn btn-ghost btn-sm" data-confirm="Vas a firmar el recibo de <?= e($p['label']) ?> en disconformidad. RRHH verá el motivo."><?= icon('check', 16) ?> Firmar en disconformidad</button>
            </form>
          </details>
        <?php endif ?>
      </div>
    </section>
  <?php endforeach ?>
</div>
