(function () {
  'use strict';
  var btn = document.querySelector('[data-print]');
  if (btn) btn.addEventListener('click', function () { window.print(); });
  if (document.body.getAttribute('data-autoprint') === '1') {
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });
  }
})();
