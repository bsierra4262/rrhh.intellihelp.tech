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

  // Plazo de documentación: al tocar la fecha se elige la opción personalizada
  $$('[data-due-date]').forEach((i) => i.addEventListener('input', () => {
    const radio = i.closest('form').querySelector('input[name=doc_due_mode][value=custom]');
    if (radio) radio.checked = true;
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
  const from = $('#from'), to = $('#to');
  const userSel = $('#userSel');
  const fmt = (d) => d ? new Date(d + 'T12:00:00').toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let timer;

  const current = () => {
    const r = form.querySelector('input[name=type_id]:checked');
    return r ? types.find((t) => t.id === +r.value) : null;
  };

  // ---------- Trámites por horario: calendario del mes y horario ----------
  const holidays = new Set(JSON.parse(form.dataset.holidays || '[]'));
  const tStart = $('#tStart'), tEnd = $('#tEnd');
  const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  let calMonth = new Date();
  calMonth.setDate(1);

  function renderMiniCal() {
    const box = $('#miniCal');
    const y = calMonth.getFullYear(), m = calMonth.getMonth();
    const title = calMonth.toLocaleDateString('es-AR', { month: 'long', year: 'numeric' });
    const first = (new Date(y, m, 1).getDay() + 6) % 7;
    const total = new Date(y, m + 1, 0).getDate();
    const today = iso(new Date());
    let html = '<div class="mc-head"><button type="button" class="icon-btn" data-mc="-1" aria-label="Mes anterior">‹</button>'
      + '<b>' + title.charAt(0).toUpperCase() + title.slice(1) + '</b>'
      + '<button type="button" class="icon-btn" data-mc="1" aria-label="Mes siguiente">›</button></div><div class="mc-grid">';
    ['L', 'M', 'M', 'J', 'V', 'S', 'D'].forEach((d) => (html += '<span class="mc-dow">' + d + '</span>'));
    for (let i = 0; i < first; i++) html += '<span></span>';
    for (let d = 1; d <= total; d++) {
      const date = new Date(y, m, d), key = iso(date);
      const off = date.getDay() === 0 || date.getDay() === 6 || holidays.has(key);
      const cls = ['mc-day', off ? 'off' : '', key === from.value ? 'sel' : '', key === today ? 'today' : ''].join(' ');
      html += '<button type="button" class="' + cls + '" data-day="' + key + '"' + (off ? ' disabled title="No hábil"' : '') + '>' + d + '</button>';
    }
    box.innerHTML = html + '</div>';
  }

  $('#miniCal').addEventListener('click', (e) => {
    const nav = e.target.closest('[data-mc]');
    if (nav) {
      calMonth.setMonth(calMonth.getMonth() + Number(nav.dataset.mc));
      renderMiniCal();
      return;
    }
    const day = e.target.closest('[data-day]');
    if (day && !day.disabled) {
      from.value = to.value = day.dataset.day;
      renderMiniCal();
      preview();
    }
  });

  // Mapa de etapas del tipo elegido: muestra qué pasa después de enviar la solicitud.
  function renderFlow(t) {
    const card = $('#flowCard');
    card.hidden = !t;
    if (!t) return;
    const steps = [[t.approval ? 'Solicitada' : 'Informada', 'Al enviar este formulario', 'current']];
    if (t.approval) steps.push(['Autorización', 'La revisa tu responsable o RRHH', 'todo']);
    steps.push(['Ausencia registrada', t.approval ? 'Al ser autorizada' : 'En el calendario del equipo', 'todo']);
    if (t.docLabel) {
      const from = t.docFrom === 'created' ? 'desde que la informás' : 'después de la licencia';
      const when = t.docDays + ' día' + (t.docDays === 1 ? '' : 's') + ' ' + from;
      steps.push([t.docLabel, (t.docRequired ? 'Obligatoria' : 'Opcional') + ' · plazo: ' + when, t.docRequired ? 'todo' : 'optional']);
      if (t.docRequired) steps.push(['Verificación de RRHH', 'Al recibir la documentación', 'todo']);
    }
    $('#flowMap').innerHTML = steps.map(([title, detail, state], i) =>
      '<li class="stage stage-' + state + '"><span class="stage-dot">' + (i + 1) + '</span><div class="stage-txt"><b>' + esc(title) + '</b><small>' + esc(detail) + '</small></div></li>').join('');
    $('#flowFoot').textContent = t.docLabel
      ? t.docLabel + ': no se adjunta ahora. Se entrega desde la solicitud ' + (t.approval ? 'una vez autorizada' : 'cuando la tengas') + ', dentro del plazo.'
      : 'Te avisamos por el portal y por email en cada etapa.';
  }

  function renderType() {
    const t = current();
    const hourly = !!t?.byHours;
    $('#rangeFields').hidden = hourly;
    $('#hoursFields').hidden = !hourly;
    from.required = to.required = !hourly;
    tStart.required = tEnd.required = hourly;
    if (hourly) {
      if (from.value) {
        const d = new Date(from.value + 'T12:00:00');
        calMonth = new Date(d.getFullYear(), d.getMonth(), 1);
      }
      renderMiniCal();
    }
    $('#typeDesc').textContent = t?.description || '';
    $('#sumType').textContent = t?.name || '—';
    renderFlow(t);
    const note = $('#flowNote span');
    const btn = $('#submitBtn span');
    if (!t) { note.textContent = 'Elegí un tipo de licencia.'; }
    else if (t.approval) {
      note.textContent = 'Se envía para su autorización. Te avisamos en el portal cuando se resuelva.'
        + (t.vacOptional ? ' Quien autoriza define si se descuenta de tus vacaciones.' : '');
      btn.textContent = 'Enviar solicitud';
    } else {
      note.textContent = 'No requiere autorización: queda registrada y se avisa a RRHH y a tu responsable.';
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
    const hourly = !!t?.byHours;
    if (hourly) {
      to.value = from.value;
      $('#sumDates').textContent = from.value ? fmt(from.value) + (tStart.value && tEnd.value ? ' · ' + tStart.value + ' a ' + tEnd.value : '') : '—';
    } else {
      $('#sumDates').textContent = from.value ? (from.value === to.value ? fmt(from.value) : fmt(from.value) + ' → ' + fmt(to.value)) : '—';
    }
    if (!t || !from.value || (hourly && !(tStart.value && tEnd.value))) return;
    timer = setTimeout(async () => {
      const q = new URLSearchParams({ type: t.id, from: from.value, to: to.value || from.value, user: userSel?.value || '' });
      if (hourly) {
        q.set('start_time', tStart.value);
        q.set('end_time', tEnd.value);
      }
      try {
        const res = await fetch(form.dataset.api + '?' + q, { headers: { Accept: 'application/json' } });
        const d = await res.json();
        if (hourly) {
          $('#hoursPreview').innerHTML = '<b>' + esc(d.label || '—') + '</b><small>no impacta en las vacaciones</small>';
          $('#sumReturn').textContent = 'El mismo día, ' + tEnd.value + ' h';
        } else {
          $('#daysPreview').innerHTML = '<b>' + esc(d.label || '—') + '</b><small>' + (d.holidays?.length ? 'sin contar ' + d.holidays.length + ' feriado' + (d.holidays.length > 1 ? 's' : '') : 'según el tipo elegido') + '</small>';
          $('#sumReturn').textContent = d.return ? d.return.charAt(0).toUpperCase() + d.return.slice(1) : '—';
        }
        $('#sumDays').textContent = d.label || '—';
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

  form.addEventListener('change', (e) => {
    if (e.target.name === 'type_id' || e.target === userSel) renderType();
    if (e.target === from || e.target === to || e.target === tStart || e.target === tEnd) preview();
  });
  renderType();
})();
