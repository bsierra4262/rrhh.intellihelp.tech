<?php /** @var array $r  @var bool $showUser */ ?>
<a class="req-row" href="<?= url('/solicitudes/' . $r['id']) ?>">
  <span class="req-icon" style="--c:<?= e($r['color']) ?>"><?= icon(type_icon($r['type_code']), 18) ?></span>
  <span class="req-main">
    <?php if (!empty($showUser)): ?>
      <b><?= e($r['user_name']) ?></b>
      <small><?= e($r['type_name']) ?> · <?= e(frange($r['start_date'], $r['end_date'])) ?> · <?= e(fdays($r['days'])) ?></small>
    <?php else: ?>
      <b><?= e($r['type_name']) ?></b>
      <small><?= e(frange($r['start_date'], $r['end_date'])) ?> · <?= e(fdays($r['days'])) ?></small>
    <?php endif ?>
  </span>
  <span class="req-side"><?= status_badge($r['status']) ?><?= icon('chevron-right', 16) ?></span>
</a>
