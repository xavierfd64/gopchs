/* MotoSupply POS — shared page behaviour (no frameworks, no build step). */
(function () {
  'use strict';

  // Mobile sidebar.
  var sidebar = document.getElementById('sidebar');
  var backdrop = document.querySelector('[data-close-sidebar]');
  var opener = document.querySelector('[data-open-sidebar]');
  function setSidebar(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('is-open', open);
    if (backdrop) backdrop.hidden = !open;
    if (opener) opener.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  if (opener) opener.addEventListener('click', function () { setSidebar(true); });
  if (backdrop) backdrop.addEventListener('click', function () { setSidebar(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sidebar && sidebar.classList.contains('is-open')) setSidebar(false);
  });

  // Live clock in the shop timezone.
  var tz = document.body.getAttribute('data-tz') || 'Asia/Manila';
  var timeEl = document.querySelector('[data-clock-time]');
  var dateEl = document.querySelector('[data-clock-date]');
  function tick() {
    try {
      var now = new Date();
      timeEl.textContent = now.toLocaleTimeString('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit' });
      dateEl.textContent = now.toLocaleDateString('en-US', { timeZone: tz, month: 'short', day: 'numeric', year: 'numeric' }).toUpperCase();
    } catch (err) { /* keep server-rendered time */ }
  }
  if (timeEl && dateEl) { tick(); setInterval(tick, 15000); }

  // Confirm important actions; prevent double submission of forms.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (form.hasAttribute('data-once')) {
      if (form.getAttribute('data-submitted') === '1') { e.preventDefault(); return; }
      form.setAttribute('data-submitted', '1');
      var btn = e.submitter || form.querySelector('button[type=submit]');
      if (btn) { btn.classList.add('is-busy'); btn.setAttribute('aria-busy', 'true'); }
    }
  });
  // Re-enable forms when the page is restored from the back/forward cache.
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('form[data-submitted]').forEach(function (f) {
      f.removeAttribute('data-submitted');
      f.querySelectorAll('.is-busy').forEach(function (b) { b.classList.remove('is-busy'); b.removeAttribute('aria-busy'); });
    });
  });

  // Filter selects submit their form when changed.
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) el.form.submit(); });
  });

  // Close open action menus when clicking elsewhere or pressing Escape.
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.menu[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.menu[open]').forEach(function (d) {
      d.removeAttribute('open');
      var s = d.querySelector('summary'); if (s) s.focus();
    });
  });

  // Desktop sidebar: collapse to an icon rail; remembered in a cookie (a display preference only).
  var railBtn = document.querySelector('[data-rail-toggle]');
  if (railBtn) {
    railBtn.addEventListener('click', function () {
      var rail = !document.body.classList.contains('sidebar-rail');
      document.body.classList.toggle('sidebar-rail', rail);
      railBtn.setAttribute('aria-pressed', rail ? 'true' : 'false');
      var label = rail ? 'Expand sidebar' : 'Collapse sidebar';
      railBtn.setAttribute('aria-label', label);
      railBtn.title = label;
      document.cookie = 'moto_sidebar=' + (rail ? 'rail' : 'full') + '; path=/; max-age=31536000; SameSite=Lax';
    });
  }

  // Show/hide password fields.
  document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
    var input = document.getElementById(btn.getAttribute('data-pw-toggle'));
    if (!input) return;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      var use = btn.querySelector('use');
      if (use) use.setAttribute('href', show ? '#i-eye-off' : '#i-eye');
      input.focus();
    });
  });

  // Login: friendly client-side check for empty fields (the server validates again).
  var loginForm = document.querySelector('[data-validate-login]');
  if (loginForm) {
    loginForm.addEventListener('submit', function (e) {
      var bad = false;
      ['username', 'password'].forEach(function (name) {
        var input = loginForm.querySelector('[name=' + name + ']');
        var msg = loginForm.querySelector('[data-error-for=' + name + ']');
        var empty = input.value.trim() === '';
        msg.hidden = !empty;
        input.setAttribute('aria-invalid', empty ? 'true' : 'false');
        if (empty && !bad) { input.focus(); bad = true; }
      });
      if (bad) { e.preventDefault(); e.stopImmediatePropagation(); loginForm.removeAttribute('data-submitted'); }
    }, true);
  }

  // Phones: tables marked .table-cards become cards; label each cell with its column title.
  document.querySelectorAll('table.table-cards').forEach(function (table) {
    var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      Array.prototype.forEach.call(tr.children, function (td, i) {
        if (!td.hasAttribute('data-label') && heads[i]) td.setAttribute('data-label', heads[i]);
      });
    });
  });

  // User form: show which permissions the selected role already includes.
  var roleSelect = document.querySelector('[data-role-select]');
  if (roleSelect) {
    var showRole = function () {
      var opt = roleSelect.options[roleSelect.selectedIndex];
      var perms = (opt && opt.getAttribute('data-perms') || '').split(',');
      document.querySelectorAll('[data-role-has]').forEach(function (el) {
        var has = perms.indexOf(el.getAttribute('data-role-has')) !== -1;
        el.textContent = has ? '✓ role' : '';
      });
    };
    roleSelect.addEventListener('change', showRole);
    showRole();
  }
})();
