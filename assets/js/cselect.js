/* Themed dropdown. Upgrades every <select data-cselect> into a styled picker (bottom sheet on phones).
   The real <select> stays in the form, so submitting, validation and server code are unchanged. */
(function () {
  function build(sel) {
    var wrap = document.createElement('div'); wrap.className = 'cs';
    sel.parentNode.insertBefore(wrap, sel); wrap.appendChild(sel);
    sel.classList.add('cs-native'); sel.tabIndex = -1; sel.setAttribute('aria-hidden', 'true');

    var title = sel.getAttribute('data-title') || 'Choose an option';
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'cs-btn';
    btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
    var lbl = document.createElement('span'); lbl.className = 'cs-lbl';
    var chev = document.createElement('i'); chev.className = 'fa-solid fa-chevron-down cs-chev';
    btn.append(lbl, chev);

    var back = document.createElement('div'); back.className = 'cs-back';
    var panel = document.createElement('div'); panel.className = 'cs-panel';
    var head = document.createElement('div'); head.className = 'cs-head';
    head.innerHTML = '<b></b><button type="button" class="cs-x" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>';
    head.firstChild.textContent = title;
    var list = document.createElement('ul'); list.className = 'cs-list'; list.setAttribute('role', 'listbox');
    panel.append(head, list); wrap.append(btn, back, panel);

    var items = [], active = -1;
    Array.prototype.forEach.call(sel.options, function (o, i) {
      var li = document.createElement('li'); li.setAttribute('role', 'option'); li.dataset.v = o.value; li.dataset.i = i;
      if (o.value === '') li.classList.add('cs-ph');
      li.innerHTML = '<span class="cs-t"></span><i class="fa-solid fa-check cs-ok"></i>';
      li.firstChild.textContent = o.textContent; list.appendChild(li); items.push(li);
    });

    function paint() {
      var o = sel.options[sel.selectedIndex];
      var empty = !o || o.value === '';
      lbl.textContent = o ? o.textContent : ''; btn.classList.toggle('cs-empty', empty);
      items.forEach(function (li, i) { var on = i === sel.selectedIndex && li.dataset.v !== ''; li.classList.toggle('on', on); li.setAttribute('aria-selected', on); });
    }
    function setActive(i) {
      if (i < 0 || i >= items.length) return;
      if (active > -1) items[active].classList.remove('hl');
      active = i; items[i].classList.add('hl'); items[i].scrollIntoView({ block: 'nearest' });
    }
    function open() {
      if (wrap.classList.contains('open')) return;
      wrap.classList.add('open'); btn.setAttribute('aria-expanded', 'true');
      document.documentElement.classList.add('cs-lock');
      setActive(Math.max(sel.selectedIndex, 0));
    }
    function close(focus) {
      wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false');
      document.documentElement.classList.remove('cs-lock');
      if (focus) btn.focus();
    }
    function pick(i) {
      sel.selectedIndex = i; paint(); close(true);
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    btn.addEventListener('click', function () { wrap.classList.contains('open') ? close() : open(); });
    back.addEventListener('click', function () { close(); });
    head.querySelector('.cs-x').addEventListener('click', function () { close(true); });
    list.addEventListener('click', function (e) { var li = e.target.closest('li'); if (li) pick(+li.dataset.i); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    wrap.addEventListener('keydown', function (e) {
      var isOpen = wrap.classList.contains('open');
      if (e.key === 'Escape' && isOpen) { e.preventDefault(); close(true); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); isOpen ? setActive(Math.min(active + 1, items.length - 1)) : open(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); isOpen ? setActive(Math.max(active - 1, 0)) : open(); }
      else if ((e.key === 'Enter' || e.key === ' ') && isOpen && active > -1) { e.preventDefault(); pick(active); }
    });
    sel.addEventListener('invalid', function () { btn.classList.add('cs-bad'); });
    sel.addEventListener('change', function () { btn.classList.remove('cs-bad'); paint(); });
    paint();
  }
  document.querySelectorAll('select[data-cselect]').forEach(build);
})();
