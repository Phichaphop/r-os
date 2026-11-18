<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var \Blue\Core\Import\ImportDefinition $d @var string $code @var array<string, string> $params @var string $back
 * @var list<array<string, mixed>> $recent @var array<string, string> $errors
 */
$qs = http_build_query($params);
?>
<?= $ui('page-header', ['title' => $t($d->titleKey()), 'subtitle' => $t('import.subtitle'),
    'actions' => $ui('button', ['label' => $t('common.back'), 'href' => $url($back), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<ol class="stepper" aria-label="<?= $e($t('import.steps')) ?>">
  <li class="stepper__step stepper__step--current"><span class="stepper__num">1</span><?= $e($t('import.step.upload')) ?></li>
  <li class="stepper__step"><span class="stepper__num">2</span><?= $e($t('import.step.check')) ?></li>
  <li class="stepper__step"><span class="stepper__num">3</span><?= $e($t('import.step.confirm')) ?></li>
</ol>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="tpl-h">
    <h2 id="tpl-h" class="card__title"><?= $e($t('import.template.title')) ?></h2>
    <p class="text-sm"><?= $e($t('import.template.help')) ?></p>
    <ul class="text-sm">
      <?php foreach ($d->columns() as $c): ?><li><strong><?= $e($t($c['label'])) ?></strong> (<span class="mono"><?= $e($c['key']) ?></span>) — <?= $e($t($c['required'] ? 'form.required_tag' : 'form.optional_tag')) ?><?= isset($c['help']) ? ': ' . $e($t($c['help'])) : '' ?></li><?php endforeach; ?>
    </ul>
    <?= $ui('button', ['label' => $t('import.template.download'), 'href' => $url('/import/' . $code . '/template') . '?' . $qs, 'icon' => 'download']) ?>
  </section>
  <section class="card" aria-labelledby="up-h">
    <h2 id="up-h" class="card__title"><?= $e($t('import.upload.title')) ?></h2>
    <form method="post" action="<?= $e($url('/import/' . $code) . '?' . $qs) ?>" enctype="multipart/form-data" class="stack-sm" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <?php foreach ($params as $k => $val): ?><input type="hidden" name="<?= $e($k) ?>" value="<?= $e($val) ?>"><?php endforeach; ?>
      <?= $ui('dropzone', ['name' => 'file', 'label' => $t('import.field.file'), 'required' => true, 'accept' => '.xlsx,.csv', 'error' => $errors['file'] ?? null, 'help' => $t('import.upload.help')]) ?>
      <?= $ui('button', ['label' => $t('import.upload.button'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'upload']) ?>
      <p class="text-sm text-muted"><?= $e($t('import.upload.nothing_yet')) ?></p>
    </form>
  </section>
</div>
<?php if ($recent !== []): ?>
<section class="card" aria-labelledby="rec-h">
  <h2 id="rec-h" class="card__title"><?= $e($t('import.recent')) ?></h2>
  <ul class="stack-sm"><?php foreach ($recent as $b): ?>
    <li><a href="<?= $e($url('/import/batch/' . $b['public_id'])) ?>"><?= $e($b['filename']) ?></a> · <?= $e($date($b['created_at'])) ?> · <?= $e($t('import.status.' . $b['status'])) ?> · <?= $e($t('import.counts', ['ok' => (int) $b['ok_rows'], 'bad' => (int) $b['error_rows']])) ?></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>
