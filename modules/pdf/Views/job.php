<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $job @var array<string, string> $errors @var array<string, mixed> $old @var string $now
 * @var array<string, string> $classes @var array<string|int, string> $companies
 */
use Blue\Core\Pdf\Toolkit;

$op = (string) $job['operation'];
$inputs = $job['inputs'];
$stTone = ['staged' => ['info', 'hourglass'], 'running' => ['info', 'loader-2'], 'done' => ['success', 'circle-check'], 'failed' => ['danger', 'alert-triangle'], 'expired' => ['neutral', 'history']];
[$tn, $ic] = $stTone[$job['status']] ?? ['neutral', 'info-circle'];
$alive = $job['cleaned_at'] === null && (string) $job['expires_at'] > $now;
$canRun = $alive && in_array($job['status'], ['staged', 'failed'], true);
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$kb = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024, 1) . ' KB';
$allWarn = [];
foreach ($inputs as $in) {
    foreach ((array) ($in['warnings'] ?? []) as $w) {
        $allWarn[$w] = true;
    }
}
foreach ((array) $job['warnings'] as $w) {
    $allWarn[$w] = true;
}
$positions = [];
foreach (Toolkit::POSITIONS as $p) {
    $positions[$p] = $t('pdf.position.' . $p);
}
$pid = (string) $job['public_id'];
$jsAttrs = ' data-lib="' . $e($asset('ui/js/vendor/pdfjs/pdf.min.js')) . '" data-worker="' . $e($asset('ui/js/vendor/pdfjs/pdf.worker.min.js')) . '"'
    . ' data-fonts="' . $e($url('/assets/ui/js/vendor/pdfjs/standard_fonts/')) . '" data-msg-error="' . $e($t('pdf.thumb_error')) . '"'
    . ' data-msg-moved="' . $e($t('pdf.moved')) . '" data-msg-rotated="' . $e($t('pdf.rotated')) . '"';
// a page picture: drawn by PDF.js in the browser (ui/js/pdf-pages.js) from the job's own preview URL; images are plain <img>
$thumb = static function (string $kind, int $n, int $page, string $label, string $type = 'pdf') use ($e, $url, $pid): string {
    $src = $url('/pdf/job/' . $pid . '/preview/' . $kind . '/' . $n);
    if ($type !== 'pdf') {
        return '<img class="pdf-thumb__img" src="' . $e($src) . '" alt="' . $e($label) . '" loading="lazy">';
    }
    return '<canvas class="pdf-thumb__canvas" data-src="' . $e($src) . '" data-page="' . $page . '" role="img" aria-label="' . $e($label) . '"></canvas>';
};
$pickGrid = static function () use ($e, $t, $thumb, $inputs, $jsAttrs): string {
    $n = (int) ($inputs[0]['pages'] ?? 0);
    $h = '<p class="text-sm text-muted pdf-js-only" hidden>' . $e($t('pdf.pick_help')) . '</p><ol class="pdf-pages pdf-pages--pick" data-pdf-pages="pick"' . $jsAttrs . '>';
    for ($i = 1; $i <= $n; $i++) {
        $lbl = $t('pdf.page_n', ['n' => (string) $i]);
        $h .= '<li class="pdf-page"><label class="pdf-page__pick"><span class="pdf-thumb">' . $thumb('in', 0, $i, $lbl) . '</span>'
            . '<span class="pdf-page__no"><input type="checkbox" data-pick="' . $i . '" class="pdf-js-only" hidden> ' . $e($lbl) . '</span></label></li>';
    }
    return $h . '</ol>';
};
$viewGrid = static function (string $kind, int $n, int $pages, int $max = 60) use ($e, $t, $thumb, $jsAttrs): string {
    $h = '<ol class="pdf-pages pdf-pages--view" data-pdf-pages="view"' . $jsAttrs . '>';
    for ($i = 1; $i <= min($pages, $max); $i++) {
        $lbl = $t('pdf.page_n', ['n' => (string) $i]);
        $h .= '<li class="pdf-page"><span class="pdf-thumb">' . $thumb($kind, $n, $i, $lbl) . '</span><span class="pdf-page__no">' . $e($lbl) . '</span></li>';
    }
    $h .= '</ol>';
    return $pages > $max ? $h . '<p class="text-sm text-muted">' . $e($t('pdf.more_pages', ['n' => (string) ($pages - $max)])) . '</p>' : $h;
};
$pageField = static fn (string $help = 'pdf.pages_help') => $ui('field', ['name' => 'pages', 'label' => $t('pdf.field.pages'), 'value' => $o('pages'), 'placeholder' => '1-3, 5, 8-', 'help' => $t($help), 'error' => $err('pages')]);
?>
<?= $ui('page-header', ['title' => $t('pdf.tool.' . $op), 'subtitle' => $t('pdf.job_subtitle', ['at' => $date((string) $job['created_at'])]),
    'actions' => $ui('badge', ['tone' => $tn, 'icon' => $ic, 'label' => $t('pdf.status.' . $job['status'])])]) ?>
