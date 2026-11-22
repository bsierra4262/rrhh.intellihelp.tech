<div class="page-head">
  <div><h1>Áreas y puestos</h1><p class="muted">Las opciones que se eligen en la ficha de cada persona. Podés agregar, cambiar el nombre o eliminar cualquiera.</p></div>
  <button class="btn btn-primary" data-open="#newArea"><?= icon('plus', 16) ?> Nueva área</button>
</div>

<details class="card type-edit" id="newArea" hidden>
  <summary><span class="grow"><b>Nueva área</b></span><?= icon('edit', 16) ?></summary>
  <form method="post" action="<?= url('/admin/areas') ?>" class="form inline-form">
    <?= csrf_field() ?><input type="hidden" name="do" value="area_add">
    <label class="field grow"><span>Nombre del área</span><input name="name" required maxlength="120" placeholder="Ej.: Calidad"></label>
    <button class="btn btn-primary"><?= icon('check', 16) ?> Agregar</button>
  </form>
</details>

<p class="muted" style="margin:4px 0 14px">Los nombres siguen el Clasificador Nacional de Ocupaciones del INDEC (CNO-17), la clasificación internacional de la OIT (CIUO-08) y los títulos con que se publican los puestos en el mercado laboral. Al cambiar un nombre se actualiza en la ficha de quienes lo tienen; lo que está asignado a alguien no se puede eliminar.</p>

<?php foreach ($areas as $a): $list = $positions[$a['id']] ?? []; ?>
  <details class="card type-edit" id="area-<?= (int) $a['id'] ?>">
    <summary>
      <span class="grow"><b><?= e($a['name']) ?></b>
        <small class="muted"><?= count($list) ?> puesto<?= count($list) === 1 ? '' : 's' ?><?= $a['people'] ? ' · ' . (int) $a['people'] . ' persona' . ($a['people'] > 1 ? 's' : '') : '' ?></small></span>
      <?= icon('edit', 16) ?>
    </summary>

    <form method="post" action="<?= url('/admin/areas') ?>" class="form inline-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
      <label class="field grow"><span>Nombre del área</span><input name="name" value="<?= e($a['name']) ?>" required maxlength="120"></label>
      <button class="btn btn-ghost btn-sm" name="do" value="area_rename"><?= icon('check', 16) ?> Guardar</button>
      <button class="btn btn-outline-danger btn-sm" name="do" value="area_delete" formnovalidate data-confirm="¿Eliminar el área «<?= e($a['name']) ?>» y todos sus puestos?"><?= icon('trash', 16) ?> Eliminar área</button>
    </form>

    <ul class="pos-list">
      <?php foreach ($list as $p): ?>
        <li>
          <form method="post" action="<?= url('/admin/areas') ?>" class="pos-row">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="area_id" value="<?= (int) $a['id'] ?>">
            <input name="name" value="<?= e($p['name']) ?>" required maxlength="120" aria-label="Nombre del puesto">
            <?php if ($p['people']): ?><span class="badge badge-registered"><?= (int) $p['people'] ?> persona<?= $p['people'] > 1 ? 's' : '' ?></span><?php endif ?>
            <button class="icon-btn" name="do" value="pos_rename" aria-label="Guardar" title="Guardar"><?= icon('check', 16) ?></button>
            <button class="icon-btn" name="do" value="pos_delete" formnovalidate aria-label="Eliminar" title="Eliminar" data-confirm="¿Eliminar el puesto «<?= e($p['name']) ?>»?"><?= icon('trash', 16) ?></button>
          </form>
        </li>
      <?php endforeach ?>
    </ul>
    <form method="post" action="<?= url('/admin/areas') ?>" class="form inline-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="pos_add"><input type="hidden" name="area_id" value="<?= (int) $a['id'] ?>">
      <label class="field grow"><span>Agregar un puesto</span><input name="name" required maxlength="120" placeholder="Nombre del puesto"></label>
      <button class="btn btn-ghost btn-sm"><?= icon('plus', 16) ?> Agregar</button>
    </form>
  </details>
<?php endforeach ?>
