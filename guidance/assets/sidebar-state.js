/* Guidance-only navigation state. No page scroll, permissions or records are changed. */
(() => {
  'use strict';
  const root = document.getElementById('guidance');
  const nav = document.querySelector('#sidebar .nav');
  if (!root || !nav || !root.dataset.navScope) return;
  const groups = [...nav.querySelectorAll('details.gd-nav-group')];
  const key = 'lcct.guidance.nav.v1:' + location.pathname + ':' + root.dataset.navScope;
  let restoring = true;
  let touched = false;
  let saved = null;
  try {
    saved = JSON.parse(sessionStorage.getItem(key));
    if (!saved || !Number.isFinite(saved.top) || saved.top < 0 ||
        !Number.isFinite(saved.time) || Date.now() - saved.time > 28800000 ||
        !Array.isArray(saved.groups)) saved = null;
  } catch (_) { saved = null; }
  function restore() {
    if (touched || !saved) return;
    groups.forEach((group, i) => {
      group.open = group.classList.contains('has-active') || saved.groups[i] === true;
    });
    nav.scrollTop = saved.top;
  }
  function save() {
    if (restoring) return;
    try {
      sessionStorage.setItem(key, JSON.stringify({
        top: nav.scrollTop, groups: groups.map(group => group.open), time: Date.now()
      }));
    } catch (_) { /* Storage restrictions must never prevent navigation. */ }
  }
  function interact() { touched = true; restoring = false; }
  ['pointerdown', 'wheel', 'touchstart', 'keydown'].forEach(type => {
    nav.addEventListener(type, interact, { passive: true });
  });
  restore();
  requestAnimationFrame(() => { restore(); restoring = false; });
  // Font loading can change menu height. Do not move it once the user interacts.
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(restore);
  nav.addEventListener('scroll', save, { passive: true });
  groups.forEach(group => group.addEventListener('toggle', save));
  nav.addEventListener('click', event => {
    if (event.target.closest('a[href]')) { restoring = false; save(); }
  }, true);
  window.addEventListener('pagehide', () => { restoring = false; save(); });
  document.addEventListener('visibilitychange', () => { if (document.hidden) save(); });
})();
