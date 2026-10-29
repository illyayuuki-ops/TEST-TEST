// Small helpers: confirm deletes, click-to-sort tables, live filter lists, print buttons.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[data-confirm]').forEach(f =>
    f.addEventListener('submit', ev => { if (!confirm(f.dataset.confirm)) ev.preventDefault(); }));

  document.querySelectorAll('[data-print]').forEach(b => b.addEventListener('click', () => window.print()));

  document.querySelectorAll('input[data-filter]').forEach(inp => {
    inp.addEventListener('input', () => {
      const q = inp.value.toLowerCase();
      document.querySelectorAll(inp.dataset.filter).forEach(li => li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none');
    });
  });

  document.querySelectorAll('table[data-sortable]').forEach(tbl => {
    const heads = tbl.querySelectorAll('thead th');
    heads.forEach((th, i) => {
      if (!th.textContent.trim()) return;
      th.dataset.sort = '1'; th.title = 'Click to sort this page';
      th.addEventListener('click', () => {
        const dir = th.dataset.dir === 'asc' ? 'desc' : 'asc';
        heads.forEach(h => delete h.dataset.dir); th.dataset.dir = dir;
        const body = tbl.tBodies[0];
        const rows = [...body.rows].filter(r => r.cells.length > 1);
        rows.sort((a, b) => {
          const x = a.cells[i].textContent.trim(), y = b.cells[i].textContent.trim();
          const nx = parseFloat(x.replace(/,/g, '')), ny = parseFloat(y.replace(/,/g, ''));
          const c = (!isNaN(nx) && !isNaN(ny)) ? nx - ny : x.localeCompare(y, undefined, {numeric: true});
          return dir === 'asc' ? c : -c;
        });
        rows.forEach(r => body.appendChild(r));
      });
    });
  });

  const fl = document.querySelector('.flash'); if (fl) setTimeout(() => fl.remove(), 4000);
});

// Profile menu: close when clicking elsewhere or pressing Esc.
document.addEventListener('click', e => { document.querySelectorAll('details.profile[open]').forEach(d => { if (!d.contains(e.target)) d.removeAttribute('open'); }); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('details.profile[open]').forEach(d => d.removeAttribute('open')); });

// Search-as-you-type: every search box shows a dropdown of suggestions after the first letter (like Google).
// Up / Down choose a suggestion, Enter or a click uses it, Esc closes the list.
(function () {
  const page = location.pathname.split('/').pop() || 'index.php';
  const sec = new URLSearchParams(location.search).get('e') || '';
  function attach(inp) {
    if (inp.dataset.suggest === 'off' || inp.dataset.filter) return;
    inp.setAttribute('autocomplete', 'off'); inp.setAttribute('role', 'combobox'); inp.setAttribute('aria-autocomplete', 'list');
    const box = document.createElement('ul'); box.className = 'suggest'; box.setAttribute('role', 'listbox'); box.hidden = true;
    const wrap = document.createElement('span'); wrap.className = 'suggest-wrap';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp); wrap.appendChild(box);
    let items = [], cur = -1, timer = null, ctl = null;
    const close = () => { box.hidden = true; cur = -1; inp.removeAttribute('aria-expanded'); };
    const pick = i => { inp.value = items[i].v; close(); if (inp.form) { inp.form.requestSubmit ? inp.form.requestSubmit() : inp.form.submit(); } };
    const show = list => {
      items = list; box.innerHTML = '';
      if (!list.length) return close();
      list.forEach((it, i) => {
        const li = document.createElement('li'); li.setAttribute('role', 'option');
        const t = document.createElement('span'); t.className = 's-text';
        const q = inp.value.trim(), at = q ? it.l.toLowerCase().indexOf(q.toLowerCase()) : -1;
        if (at >= 0) { t.append(it.l.slice(0, at)); const b = document.createElement('b'); b.textContent = it.l.slice(at, at + q.length); t.append(b, it.l.slice(at + q.length)); } else t.textContent = it.l;
        const h = document.createElement('small'); h.textContent = it.h;
        li.append(t, h);
        li.addEventListener('mousedown', ev => { ev.preventDefault(); pick(i); });
        box.appendChild(li);
      });
      box.hidden = false; inp.setAttribute('aria-expanded', 'true'); cur = -1;
    };
    const mark = () => [...box.children].forEach((li, i) => li.classList.toggle('on', i === cur));
    inp.addEventListener('input', () => {
      clearTimeout(timer); const q = inp.value.trim();
      if (!q) return close();
      timer = setTimeout(() => {
        if (ctl) ctl.abort(); ctl = new AbortController();
        const u = 'suggest.php?' + new URLSearchParams({ p: page, e: sec, n: inp.name || 'q', q });
        fetch(u, { signal: ctl.signal, credentials: 'same-origin' }).then(r => r.ok ? r.json() : []).then(list => { if (inp.value.trim() === q) show(list); }).catch(() => {});
      }, 180);
    });
    inp.addEventListener('keydown', ev => {
      if (box.hidden) return;
      if (ev.key === 'ArrowDown') { ev.preventDefault(); cur = (cur + 1) % items.length; mark(); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); cur = (cur - 1 + items.length) % items.length; mark(); }
      else if (ev.key === 'Enter' && cur >= 0) { ev.preventDefault(); pick(cur); }
      else if (ev.key === 'Escape') close();
    });
    inp.addEventListener('blur', () => setTimeout(close, 120));
  }
  document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('input[type=search]').forEach(attach));
})();
