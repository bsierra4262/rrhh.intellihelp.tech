<div class="page-head">
  <div><h1>Tipos de licencia</h1><p class="muted">Definí qué requiere autorización, qué documentación es obligatoria y los topes.</p></div>
  <button class="btn btn-primary" data-open="#newType"><?= icon('plus', 16) ?> Nuevo tipo</button>
</div>

<?php
$blank = ['id' => 0, 'name' => '', 'description' => '', 'color' => '#663399', 'requires_approval' => 1, 'requires_document' => 0, 'document_label' => '', 'doc_due_days' => null, 'doc_due_from' => 'end',
    'max_days_per_year' => null, 'max_days_per_request' => null, 'counting' => 'habiles', 'uses_vacation_balance' => 0, 'vacation_optional' => 0, 'min_notice_days' => 0, 'active' => 1, 'sort' => 50, 'code' => 'nuevo'];
foreach (array_merge([$blank], $types) as $t): $isNew = !$t['id']; ?>
  <details class="card type-edit" <?= $isNew ? 'id="newType"' : '' ?> <?= $isNew ? 'hidden' : '' ?>>
    <summary>
      <span class="req-icon" style="--c:<?= e($t['color']) ?>"><?= icon(type_icon($t['code']), 18) ?></span>
      <span class="grow"><b><?= $isNew ? 'Nuevo tipo de licencia' : e($t['name']) ?></b>
        <?php if (!$isNew): ?><small class="muted">
          <?= $t['requires_approval'] ? 'Requiere autorización' : 'Solo se informa' ?>
          <?= $t['requires_document'] ? ' · ' . e($t['document_label'] ?: 'documentación') . ' obligatoria' : '' ?>
          · <?= $t['counting'] === 'habiles' ? 'días hábiles' : 'días corridos' ?>
          <?= $t['uses_vacation_balance'] ? ' · descuenta de vacaciones' : (!empty($t['vacation_optional']) ? ' · descuento de vacaciones a criterio' : '') ?><?= !empty($t['weekend_rule']) ? ' · suma el fin de semana' : '' ?>
          <?= $t['max_days_per_year'] ? ' · máx. ' . (int) $t['max_days_per_year'] . '/año' : '' ?>
        </small><?php endif ?>
      </span>
      <?= !$isNew && !$t['active'] ? '<span class="badge badge-cancelled">Inactivo</span>' : '' ?>
      <?= icon('edit', 16) ?>
    </summary>
    <form method="post" action="<?= url('/admin/tipos') ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
      <div class="form-grid">
        <label class="field"><span>Nombre</span><input name="name" value="<?= e($t['name']) ?>" required maxlength="80"></label>
        <label class="field"><span>Color</span><input type="color" name="color" value="<?= e($t['color']) ?>"></label>
        <label class="field span-2"><span>Descripción (se muestra al pedirla)</span><input name="description" value="<?= e($t['description']) ?>" maxlength="255"></label>
        <label class="field"><span>Conteo de días</span>
          <select name="counting">
            <option value="habiles" <?= $t['counting'] === 'habiles' ? 'selected' : '' ?>>Hábiles (sin fines de semana ni feriados)</option>
            <option value="corridos" <?= $t['counting'] === 'corridos' ? 'selected' : '' ?>>Corridos</option>
          </select>
        </label>
        <label class="field"><span>Nombre de la documentación</span><input name="document_label" value="<?= e($t['document_label']) ?>" placeholder="Ej.: Certificado médico" maxlength="120"></label>
        <label class="field"><span>Plazo para entregar la documentación (días hábiles)</span><input type="number" min="1" name="doc_due_days" value="<?= e($t['doc_due_days']) ?>" placeholder="3"></label>
        <label class="field"><span>El plazo se cuenta desde</span>
          <select name="doc_due_from">
            <option value="end" <?= ($t['doc_due_from'] ?? 'end') === 'end' ? 'selected' : '' ?>>El último día de la licencia</option>
            <option value="created" <?= ($t['doc_due_from'] ?? '') === 'created' ? 'selected' : '' ?>>El momento en que se informa</option>
          </select>
        </label>
        <label class="field"><span>Máximo de días por año</span><input type="number" min="1" name="max_days_per_year" value="<?= e($t['max_days_per_year']) ?>" placeholder="Sin tope"></label>
        <label class="field"><span>Máximo de días por solicitud</span><input type="number" min="1" name="max_days_per_request" value="<?= e($t['max_days_per_request']) ?>" placeholder="Sin tope"></label>
        <label class="field"><span>Anticipación sugerida (días)</span><input type="number" min="0" name="min_notice_days" value="<?= (int) $t['min_notice_days'] ?>"></label>
        <label class="field"><span>Orden</span><input type="number" name="sort" value="<?= (int) $t['sort'] ?>"></label>
      </div>
      <div class="form-foot">
        <label class="switch"><input type="checkbox" name="requires_approval" <?= $t['requires_approval'] ? 'checked' : '' ?>><span></span> Requiere autorización</label>
        <label class="switch"><input type="checkbox" name="requires_document" <?= $t['requires_document'] ? 'checked' : '' ?>><span></span> Documentación obligatoria (se entrega después, dentro del plazo)</label>
        <label class="switch"><input type="checkbox" name="uses_vacation_balance" <?= $t['uses_vacation_balance'] ? 'checked' : '' ?>><span></span> Descuenta de las vacaciones</label>
        <label class="switch"><input type="checkbox" name="vacation_optional" <?= !empty($t['vacation_optional']) ? 'checked' : '' ?>><span></span> Quien autoriza decide si descuenta de las vacaciones</label>
        <label class="switch"><input type="checkbox" name="weekend_rule" <?= !empty($t['weekend_rule']) ? 'checked' : '' ?>><span></span> Al tomar una semana completa se suma el fin de semana siguiente; si es menos de una semana, decide quien autoriza (solo días corridos)</label>
        <label class="switch"><input type="checkbox" name="active"<?= $t['active'] ? 'checked' : '' ?>><span></span> Activo</label>
        <button class="btn btn-primary btn-sm"><?= icon('check', 16) ?> Guardar</button>
      </div>
    </form>
  </details>
<?php endforeach ?>
