/* S01 upload helper: lists the chosen files (name + size) before uploading and stops a second submit. Nothing is read from the files here. */
(() => {
  'use strict';
  document.querySelectorAll('[data-pdf-upload]').forEach((form) => {
    const input = form.querySelector('[data-pdf-files]');
    const list = form.querySelector('[data-pdf-list]');
    const size = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : (b / 1024).toFixed(1) + ' KB');
    if (input && list) {
      input.addEventListener('change', () => {
        list.replaceChildren(...Array.from(input.files || []).map((f, i) => {
          const li = document.createElement('li');
          li.textContent = `${i + 1}. ${f.name} — ${size(f.size)}`;
          return li;
        }));
      });
    }
    form.addEventListener('submit', () => {
      const btn = form.querySelector('button[type=submit]');
      if (btn) { setTimeout(() => { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }, 0); }
    });
  });
})();
