/* Settings → Appearance: live theme preview (the server validates contrast again on save). */
(function () {
  'use strict';
  function lum(hex) {
    var c = [1, 3, 5].map(function (i) {
      var v = parseInt(hex.substr(i, 2), 16) / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function contrast(a, b) {
    var x = lum(a), y = lum(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  }
  function mix(hex, other, t) {
    var out = '#';
    for (var i = 1; i < 7; i += 2) {
      var a = parseInt(hex.substr(i, 2), 16), b = parseInt(other.substr(i, 2), 16);
      out += ('0' + Math.round(a + (b - a) * t).toString(16)).slice(-2);
    }
    return out;
  }
  var root = document.documentElement;
  var primary = document.getElementById('theme_primary');
  var sidebar = document.getElementById('theme_sidebar');
  var msg = document.querySelector('[data-contrast-msg]');
  if (!primary || !sidebar) return;
  function apply() {
    var p = primary.value, s = sidebar.value;
    var onAccent = contrast(p, '#ffffff') >= 3 ? '#ffffff' : '#16171a';
    var onSidebar = contrast(s, '#ffffff') >= contrast(s, '#16171a') ? '#ffffff' : '#16171a';
    root.style.setProperty('--accent', p);
    root.style.setProperty('--accent-hover', mix(p, '#000000', 0.12));
    root.style.setProperty('--accent-soft', mix(p, '#ffffff', 0.9));
    root.style.setProperty('--on-accent', onAccent);
    root.style.setProperty('--sidebar', s);
    root.style.setProperty('--sidebar-2', mix(s, onSidebar === '#ffffff' ? '#ffffff' : '#000000', 0.08));
    root.style.setProperty('--on-sidebar', onSidebar);
    root.style.setProperty('--sidebar-text', mix(onSidebar, s, 0.22));
    document.querySelector('[data-color-code="theme_primary"]').textContent = p;
    document.querySelector('[data-color-code="theme_sidebar"]').textContent = s;
    var problems = [];
    if (contrast(p, '#ffffff') < 3) problems.push('the primary colour is too light on white');
    if (contrast(s, onSidebar) < 4.5) problems.push('sidebar text would be hard to read');
    msg.textContent = problems.length ? 'Cannot be saved: ' + problems.join('; ') + '.' : 'Contrast OK.';
    msg.className = 'small ' + (problems.length ? 'text-danger' : 'text-success');
  }
  primary.addEventListener('input', apply);
  sidebar.addEventListener('input', apply);
  apply();
})();
