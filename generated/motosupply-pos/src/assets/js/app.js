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
})();
