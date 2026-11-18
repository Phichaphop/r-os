/* M30 font preview: loads each font from this system (FontFace API, CSP font-src 'self') only when its sample comes into view,
   then shows the text and size the person typed. Nothing is sent anywhere. */
(() => {
  'use strict';
  const samples = Array.from(document.querySelectorAll('[data-font-sample][data-font-src]'));
  if (samples.length === 0) return;
  const text = document.querySelector('[data-font-text]');
  const size = document.querySelector('[data-font-size]');
  const sizeOut = document.querySelector('[data-font-size-out]');
  const fallback = samples[0].textContent;

  const apply = () => {
    const t = text && text.value.trim() !== '' ? text.value : fallback;
    const px = size ? Number(size.value) : 32;
    if (sizeOut) sizeOut.textContent = String(px);
    samples.forEach((el) => { el.textContent = t; el.style.fontSize = px + 'px'; });
  };

  let n = 0;
  const load = (el) => {
    if (el.dataset.fontLoaded) return;
    el.dataset.fontLoaded = '1';
    const family = 'blue-font-' + (++n);
    if (!('FontFace' in window)) return;
    const face = new FontFace(family, `url("${el.dataset.fontSrc}")`);
    el.setAttribute('aria-busy', 'true');
    face.load().then((f) => {
      document.fonts.add(f);
      el.style.fontFamily = `"${family}", var(--font-body, sans-serif)`;
    }).catch(() => {
      el.classList.add('font-sample--failed');
    }).finally(() => el.removeAttribute('aria-busy'));
  };

  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => entries.forEach((e) => { if (e.isIntersecting) { load(e.target); io.unobserve(e.target); } }), { rootMargin: '200px' });
    samples.forEach((el) => io.observe(el));
  } else {
    samples.forEach(load);
  }
  if (text) text.addEventListener('input', apply);
  if (size) size.addEventListener('input', apply);
  apply();
})();
