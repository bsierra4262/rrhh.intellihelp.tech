<div class="page-head">
  <div><h1><?= e($title) ?></h1><p class="muted"><?= count($list) ?> resultado<?= count($list) === 1 ? '' : 's' ?></p></div>
  <a class="btn btn-ghost" href="?<?= e(http_build_query($f + ['export' => 'csv'])) ?>"><?= icon('download', 16) ?> Exportar CSV</a>
</div>

<section class="card">
  <form class="filters wrap" method="get">
    <label class="field sm grow"><span>Buscar</span><input name="q" value="<?= e($f['q']) ?>" placeholder="Nombre o área"></label>
    <label class="field sm"><span>Tipo</span>
      <select name="tipo"><option value="">Todos</option>
        <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $f['tipo'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach ?>
      </select>
    </label>
    <label class="field sm"><span>Estado</span>
      <select name="estado"><option value="">Todos</option>
        <?php foreach (STATUS_LABELS as $k => $l): ?><option value="<?= $k ?>" <?= $f['estado'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
      </select>
    </label>
    <label class="field sm"><span>Documentación</span>
      <select name="doc"><option value="">Todas</option>
        <?php foreach (DOC_LABELS as $k => $l): ?><option value="<?= $k ?>" <?= $f['doc'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
      </select>
    </label>
    <label class="field sm"><span>Desde</span><input type="date" name="desde" value="<?= e($f['desde']) ?>"></label>
    <label class="field sm"><span>Hasta</span><input type="date" name="hasta" value="<?= e($f['hasta']) ?>"></label>
    <button class="btn btn-primary btn-sm"><?= icon('search', 16) ?> Filtrar</button>
  </form>

  <?php if ($list): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Colaborador/a</th><th>Tipo</th><th>Fechas</th><th class="num">Duración</th><th>Estado</th><th>Doc.</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($list as $r): ?>
          <tr data-href="<?= url('/solicitudes/' . $r['id']) ?>">
            <td><div class="cell-person"><?= avatar($r['user_name'], 'sm') ?><span><b><?= e($r['user_name']) ?></b><small><?= e($r['department'] ?? '') ?></small></span></div></td>
            <td><?= type_dot($r['color']) ?><?= e($r['type_name']) ?></td>
            <td class="nowrap"><?= e(fwhen($r)) ?></td>
            <td class="num"><?= is_hourly($r) ? e(fhours($r['hours'])) : e(rtrim(rtrim(number_format((float) $r['days'], 1, ',', ''), '0'), ',')) ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td><?= $r['doc_status'] !== 'none' ? '<span title="' . e(DOC_LABELS[$r['doc_status']]) . '" class="doc-ic doc-' . e($r['doc_status']) . '">' . icon('paperclip', 16) . '</span>' : '' ?></td>
            <td><a class="icon-btn" href="<?= url('/solicitudes/' . $r['id']) ?>" aria-label="Ver"><?= icon('chevron-right', 16) ?></a></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty"><?= icon('search', 30) ?><p>No hay licencias con esos filtros.</p></div>
  <?php endif ?>
</section>
