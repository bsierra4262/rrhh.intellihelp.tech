<?php
$mine = (int) $r['user_id'] === uid();
$statusIcon = ['pending' => 'clock', 'approved' => 'check-circle', 'rejected' => 'x-circle', 'cancelled' => 'x', 'registered' => 'check-circle'][$r['status']];
$statusText = match ($r['status']) {
    'pending' => $r['approver_name'] ? 'Esperando la autorización de ' . $r['approver_name'] : 'Esperando la autorización de RRHH',
    'approved' => 'Aprobada por ' . $r['decider_name'] . ' el ' . fdatetime($r['decided_at']),
    'rejected' => 'Rechazada por ' . $r['decider_name'] . ' el ' . fdatetime($r['decided_at']),
    'cancelled' => 'Esta solicitud fue cancelada',
    'registered' => 'Licencia informada — no requiere autorización',
};
$gcal = gcal_link($r['type_name'] . ($mine ? '' : ' — ' . $r['user_name']), $r['start_date'], $r['end_date'], url('/solicitudes/' . $r['id']));
?>
<div class="page-head">
  <div>
    <a class="back" href="<?= url($mine ? '/solicitudes' : (is_approver() ? '/aprobaciones' : '/')) ?>"><?= icon('arrow-left', 16) ?> Volver</a>
    <h1><span class="req-icon lg" style="--c:<?= e($r['color']) ?>"><?= icon(type_icon($r['type_code']), 22) ?></span> <?= e($r['type_name']) ?> <small class="muted">#<?= (int) $r['id'] ?></small></h1>
  </div>
  <div class="head-badges"><?= status_badge($r['status']) ?> <?= doc_badge($r['doc_status']) ?></div>
</div>

<div class="status-banner status-<?= e($r['status']) ?>">
  <?= icon($statusIcon, 22) ?>
  <div><b><?= e($statusText) ?></b>
    <?php if ($r['decision_comment']): ?><p>«<?= nl2br(e($r['decision_comment'])) ?>»</p><?php endif ?>
  </div>
</div>

<div class="grid-main">
  <div class="stack">
    <section class="card">
      <div class="detail-grid">
        <div class="person">
          <?= avatar($r['user_name'], 'lg') ?>
          <div><b><?= e($r['user_name']) ?></b><small><?= e(implode(' · ', array_filter([$r['position'], $r['department']]))) ?: e($r['user_email']) ?></small></div>
        </div>
        <div class="kv"><span>Desde</span><b><?= e(ucfirst(flongdate($r['start_date']))) ?></b></div>
        <div class="kv"><span>Hasta</span><b><?= e(ucfirst(flongdate($r['end_date']))) ?></b></div>
        <div class="kv"><span>Duración</span><b><?= e(fdays($r['days'])) ?> <?= $r['counting'] === 'habiles' ? 'hábiles' : 'corridos' ?></b></div>
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
        <div class="alert alert-warning inline"><?= icon('alert') ?><span>En esas fechas también faltan: <?= e(implode(', ', array_map(fn($o) => $o['user_name'] . ' (' . frange($o['start_date'], $o['end_date']) . ')', $overlaps))) ?>.</span></div>
      <?php endif ?>
      <form method="post" action="<?= url('/solicitudes/' . $r['id'] . '/decidir') ?>" class="form">
        <?= csrf_field() ?>
        <label class="field"><span>Comentario para <?= e(explode(' ', $r['user_name'])[0]) ?> <small class="muted">(obligatorio si rechazás)</small></span>
          <textarea name="comment" rows="2" maxlength="2000" placeholder="Ej.: ¡Que lo disfrutes! / Necesitamos cubrir la guardia esa semana…"></textarea>
        </label>
        <div class="btn-row">
          <button class="btn btn-positive btn-lg" name="decision" value="approved"><?= icon('check', 18) ?> Autorizar</button>
          <button class="btn btn-outline-danger btn-lg" name="decision" value="rejected" data-confirm="¿Rechazar la solicitud?"><?= icon('x', 18) ?> Rechazar</button>
        </div>
      </form>
    </section>
    <?php endif ?>

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
        <p class="empty-sm"><?= $r['requires_document'] ? icon('alert', 16) . ' Todavía no se subió la documentación.' : 'Sin archivos adjuntos.' ?></p>
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
  </div>

  <aside class="stack">
    <?php if ($balances): ?>
    <section class="card card-soft">
      <h3 class="sub">Saldos de <?= e(explode(' ', $r['user_name'])[0]) ?> <?= substr($r['start_date'], 0, 4) ?></h3>
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
