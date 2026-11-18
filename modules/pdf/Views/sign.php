<?php
/**
 * 0.39.0 (item 3.1) signature tool — runs only in the person's browser (ui/js/pdf-sign.js). There is deliberately no
 * <form> here and the file input has no name: the drawing and the PDF never leave the browser, so the server neither
 * receives, keeps nor audits a signature (the long-standing "no drawn signatures on the server" rule).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $feature @var array<string, int> $limits
 */
?>
<p><a href="<?= $e($url('/pdf')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('pdf.back')) ?></a></p>
<div class="pdf-hero">
  <span class="pdf-hero__icon" aria-hidden="true"><?= $ui('icon', ['name' => 'pencil']) ?></span>
  <h1 class="pdf-hero__title"><?= $e($t('pdf.tool.sign')) ?></h1>
  <p class="pdf-hero__lead"><?= $e($t('pdf.about.sign')) ?></p>
</div>
<?php if ($feature['state'] !== 'available'): ?>
  <?= $ui('states', ['kind' => 'forbidden', 'title' => $t('pdf.state.' . $feature['state']), 'text' => $t('pdf.state_text.disabled')]) ?>
<?php else: ?>
<?= $ui('alert', ['tone' => 'info', 'title' => $t('pdf.sign.local_title'), 'text' => $t('pdf.sign.local_text')]) ?>
<div class="sign" data-sign
  data-lib="<?= $e($asset('ui/js/vendor/pdfjs/pdf.min.js')) ?>" data-worker="<?= $e($asset('ui/js/vendor/pdfjs/pdf.worker.min.js')) ?>"
  data-fonts="<?= $e($url('/assets/ui/js/vendor/pdfjs/standard_fonts/')) ?>" data-pdflib="<?= $e($asset('ui/js/vendor/pdflib/pdf-lib.min.js')) ?>"
  data-max-mb="<?= (int) $limits['max_mb'] ?>"
  data-msg-empty="<?= $e($t('pdf.sign.msg_empty')) ?>" data-msg-copied="<?= $e($t('pdf.sign.msg_copied')) ?>" data-msg-copy-failed="<?= $e($t('pdf.sign.msg_copy_failed')) ?>"
  data-msg-not-pdf="<?= $e($t('pdf.sign.msg_not_pdf')) ?>" data-msg-too-big="<?= $e($t('pdf.sign.msg_too_big', ['mb' => (string) $limits['max_mb']])) ?>"
  data-msg-encrypted="<?= $e($t('pdf.sign.msg_encrypted')) ?>" data-msg-failed="<?= $e($t('pdf.sign.msg_failed')) ?>" data-msg-placed="<?= $e($t('pdf.sign.msg_placed')) ?>"
  data-msg-removed="<?= $e($t('pdf.sign.msg_removed')) ?>" data-msg-ready="<?= $e($t('pdf.sign.msg_ready')) ?>" data-msg-need-sig="<?= $e($t('pdf.sign.msg_need_sig')) ?>"
  data-msg-need-place="<?= $e($t('pdf.sign.msg_need_place')) ?>" data-msg-page="<?= $e($t('pdf.sign.page_of')) ?>" data-msg-placement="<?= $e($t('pdf.sign.placement')) ?>"
  data-msg-remove="<?= $e($t('pdf.sign.remove')) ?>">

  <section class="card stack" aria-labelledby="sg-1">
    <h2 id="sg-1" class="card__title"><?= $e($t('pdf.sign.step1')) ?></h2>
    <div class="sign__pad-wrap">
      <canvas class="sign__pad" data-pad tabindex="0" aria-label="<?= $e($t('pdf.sign.pad_label')) ?>" aria-describedby="sg-pad-help"></canvas>
      <span class="sign__line" aria-hidden="true"></span>
      <span class="sign__hint" data-pad-hint aria-hidden="true"><?= $e($t('pdf.sign.pad_hint')) ?></span>
    </div>
    <p id="sg-pad-help" class="text-sm text-muted"><?= $e($t('pdf.sign.pad_help')) ?></p>
    <div class="sign__options">
      <fieldset class="sign__ink">
        <legend class="field__label"><?= $e($t('pdf.sign.ink')) ?></legend>
        <label class="choice"><input type="radio" name="sign_ink" value="black" checked> <span class="sign__swatch sign__swatch--black" aria-hidden="true"></span> <?= $e($t('pdf.sign.ink_black')) ?></label>
        <label class="choice"><input type="radio" name="sign_ink" value="blue"> <span class="sign__swatch sign__swatch--blue" aria-hidden="true"></span> <?= $e($t('pdf.sign.ink_blue')) ?></label>
      </fieldset>
      <div class="field">
        <label class="field__label" for="sg-width"><?= $e($t('pdf.sign.width')) ?></label>
        <input class="sign__range" type="range" id="sg-width" min="1" max="8" step="0.5" value="3" data-width>
      </div>
    </div>
    <div class="field">
      <label class="field__label" for="sg-typed"><?= $e($t('pdf.sign.typed')) ?></label>
      <div class="row">
        <input class="input sign__typed" id="sg-typed" maxlength="60" autocomplete="off" aria-describedby="sg-typed-help" data-typed>
        <?= $ui('button', ['label' => $t('pdf.sign.use_typed'), 'variant' => 'secondary', 'icon' => 'pencil', 'attrs' => ['data-act' => 'typed']]) ?>
      </div>
      <div id="sg-typed-help" class="field__help"><?= $e($t('pdf.sign.typed_help')) ?></div>
    </div>
    <div class="row">
      <?= $ui('button', ['label' => $t('pdf.sign.undo'), 'variant' => 'ghost', 'icon' => 'arrow-back-up', 'attrs' => ['data-act' => 'undo']]) ?>
      <?= $ui('button', ['label' => $t('pdf.sign.clear'), 'variant' => 'ghost', 'icon' => 'trash', 'attrs' => ['data-act' => 'clear']]) ?>
      <?= $ui('button', ['label' => $t('pdf.sign.download_png'), 'variant' => 'primary', 'icon' => 'download', 'attrs' => ['data-act' => 'png']]) ?>
      <?= $ui('button', ['label' => $t('pdf.sign.copy_base64'), 'variant' => 'secondary', 'icon' => 'copy', 'attrs' => ['data-act' => 'copy']]) ?>
    </div>
    <details class="stack-sm">
      <summary class="text-sm"><?= $e($t('pdf.sign.base64_title')) ?></summary>
      <label class="field__label" for="sg-b64"><?= $e($t('pdf.sign.base64_label')) ?></label>
      <textarea class="input mono sign__b64" id="sg-b64" rows="4" readonly data-b64></textarea>
    </details>
  </section>

  <section class="card stack" aria-labelledby="sg-2">
    <h2 id="sg-2" class="card__title"><?= $e($t('pdf.sign.step2')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('pdf.sign.step2_help')) ?></p>
    <div class="field">
      <label class="field__label" for="sg-pdf"><?= $e($t('pdf.field.pdf')) ?></label>
      <input class="input" type="file" id="sg-pdf" accept=".pdf,application/pdf" data-pdf aria-describedby="sg-pdf-help">
      <div id="sg-pdf-help" class="field__help"><?= $e($t('pdf.sign.pdf_help', ['mb' => (string) $limits['max_mb']])) ?></div>
    </div>
    <div class="sign__doc stack-sm" data-doc hidden>
      <div class="row sign__nav">
        <?= $ui('button', ['label' => $t('pdf.sign.prev'), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left', 'attrs' => ['data-act' => 'prev']]) ?>
        <span class="text-sm" data-page-label aria-live="polite"></span>
        <?= $ui('button', ['label' => $t('pdf.sign.next'), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-right', 'attrs' => ['data-act' => 'next']]) ?>
        <label class="field__label sign__size-label" for="sg-size"><?= $e($t('pdf.sign.size')) ?></label>
        <input class="sign__range" type="range" id="sg-size" min="8" max="60" value="25" data-size>
      </div>
      <div class="sign__page" data-stage>
        <canvas class="sign__page-canvas" data-page-canvas role="img" aria-label="<?= $e($t('pdf.sign.page_label')) ?>"></canvas>
        <div class="sign__layer" data-layer></div>
      </div>
      <p class="text-sm text-muted"><?= $e($t('pdf.sign.place_help')) ?></p>
      <div class="row">
        <?= $ui('button', ['label' => $t('pdf.sign.place_here'), 'variant' => 'secondary', 'icon' => 'plus', 'attrs' => ['data-act' => 'place']]) ?>
        <?= $ui('button', ['label' => $t('pdf.sign.download_pdf'), 'variant' => 'primary', 'icon' => 'download', 'attrs' => ['data-act' => 'save']]) ?>
      </div>
      <ul class="plain-list text-sm stack-sm" data-placements aria-label="<?= $e($t('pdf.sign.placements')) ?>"></ul>
    </div>
  </section>
  <div class="sr-only" aria-live="polite" data-live></div>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('pdf.sign.legal_title'), 'text' => $t('pdf.sign.legal_text')]) ?>
</div>
<script src="<?= $e($asset('ui/js/pdf-sign.js')) ?>" defer></script>
<?php endif; ?>
