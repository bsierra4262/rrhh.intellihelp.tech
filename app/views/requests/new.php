<?php
$selected = old('type_id');
if (!$selected && $preselect) {
    foreach ($types as $t) if ($t['code'] === $preselect) $selected = (string) $t['id'];
}
$typeData = array_map(fn($t) => [
    'id' => (int) $t['id'], 'code' => $t['code'], 'name' => $t['name'], 'description' => $t['description'],
    'approval' => (bool) $t['requires_approval'], 'docRequired' => (bool) $t['requires_document'], 'docLabel' => $t['document_label'] ?: ($t['requires_document'] ? 'Documentación' : null),
    'docDays' => $t['doc_due_days'] ? (int) $t['doc_due_days'] : 2, 'docFrom' => $t['doc_due_from'],
    'counting' => $t['counting'], 'vacOptional' => (bool) $t['vacation_optional'], 'byHours' => (bool) $t['by_hours'], 'maxReq' => $t['max_days_per_request'] ? (int) $t['max_days_per_request'] : null,
], $types);
$holidayDates = array_column(rows('SELECT date FROM holidays WHERE date BETWEEN ? AND ?', [date('Y-01-01'), date('Y-12-31', strtotime('+1 year'))]), 'date');
$timeSlots = [];
for ($m = 7 * 60; $m <= 20 * 60; $m += 15) {
    $timeSlots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}
?>
<div class="page-head">
  <div>
    <h1>Nueva solicitud</h1>
    <p class="muted">Elegí el tipo, las fechas y listo. Te avisamos cuando se resuelva.</p>
  </div>
</div>

<form method="post" action="<?= url('/solicitudes/nueva') ?>" class="new-req" id="newReq" data-types='<?= e(json_encode($typeData, JSON_UNESCAPED_UNICODE)) ?>' data-api="<?= url('/api/dias') ?>" data-holidays='<?= e(json_encode($holidayDates)) ?>' data-today="<?= date('Y-m-d') ?>" data-now="<?= date('H:i') ?>">
  <?= csrf_field() ?>
  <div class="stack">
    <section class="card">
      <h2 class="step"><span>1</span> ¿Qué necesitás?</h2>
      <div class="type-grid">
        <?php foreach ($types as $t): ?>
          <label class="type-card" style="--c:<?= e($t['color']) ?>">
            <input type="radio" name="type_id" value="<?= (int) $t['id'] ?>" <?= (string) $t['id'] === (string) $selected ? 'checked' : '' ?> required>
            <span class="type-ic"><?= icon(type_icon($t['code']), 20) ?></span>
            <span class="type-txt"><b><?= e($t['name']) ?></b>
              <small><?= $t['requires_approval'] ? 'Requiere autorización' : 'Se informa, sin autorización' ?><?= $t['by_hours'] ? ' · por horario' : '' ?></small>
            </span>
          </label>
        <?php endforeach ?>
      </div>
      <p class="type-desc muted" id="typeDesc"></p>
    </section>

    <?php if ($employees): ?>
    <section class="card">
      <h2 class="step"><span><?= icon('user', 14) ?></span> ¿Para quién? <small class="muted">(RRHH)</small></h2>
      <div class="form-row">
        <label class="field grow">
          <span>Colaborador/a</span>
          <select name="user_id" id="userSel">
            <option value="">Yo (<?= e(current_user()['name']) ?>)</option>
            <?php foreach ($employees as $emp): if ((int) $emp['id'] === uid()) continue; ?>
              <option value="<?= (int) $emp['id'] ?>" <?= old('user_id') == $emp['id'] ? 'selected' : '' ?>><?= e($emp['name']) ?><?= $emp['department'] ? ' — ' . e($emp['department']) : '' ?></option>
            <?php endforeach ?>
          </select>
        </label>
        <label class="check" id="autoApproveWrap">
          <input type="checkbox" name="auto_approve" value="1"> <span>Cargar como ya aprobada</span>
        </label>
      </div>
    </section>
    <?php endif ?>

    <section class="card">
      <h2 class="step"><span>2</span> ¿Cuándo?</h2>
      <div class="form-row" id="rangeFields">
        <label class="field"><span>Desde</span><input type="date" name="start_date" id="from" value="<?= e(old('start_date')) ?>" min="<?= date('Y-m-d') ?>" required></label>
        <label class="field"><span>Hasta</span><input type="date" name="end_date" id="to" value="<?= e(old('end_date')) ?>" min="<?= date('Y-m-d') ?>" required></label>
        <div class="days-preview" id="daysPreview" aria-live="polite">
          <b>—</b><small>Elegí las fechas</small>
        </div>
      </div>
      <div class="hours-picker" id="hoursFields" hidden>
        <div class="mini-cal" id="miniCal" aria-label="Elegí el día del trámite"></div>
        <div class="hours-side">
          <label class="field"><span>Hora de salida</span>
            <select name="start_time" id="tStart">
              <option value="">Elegir…</option>
              <?php foreach ($timeSlots as $slot): ?><option <?= old('start_time') === $slot ? 'selected' : '' ?>><?= $slot ?></option><?php endforeach ?>
            </select>
          </label>
          <label class="field"><span>Hora de regreso</span>
            <select name="end_time" id="tEnd">
              <option value="">Elegir…</option>
              <?php foreach ($timeSlots as $slot): ?><option <?= old('end_time') === $slot ? 'selected' : '' ?>><?= $slot ?></option><?php endforeach ?>
            </select>
          </label>
          <div class="days-preview" id="hoursPreview" aria-live="polite"><b>—</b><small>Elegí el día y el horario</small></div>
        </div>
      </div>
      <div id="dayMsgs"></div>
    </section>

    <section class="card">
      <h2 class="step"><span>3</span> Detalle</h2>
      <label class="field">
        <span>Comentario <small class="muted">(opcional)</small></span>
        <textarea name="reason" rows="3" maxlength="2000" placeholder="Ej.: examen final de Base de Datos, viaje familiar…"><?= e(old('reason')) ?></textarea>
      </label>
    </section>

    <section class="card flow-card" id="flowCard" hidden>
      <h2 class="step"><span><?= icon('arrow-right', 14) ?></span> Cómo sigue tu solicitud</h2>
      <ol class="stages" id="flowMap"></ol>
      <p class="muted flow-foot" id="flowFoot"></p>
    </section>
  </div>

  <aside class="stack sticky">
    <section class="card summary" id="summary">
      <h2>Resumen</h2>
      <dl>
        <dt>Tipo</dt><dd id="sumType">—</dd>
        <dt>Fechas</dt><dd id="sumDates">—</dd>
        <dt>Duración</dt><dd id="sumDays">—</dd>
        <dt>Vuelta al trabajo</dt><dd id="sumReturn">—</dd>
      </dl>
      <p class="flow-note" id="flowNote"><?= icon('info', 16) ?> <span>Elegí un tipo de licencia.</span></p>
      <input type="hidden" name="exception_ack" id="exceptionAck" value="">
      <button class="btn btn-primary btn-block btn-lg" id="submitBtn"><?= icon('check', 18) ?> <span>Enviar solicitud</span></button>
    </section>
    <section class="card card-soft">
      <h3 class="sub">Tus días disponibles · <?= date('Y') ?></h3>
      <?php partial('balances', ['balances' => $balances]) ?>
    </section>
  </aside>
