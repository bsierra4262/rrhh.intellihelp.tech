<?php $byFile = $byFile ?? false; ?>
<h2>Verificar un recibo de sueldo</h2>
<p class="muted">Ingresá el código de verificación que figura en la firma del recibo, o subí el PDF para confirmar que no fue modificado.</p>

<?php if ($result !== null): ?>
  <?php if ($result['found']): $p = $result['p']; ?>
    <div class="verify-result ok">
      <div class="verify-head"><?= icon('check-circle', 22) ?><b><?= $result['match'] === 'code' ? 'El código corresponde a un recibo emitido' : ($result['match'] === 'signed' ? 'Documento auténtico: es la copia firmada, sin modificaciones' : 'Documento auténtico: es el recibo emitido, sin modificaciones') ?></b></div>
      <dl>
        <dt>Empresa</dt><dd>INTELLIHELP SA · CUIT 30-71140975-7</dd>
        <dt>Recibo</dt><dd><?= e($p['label']) ?><?= $p['pay_date'] ? ' · pagado el ' . e(fdate($p['pay_date'])) : '' ?></dd>
        <dt>Trabajador/a</dt><dd><?= e(mask_name((string) $p['user_name'])) ?> · CUIL <?= e(mask_cuil($p['cuil'])) ?></dd>
        <dt>Firma de la empresa</dt><dd><?= $p['employer_sig'] === 'valida' ? 'Firma digital de ' . e($p['employer_signer']) : ($p['employer_sig'] === 'emitido' ? 'Firmado por la empresa · ' . e($p['employer_signer']) : 'Emitido por la empresa') ?></dd>
        <dt>Firma del trabajador/a</dt><dd><?= $p['signed_at'] ? ($p['sign_status'] === 'conforme' ? 'Conforme' : 'En disconformidad') . ' · ' . e(fdatetime($p['signed_at'])) : 'Todavía no firmado' ?></dd>
        <dt>Código</dt><dd><code><?= e(strtoupper(substr($p['sha256'], 0, 16))) ?></code></dd>
      </dl>
      <?php if ($result['match'] === 'code'): ?><p class="muted">Para confirmar que tu archivo no fue modificado, subí el PDF abajo.</p><?php endif ?>
    </div>
  <?php else: ?>
    <div class="verify-result bad">
      <div class="verify-head"><?= icon('x-circle', 22) ?><b><?= $byFile ? 'El archivo no coincide con ningún recibo emitido' : 'No hay ningún recibo con ese código' ?></b></div>
      <p><?= $byFile ? 'Puede haber sido modificado, o no es un recibo emitido por el portal. Pedí una copia a Recursos Humanos.' : 'Revisá que el código esté bien escrito: tiene 16 caracteres, números y letras de la A a la F.' ?></p>
    </div>
  <?php endif ?>
<?php endif ?>

<form method="post" action="<?= url('/verificar') ?>" class="form">
  <?= csrf_field() ?>
  <label class="field"><span>Código de verificación</span>
    <input name="codigo" value="<?= e($code) ?>" maxlength="20" placeholder="Ej.: 306DA002D3F43D6C" autocomplete="off" spellcheck="false" style="text-transform:uppercase;letter-spacing:.06em"></label>
  <button class="btn btn-primary btn-block"><?= icon('search', 16) ?> Verificar código</button>
</form>
<form method="post" action="<?= url('/verificar') ?>" enctype="multipart/form-data" class="form" style="margin-top:14px">
  <?= csrf_field() ?>
  <label class="field"><span>O subí el recibo en PDF</span><input type="file" name="file" accept="application/pdf" required></label>
  <button class="btn btn-ghost btn-block"><?= icon('check', 16) ?> Verificar archivo</button>
</form>
<a class="link-sm center" href="<?= url('/login') ?>"><?= icon('arrow-left', 14) ?> Ir al portal</a>
