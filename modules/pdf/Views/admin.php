<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, array<string, mixed>> $features @var array<string, int> $limits @var array<string, string> $errors @var array<string, string> $php
 */
use Blue\Modules\Pdf\PdfFeatures;

?>
<?= $ui('page-header', ['title' => $t('pdf.admin.title'), 'subtitle' => $t('pdf.admin.subtitle')]) ?>
<p><a href="<?= $e($url('/pdf')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('pdf.back')) ?></a></p>
<form method="post" action="<?= $e($url('/pdf/admin')) ?>" class="stack" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <section class="card stack-sm" aria-labelledby="pa-f">
    <h2 id="pa-f" class="card__title"><?= $e($t('pdf.admin.features')) ?></h2>
    <div class="table-wrap" role="region" aria-labelledby="pa-f" tabindex="0">
      <table class="table table--stack">
        <thead><tr><th scope="col"><?= $e($t('pdf.col.tool')) ?></th><th scope="col"><?= $e($t('pdf.col.status')) ?></th><th scope="col"><?= $e($t('pdf.admin.enabled')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($features as $code => $f): $built = in_array($code, PdfFeatures::switchable(), true); ?>
          <tr>
            <td data-label="<?= $e($t('pdf.col.tool')) ?>"><?= $e($t('pdf.tool.' . $code)) ?></td>
            <td data-label="<?= $e($t('pdf.col.status')) ?>"><?= $ui('badge', ['tone' => ['available' => 'success', 'disabled' => 'neutral', 'unavailable' => 'warning'][$f['state']],
                'icon' => ['available' => 'circle-check', 'disabled' => 'lock', 'unavailable' => 'alert-circle'][$f['state']], 'label' => $t('pdf.state.' . $f['state'])]) ?>
              <?php if ($f['reason'] !== null): ?><span class="d-block text-sm text-muted"><?= $e($t((string) $f['reason'], ['ext' => (string) $f['missing']])) ?></span><?php endif; ?></td>
            <td data-label="<?= $e($t('pdf.admin.enabled')) ?>">
              <?php if ($built && $f['reason'] === null): ?>
                <label class="check"><input type="checkbox" name="enabled[<?= $e($code) ?>]" value="1"<?= $f['state'] === 'available' ? ' checked' : '' ?>> <span class="sr-only"><?= $e($t('pdf.tool.' . $code)) ?></span><?= $e($t('pdf.admin.on')) ?></label>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <section class="card stack-sm" aria-labelledby="pa-l">
    <h2 id="pa-l" class="card__title"><?= $e($t('pdf.admin.limits')) ?></h2>
    <div class="grid grid--2">
      <?php foreach (['max_mb' => [1, 100], 'max_pages' => [1, 5000], 'max_files' => [2, 50], 'result_minutes' => [10, 1440]] as $k => [$min, $max]): ?>
        <?= $ui('field', ['name' => $k, 'type' => 'number', 'label' => $t('pdf.admin.' . $k), 'value' => (string) $limits[$k], 'min' => $min, 'max' => $max, 'error' => $errors[$k] ?? $errors['value'] ?? null]) ?>
      <?php endforeach; ?>
    </div>
    <p class="text-sm text-muted"><?= $e($t('pdf.admin.php', $php)) ?></p>
  </section>
  <section class="card stack-sm">
    <?= $ui('field', ['name' => 'reason', 'label' => $t('pdf.admin.reason'), 'required' => true, 'error' => $errors['reason'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
    <p class="text-sm text-muted"><?= $e($t('pdf.admin.saved_as_config')) ?></p>
  </section>
</form>