</form>

<div class="modal" id="excModal" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="excTitle1">
    <div class="modal-step" data-step="1">
      <span class="modal-ic"><?= icon('alert', 26) ?></span>
      <h3 id="excTitle1">¿Estás seguro/a de enviarla así?</h3>
      <p>Elegiste <b data-exc-days>—</b>, fuera del período habitual de vacaciones: <b>7 días corridos, de lunes a domingo</b>.</p>
      <p class="muted">La solicitud puede ser rechazada o pueden contactarte para confirmarla.</p>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-exc="close">Volver</button>
        <button type="button" class="btn btn-primary" data-exc="next">Sí, continuar</button>
      </div>
    </div>
    <div class="modal-step" data-step="2" hidden>
      <span class="modal-ic"><?= icon('phone', 26) ?></span>
      <h3>Hablalo antes de enviarla</h3>
      <p>Las vacaciones fuera del período habitual se acuerdan antes. Comunicate con:</p>
      <ul class="contact-list">
        <?php foreach (vacation_contacts() as $c): $tel = preg_replace('/\D/', '', (string) $c['phone']); ?>
          <li>
            <?= avatar($c['name']) ?>
            <span><b><?= e($c['name']) ?></b>
              <small>
                <?php if ($tel): ?><a href="tel:+<?= e($tel) ?>"><?= icon('phone', 14) ?> <?= e(fphone($c['phone'])) ?></a><a href="https://wa.me/<?= e($tel) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 14) ?> WhatsApp</a><?php endif ?>
                <a href="mailto:<?= e($c['email']) ?>"><?= icon('mail', 14) ?> <?= e($c['email']) ?></a>
              </small>
            </span>
          </li>
        <?php endforeach ?>
      </ul>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-exc="close">Cancelar</button>
        <button type="button" class="btn btn-primary" data-exc="confirm"><?= icon('check', 16) ?> Ya me comuniqué</button>
      </div>
    </div>
  </div>
</div>
