<?php
$mine = (int) $r['user_id'] === uid();
$stages = request_stages($r, $docs, $events);
$next = null;
foreach ($stages as $st) {
    if ($st['state'] === 'current') {
        $next = $st;
        break;
    }
}
$stageIcon = ['done' => 'check', 'current' => 'clock', 'failed' => 'x', 'todo' => '', 'optional' => 'paperclip'];
$showDocs = accepts_documents($r) || $docs;
$gcal = gcal_link($r['type_name'] . ($mine ? '' : ' — ' . $r['user_name']), $r['start_date'], $r['end_date'], url('/solicitudes/' . $r['id']), $r['start_time'], $r['end_time']);
?>
<div class="page-head">
  <div>
    <a class="back" href="<?= url($mine ? '/solicitudes' : (is_approver() ? '/aprobaciones' : '/')) ?>"><?= icon('arrow-left', 16) ?> Volver</a>
    <h1><span class="req-icon lg" style="--c:<?= e($r['color']) ?>"><?= icon(type_icon($r['type_code']), 22) ?></span> <?= e($r['type_name']) ?> <small class="muted">#<?= (int) $r['id'] ?></small></h1>
  </div>
  <div class="head-badges"><?= status_badge($r['status']) ?> <?= doc_badge($r['doc_status']) ?></div>
</div>

<section class="card stages-card">
  <ol class="stages">
    <?php foreach ($stages as $i => $st): ?>
      <li class="stage stage-<?= e($st['state']) ?>">
        <span class="stage-dot"><?= $stageIcon[$st['state']] ? icon($stageIcon[$st['state']], 15) : $i + 1 ?></span>
        <div class="stage-txt">
          <b><?= e($st['title']) ?></b>
          <?php if ($st['detail']): ?><small><?= e($st['detail']) ?></small><?php endif ?>
          <?php if (!empty($st['note'])): ?><p>«<?= nl2br(e($st['note'])) ?>»</p><?php endif ?>
        </div>
      </li>
    <?php endforeach ?>
  </ol>
  <?php if ($next): ?>
    <p class="stages-next"><?= icon('arrow-right', 15) ?> <span>Etapa actual: <b><?= e($next['title']) ?></b><?= $next['detail'] ? ' — ' . e($next['detail']) : '' ?></span></p>
  <?php endif ?>
</section>