<p><a href="<?= $e($url('/pdf')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('pdf.back')) ?></a></p>

<?php if (!$alive): ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('pdf.expired_text')]) ?>
<?php endif; ?>
<?php if ($job['status'] === 'failed' && $job['error_key'] !== null): ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('pdf.failed_title'), 'text' => $t((string) $job['error_key'])]) ?>
<?php endif; ?>
<?php if (isset($errors['run'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['run']]) ?><?php endif; ?>

<section class="card stack-sm" aria-labelledby="pi-h">
  <h2 id="pi-h" class="card__title"><?= $e($t('pdf.inputs')) ?></h2>
  <details class="text-sm"><summary><?= $e($t('pdf.inputs_checked', ['n' => (string) count($inputs), 'pages' => (string) array_sum(array_map(static fn (array $i): int => (int) $i['pages'], $inputs))])) ?></summary>
  <div class="table-wrap" role="region" aria-labelledby="pi-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('pdf.col.file')) ?></th><th scope="col"><?= $e($t('pdf.col.pages')) ?></th><th scope="col"><?= $e($t('pdf.col.size')) ?></th><th scope="col"><?= $e($t('pdf.col.check')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($inputs as $i => $in): ?>
        <tr>
          <td data-label="<?= $e($t('pdf.col.file')) ?>"><span class="break-all"><?= $e($in['name']) ?></span>
            <span class="d-block text-xs text-muted mono">SHA-256 <?= $e(substr((string) $in['sha256'], 0, 16)) ?>…</span></td>
          <td data-label="<?= $e($t('pdf.col.pages')) ?>"><?= (int) $in['pages'] ?></td>
          <td data-label="<?= $e($t('pdf.col.size')) ?>"><?= $e($kb((int) $in['size'])) ?></td>
          <td data-label="<?= $e($t('pdf.col.check')) ?>"><?= $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => strtoupper((string) $in['type']) . (($in['version'] ?? null) ? ' ' . $in['version'] : '')]) ?>
            <?php if (!empty($in['encrypted'])): ?> <?= $ui('badge', ['tone' => 'info', 'icon' => 'lock-open', 'label' => $t('pdf.was_encrypted', ['method' => (string) ($in['method'] ?? '')])]) ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </details>
  <?php if ($allWarn !== []): ?>
    <div class="alert alert--warning" role="status"><?= $ui('icon', ['name' => 'alert-triangle']) ?><div class="alert__body"><strong><?= $e($t('pdf.warnings')) ?></strong>
      <ul class="plain-list"><?php foreach (array_keys($allWarn) as $w): ?><li><?= $e($t((string) $w)) ?></li><?php endforeach; ?></ul></div></div>
  <?php endif; ?>
</section>

