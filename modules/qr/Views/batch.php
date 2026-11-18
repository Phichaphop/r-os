<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $errors @var bool $hasLogo */
?>
<?= $ui('page-header', ['title' => $t('qr.batch.title'), 'subtitle' => $t('qr.batch.subtitle')]) ?>
<p><a href="<?= $e($url('/qr')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('qr.back')) ?></a></p>
<form method="post" action="<?= $e($url('/qr/batch')) ?>" enctype="multipart/form-data" class="card stack" novalidate aria-labelledby="qr-batch-h">
  <h2 id="qr-batch-h" class="card__title"><?= $e($t('qr.batch.file')) ?></h2>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <p class="text-sm text-muted"><?= $e($t('qr.batch.help')) ?> <a href="<?= $e($url('/qr/batch/template')) ?>"><?= $ui('icon', ['name' => 'download', 'size' => 'sm']) ?><?= $e($t('qr.batch.template')) ?></a></p>
  <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('qr.batch.file'), 'required' => true, 'accept' => '.xlsx,.csv', 'error' => $errors['file'] ?? null]) ?>
  <div class="grid grid--3">
    <?= $ui('field', ['name' => 'level', 'type' => 'select', 'label' => $t('qr.field.level'), 'value' => 'M', 'options' => ['L' => $t('qr.level.L'), 'M' => $t('qr.level.M'), 'Q' => $t('qr.level.Q'), 'H' => $t('qr.level.H')]]) ?>
    <?= $ui('field', ['name' => 'format', 'type' => 'select', 'label' => $t('qr.batch.format'), 'value' => 'png', 'options' => ['png' => 'PNG', 'svg' => 'SVG', 'both' => 'PNG + SVG']]) ?>
    <?= $ui('field', ['name' => 'size', 'type' => 'select', 'label' => $t('qr.field.size'), 'value' => 'm', 'options' => ['s' => $t('qr.size.s'), 'm' => $t('qr.size.m'), 'l' => $t('qr.size.l')]]) ?>
  </div>
  <label class="check"><input type="checkbox" name="open_external" value="1"> <?= $e($t('qr.field.open_external')) ?></label>
  <label class="check<?= $hasLogo ? '' : ' is-disabled' ?>"><input type="checkbox" name="logo" value="1"<?= $hasLogo ? '' : ' disabled' ?>> <?= $e($t('qr.field.logo')) ?></label>
  <p class="text-sm text-muted"><?= $e($t('qr.batch.not_saved')) ?></p>
  <div class="row"><?= $ui('button', ['label' => $t('qr.batch.run'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'download']) ?></div>
</form>
