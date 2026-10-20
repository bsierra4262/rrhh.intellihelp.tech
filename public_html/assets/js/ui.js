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
  const human = (v) => {
    const d = parse(v);
    return d ? d.getDate() + ' ' + MONTHS[d.getMonth()].slice(0, 3) + ' ' + d.getFullYear() : '';
  };
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
    wrap.classList.remove('up');
    const r = pop.getBoundingClientRect();
    if (r.bottom > window.innerHeight - 8 && wrap.getBoundingClientRect().top > r.height + 16) {
      wrap.classList.add('up');
    }
  }

  // ---------------------------------------------------------------- Calendario
  function enhanceDate(input) {
    const wrap = document.createElement('div');
    wrap.className = 'ui-field ui-date';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.classList.add('ui-native');
    input.tabIndex = -1;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ui-trigger';
    btn.innerHTML = '<span class="ui-ico">' + calIcon + '</span><span class="ui-value"></span>';
    const pop = document.createElement('div');
    pop.className = 'ui-pop ui-cal';
    pop.hidden = true;
    wrap.append(btn, pop);

    let view = parse(input.value) || new Date();
    view = new Date(view.getFullYear(), view.getMonth(), 1);
    let last = null;

    const render = () => {
      const y = view.getFullYear(), m = view.getMonth();
      const first = (new Date(y, m, 1).getDay() + 6) % 7;
      const total = new Date(y, m + 1, 0).getDate();
      const today = iso(new Date());
      const min = input.min || '', max = input.max || '';
      let h = '<div class="ui-cal-head"><button type="button" class="ui-nav" data-nav="-1" aria-label="Mes anterior">‹</button>'
        + '<b>' + MONTHS[m].charAt(0).toUpperCase() + MONTHS[m].slice(1) + ' ' + y + '</b>'
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
      btn.querySelector('.ui-value').textContent = input.value ? human(input.value) : 'Elegir fecha';
      btn.classList.toggle('is-empty', !input.value);
    };
    const set = (v) => {
      input.value = v;
      sync();
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const api = {
      close() {
        pop.hidden = true;
        wrap.classList.remove('open');
      },
    };

    btn.addEventListener('click', () => {
      if (!pop.hidden) {
        closeOpen();
        return;
      }
      closeOpen();
      const cur = parse(input.value) || parse(input.min) || new Date();
      view = new Date(cur.getFullYear(), cur.getMonth(), 1);
      render();
      pop.hidden = false;
      wrap.classList.add('open');
      place(wrap, pop);
      openPop = api;
    });
    pop.addEventListener('click', (e) => {
      e.stopPropagation();
      const nav = e.target.closest('[data-nav]');
      const day = e.target.closest('[data-day]');
      const act = e.target.closest('[data-act]');
      if (nav) {
        view.setMonth(view.getMonth() + Number(nav.dataset.nav));
        render();
      } else if (day && !day.disabled) {
        set(day.dataset.day);
        closeOpen();
        btn.focus();
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

    const searchable = select.options.length > 8;
    let active = -1;
    let last = null;

    const items = () => [...pop.querySelectorAll('.ui-opt:not([hidden])')];
    const render = () => {
      let h = searchable ? '<div class="ui-search"><input type="text" placeholder="Buscar…" aria-label="Buscar"></div>' : '';
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
    syncers.push(sync);
    sync();
  }

  document.addEventListener('click', (e) => {
    if (openPop && !e.target.closest('.ui-field.open')) closeOpen();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeOpen();
  });

  document.querySelectorAll('input[type=date]:not([data-native])').forEach(enhanceDate);
  document.querySelectorAll('select:not([data-native]):not([multiple])').forEach(enhanceSelect);

  // Mantiene la presentación sincronizada si otro script modifica el valor del campo.
  setInterval(() => syncers.forEach((s) => s()), 250);
})();