<div class="grid-main">
  <div class="stack">
    <section class="card">
      <div class="detail-grid">
        <div class="person">
          <?= avatar($r['user_name'], 'lg') ?>
          <div><b><?= e($r['user_name']) ?></b><small><?= e(implode(' · ', array_filter([$r['position'], $r['department']]))) ?: e($r['user_email']) ?></small></div>
        </div>
        <?php if (is_hourly($r)): ?>
          <div class="kv"><span>Día</span><b><?= e(ucfirst(flongdate($r['start_date']))) ?></b></div>
          <div class="kv"><span>Horario</span><b><?= e(substr($r['start_time'], 0, 5)) ?> a <?= e(substr($r['end_time'], 0, 5)) ?> h</b></div>
        <?php else: ?>
          <div class="kv"><span>Desde</span><b><?= e(ucfirst(flongdate($r['start_date']))) ?></b></div>
          <div class="kv"><span>Hasta</span><b><?= e(ucfirst(flongdate($r['end_date']))) ?></b></div>
        <?php endif ?>
        <div class="kv"><span>Duración</span><b><?= e(fduration($r)) ?></b></div>
        <div class="kv"><span>Cargada</span><b><?= e(fdatetime($r['created_at'])) ?></b></div>
      </div>
      <?php if ($r['reason']): ?>
        <div class="reason"><span>Comentario</span><p><?= nl2br(e($r['reason'])) ?></p></div>
      <?php endif ?>
      <?php if (in_array($r['status'], ['approved', 'registered', 'pending'], true)): ?>
        <div class="actions-row">
          <a class="btn btn-ghost btn-sm" href="<?= e($gcal) ?>" target="_blank" rel="noopener"><?= icon('calendar', 16) ?> Agregar a Google Calendar</a>
        </div>
      <?php endif ?>
    </section>

    <?php if ($can_decide): ?>
    <section class="card card-accent decide" id="decidir">
      <h2><?= icon('check-circle') ?> Tu decisión</h2>
      <?php if ($overlaps): ?>
        <div class="alert alert-warning inline"><?= icon('alert') ?><span>En esas fechas también faltan: <?= e(implode(', ', array_map(fn($o) => $o['user_name'] . ' (' . fwhen($o) . ')', $overlaps))) ?>.</span></div>
      <?php endif ?>
      <form method="post" action="<?= url('/solicitudes/' . $r['id'] . '/decidir') ?>" class="form">
        <?= csrf_field() ?>
        <label class="field"><span>Comentario para <?= e(explode(' ', $r['user_name'])[0]) ?> <small class="muted">(obligatorio si rechazás)</small></span>
          <textarea name="comment" rows="2" maxlength="2000" placeholder="Ej.: ¡Que lo disfrutes! / Necesitamos cubrir la guardia esa semana…"></textarea>
        </label>
        <?php if (!empty($r['vacation_optional'])): ?>
          <label class="deduct">
            <input type="checkbox" name="deduct_vacation" value="1">
            <span><b>Descontar de las vacaciones</b>
              <small><?= e(fdays($r['days'])) ?> de las vacaciones de <?= e(explode(' ', $r['user_name'])[0]) ?> · le quedan <?= e(fdays($vacation_left ?? 0)) ?> disponibles</small>
            </span>
          </label>
        <?php endif ?>
        <?php if (accepts_documents($r)): $autoDue = doc_auto_due(array_merge($r, ['status' => 'approved'])); ?>
          <fieldset class="due-box">
            <legend><?= icon('clock', 15) ?> Plazo para entregar <?= e(mb_strtolower($r['document_label'] ?: 'documentación')) ?></legend>
            <label class="due-opt"><input type="radio" name="doc_due_mode" value="auto" checked> <span>Automático: <b><?= e(fdate($autoDue)) ?></b></span></label>
            <label class="due-opt"><input type="radio" name="doc_due_mode" value="custom"> <span>Elegir fecha</span>
              <input type="date" name="doc_due_date" min="<?= e(doc_due_min($r)) ?>" value="<?= e($autoDue) ?>" data-due-date>
            </label>
          </fieldset>
        <?php endif ?>
        <div class="btn-row">
          <button class="btn btn-positive btn-lg" name="decision" value="approved"><?= icon('check', 18) ?> Autorizar</button>
          <button class="btn btn-outline-danger btn-lg" name="decision" value="rejected" data-confirm="¿Rechazar la solicitud?"><?= icon('x', 18) ?> Rechazar</button>
        </div>
      </form>
    </section>
    <?php endif ?>

    <?php if ($showDocs): ?>
    <section class="card">
      <div class="card-head">
        <h2><?= icon('paperclip') ?> <?= e($r['document_label'] ?: 'Documentación') ?><?= $r['requires_document'] ? ' <small class="req-mark">obligatoria</small>' : '' ?></h2>
      </div>
      <?php if ($docs): ?>
        <ul class="docs">
          <?php foreach ($docs as $d): $isImg = str_starts_with($d['mime'], 'image/') && $d['mime'] !== 'image/heic'; ?>
            <li>
              <a class="doc-thumb" href="<?= url('/archivos/' . $d['id']) ?>" target="_blank" rel="noopener">
                <?php if ($isImg): ?><img src="<?= url('/archivos/' . $d['id']) ?>" alt="" loading="lazy"><?php else: ?><?= icon('file', 28) ?><?php endif ?>
              </a>
              <div class="doc-info">
                <a href="<?= url('/archivos/' . $d['id']) ?>" target="_blank" rel="noopener"><b><?= e($d['original_name']) ?></b></a>
                <small><?= e(round($d['size'] / 1024)) ?> KB · subido por <?= e($d['uploader']) ?> · <?= e(fdatetime($d['created_at'])) ?></small>
              </div>
              <a class="icon-btn" href="<?= url('/archivos/' . $d['id'], ['descargar' => 1]) ?>" title="Descargar"><?= icon('download', 18) ?></a>
            </li>
          <?php endforeach ?>
        </ul>
      <?php else: ?>
        <p class="empty-sm">
          <?php if ($r['requires_approval'] && $r['status'] === 'pending'): ?>
            <?= icon('info', 16) ?> Se entrega una vez autorizada la solicitud.
          <?php elseif ($r['doc_due_date'] && $r['doc_due_date'] < date('Y-m-d') && doc_pending($r)): ?>
            <?= icon('alert', 16) ?> El plazo de entrega venció el <?= e(fdate($r['doc_due_date'])) ?>.
          <?php elseif ($r['requires_document']): ?>
            <?= icon('clock', 16) ?> Pendiente de entrega<?= $r['doc_due_date'] ? ' hasta el ' . e(fdate($r['doc_due_date'])) : '' ?>.
          <?php else: ?>
            Opcional<?= $r['doc_due_date'] ? ': se puede entregar hasta el ' . e(fdate($r['doc_due_date'])) : '' ?>.
          <?php endif ?>
        </p>
      <?php endif ?>

      <?php if (is_hr() && $docs && in_array($r['doc_status'], ['uploaded', 'observed'], true)): ?>
        <form method="post" action="<?= url('/solicitudes/' . $r['id'] . '/revisar-doc') ?>" class="form review-doc">
          <?= csrf_field() ?>
          <label class="field"><span>Revisión de RRHH</span><input name="comment" maxlength="1000" placeholder="Comentario (obligatorio si observás)"></label>
          <div class="btn-row">
            <button class="btn btn-positive btn-sm" name="result" value="verified"><?= icon('shield', 16) ?> Marcar como verificada</button>
            <button class="btn btn-ghost btn-sm" name="result" value="observed"><?= icon('alert', 16) ?> Observar</button>
          </div>
        </form>
      <?php endif ?>

      <?php if ($r['doc_due_date'] && $docs && doc_pending($r)): ?>
        <p class="empty-sm"><?= icon('clock', 16) ?> Plazo de entrega: <?= e(fdate($r['doc_due_date'])) ?>.</p>
      <?php endif ?>

      <?php if (can_set_doc_due($r) && $r['doc_due_date'] && $r['doc_status'] !== 'uploaded'): ?>
        <details class="due-edit">
          <summary><?= icon('edit', 14) ?> Cambiar plazo de entrega <small class="muted">(actual: <?= e(fdate($r['doc_due_date'])) ?><?= $r['doc_due_custom'] ? ', fecha elegida' : ', automático' ?>)</small></summary>
          <form method="post" action="<?= url('/solicitudes/' . $r['id'] . '/plazo') ?>" class="due-box">
            <?= csrf_field() ?>
            <label class="due-opt"><input type="radio" name="doc_due_mode" value="auto" <?= $r['doc_due_custom'] ? '' : 'checked' ?>> <span>Automático: <b><?= e(fdate(doc_auto_due($r))) ?></b></span></label>
            <label class="due-opt"><input type="radio" name="doc_due_mode" value="custom" <?= $r['doc_due_custom'] ? 'checked' : '' ?>> <span>Elegir fecha</span>
              <input type="date" name="doc_due_date" min="<?= e(doc_due_min($r)) ?>" value="<?= e($r['doc_due_date']) ?>" data-due-date>
            </label>
            <button class="btn btn-primary btn-sm"><?= icon('check', 14) ?> Guardar plazo</button>
          </form>
        </details>
      <?php endif ?>

      <?php if ($can_upload): ?>
        <form method="post" action="<?= url('/solicitudes/' . $r['id'] . '/documentos') ?>" enctype="multipart/form-data" class="upload-inline">
          <?= csrf_field() ?>
          <label class="drop sm">
            <input type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,application/pdf,image/*" required data-autosubmit>
            <?= icon('upload', 20) ?> <b><?= $docs ? 'Agregar otro archivo' : 'Subir ' . e(mb_strtolower($r['document_label'] ?: 'documentación')) ?></b>
          </label>
        </form>
      <?php endif ?>
    </section>
    <?php endif ?>
  </div>

  <aside class="stack">
    <?php if ($balances): ?>
    <section class="card card-soft">
      <h3 class="sub">Días disponibles de <?= e(explode(' ', $r['user_name'])[0]) ?> · <?= substr($r['start_date'], 0, 4) ?></h3>
      <?php partial('balances', ['balances' => $balances]) ?>
    </section>
    <?php endif ?>

    <section class="card">
      <h3 class="sub">Historial</h3>
      <ol class="timeline">
        <?php foreach ($events as $ev): ?>
          <li class="ev-<?= e($ev['action']) ?>">
            <b><?= e($ev['user_name'] ?? 'Sistema') ?></b> <?= e(EVENT_LABELS[$ev['action']] ?? $ev['action']) ?>
            <?php if ($ev['comment']): ?><p><?= nl2br(e($ev['comment'])) ?></p><?php endif ?>
            <small><?= e(fdatetime($ev['created_at'])) ?></small>
          </li>
        <?php endforeach ?>
      </ol>
    </section>

    <?php if ($can_cancel): ?>
      <form method="post" action="<?= url('/solicitudes/' . $r['id'] . '/cancelar') ?>">
        <?= csrf_field() ?>
        <button class="btn btn-ghost btn-block danger-text" data-confirm="¿Seguro que querés cancelar esta solicitud?"><?= icon('trash', 16) ?> Cancelar solicitud</button>
      </form>
    <?php endif ?>
  </aside>
</div>
