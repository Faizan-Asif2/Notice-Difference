document.addEventListener('DOMContentLoaded', function () {
  // Public mobile menu
  var btn = document.getElementById('menu-btn');
  var menu = document.getElementById('pub-menu');
  if (btn && menu) {
    function closeMenu() {
      menu.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      btn.setAttribute('aria-label', 'Open menu');
    }
    btn.addEventListener('click', function () {
      var open = menu.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    });
    menu.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', closeMenu); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.classList.contains('open')) { closeMenu(); btn.focus(); }
    });
  }

  // App sidebar: collapses on desktop, opens as a drawer on mobile
  var shell = document.querySelector('.app-shell');
  var toggle = document.getElementById('side-toggle');
  var scrim = document.getElementById('scrim');
  if (shell && toggle) {
    var wide = window.matchMedia('(min-width: 901px)');
    try {
      if (localStorage.getItem('nd-collapsed') === '1' && wide.matches) {
        shell.classList.add('collapsed');
        toggle.setAttribute('aria-expanded', 'false');
      }
    } catch (err) {}

    function closeDrawer() {
      shell.classList.remove('drawer-open');
      toggle.setAttribute('aria-expanded', 'false');
    }
    if (!wide.matches) toggle.setAttribute('aria-expanded', 'false');

    toggle.addEventListener('click', function () {
      if (wide.matches) {
        var collapsed = shell.classList.toggle('collapsed');
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        try { localStorage.setItem('nd-collapsed', collapsed ? '1' : '0'); } catch (err) {}
      } else {
        var open = shell.classList.toggle('drawer-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
    });
    if (scrim) scrim.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeDrawer();
    });
  }
});