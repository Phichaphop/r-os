<?php
/**
 * Read a PDF in the browser (PDF.js, self-hosted): page by page, zoom, go to a page, the place is saved for "continue reading",
 * bookmark this page, the page's text for screen readers. Without JavaScript: open the file in the browser instead.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v @var array<string, mixed> $it @var int $start
 * @var bool $canManage @var string $here
 */
$pid = (string) $it['public_id'];
$pages = (int) ($it['file']['pages'] ?? 0);
$start = $pages > 0 ? min($start, $pages) : $start;
?>
<?= $ui('page-header', ['title' => (string) $it['title'], 'subtitle' => $t('library.reader_subtitle'), 'crumbs' => [[$t('library.title'), $url('/library')], [(string) $it['title'], $url('/library/i/' . $pid)], [$t('library.read_online'), null]]]) ?>
<section class="card stack-sm lb-reader" aria-label="<?= $e($t('library.reader')) ?>" data-lb-reader
  data-lib="<?= $e($asset('ui/js/vendor/pdfjs/pdf.min.js')) ?>" data-worker="<?= $e($asset('ui/js/vendor/pdfjs/pdf.worker.min.js')) ?>"
  data-fonts="<?= $e($url('/assets/ui/js/vendor/pdfjs/standard_fonts/')) ?>" data-src="<?= $e($url('/library/i/' . $pid . '/view')) ?>"
  data-progress="<?= $e($url('/library/i/' . $pid . '/progress')) ?>" data-text="<?= $e($url('/library/i/' . $pid . '/text')) ?>" data-csrf="<?= $e($v['csrf'] ?? '') ?>"
  data-start="<?= (int) $start ?>" data-msg-loading="<?= $e($t('library.reader_loading')) ?>" data-msg-error="<?= $e($t('library.reader_error')) ?>"
  data-msg-page="<?= $e($t('library.reader_page')) ?>" data-msg-notext="<?= $e($t('library.reader_no_text')) ?>">
  <div class="lb-reader__bar">
    <div class="row">
      <button type="button" class="btn btn--secondary btn--sm" data-lb-prev aria-label="<?= $e($t('library.prev_page')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?></button>
      <label class="lb-reader__goto"><span><?= $e($t('library.field.page')) ?></span> <input class="input" type="number" min="1" value="<?= (int) $start ?>" data-lb-page inputmode="numeric"> <span>/ <span data-lb-count><?= $pages > 0 ? $pages : '?' ?></span></span></label>
      <button type="button" class="btn btn--secondary btn--sm" data-lb-next aria-label="<?= $e($t('library.next_page')) ?>"><?= $ui('icon', ['name' => 'chevron-right', 'size' => 'sm']) ?></button>
    </div>
    <div class="row">
      <button type="button" class="btn btn--ghost btn--sm" data-lb-zoom="-1" aria-label="<?= $e($t('library.zoom_out')) ?>">−</button>
      <button type="button" class="btn btn--ghost btn--sm" data-lb-zoom="0"><?= $e($t('library.fit_width')) ?></button>
      <button type="button" class="btn btn--ghost btn--sm" data-lb-zoom="1" aria-label="<?= $e($t('library.zoom_in')) ?>">+</button>
      <form method="post" action="<?= $e($url('/library/i/' . $pid . '/bookmark')) ?>" class="lb-reader__bm"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="back" value="read"><input type="hidden" name="page" value="<?= (int) $start ?>" data-lb-bm-page>
        <?= $ui('button', ['label' => $t('library.bookmark_page'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'bookmark']) ?></form>
    </div>
  </div>
  <p class="text-sm text-muted" role="status" aria-live="polite" data-lb-status><?= $e($t('library.reader_loading')) ?></p>
  <div class="lb-reader__view" data-lb-view tabindex="0" aria-label="<?= $e($t('library.reader')) ?>"><canvas class="lb-reader__page" data-lb-canvas role="img" aria-label="<?= $e($t('library.reader_page', ['page' => (string) $start])) ?>"></canvas></div>
  <details class="lb-reader__text" data-lb-textbox><summary><?= $e($t('library.page_text')) ?></summary><div class="text-sm" data-lb-text></div></details>
  <p class="text-sm text-muted"><a href="<?= $e($url('/library/i/' . $pid . '/view')) ?>" target="_blank" rel="noopener"><?= $e($t('library.open_in_browser')) ?></a> · <?= $e($t('library.reader_note')) ?></p>
</section>
<script type="module" src="<?= $e($asset('ui/js/library.js')) ?>"></script>
