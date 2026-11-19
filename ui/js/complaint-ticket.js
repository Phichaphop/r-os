/*
 * M16 anonymous complaints — follow-up ticket and tracking helpers (0.44.2 · DEC-113). Everything runs in the browser and
 * this file sends nothing (drawing the picture may make the browser load the page's own font file, which carries no data):
 *  - [data-cp-ticket]  the ticket shown once after sending: save as a picture (drawn here on a canvas from the QR modules
 *                      the page carries), save as PDF (the browser's print → "Save as PDF") and copy the code.
 *  - [data-cp-track]   the follow-up page: a code after "#" (from the ticket's QR) fills the form, is removed from the
 *                      address at once and the form is sent; or read the QR from a saved ticket picture or the camera
 *                      (jsQR, ui/js/vendor/jsqr) — pictures and camera frames never leave the browser.
 */
(function () {
  'use strict';
  var CODE = /([0-9A-HJKMNP-TV-Z]{4}-?){4}[0-9A-HJKMNP-TV-Z]{4}/i;

  function codeFrom(text) {
    var s = String(text || '');
    var i = s.indexOf('#');
    if (i >= 0) { s = s.slice(i + 1); }
    var m = s.match(CODE);
    return m ? m[0].toUpperCase() : null;
  }

  // ---- the ticket -------------------------------------------------------------------------------------------------------
  var t = document.querySelector('[data-cp-ticket]');
  if (t) {
    var d = t.dataset;
    var status = t.querySelector('[data-cp-ticket-status]');
    var say = function (m) { if (status) { status.textContent = m; } };

    var copy = t.querySelector('[data-cp-ticket-copy]');
    if (copy) {
      copy.addEventListener('click', function () {
        var done = function () { say(d.msgCopied); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(d.code).then(done, function () { fallbackCopy(); });
        } else { fallbackCopy(); }
        function fallbackCopy() {
          var input = document.getElementById('cp-code-copy');
          if (input) { input.focus(); input.select(); try { document.execCommand('copy'); done(); } catch (e) { say(d.msgFailed); } }
        }
      });
    }

    var print = t.querySelector('[data-cp-ticket-print]');
    if (print) { print.addEventListener('click', function () { window.print(); }); }

    var img = t.querySelector('[data-cp-ticket-image]');
    if (img) {
      img.addEventListener('click', function () {
        try {
          drawTicket(function (blob) {
            if (!blob) { say(d.msgFailed); return; }
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = d.file || 'blue-ticket.png';
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
            say(d.msgSaved);
          });
        } catch (e) { say(d.msgFailed); }
      });
    }

    var drawTicket = function (cb) {
      var css = getComputedStyle(document.body);
      var font = css.fontFamily || 'sans-serif';
      // paper colours (the same in light and dark themes) from the design tokens
      var root = getComputedStyle(document.documentElement);
      var tok = function (n, f) { return root.getPropertyValue(n).trim() || f; };
      var paper = tok('--color-paper', 'white'), ink = tok('--color-paper-ink', 'black'), muted = tok('--color-paper-muted', 'gray'), rule = tok('--color-paper-rule', 'silver');
      var W = 1080, P = 72;
      var rows = (d.modules || '').split('|').filter(Boolean);
      var n = rows.length, cell = Math.floor(420 / (n + 8)), qrSize = cell * (n + 8);
      var c = document.createElement('canvas');
      var ctx = c.getContext('2d');
      // measure wrapped text first so the picture is as tall as it needs to be
      ctx.font = '30px ' + font;
      var how = wrap(ctx, d.how, W - 2 * P);
      ctx.font = '26px ' + font;
      var keep = wrap(ctx, d.keep, W - 2 * P);
      var H = P + 70 + (d.company ? 46 : 0) + 40 + qrSize + 40 + 3 * 104 + 30 + how.length * 42 + 24 + keep.length * 36 + P;
      c.width = W; c.height = H;
      ctx.fillStyle = paper; ctx.fillRect(0, 0, W, H);
      ctx.strokeStyle = rule; ctx.lineWidth = 4; ctx.strokeRect(2, 2, W - 4, H - 4);
      ctx.fillStyle = ink; ctx.textBaseline = 'top';
      var y = P;
      ctx.font = '600 52px ' + font; ctx.fillText(d.title, P, y); y += 70;
      if (d.company) { ctx.font = '32px ' + font; ctx.fillStyle = muted; ctx.fillText(d.company, P, y); y += 46; }
      y += 40;
      // QR (dark modules on white, with its quiet zone)
      var qx = Math.round((W - qrSize) / 2);
      ctx.fillStyle = ink;
      for (var r = 0; r < n; r++) {
        for (var k = 0; k < rows[r].length; k++) {
          if (rows[r].charAt(k) === '1') { ctx.fillRect(qx + (k + 4) * cell, y + (r + 4) * cell, cell, cell); }
        }
      }
      y += qrSize + 40;
      [[d.lRef, d.ref, '600 40px '], [d.lCode, d.code, '700 46px '], [d.lDay, d.day, '600 40px ']].forEach(function (f) {
        ctx.fillStyle = muted; ctx.font = '28px ' + font; ctx.fillText(f[0], P, y);
        ctx.fillStyle = ink; ctx.font = f[2] + font; ctx.fillText(f[1], P, y + 34);
        y += 104;
      });
      y += 30;
      ctx.fillStyle = ink; ctx.font = '30px ' + font;
      how.forEach(function (l) { ctx.fillText(l, P, y); y += 42; });
      y += 24;
      ctx.fillStyle = muted; ctx.font = '26px ' + font;
      keep.forEach(function (l) { ctx.fillText(l, P, y); y += 36; });
      c.toBlob(cb, 'image/png');
    };
  }

  /** Break text into lines that fit (by spaces; long words such as an address are broken by characters). */
  function wrap(ctx, text, max) {
    var out = [], line = '';
    String(text || '').split(/(\s+)/).forEach(function (w) {
      var test = line + w;
      if (ctx.measureText(test).width <= max || line === '') {
        line = test;
        while (ctx.measureText(line).width > max && line.length > 1) {
          var cut = line.length - 1;
          while (cut > 1 && ctx.measureText(line.slice(0, cut)).width > max) { cut--; }
          out.push(line.slice(0, cut)); line = line.slice(cut);
        }
      } else { out.push(line.trim()); line = w.trimStart(); }
    });
    if (line.trim() !== '') { out.push(line.trim()); }
    return out;
  }

  // ---- the follow-up page -----------------------------------------------------------------------------------------------
  var tr = document.querySelector('[data-cp-track]');
  if (!tr) { return; }
  var form = tr.querySelector('form[data-cp-track-form]');
  var field = form ? form.querySelector('input[name="code"]') : null;
  var msg = tr.querySelector('[data-cp-scan-status]');
  var tell = function (m) { if (msg) { msg.textContent = m; } };
  var open = function (code) {
    if (!form || !field) { return; }
    field.value = code;
    tell(tr.dataset.msgFound);
    if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
  };

  // a code after "#" (the ticket's QR): never sent to the server; removed from the address before sending the form
  var fromHash = codeFrom(location.hash);
  if (location.hash) { history.replaceState(null, '', location.pathname + location.search); }
  if (fromHash) { open(fromHash); return; }

  if (typeof window.jsQR !== 'function') { return; }
  var tryDecode = function (src, w, h) {
    var sizes = [1, 1200 / Math.max(w, h), 800 / Math.max(w, h), 500 / Math.max(w, h)];
    var cv = document.createElement('canvas'), cx = cv.getContext('2d', { willReadFrequently: true });
    for (var i = 0; i < sizes.length; i++) {
      var s = Math.min(1, sizes[i]); var cw = Math.max(1, Math.round(w * s)), ch = Math.max(1, Math.round(h * s));
      cv.width = cw; cv.height = ch; cx.drawImage(src, 0, 0, cw, ch);
      var hit = window.jsQR(cx.getImageData(0, 0, cw, ch).data, cw, ch, { inversionAttempts: 'attemptBoth' });
      if (hit && codeFrom(hit.data)) { return codeFrom(hit.data); }
    }
    return null;
  };

  var file = tr.querySelector('[data-cp-scan-file]');
  if (file) {
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) { return; }
      var url = URL.createObjectURL(f), im = new Image();
      im.onload = function () {
        var code = tryDecode(im, im.naturalWidth, im.naturalHeight);
        URL.revokeObjectURL(url);
        if (code) { open(code); } else { tell(tr.dataset.msgNone); }
      };
      im.onerror = function () { URL.revokeObjectURL(url); tell(tr.dataset.msgNone); };
      im.src = url;
    });
  }

  var start = tr.querySelector('[data-cp-scan-start]'), stop = tr.querySelector('[data-cp-scan-stop]');
  var box = tr.querySelector('[data-cp-scan-video-box]'), video = tr.querySelector('[data-cp-scan-video]');
  var stream = null, raf = 0;
  var halt = function () {
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
    if (stream) { stream.getTracks().forEach(function (k) { k.stop(); }); stream = null; }
    if (box) { box.hidden = true; }
    if (start) { start.hidden = false; }
    if (stop) { stop.hidden = true; }
  };
  if (start && video && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
    start.addEventListener('click', function () {
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
        stream = s; video.srcObject = s; video.play();
        box.hidden = false; start.hidden = true; if (stop) { stop.hidden = false; }
        tell(tr.dataset.msgLooking);
        var cv = document.createElement('canvas'), cx = cv.getContext('2d', { willReadFrequently: true });
        var tick = function () {
          if (!stream) { return; }
          if (video.readyState >= 2 && video.videoWidth) {
            var w = video.videoWidth, h = video.videoHeight;
            cv.width = w; cv.height = h; cx.drawImage(video, 0, 0, w, h);
            var hit = window.jsQR(cx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' });
            var code = hit ? codeFrom(hit.data) : null;
            if (code) { halt(); open(code); return; }
          }
          raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
      }, function () { tell(tr.dataset.msgCamera); halt(); });
    });
    if (stop) { stop.addEventListener('click', halt); }
    window.addEventListener('pagehide', halt);
  } else if (start) {
    start.hidden = true;
  }
})();
