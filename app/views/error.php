<div class="empty big">
  <div class="empty-code"><?= (int) $code ?></div>
  <h2><?= e($title) ?></h2>
  <p class="muted"><?= e($msg) ?></p>
  <p class="muted">Si el problema persiste, escribí a <a href="mailto:<?= e(support_email()) ?>"><?= e(support_email()) ?></a>.</p>
  <a class="btn btn-primary" href="<?= url('/') ?>"><?= icon('home', 16) ?> Ir al inicio</a>
</div>
