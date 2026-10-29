/* Adds a show/hide (eye) button to every password field on the page. */
(function () {
  document.querySelectorAll('input[type=password]').forEach(function (inp) {
    if (inp.dataset.pw) return; inp.dataset.pw = '1';
    var wrap = inp.parentNode;
    if (!wrap.classList.contains('input-wrap')) {
      wrap = document.createElement('div'); wrap.className = 'pw-wrap';
      inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
    }
    inp.classList.add('has-eye');
    var b = document.createElement('button'); b.type = 'button'; b.className = 'eye-btn';
    b.setAttribute('aria-label', 'Show password'); b.setAttribute('aria-pressed', 'false'); b.title = 'Show password';
    b.innerHTML = '<i class="fa-solid fa-eye"></i>';
    b.addEventListener('click', function () {
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      b.innerHTML = '<i class="fa-solid ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>';
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); b.title = show ? 'Hide password' : 'Show password';
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      inp.focus({ preventScroll: true });
      try { var n = inp.value.length; inp.setSelectionRange(n, n); } catch (e) {}
    });
    wrap.appendChild(b);
  });
})();
