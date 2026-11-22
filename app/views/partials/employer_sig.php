<?php /** @var array $p Recibo de sueldo. */ ?>
<?php if ($p['employer_sig'] === 'valida'): ?>
  <span class="sig-tag ok" title="<?= e(($p['employer_sig_issuer'] ? 'Certificador: ' . $p['employer_sig_issuer'] : '') . ($p['employer_signed_at'] ? ' · ' . fdatetime($p['employer_signed_at']) : '')) ?>"><?= icon('check', 13) ?> Firmado digitalmente por <?= e($p['employer_signer'] ?: 'la empresa') ?></span>
<?php elseif ($p['employer_sig'] === 'no_verificada'): ?>
  <span class="sig-tag warn" title="<?= e($p['employer_sig_issuer'] ? 'Certificador: ' . $p['employer_sig_issuer'] : '') ?>"><?= icon('alert', 13) ?> Firma digital de <?= e($p['employer_signer'] ?: 'la empresa') ?> (certificador no reconocido)</span>
<?php else: ?>
  <span class="sig-tag none"><?= icon('info', 13) ?> Sin firma digital de la empresa</span>
<?php endif ?>
