/* QR generator (0.38.0): keep each colour picker and its #RRGGBB box in step. Everything works without this script. */
(function () {
  'use strict';
  var isHex = function (v) { return /^#[0-9A-Fa-f]{6}$/.test(v); };
  document.querySelectorAll('[data-qr-color]').forEach(function (row) {
    var picker = row.querySelector('[data-qr-picker]');
    var hex = row.querySelector('[data-qr-hex]');
    if (!picker || !hex) return;
    picker.addEventListener('input', function () { hex.value = picker.value.toUpperCase(); });
    hex.addEventListener('input', function () { if (isHex(hex.value)) picker.value = hex.value; });
  });
})();
