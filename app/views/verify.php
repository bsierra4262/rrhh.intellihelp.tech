<?php $byFile = $byFile ?? false; ?>
<section class="pub-card">
  <div class="pub-head">
    <span class="pub-ic"><?= icon('shield', 22) ?></span>
    <div>
      <h1>Verificación de documentos</h1>
      <p class="muted">Comprobá que un recibo de sueldo emitido por Intellihelp SA es auténtico y no fue modificado.</p>
    </div>
  </div>

  <?php if ($result !== null): ?>
    <?php if ($result['found']): $p = $result['p']; ?>
      <div class="verify-result ok" role="status">
        <div class="verify-head"><?= icon('check-circle', 22) ?><b><?= match ($result['match']) {
            'signed' => 'Documento auténtico · copia firmada sin modificaciones',
            'original' => 'Documento auténtico · recibo emitido sin modificaciones',
            default => 'Código válido · corresponde a un documento emitido',
        } ?></b></div>
        <dl>
          <dt>Documento</dt><dd>Recibo de sueldo · <?= e($p['label']) ?></dd>
          <dt>Emisor</dt><dd>INTELLIHELP SA · CUIT 30-71140975-7</dd>
          <dt>Titular</dt><dd><?= e(holder_initials((string) $p['user_name'])) ?></dd>
          <dt>Firma del empleador</dt><dd><?= $p['employer_sig'] === 'valida' ? 'Firma digital verificada' : 'Firmado por la empresa' ?></dd>
          <dt>Firma del trabajador</dt><dd><?= $p['signed_at'] ? 'Firmado electrónicamente el ' . e(date('d/m/Y', strtotime($p['signed_at']))) : 'Pendiente de firma' ?></dd>
        </dl>
        <?php if ($result['match'] === 'code'): ?><p class="verify-note">El código confirma que el documento existe. Para comprobar que tu archivo no fue modificado, subilo abajo.</p><?php endif ?>
      </div>
    <?php else: ?>
      <div class="verify-result bad" role="alert">
        <div class="verify-head"><?= icon('x-circle', 22) ?><b><?= match ($result['reason']) {
            'limit' => 'Se alcanzó el límite de consultas',
            'format' => 'El código no tiene el formato correcto',
            'file' => 'El archivo no es válido',
            'code' => 'No hay ningún documento con ese código',
            default => 'El archivo no coincide con ningún documento emitido',
        } ?></b></div>
        <p><?= match ($result['reason']) {
            'limit' => 'Por seguridad hay un límite de verificaciones. Probá de nuevo en unos minutos.',
            'format' => 'El código tiene 16 caracteres: números y letras de la A a la F.',
            'file' => 'Subí el documento en PDF, de hasta ' . VERIFY_MAX_MB . ' MB.',
            'code' => 'Revisá que esté bien escrito tal como figura en la firma del recibo.',
            default => 'Puede haber sido modificado o no fue emitido por Intellihelp SA. Pedí una copia a Recursos Humanos.',
        } ?></p>
      </div>
    <?php endif ?>
  <?php endif ?>

  <div class="pub-methods">
    <form method="post" action="<?= url('/verificar') ?>" class="form">
      <?= csrf_field() ?>
      <label class="field"><span>Código de verificación</span>
        <input name="codigo" class="code-input" value="<?= e($code) ?>" maxlength="24" placeholder="Ej.: 306DA002D3F43D6C" autocomplete="off" spellcheck="false" required>
        <small class="muted">Figura en la firma del recibo, debajo de los datos del trabajador.</small></label>
      <button class="btn btn-primary btn-block"><?= icon('search', 16) ?> Verificar código</button>
    </form>
    <div class="pub-or"><span>o</span></div>
    <form method="post" action="<?= url('/verificar') ?>" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <label class="field"><span>Documento en PDF</span><input type="file" name="file" accept="application/pdf" required>
        <small class="muted">Se compara con el emitido y se descarta: no se guarda.</small></label>
      <button class="btn btn-ghost btn-block"><?= icon('check', 16) ?> Verificar documento</button>
    </form>
  </div>
</section>
<p class="pub-legal">La verificación confirma la autenticidad del documento sin mostrar importes ni datos personales. Ante cualquier duda, comunicate con Recursos Humanos de Intellihelp SA.</p>
