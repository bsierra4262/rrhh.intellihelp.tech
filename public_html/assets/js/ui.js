/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Componentes de formulario con la identidad visual del portal: reemplazan la presentación
 * nativa de los campos de fecha y de las listas desplegables por un calendario y una lista
 * propios. Los campos originales se conservan (ocultos visualmente) para el envío del
 * formulario, la validación del navegador y los eventos que usa el resto de la aplicación.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

(() => {
  'use strict';

  const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  const DOW = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];
  const holidays = (() => {
    try {
      return JSON.parse(document.body.dataset.holidays || '{}');
    } catch {
      return {};
    }
  })();
  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const parse = (v) => (/^\d{4}-\d{2}-\d{2}$/.test(v) ? new Date(v + 'T12:00:00') : null);
  const chevron = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
  const calIcon = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/></svg>';
  const check = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 12.5 5 5 10-11"/></svg>';
  const syncers = [];
  let openPop = null;

  function closeOpen() {
    if (openPop) {
      openPop.close();
      openPop = null;
    }
  }

  /** Ubica el panel arriba del campo si no entra debajo. */
  function place(wrap, pop) {
    wrap.classList.remove('up', 'right');
    let r = pop.getBoundingClientRect();
    // Si la lista se sale por la derecha, se alinea con el borde derecho del campo.
    if (r.right > window.innerWidth - 8) {
      wrap.classList.add('right');
      r = pop.getBoundingClientRect();
    }
    if (r.bottom > window.innerHeight - 8 && wrap.getBoundingClientRect().top > r.height + 16) {
      wrap.classList.add('up');
    }
  }

  // ---------------------------------------------------------------- Calendario
  /**
   * Campo de fecha escribible (dd/mm/aaaa) con calendario desplegable. El calendario permite
   * saltar por meses y años desde el encabezado para fechas lejanas.
   */
  function enhanceDate(input) {
    const wrap = document.createElement('div');
    wrap.className = 'ui-field ui-date';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.classList.add('ui-native');
    input.tabIndex = -1;

    const box = document.createElement('div');
    box.className = 'ui-trigger ui-date-box';
    box.innerHTML = '<button type="button" class="ui-ico ui-cal-btn" aria-label="Abrir calendario">' + calIcon + '</button>'
      + '<input type="text" class="ui-date-text" inputmode="numeric" autocomplete="off" placeholder="dd/mm/aaaa" maxlength="10" aria-label="Fecha (dd/mm/aaaa)">';
    const text = box.querySelector('.ui-date-text');
    const calBtn = box.querySelector('.ui-cal-btn');
    const hint = document.createElement('small');
    hint.className = 'ui-date-error';
    hint.hidden = true;
    const pop = document.createElement('div');
    pop.className = 'ui-pop ui-cal';
    pop.hidden = true;
    wrap.append(box, pop, hint);

    let view = new Date();
    let mode = 'days';
    let last = null;
    const toText = (v) => {
      const d = parse(v);
      return d ? pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() : '';
    };
    const showError = (msg) => {
      hint.textContent = msg || '';
      hint.hidden = !msg;
      box.classList.toggle('invalid', !!msg);
    };

    const render = () => {
      const y = view.getFullYear(), m = view.getMonth();
      const min = input.min || '', max = input.max || '';
      let h = '';
      if (mode === 'years') {
        const start = y - (y % 12);
        h = '<div class="ui-cal-head"><button type="button" class="ui-nav" data-nav="-12" aria-label="Años anteriores">‹</button>'
          + '<b>' + start + ' – ' + (start + 11) + '</b>'
          + '<button type="button" class="ui-nav" data-nav="12" aria-label="Años siguientes">›</button></div><div class="ui-cal-grid ui-cal-big">';
        for (let i = 0; i < 12; i++) {
          const yy = start + i;
          const disabled = (min && String(yy) < min.slice(0, 4)) || (max && String(yy) > max.slice(0, 4));
          h += '<button type="button" class="ui-cell' + (yy === y ? ' sel' : '') + '" data-year="' + yy + '"' + (disabled ? ' disabled' : '') + '>' + yy + '</button>';
        }
        pop.innerHTML = h + '</div>';
        return;
      }
      if (mode === 'months') {
        h = '<div class="ui-cal-head"><button type="button" class="ui-nav" data-nav="-12" aria-label="Año anterior">‹</button>'
          + '<button type="button" class="ui-cal-title" data-mode="years">' + y + '</button>'
          + '<button type="button" class="ui-nav" data-nav="12" aria-label="Año siguiente">›</button></div><div class="ui-cal-grid ui-cal-big">';
        MONTHS.forEach((name, i) => {
          const key = y + '-' + pad(i + 1);
          const disabled = (min && key < min.slice(0, 7)) || (max && key > max.slice(0, 7));
          h += '<button type="button" class="ui-cell' + (i === m ? ' sel' : '') + '" data-month="' + i + '"' + (disabled ? ' disabled' : '') + '>'
            + name.charAt(0).toUpperCase() + name.slice(1, 3) + '</button>';
        });
        pop.innerHTML = h + '</div>';
        return;
      }
      const first = (new Date(y, m, 1).getDay() + 6) % 7;
      const total = new Date(y, m + 1, 0).getDate();
      const today = iso(new Date());
      h = '<div class="ui-cal-head"><button type="button" class="ui-nav" data-nav="-1" aria-label="Mes anterior">‹</button>'
        + '<button type="button" class="ui-cal-title" data-mode="months" title="Elegir mes y año">' + MONTHS[m].charAt(0).toUpperCase() + MONTHS[m].slice(1) + ' ' + y + ' ' + chevron + '</button>'
        + '<button type="button" class="ui-nav" data-nav="1" aria-label="Mes siguiente">›</button></div><div class="ui-cal-grid">';
      DOW.forEach((d, i) => (h += '<span class="ui-dow' + (i > 4 ? ' we' : '') + '">' + d + '</span>'));
      for (let i = 0; i < first; i++) h += '<span></span>';
      for (let d = 1; d <= total; d++) {
        const date = new Date(y, m, d), key = iso(date);
        const disabled = (min && key < min) || (max && key > max);
        const cls = ['ui-day',
          date.getDay() === 0 || date.getDay() === 6 ? 'we' : '',
          holidays[key] ? 'hol' : '',
          key === today ? 'today' : '',
          key === input.value ? 'sel' : ''].join(' ');
        h += '<button type="button" class="' + cls + '" data-day="' + key + '"' + (disabled ? ' disabled' : '')
          + (holidays[key] ? ' title="Feriado: ' + holidays[key].replace(/"/g, '&quot;') + '"' : '') + '>' + d + '</button>';
      }
      h += '</div><div class="ui-cal-foot"><button type="button" data-act="clear">Borrar</button><button type="button" data-act="today">Hoy</button></div>';
      pop.innerHTML = h;
    };
    const sync = () => {
      if (input.value === last) return;
      last = input.value;
      if (document.activeElement !== text) text.value = toText(input.value);
      showError('');
    };
    const set = (v) => {
      input.value = v;
      last = v;
      text.value = toText(v);
      showError('');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const api = {
      close() {
        pop.hidden = true;
        wrap.classList.remove('open');
      },
    };
    const open = () => {
      closeOpen();
      const cur = parse(input.value) || parse(input.min) || new Date();
      view = new Date(cur.getFullYear(), cur.getMonth(), 1);
      mode = 'days';
      render();
      pop.hidden = false;
      wrap.classList.add('open');
      place(wrap, pop);
      openPop = api;
    };

    // Escritura: agrega las barras mientras se tipea y valida al confirmar.
    text.addEventListener('input', () => {
      const parts = text.value.split(/[/\-.]/);
      // Si los separadores están en su lugar (o no hay), se reformatea desde los dígitos;
      // si se escribió a mano en formato corto (9/1/2024), se respeta.
      const auto = parts.slice(0, -1).every((p) => p.replace(/\D/g, '').length === 2);
      if (auto) {
        const digits = text.value.replace(/\D/g, '').slice(0, 8);
        text.value = digits.length > 4 ? digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4)
          : digits.length > 2 ? digits.slice(0, 2) + '/' + digits.slice(2) : digits;
      } else {
        text.value = text.value.replace(/[^\d/\-.]/g, '').slice(0, 10);
      }
      showError('');
    });
    const commit = () => {
      const raw = text.value.trim();
      if (raw === '') {
        if (input.value) set('');
        return true;
      }
      let dd, mm, yy;
      const parts = raw.split(/[/\-.]/);
      if (parts.length === 3) {
        [dd, mm, yy] = parts;
      } else {
        const digits = raw.replace(/\D/g, '');
        if (digits.length !== 8) {
          showError('Escribí la fecha completa: dd/mm/aaaa.');
          return false;
        }
        [dd, mm, yy] = [digits.slice(0, 2), digits.slice(2, 4), digits.slice(4)];
      }
      if (yy && yy.length === 2) yy = '20' + yy;
      const d = new Date(Number(yy), Number(mm) - 1, Number(dd), 12);
      if (!yy || yy.length !== 4 || isNaN(d) || d.getDate() !== Number(dd) || d.getMonth() !== Number(mm) - 1) {
        showError('La fecha no es válida.');
        return false;
      }
      const key = iso(d);
      if (input.min && key < input.min) {
        showError('La fecha no puede ser anterior al ' + toText(input.min) + '.');
        return false;
      }
      if (input.max && key > input.max) {
        showError('La fecha no puede ser posterior al ' + toText(input.max) + '.');
        return false;
      }
      if (key !== input.value) set(key);
      else text.value = toText(key);
      return true;
    };
    text.addEventListener('blur', () => setTimeout(() => { if (!wrap.contains(document.activeElement)) commit(); }, 120));
    text.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (commit()) closeOpen();
      } else if (e.key === 'ArrowDown' && pop.hidden) {
        e.preventDefault();
        open();
      }
    });
    text.closest('form')?.addEventListener('submit', (e) => {
      if (!commit()) {
        e.preventDefault();
        e.stopImmediatePropagation();
        text.focus();
      }
    }, true);

    calBtn.addEventListener('click', () => (pop.hidden ? open() : closeOpen()));
    pop.addEventListener('click', (e) => {
      e.stopPropagation();
      const nav = e.target.closest('[data-nav]');
      const day = e.target.closest('[data-day]');
      const act = e.target.closest('[data-act]');
      const goMode = e.target.closest('[data-mode]');
      const month = e.target.closest('[data-month]');
      const year = e.target.closest('[data-year]');
      if (nav) {
        const step = Number(nav.dataset.nav);
        if (mode === 'days') view.setMonth(view.getMonth() + step);
        else view.setFullYear(view.getFullYear() + (mode === 'months' ? step / 12 : step));
        render();
      } else if (goMode) {
        mode = goMode.dataset.mode;
        render();
      } else if (year && !year.disabled) {
        view.setFullYear(Number(year.dataset.year));
        mode = 'months';
        render();
      } else if (month && !month.disabled) {
        view.setMonth(Number(month.dataset.month));
        mode = 'days';
        render();
      } else if (day && !day.disabled) {
        set(day.dataset.day);
        closeOpen();
        text.focus();
      } else if (act) {
        set(act.dataset.act === 'today' ? iso(new Date()) : '');
        closeOpen();
      }
    });
    syncers.push(sync);
    sync();
  }

  // ---------------------------------------------------------------- Lista desplegable
  function enhanceSelect(select) {
    const wrap = document.createElement('div');
    wrap.className = 'ui-field ui-select' + (select.closest('.field.sm, .filters') ? ' sm' : '');
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('ui-native');
    select.tabIndex = -1;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ui-trigger';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.innerHTML = '<span class="ui-value"></span><span class="ui-chev">' + chevron + '</span>';
    const pop = document.createElement('div');
    pop.className = 'ui-pop ui-list';
    pop.hidden = true;
    wrap.append(btn, pop);

    // La búsqueda aparece cuando la lista es larga (se evalúa al abrir: las opciones pueden cambiar).
    const searchable = () => select.options.length > 8;
    let active = -1;
    let last = null;

    const items = () => [...pop.querySelectorAll('.ui-opt:not([hidden])')];
    const render = () => {
      let h = searchable() ? '<div class="ui-search"><input type="text" placeholder="Buscar…" aria-label="Buscar"></div>' : '';
      h += '<ul role="listbox">';
      [...select.options].forEach((o, i) => {
        h += '<li class="ui-opt' + (o.selected ? ' sel' : '') + '" role="option" data-i="' + i + '"' + (o.disabled ? ' aria-disabled="true"' : '') + '>'
          + '<span>' + o.text.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</span>' + (o.selected ? check : '') + '</li>';
      });
      pop.innerHTML = h + '</ul>';
    };
    const sync = () => {
      const o = select.options[select.selectedIndex];
      const key = select.value + '|' + select.options.length;
      if (key === last) return;
      last = key;
      btn.querySelector('.ui-value').textContent = o ? o.text : '';
      btn.disabled = select.disabled;
    };
    const choose = (i) => {
      const o = select.options[i];
      if (!o || o.disabled) return;
      select.selectedIndex = i;
      sync();
      select.dispatchEvent(new Event('change', { bubbles: true }));
      closeOpen();
      btn.focus();
    };
    const highlight = (n) => {
      const list = items();
      if (!list.length) return;
      active = (n + list.length) % list.length;
      list.forEach((li, k) => li.classList.toggle('act', k === active));
      list[active].scrollIntoView({ block: 'nearest' });
    };
    const api = {
      close() {
        pop.hidden = true;
        wrap.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
      },
    };
    const open = () => {
      closeOpen();
      render();
      pop.hidden = false;
      wrap.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      place(wrap, pop);
      openPop = api;
      const list = items();
      active = list.findIndex((li) => li.classList.contains('sel'));
      if (active >= 0) list[active].scrollIntoView({ block: 'nearest' });
      const s = pop.querySelector('.ui-search input');
      if (s) {
        s.focus();
        s.addEventListener('input', () => {
          const q = s.value.toLowerCase();
          pop.querySelectorAll('.ui-opt').forEach((li) => (li.hidden = !li.textContent.toLowerCase().includes(q)));
          highlight(0);
        });
        s.addEventListener('keydown', keys);
      }
    };
    function keys(e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlight(active + 1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlight(active - 1);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const li = items()[active];
        if (li) choose(Number(li.dataset.i));
      } else if (e.key === 'Escape') {
        closeOpen();
        btn.focus();
      }
    }

    btn.addEventListener('click', () => (pop.hidden ? open() : closeOpen()));
    btn.addEventListener('keydown', (e) => {
      if (pop.hidden && ['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
        e.preventDefault();
        open();
      } else if (!pop.hidden) {
        keys(e);
      }
    });
    pop.addEventListener('click', (e) => {
      e.stopPropagation();
      const li = e.target.closest('.ui-opt');
      if (li) choose(Number(li.dataset.i));
    });
    pop.addEventListener('mousemove', (e) => {
      const li = e.target.closest('.ui-opt');
      if (li) highlight(items().indexOf(li));
    });
    select.addEventListener('change', sync);
    // Un clic en el título del campo enfoca la lista con diseño, no la nativa.
    const lab = select.closest('label');
    if (lab) {
      lab.addEventListener('click', (e) => {
        // Los enlaces de la ayuda del campo (por ejemplo, el buscador oficial) se abren normalmente.
        if (!e.target.closest('.ui-field') && !e.target.closest('a')) {
          e.preventDefault();
          btn.focus();
        }
      });
    }
    syncers.push(sync);
    sync();
  }

  document.addEventListener('click', (e) => {
    if (openPop && !e.target.closest('.ui-field.open')) closeOpen();
  });
  // Los campos están dentro de un <label>: un clic en la lista o el calendario no debe activar el
  // control nativo oculto (abriría la lista o el calendario del navegador, sin diseño).
  document.addEventListener('click', (e) => {
    if (e.target.closest('.ui-pop') && !e.target.closest('input, textarea')) e.preventDefault();
  }, true);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeOpen();
  });

  // Teléfonos: se muestran siempre igual (+54 9 11 5769-0543), se escriban o se peguen como sea.
  function phoneFormat(v) {
    const raw = v.trim();
    if (!raw) return '';
    let d = raw.replace(/\D/g, '');
    let intl = raw.startsWith('+');
    if (d.startsWith('00')) { d = d.slice(2); intl = true; }
    if (intl && !d.startsWith('54')) return '+' + d;
    if (d.startsWith('54') && d.length >= 12) { d = d.slice(2); if (d.startsWith('9')) d = d.slice(1); }
    d = d.replace(/^0+/, '');
    if (d.length === 12) {
      for (const a of [2, 3, 4]) if (d.substr(a, 2) === '15') { d = d.slice(0, a) + d.slice(a + 2); break; }
    }
    if (d.length !== 10) return (intl || d.length > 10 ? '+' : '') + d;
    const area = d.startsWith('11') ? 2 : 3;
    const local = d.slice(area);
    return '+54 9 ' + d.slice(0, area) + ' ' + local.slice(0, local.length - 4) + '-' + local.slice(-4);
  }
  document.querySelectorAll('input[name=phone], input[data-phone]').forEach((input) => {
    input.setAttribute('inputmode', 'tel');
    const fix = () => { input.value = phoneFormat(input.value); };
    fix();
    input.addEventListener('blur', fix);
    input.addEventListener('paste', () => setTimeout(fix, 0));
  });

  // CUIL siempre como 20-12345678-9, se escriba o se pegue como sea.
  document.querySelectorAll('input[data-cuil]').forEach((input) => {
    const fix = () => {
      const d = input.value.replace(/\D/g, '').slice(0, 11);
      input.value = d.length > 10 ? d.slice(0, 2) + '-' + d.slice(2, 10) + '-' + d.slice(10) : (d.length > 2 ? d.slice(0, 2) + '-' + d.slice(2) : d);
    };
    fix();
    input.addEventListener('input', fix);
  });

  const upload = '<span class="ui-up"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V4M7 9l5-5 5 5M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg></span>';
  // Selector de archivos con diseño propio: botón y lista de archivos elegidos.
  document.querySelectorAll('input[type=file]').forEach((input) => {
    const wrap = document.createElement('div');
    wrap.className = 'ui-file';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.classList.add('ui-native');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ui-trigger';
    const show = () => {
      const files = [...input.files];
      btn.innerHTML = '<span class="ui-value">' + (files.length
        ? (files.length === 1 ? files[0].name : files.length + ' archivos: ' + files.slice(0, 3).map((f) => f.name).join(', ') + (files.length > 3 ? '…' : ''))
        : (input.multiple ? 'Elegir archivos o arrastrarlos acá' : 'Elegir archivo')) + '</span>';
      btn.insertAdjacentHTML('afterbegin', upload);
      wrap.classList.toggle('has-files', files.length > 0);
    };
    btn.addEventListener('click', () => input.click());
    input.addEventListener('change', () => { show(); input.dispatchEvent(new Event('input', { bubbles: true })); });
    ['dragover', 'dragenter'].forEach((ev) => wrap.addEventListener(ev, (e) => { e.preventDefault(); wrap.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach((ev) => wrap.addEventListener(ev, () => wrap.classList.remove('drag')));
    wrap.addEventListener('drop', (e) => {
      e.preventDefault();
      if (e.dataTransfer?.files?.length) {
        input.files = e.dataTransfer.files;
        show();
      }
    });
    wrap.appendChild(btn);
    show();
  });

  // Selector de color con diseño propio (muestras predefinidas) en lugar de la paleta del sistema.
  const SWATCHES = ['#663399', '#9f88b1', '#7d6b91', '#3d1f5c', '#2E86AB', '#00897B', '#e0a100', '#8C6A4F', '#D1495B', '#C2185B', '#B71C1C', '#455A64'];
  document.querySelectorAll('input[type=color]').forEach((input) => {
    const wrap = document.createElement('div');
    wrap.className = 'ui-field ui-color';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.classList.add('ui-native');
    input.tabIndex = -1;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ui-trigger';
    const pop = document.createElement('div');
    pop.className = 'ui-pop ui-swatches';
    pop.hidden = true;
    wrap.append(btn, pop);
    const paint = () => { btn.innerHTML = '<span class="ui-swatch" style="background:' + input.value + '"></span><span class="ui-value">' + input.value.toUpperCase() + '</span>'; };
    const colors = SWATCHES.map((c) => c.toLowerCase());
    if (!colors.includes(input.value.toLowerCase())) colors.unshift(input.value.toLowerCase());
    pop.innerHTML = colors.map((c) => '<button type="button" class="ui-swatch-btn" data-color="' + c + '" style="background:' + c + '" aria-label="' + c + '"></button>').join('');
    const api = { close() { pop.hidden = true; wrap.classList.remove('open'); } };
    btn.addEventListener('click', () => {
      if (!pop.hidden) return closeOpen();
      closeOpen(); pop.hidden = false; wrap.classList.add('open'); place(wrap, pop); openPop = api;
    });
    pop.addEventListener('click', (e) => {
      const s = e.target.closest('[data-color]');
      if (!s) return;
      input.value = s.dataset.color;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      paint(); closeOpen(); btn.focus();
    });
    paint();
  });

  // Validación de formularios con mensajes propios debajo del campo (sin los globos del navegador).
  const fieldMsg = (el) => {
    const v = el.validity;
    if (v.valueMissing) return el.tagName === 'SELECT' ? 'Elegí una opción.' : 'Completá este campo.';
    if (v.typeMismatch) return el.type === 'email' ? 'Escribí un correo válido, por ejemplo nombre@intellihelp.tech.' : 'El formato no es válido.';
    if (v.tooShort) return 'Tiene que tener al menos ' + el.minLength + ' caracteres.';
    if (v.rangeUnderflow) return 'El valor mínimo es ' + el.min + '.';
    if (v.rangeOverflow) return 'El valor máximo es ' + el.max + '.';
    if (v.patternMismatch) return el.title || 'El formato no es válido.';
    return el.validationMessage;
  };
  let firstInvalid = null;
  document.addEventListener('invalid', (e) => {
    const el = e.target;
    e.preventDefault();
    const box = el.closest('.field') || el.parentNode;
    box.classList.add('is-invalid');
    let msg = box.querySelector(':scope > .field-error');
    if (!msg) {
      msg = document.createElement('small');
      msg.className = 'field-error';
      box.appendChild(msg);
    }
    msg.textContent = fieldMsg(el);
    if (!firstInvalid) {
      firstInvalid = el;
      setTimeout(() => {
        const target = el.closest('.ui-field')?.querySelector('.ui-trigger, input:not(.ui-native)') || el;
        (el.closest('.field') || el).scrollIntoView({ block: 'center', behavior: 'smooth' });
        target.focus({ preventScroll: true });
        firstInvalid = null;
      }, 0);
    }
  }, true);
  const clearInvalid = (e) => {
    const box = e.target.closest?.('.field.is-invalid');
    if (box && e.target.checkValidity?.()) {
      box.classList.remove('is-invalid');
      box.querySelector(':scope > .field-error')?.remove();
    }
  };
  document.addEventListener('input', clearInvalid, true);
  document.addEventListener('change', clearInvalid, true);

  // Listas con «Otro»: al elegirlo aparece el campo para escribirlo a mano.
  const otherToggle = (sel) => {
    const box = document.querySelector(sel.dataset.other);
    if (!box) return;
    box.hidden = sel.value !== '__otro';
    box.required = !box.hidden;
    if (!box.hidden && document.activeElement !== box) setTimeout(() => box.focus(), 0);
  };
  const fillOptions = (sel, list, placeholder, otherLabel) => {
    const cur = sel.value;
    sel.innerHTML = '';
    sel.add(new Option(placeholder, ''));
    list.forEach((t) => sel.add(new Option(t, t)));
    sel.add(new Option(otherLabel, '__otro'));
    sel.value = list.includes(cur) || cur === '__otro' ? cur : '';
    otherToggle(sel);
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  };
  document.querySelectorAll('select[data-other]').forEach((sel) => {
    sel.addEventListener('change', () => otherToggle(sel));
  });
  const dep = document.getElementById('fDep');
  const pos = document.getElementById('fPos');
  if (dep && pos) {
    const areas = JSON.parse(pos.dataset.areas || '{}');
    dep.addEventListener('change', () => fillOptions(pos, areas[dep.value] || [], '— Elegí el puesto —', 'Otro (escribir)'));
  }
  const cct = document.getElementById('fCct');
  const cat = document.getElementById('fCat');
  if (cct && cat) {
    const cats = JSON.parse(cct.dataset.cats || '{}');
    cct.addEventListener('change', () => {
      document.getElementById('fCatWrap').hidden = cct.value === '';
      fillOptions(cat, cats[cct.value] || [], '— Elegí la categoría —', 'Otra (escribir)');
    });
  }

  document.querySelectorAll('input[type=date]:not([data-native])').forEach(enhanceDate);
  document.querySelectorAll('select:not([data-native]):not([multiple])').forEach(enhanceSelect);

  // Mantiene la presentación sincronizada si otro script modifica el valor del campo.
  setInterval(() => syncers.forEach((s) => s()), 250);
})();
