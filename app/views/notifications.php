<div class="page-head">
  <div><h1>Notificaciones</h1></div>
  <?php if (array_filter($list, fn($n) => !$n['read_at'])): ?>
    <form method="post" action="<?= url('/notificaciones/leer') ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= icon('check', 16) ?> Marcar todas como leídas</button></form>
  <?php endif ?>
</div>
<section class="card">
  <?php if ($list): ?>
    <ul class="notif-list">
      <?php foreach ($list as $n): ?>
        <li class="<?= $n['read_at'] ? '' : 'unread' ?>">
          <form method="post" action="<?= url('/notificaciones/leer') ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
            <button class="notif">
              <span class="notif-dot"></span>
              <span><b><?= e($n['title']) ?></b><?php if ($n['body']): ?><small><?= e($n['body']) ?></small><?php endif ?></span>
              <time><?= e(ago($n['created_at'])) ?></time>
            </button>
          </form>
        </li>
      <?php endforeach ?>
    </ul>
  <?php else: ?>
    <div class="empty"><?= icon('bell', 32) ?><p>No tenés notificaciones.</p></div>
  <?php endif ?>
</section>
