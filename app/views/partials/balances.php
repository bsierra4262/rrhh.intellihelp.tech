<?php /** @var array $balances */ ?>
<div class="balances">
  <?php foreach ($balances as $b):
      $pctUsed = $b['total'] > 0 ? min(100, $b['used'] / $b['total'] * 100) : 0;
      $pctPend = $b['total'] > 0 ? min(100 - $pctUsed, $b['pending'] / $b['total'] * 100) : 0;
  ?>
    <div class="balance">
      <div class="balance-head">
        <span><?= type_dot($b['color']) ?><?= e($b['name']) ?></span>
        <small><?= e(rtrim(rtrim(number_format((float) $b['used'], 1, ',', ''), '0'), ',')) ?> / <?= (int) $b['total'] ?></small>
      </div>
      <div class="balance-num"><b><?= e(rtrim(rtrim(number_format((float) $b['left'], 1, ',', ''), '0'), ',')) ?></b> <span>disponibles</span></div>
      <div class="meter" role="img" aria-label="<?= e($b['name']) ?>: usados <?= e($b['used']) ?> de <?= (int) $b['total'] ?>">
        <i style="width:<?= $pctUsed ?>%;background:<?= e($b['color']) ?>"></i><i class="pend" style="width:<?= $pctPend ?>%;--c:<?= e($b['color']) ?>"></i>
      </div>
      <?php if ($b['pending'] > 0): ?><small class="muted"><?= e(fdays($b['pending'])) ?> en revisión</small><?php endif ?>
      <?php if (!empty($b['carried'])): ?><small class="muted">Incluye <?= e(fdays($b['carried'])) ?> acumulados del año anterior</small><?php endif ?>
      <?php if (!empty($b['previous'])): ?><small class="muted">Además quedan <?= e(fdays($b['previous'])) ?> de <?= (int) $b['previous_year'] ?>, para vacaciones que empiecen hasta el 30 de abril</small><?php endif ?>
      <?php if ($b['code'] === 'vacaciones'): ?><small class="muted">Se pueden usar hasta el 30 de abril del año siguiente y no se pagan en dinero, salvo al terminar la relación laboral.</small><?php endif ?>
    </div>
  <?php endforeach ?>
</div>
