<?php
/** @var array $pg Resultado de paginate(): rows, page, pages, total, from, to. */
if (empty($pg) || $pg['total'] <= PER_PAGE) {
    return;
}
$p = $pg['page'];
$n = $pg['pages'];
// Primera, última y las vecinas de la actual; el resto se resume con «…».
$show = array_unique(array_filter([1, 2, $p - 1, $p, $p + 1, $n - 1, $n], fn ($i) => $i >= 1 && $i <= $n));
sort($show);
?>
<nav class="pager" aria-label="Páginas">
  <span class="pager-info">Mostrando <b><?= $pg['from'] ?>–<?= $pg['to'] ?></b> de <b><?= $pg['total'] ?></b></span>
  <div class="pager-links">
    <?php if ($p > 1): ?><a class="pager-btn" href="<?= e(page_url($p - 1)) ?>" aria-label="Página anterior"><?= icon('chevron-left', 16) ?></a><?php else: ?><span class="pager-btn disabled"><?= icon('chevron-left', 16) ?></span><?php endif ?>
    <?php $prev = 0; foreach ($show as $i): ?>
      <?php if ($i - $prev > 1): ?><span class="pager-gap">…</span><?php endif ?>
      <?php if ($i === $p): ?><span class="pager-btn current" aria-current="page"><?= $i ?></span><?php else: ?><a class="pager-btn" href="<?= e(page_url($i)) ?>"><?= $i ?></a><?php endif ?>
    <?php $prev = $i; endforeach ?>
    <?php if ($p < $n): ?><a class="pager-btn" href="<?= e(page_url($p + 1)) ?>" aria-label="Página siguiente"><?= icon('chevron-right', 16) ?></a><?php else: ?><span class="pager-btn disabled"><?= icon('chevron-right', 16) ?></span><?php endif ?>
  </div>
</nav>