<?php if ($canRun): ?>
<form method="post" action="<?= $e($url('/pdf/job/' . $job['public_id'] . '/run')) ?>" class="card stack" novalidate aria-labelledby="po-h">
  <h2 id="po-h" class="card__title"><?= $e($t('pdf.step2')) ?></h2>
  <?= $csrf ?>
  <?php if ($op === 'merge' || $op === 'images'): ?>
    <p class="text-sm text-muted"><?= $e($t('pdf.order_help')) ?></p>
    <ol class="pdf-pages pdf-pages--files" data-pdf-pages="order"<?= $jsAttrs ?>>
      <?php foreach ($inputs as $i => $in): $lbl = (string) $in['name']; ?>
        <li class="pdf-page" data-pdf-item draggable="true">
          <span class="pdf-thumb"><?= $thumb('in', $i, 1, $t('pdf.first_page_of', ['name' => $lbl]), (string) $in['type']) ?></span>
          <span class="pdf-page__no break-all"><?= $e($lbl) ?> <span class="text-xs text-muted"><?= $e($t('pdf.n_pages', ['n' => (string) $in['pages']])) ?></span></span>
          <span class="pdf-page__tools pdf-js-only" hidden>
            <button type="button" class="btn btn--ghost btn--sm btn--icon" data-act="prev" aria-label="<?= $e($t('pdf.move_before', ['what' => $lbl])) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?></button>
            <button type="button" class="btn btn--ghost btn--sm btn--icon" data-act="next" aria-label="<?= $e($t('pdf.move_after', ['what' => $lbl])) ?>"><?= $ui('icon', ['name' => 'chevron-right', 'size' => 'sm']) ?></button>
          </span>
          <span class="pdf-page__fields">
            <label class="text-xs" for="ord-<?= $i ?>"><?= $e($t('pdf.field.position', ['name' => $lbl])) ?></label>
            <input class="input pdf-order__pos" id="ord-<?= $i ?>" name="order[<?= $i ?>]" inputmode="numeric" data-pos value="<?= $e(is_array($old['order'] ?? null) ? (string) ($old['order'][$i] ?? $i + 1) : (string) ($i + 1)) ?>">
          </span>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if ($op === 'images'): ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'page_size', 'type' => 'select', 'label' => $t('pdf.field.page_size'), 'value' => $o('page_size', 'A4'),
            'options' => ['A4' => $t('pdf.size.A4'), 'A4L' => $t('pdf.size.A4L'), 'Letter' => 'Letter', 'fit' => $t('pdf.size.fit')]]) ?>
        <?= $ui('field', ['name' => 'margin', 'type' => 'number', 'label' => $t('pdf.field.margin'), 'value' => $o('margin', '10'), 'min' => 0, 'max' => 25]) ?>
      </div>
    <?php endif; ?>
  <?php elseif ($op === 'split'): ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('pdf.field.split_mode')) ?></legend>
      <?php foreach (['every', 'chunk', 'ranges'] as $m): ?>
        <label class="choice"><input type="radio" name="mode" value="<?= $m ?>"<?= $o('mode', 'every') === $m ? ' checked' : '' ?>> <?= $e($t('pdf.split.' . $m)) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'chunk', 'type' => 'number', 'label' => $t('pdf.field.chunk'), 'value' => $o('chunk', '1'), 'min' => 1]) ?>
      <?= $ui('field', ['name' => 'ranges', 'label' => $t('pdf.field.ranges'), 'value' => $o('ranges'), 'placeholder' => '1-3, 4-6, 7', 'help' => $t('pdf.ranges_help'), 'error' => $err('pages')]) ?>
    </div>
  <?php elseif ($op === 'extract'): ?>
    <?= $pickGrid() ?>
    <?= $pageField('pdf.extract_help') ?>
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('pdf.note.extract_not_redact')]) ?>
  <?php elseif ($op === 'organize'): ?>
    <p class="text-sm text-muted"><?= $e($t('pdf.organize_help')) ?></p>
    <?php if ($err('pages') !== null): ?><p class="field__error" role="alert"><?= $e((string) $err('pages')) ?></p><?php endif; ?>
    <ol class="pdf-pages pdf-pages--organize" data-pdf-pages="organize"<?= $jsAttrs ?> aria-label="<?= $e($t('pdf.tool.organize')) ?>">
      <?php foreach ((array) ($inputs[0]['sizes'] ?? []) as $i => $sz): $no = (string) ($i + 1); $lbl = $t('pdf.page_n', ['n' => $no]); $rot = (string) ($old['rot'][$i] ?? '0'); ?>
        <li class="pdf-page" data-pdf-item draggable="true">
          <span class="pdf-thumb" data-turn="<?= $e($rot) ?>"><?= $thumb('in', 0, $i + 1, $lbl) ?></span>
          <span class="pdf-page__no"><?= $e($lbl) ?> <span class="text-xs text-muted"><?= $e((string) $sz) ?></span></span>
          <span class="pdf-page__tools">
            <span class="pdf-js-only" hidden>
              <button type="button" class="btn btn--ghost btn--sm btn--icon" data-act="prev" aria-label="<?= $e($t('pdf.move_before', ['what' => $lbl])) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?></button>
              <button type="button" class="btn btn--ghost btn--sm btn--icon" data-act="rot" aria-label="<?= $e($t('pdf.rotate_page', ['n' => $no])) ?>"><?= $ui('icon', ['name' => 'refresh', 'size' => 'sm']) ?></button>
              <button type="button" class="btn btn--ghost btn--sm btn--icon" data-act="next" aria-label="<?= $e($t('pdf.move_after', ['what' => $lbl])) ?>"><?= $ui('icon', ['name' => 'chevron-right', 'size' => 'sm']) ?></button>
            </span>
            <label class="check pdf-page__keep"><input type="checkbox" name="keep[<?= $i ?>]" value="1" data-keep<?= !isset($old['pos']) || (($old['keep'][$i] ?? '') === '1') ? ' checked' : '' ?>>
              <?= $e($t('pdf.col.keep')) ?><span class="sr-only"> <?= $e($lbl) ?></span></label>
          </span>
          <span class="pdf-page__fields">
            <input class="input pdf-order__pos" name="pos[<?= $i ?>]" inputmode="numeric" data-pos aria-label="<?= $e($t('pdf.position_of', ['n' => $no])) ?>"
              value="<?= $e(is_array($old['pos'] ?? null) ? (string) ($old['pos'][$i] ?? '') : $no) ?>">
            <select class="input" name="rot[<?= $i ?>]" data-rot aria-label="<?= $e($t('pdf.rotate_of', ['n' => $no])) ?>">
              <?php foreach ([0, 90, 180, 270] as $d): ?><option value="<?= $d ?>"<?= $rot === (string) $d ? ' selected' : '' ?>><?= $d === 0 ? $e($t('pdf.rot.none')) : $d . '°' ?></option><?php endforeach; ?>
            </select>
          </span>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php elseif ($op === 'rotate'): ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'degrees', 'type' => 'select', 'label' => $t('pdf.field.degrees'), 'value' => $o('degrees', '90'),
          'options' => ['90' => $t('pdf.rot.90'), '180' => $t('pdf.rot.180'), '270' => $t('pdf.rot.270')], 'error' => $err('degrees')]) ?>
      <?= $pageField() ?>
    </div>
    <?= $pickGrid() ?>
  <?php elseif ($op === 'compress'): ?>
    <label class="check"><input type="checkbox" name="strip_metadata" value="1"<?= $o('strip_metadata') === '1' ? ' checked' : '' ?>> <?= $e($t('pdf.field.strip_metadata')) ?></label>
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('pdf.compress_help')]) ?>
  <?php elseif ($op === 'watermark'): ?>
    <?= $ui('field', ['name' => 'text', 'label' => $t('pdf.field.watermark_text'), 'required' => true, 'value' => $o('text'), 'placeholder' => $t('pdf.watermark_example'), 'error' => $err('text')]) ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'size', 'type' => 'number', 'label' => $t('pdf.field.font_size'), 'value' => $o('size', '60'), 'min' => 8, 'max' => 200]) ?>
      <?= $ui('field', ['name' => 'opacity', 'type' => 'number', 'label' => $t('pdf.field.opacity'), 'value' => $o('opacity', '25'), 'min' => 5, 'max' => 100]) ?>
      <?= $ui('field', ['name' => 'angle', 'type' => 'select', 'label' => $t('pdf.field.angle'), 'value' => $o('angle', '45'), 'options' => ['45' => $t('pdf.angle.45'), '0' => $t('pdf.angle.0')]]) ?>
    </div>
    <?= $pageField() ?>
    <?= $pickGrid() ?>
  <?php elseif ($op === 'page_numbers'): ?>
    <?= $ui('field', ['name' => 'template', 'label' => $t('pdf.field.template'), 'value' => $o('template', '{n} / {total}'), 'help' => $t('pdf.template_help'), 'error' => $err('text')]) ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'position', 'type' => 'select', 'label' => $t('pdf.field.position'), 'value' => $o('position', 'bottom-center'), 'options' => $positions]) ?>
      <?= $ui('field', ['name' => 'start', 'type' => 'number', 'label' => $t('pdf.field.start'), 'value' => $o('start', '1'), 'min' => 0]) ?>
      <?= $ui('field', ['name' => 'size', 'type' => 'number', 'label' => $t('pdf.field.font_size'), 'value' => $o('size', '10'), 'min' => 6, 'max' => 48]) ?>
    </div>
    <?= $pageField() ?>
    <?= $pickGrid() ?>
  <?php elseif ($op === 'protect'): ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'user_password', 'type' => 'password', 'label' => $t('pdf.field.user_password'), 'required' => true, 'autocomplete' => 'new-password', 'help' => $t('pdf.user_password_help'), 'error' => $err('user_password')]) ?>
      <?= $ui('field', ['name' => 'user_password_confirm', 'type' => 'password', 'label' => $t('pdf.field.user_password_confirm'), 'required' => true, 'autocomplete' => 'new-password', 'error' => $err('user_password_confirm')]) ?>
    </div>
    <?= $ui('field', ['name' => 'owner_password', 'type' => 'password', 'label' => $t('pdf.field.owner_password'), 'autocomplete' => 'new-password', 'help' => $t('pdf.owner_password_help')]) ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('pdf.field.permissions')) ?></legend>
      <?php foreach (['allow_print' => true, 'allow_copy' => false, 'allow_modify' => false] as $k => $def): ?>
        <label class="check"><input type="checkbox" name="<?= $k ?>" value="1"<?= ($old === [] ? $def : $o($k) === '1') ? ' checked' : '' ?>> <?= $e($t('pdf.field.' . $k)) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <?= $ui('alert', ['tone' => 'warning', 'text' => $t('pdf.note.permissions_advisory') . ' ' . $t('pdf.protect_lost')]) ?>
  <?php elseif ($op === 'unlock'): ?>
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('pdf.unlock_ready')]) ?>
  <?php elseif ($op === 'metadata'): ?>
    <dl class="facts">
      <?php foreach ((array) ($inputs[0]['metadata'] ?? []) as $k => $val): ?>
        <div><dt><?= $e((string) $k) ?></dt><dd class="break-all"><?= $e((string) $val) ?></dd></div>
      <?php endforeach; ?>
      <div><dt>XMP</dt><dd><?= $e($t(!empty($inputs[0]['xmp']) ? 'pdf.xmp_yes' : 'pdf.xmp_no')) ?></dd></div>
    </dl>
    <?php if (($inputs[0]['metadata'] ?? []) === [] && empty($inputs[0]['xmp'])): ?><p class="text-sm text-muted"><?= $e($t('pdf.no_metadata')) ?></p><?php endif; ?>
    <label class="check"><input type="checkbox" name="confirm" value="1"> <?= $e($t('pdf.metadata_confirm')) ?></label>
    <?php if ($err('confirm') !== null): ?><p class="field__error" role="alert"><?= $e((string) $err('confirm')) ?></p><?php endif; ?>
  <?php elseif ($op === 'repair'): ?>
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('pdf.note.repair_best_effort')]) ?>
  <?php endif; ?>
  <?php if (!in_array($op, ['merge', 'images', 'organize', 'extract', 'rotate', 'watermark', 'page_numbers'], true)): ?>
    <details class="stack-sm"<?= $op === 'split' ? ' open' : '' ?>><summary class="text-sm"><?= $e($t('pdf.show_pages')) ?></summary>
      <?= $viewGrid('in', 0, (int) ($inputs[0]['pages'] ?? 0)) ?></details>
  <?php endif; ?>
  <div class="row">
    <?= $ui('button', ['label' => $t('pdf.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
  </div>
  <p class="text-sm text-muted"><?= $e($t('pdf.derivative_note')) ?></p>
</form>
<script src="<?= $e($asset('ui/js/pdf-pages.js')) ?>" defer></script>
<?php endif; ?>

<?php if ($job['status'] === 'done'): $outs = (array) $job['outputs']; ?>
<section class="card stack pdf-result" aria-labelledby="pr-h">
  <h2 id="pr-h" class="card__title"><?= $ui('icon', ['name' => 'circle-check']) ?> <?= $e($t('pdf.result')) ?></h2>
  <?php if ((array) $job['notes'] !== []): ?>
    <ul class="plain-list text-sm"><?php foreach ((array) $job['notes'] as $n): ?><li><?= $ui('icon', ['name' => 'info-circle', 'size' => 'sm']) ?> <?= $e($t((string) $n[0], (array) ($n[1] ?? []))) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ($alive): ?>
  <?php $saved = array_filter($outs, static fn (array $o): bool => $o['document'] !== null) !== []; ?>
  <?= $ui('alert', ['tone' => $saved ? 'success' : 'warning', 'text' => $t($saved ? 'pdf.kept_saved' : 'pdf.not_kept', ['at' => $date((string) $job['expires_at'])])]) ?>
  <div class="row row--center pdf-result__actions">
    <?php if (count($outs) > 1): ?><?= $ui('button', ['label' => $t('pdf.download_all'), 'href' => $url('/pdf/job/' . $pid . '/zip'), 'variant' => 'primary', 'size' => 'lg', 'icon' => 'download']) ?>
    <?php else: ?><?= $ui('button', ['label' => $t('pdf.download'), 'href' => $url('/pdf/job/' . $pid . '/file/0'), 'variant' => 'primary', 'size' => 'lg', 'icon' => 'download']) ?><?php endif; ?>
    <form method="post" action="<?= $e($url('/pdf/job/' . $pid . '/discard')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('pdf.discard'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?></form>
  </div>
  <ul class="pdf-outputs" role="list">
    <?php foreach ($outs as $n => $out): ?>
      <li class="pdf-output stack-sm">
        <span class="pdf-thumb"><?= $thumb('out', (int) $n, 1, $t('pdf.first_page_of', ['name' => (string) $out['name']])) ?></span>
        <a href="<?= $e($url('/pdf/job/' . $pid . '/file/' . $n)) ?>"><?= $ui('icon', ['name' => 'download', 'size' => 'sm']) ?> <span class="break-all"><?= $e($out['name']) ?></span></a>
        <span class="text-xs text-muted"><?= $e($t('pdf.n_pages', ['n' => (string) $out['pages']])) ?> · <?= $e($kb((int) $out['size'])) ?> · <span class="mono">SHA-256 <?= $e(substr((string) $out['sha256'], 0, 12)) ?>…</span></span>
        <?php if ($out['document'] !== null): ?>
          <span><?= $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('pdf.saved_badge')]) ?> <a href="<?= $e($url('/documents/' . $out['document'])) ?>"><?= $e($t('pdf.saved_link')) ?></a></span>
        <?php elseif ($classes !== [] && $companies !== []): ?>
          <details class="pdf-save"><summary class="btn btn--secondary btn--sm"><?= $ui('icon', ['name' => 'file-text', 'size' => 'sm']) ?> <?= $e($t('pdf.save_to_records')) ?></summary>
            <form method="post" action="<?= $e($url('/pdf/job/' . $pid . '/file/' . $n . '/save')) ?>" class="stack-sm">
              <?= $csrf ?>
              <?= $ui('field', ['name' => 'title', 'id' => 'sv-t-' . $n, 'label' => $t('pdf.field.title'), 'value' => (string) $out['name']]) ?>
              <?= $ui('field', ['name' => 'class', 'id' => 'sv-c-' . $n, 'type' => 'select', 'label' => $t('pdf.field.class'), 'options' => $classes]) ?>
              <?= $ui('field', ['name' => 'legal_entity_id', 'id' => 'sv-l-' . $n, 'type' => 'select', 'label' => $t('pdf.field.company'), 'options' => $companies]) ?>
              <?= $ui('button', ['label' => $t('pdf.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'file-text']) ?>
            </form></details>
        <?php else: ?>
          <span class="text-sm text-muted"><?= $e($t('pdf.no_record_right')) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (count($outs) === 1 && (int) $outs[0]['pages'] > 1): ?>
    <details class="stack-sm"><summary class="text-sm"><?= $e($t('pdf.show_result_pages')) ?></summary><?= $viewGrid('out', 0, (int) $outs[0]['pages']) ?></details>
  <?php endif; ?>
  <script src="<?= $e($asset('ui/js/pdf-pages.js')) ?>" defer></script>
  <?php endif; ?>
  <details class="text-sm"><summary><?= $e($t('pdf.provenance')) ?></summary>
    <dl class="facts">
      <div><dt><?= $e($t('pdf.prov.engine')) ?></dt><dd><?= $e((string) $job['engine']) ?></dd></div>
      <div><dt><?= $e($t('pdf.prov.params')) ?></dt><dd class="break-all mono"><?= $e((string) json_encode($job['params'], JSON_UNESCAPED_UNICODE)) ?></dd></div>
      <div><dt><?= $e($t('pdf.prov.job')) ?></dt><dd class="mono"><?= $e((string) $job['public_id']) ?></dd></div>
      <div><dt><?= $e($t('pdf.prov.finished')) ?></dt><dd><?= $e($date((string) $job['finished_at'])) ?></dd></div>
    </dl>
  </details>
</section>
<?php endif; ?>
