<?php
/** HR: directory settings. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid @var array{fields: array<string, bool>, cross_company: bool} $settings @var bool $canExport */
?>
<?= $ui('page-header', ['title' => $t('directory.admin_title'), 'subtitle' => $t('directory.admin_subtitle'), 'crumbs' => [[$t('directory.title'), $url('/directory')], [$t('directory.admin_title'), null]],
    'actions' => $canExport ? $ui('button', ['label' => $t('directory.export'), 'href' => $url('/directory/admin/export?le=' . rawurlencode($lePid) . '&format=xlsx'), 'variant' => 'secondary', 'icon' => 'download']) : '']) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('directory.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/directory/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack" aria-labelledby="ds-h">
  <h2 id="ds-h" class="card__title"><?= $e($t('directory.settings')) ?></h2>
  <form method="post" action="<?= $e($url('/directory/admin')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('directory.fields_shown')) ?></legend>
      <div class="check-list">
        <?php foreach ($settings['fields'] as $k => $on): ?><label class="check"><input type="checkbox" name="fields[]" value="<?= $e($k) ?>"<?= $on ? ' checked' : '' ?>> <?= $e($t('directory.field.' . $k)) ?></label><?php endforeach; ?>
      </div>
      <p class="text-sm text-muted"><?= $e($t('directory.fields_help')) ?></p>
    </fieldset>
    <label class="check"><input type="checkbox" name="cross_company" value="1"<?= $settings['cross_company'] ? ' checked' : '' ?>> <?= $e($t('directory.cross_company')) ?></label>
    <div class="row"><?= $ui('button', ['label' => $t('directory.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
