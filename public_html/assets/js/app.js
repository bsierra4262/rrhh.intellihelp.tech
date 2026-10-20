/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Comportamiento de la interfaz: navegación móvil, confirmaciones, copiado al
 * portapapeles y formulario de nueva solicitud con cálculo de días en tiempo real.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

(() => {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];

  // Menú móvil
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-toggle-nav]')) document.body.classList.toggle('nav-open');
    if (e.target.closest('[data-close-nav]')) document.body.classList.remove('nav-open');
    const dismiss = e.target.closest('[data-dismiss]');
    if (dismiss) dismiss.closest('.alert').remove();
  });

  // Confirmaciones
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-confirm]');
    if (el && !confirm(el.dataset.confirm)) e.preventDefault();
  });

  // Copiar al portapapeles
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    e.preventDefault();
    const src = $(btn.dataset.copy);
    const text = src.value ?? src.textContent;
    try { await navigator.clipboard.writeText(text.trim()); } catch { src.select?.(); document.execCommand('copy'); }
    const old = btn.innerHTML;
    btn.textContent = '¡Copiado!';
    setTimeout(() => (btn.innerHTML = old), 1600);
  });

  // Filas de tabla con enlace
  document.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-href]');
    if (tr && !e.target.closest('a,button,input')) location.href = tr.dataset.href;
  });

  // Envío automático al cambiar el valor
  $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

  // Mostrar contraseña
  $$('[data-pw]').forEach((b) => b.addEventListener('click', () => {
    const i = b.parentElement.querySelector('input');
    i.type = i.type === 'password' ? 'text' : 'password';
  }));

  // Despliega bloques ocultos (alta de tipo de licencia)
  $$('[data-open]').forEach((b) => b.addEventListener('click', () => {
    const d = $(b.dataset.open);
    d.hidden = false;
    d.open = true;
    d.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));

  // Evitar doble envío
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (f.dataset.sent) { e.preventDefault(); return; }
    if (f.method.toLowerCase() === 'post') {
      f.dataset.sent = '1';
      setTimeout(() => delete f.dataset.sent, 8000);
    }
  });

  // Auto-ocultar mensajes de éxito
  $$('.content > .alert-success').forEach((a) => setTimeout(() => a.remove(), 7000));

  // ---------- Nueva solicitud ----------
  const form = $('#newReq');
  if (!form) return;
  const types = JSON.parse(form.dataset.types);
  const from = $('#from'), to = $('#to'), docs = $('#docs');
  const userSel = $('#userSel');
  const fmt = (d) => d ? new Date(d + 'T12:00:00').toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let timer;

  const current = () => {
    const r = form.querySelector('input[name=type_id]:checked');
    return r ? types.find((t) => t.id === +r.value) : null;
  };

  function renderType() {
    const t = current();
    $('#typeDesc').textContent = t?.description || '';
    $('#sumType').textContent = t?.name || '—';
    const label = $('#docLabel');
    const drop = $('#drop');
    // El campo de adjuntos solo aparece en los tipos que admiten documentación.
    const acceptsDocs = !!(t && (t.docRequired || t.docLabel));
    $('#docField').hidden = !acceptsDocs;
    if (!acceptsDocs) {
      docs.value = '';
      renderFiles();
    }
    if (t?.docRequired) {
      label.innerHTML = esc(t.docLabel || 'Documentación') + ' <small class="req-mark">obligatorio</small>';
      drop.classList.add('required');
      docs.required = true;
    } else {
      label.innerHTML = esc(t?.docLabel || 'Documentación') + ' <small class="muted">(opcional)</small>';
      drop.classList.remove('required');
      docs.required = false;
    }
    const note = $('#flowNote span');
    const btn = $('#submitBtn span');
    if (!t) { note.textContent = 'Elegí un tipo de licencia.'; }
    else if (t.approval) {
      note.textContent = 'Se envía para su autorización. Te avisamos en el portal cuando se resuelva.'
        + (t.vacOptional ? ' Quien autoriza define si se descuenta de tus vacaciones.' : '');
      btn.textContent = 'Enviar solicitud';
    } else {
      note.textContent = 'No requiere autorización: queda registrada y se avisa a RRHH y a tu responsable' + (t.docRequired ? ' con el certificado adjunto.' : '.');
      btn.textContent = 'Informar licencia';
    }
    const aa = $('#autoApproveWrap');
    if (aa) aa.style.display = t?.approval && userSel?.value ? '' : 'none';
    if (t && t.maxReq === 1 && from.value) to.value = from.value;
    preview();
  }

  function preview() {
    clearTimeout(timer);
    const t = current();
    if (from.value && (!to.value || to.value < from.value)) to.value = from.value;
    to.min = from.value || '';
    $('#sumDates').textContent = from.value ? (from.value === to.value ? fmt(from.value) : fmt(from.value) + ' → ' + fmt(to.value)) : '—';
    if (!t || !from.value) return;
    timer = setTimeout(async () => {
      const q = new URLSearchParams({ type: t.id, from: from.value, to: to.value || from.value, user: userSel?.value || '' });
      try {
        const res = await fetch(form.dataset.api + '?' + q, { headers: { Accept: 'application/json' } });
        const d = await res.json();
        $('#daysPreview').innerHTML = '<b>' + esc(d.label || '—') + '</b><small>' + (d.holidays?.length ? 'sin contar ' + d.holidays.length + ' feriado' + (d.holidays.length > 1 ? 's' : '') : 'según el tipo elegido') + '</small>';
        $('#sumDays').textContent = d.label || '—';
        $('#sumReturn').textContent = d.return ? d.return.charAt(0).toUpperCase() + d.return.slice(1) : '—';
        const msgs = $('#dayMsgs');
        msgs.innerHTML = '';
        (d.errors || []).forEach((m) => msgs.insertAdjacentHTML('beforeend', '<div class="alert alert-error"><span>' + esc(m) + '</span></div>'));
        (d.warnings || []).forEach((m) => msgs.insertAdjacentHTML('beforeend', '<div class="alert alert-warning"><span>' + esc(m) + '</span></div>'));
        if (d.holidays?.length) msgs.insertAdjacentHTML('beforeend', '<div class="alert alert-info"><span>Feriados en el rango: ' + esc(d.holidays.join(', ')) + '</span></div>');
        $('#submitBtn').disabled = (d.errors || []).length > 0;
      } catch {
        // Sin conexión: el servidor vuelve a validar al enviar.
      }
    }, 220);
  }

  function renderFiles() {
    const list = $('#fileList');
    if (!list) return;
    list.innerHTML = '';
    [...docs.files].forEach((f) => list.insertAdjacentHTML('beforeend', '<li>📎 ' + esc(f.name) + ' · ' + Math.max(1, Math.round(f.size / 1024)) + ' KB</li>'));
  }

  const drop = $('#drop');
  ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.add('over')));
  ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('over')));

  form.addEventListener('change', (e) => {
    if (e.target.name === 'type_id' || e.target === userSel) renderType();
    if (e.target === from || e.target === to) preview();
    if (e.target === docs) renderFiles();
  });
  renderType();
})();
