/* Print buttons ([data-print]) — resume builder, JD, documents: the page hides menus and forms when printing. */
(function () {
  'use strict';
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });
})();
