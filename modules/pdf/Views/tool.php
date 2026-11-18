<?php
/**
 * Step 1 of a PDF tool, ILovePDF style (0.39.0): one big drop area in the middle of the page. Nothing is made yet —
 * the files are checked, then step 2 shows every page as a picture.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var string $op @var array<string, mixed> $feature @var array<string, int> $limits @var array<string, string> $errors
 */
use Blue\Modules\Pdf\PdfFeatures;

$images = $feature['input'] === 'images';
?>
<p><a href="<?= $e($url('/pdf')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('pdf.back')) ?></a></p>
<div class="pdf-hero">
  <span class="pdf-hero__icon" aria-hidden="true"><?= $ui('icon', ['name' => PdfFeatures::ICONS[$op] ?? 'file-type-pdf']) ?></span>
  <h1 class="pdf-hero__title"><?= $e($t('pdf.tool.' . $op)) ?></h1>
  <p class="pdf-hero__lead"><?= $e($t('pdf.about.' . $op)) ?></p>
</div>
<?php if ($feature['state'] !== 'available'): ?>
  <?= $ui('states', ['kind' => 'forbidden', 'title' => $t('pdf.state.' . $feature['state']), 'text' => $feature['reason'] !== null ? $t((string) $feature['reason'], ['ext' => (string) $feature['missing']]) : $t('pdf.state_text.disabled')]) ?>
<?php else: ?>
<form method="post" action="<?= $e($url('/pdf/tool/' . $op)) ?>" enctype="multipart/form-data" class="card stack pdf-upload" novalidate aria-labelledby="pu-h" data-pdf-upload>
  <h2 id="pu-h" class="card__title"><?= $e($t('pdf.step1')) ?></h2>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('dropzone', ['name' => 'files[]', 'id' => 'f-files', 'required' => true, 'multiple' => (bool) $feature['multiple'],
      'label' => $t($images ? 'pdf.field.images' : ($feature['multiple'] ? 'pdf.field.pdfs' : 'pdf.field.pdf')),
      'hint' => $t($images ? 'pdf.drop_images' : ($feature['multiple'] ? 'pdf.drop_many' : 'pdf.drop_one')),
      'accept' => $images ? '.jpg,.jpeg,.png,.webp,.gif' : '.pdf,application/pdf', 'error' => $errors['files'] ?? null, 'data' => ['pdf-files' => '1'],
      'help' => $t('pdf.limits', ['mb' => (string) $limits['max_mb'], 'pages' => (string) $limits['max_pages'], 'files' => (string) ($feature['multiple'] ? $limits['max_files'] : 1)])]) ?>
  <?php if (!$images): ?>
    <details class="stack-sm"<?= $op === 'unlock' || isset($errors['password']) ? ' open' : '' ?>>
      <summary class="text-sm"><?= $e($t('pdf.has_password')) ?></summary>
      <?= $ui('field', ['name' => 'password', 'type' => 'password', 'label' => $t($op === 'unlock' ? 'pdf.field.password_unlock' : 'pdf.field.password'), 'required' => $op === 'unlock',
          'autocomplete' => 'off', 'help' => $t('pdf.password_help')]) ?>
    </details>
  <?php endif; ?>
  <div class="row row--center"><?= $ui('button', ['label' => $t('pdf.upload_check'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'upload']) ?></div>
  <p class="text-sm text-muted text-center"><?= $e($t('pdf.upload_note', ['minutes' => (string) $limits['result_minutes']])) ?></p>
</form>
<script src="<?= $e($asset('ui/js/pdf.js')) ?>" defer></script>
<?php endif; ?>
