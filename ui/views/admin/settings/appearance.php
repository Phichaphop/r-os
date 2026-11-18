<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $look @var array<string, mixed> $form @var array<string, mixed> $build
 * @var array<string, array<string,string>> $presets @var array<string, string> $errors
 */
$colorFields = ['primary' => 'primary', 'menu' => 'navy', 'accent' => 'accent', 'background' => 'bg'];
$previewQs = http_build_query(['preset' => $form['preset']] + array_map(static fn (string $f): string => (string) ($form[$f] ?? ''), array_combine(array_keys($colorFields), array_keys($colorFields))));
$presetColors = [];
foreach ($presets as $code => $pc) {
    foreach ($colorFields as $f => $k) {
        $presetColors[$code][$f] = $pc[$k];
    }
}
?>
<link rel="stylesheet" id="theme-preview" href="<?= $e($url('/admin/settings/appearance/preview.css') . '?' . $previewQs) ?>" data-base="<?= $e($url('/admin/settings/appearance/preview.css')) ?>">
<?= $ui('page-header', ['title' => $t('settings.appearance.title'), 'subtitle' => $t('settings.appearance.subtitle')]) ?>
<form method="post" action="<?= $e($url('/admin/settings/appearance')) ?>" class="settings-grid" novalidate data-theme-form data-check-url="<?= $e($url('/admin/settings/appearance/check')) ?>" data-preset-colors="<?= $e((string) json_encode($presetColors)) ?>">
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <section class="card stack" aria-labelledby="brand-h">
    <h2 id="brand-h" class="card__title"><?= $e($t('settings.brand.title')) ?></h2>
    <?= $ui('field', ['name' => 'app_name', 'label' => $t('settings.brand.app_name'), 'required' => true, 'value' => (string) $form['app_name'], 'error' => $errors['app_name'] ?? null, 'help' => $t('settings.brand.app_name_help')]) ?>
    <?= $ui('field', ['name' => 'tagline', 'label' => $t('settings.brand.tagline'), 'value' => (string) $form['tagline'], 'error' => $errors['tagline'] ?? null]) ?>
    <div class="field">
      <input type="hidden" name="show_name" value="0">
      <label class="choice"><input type="checkbox" name="show_name" value="1"<?= ($form['show_name'] ?? true) ? ' checked' : '' ?> aria-describedby="show-name-help"> <?= $e($t('settings.brand.show_name')) ?></label>
      <p id="show-name-help" class="field__help"><?= $e($t('settings.brand.show_name_help')) ?></p>
    </div>
    <fieldset class="stack-sm">
      <legend class="field__label"><?= $e($t('settings.brand.builtin_logo')) ?></legend>
      <div class="builtin-logos">
      <?php foreach (['blue' => 'logo-blue.png', 'blue_os' => 'logo-blue-os.png'] as $bl => $file): ?>
        <label class="choice builtin-logo"><input type="radio" name="builtin_logo" value="<?= $bl ?>"<?= ($form['builtin_logo'] ?? 'blue') === $bl ? ' checked' : '' ?>>
          <span class="builtin-logo__img navy-dots on-navy"><img src="<?= $e($asset('ui/icons/' . $file)) ?>" alt="" width="96" height="32"></span> <?= $e($t('settings.brand.builtin_' . $bl)) ?></label>
      <?php endforeach; ?>
      </div>
      <p class="text-sm text-muted"><?= $e($t('settings.brand.builtin_help')) ?></p>
    </fieldset>
    <fieldset class="stack-sm">
      <legend class="field__label"><?= $e($t('settings.brand.logo_tint')) ?></legend>
      <?php foreach (['original', 'light', 'accent'] as $tint): ?>
        <label class="choice"><input type="radio" name="logo_tint" value="<?= $tint ?>"<?= ($form['logo_tint'] ?? 'original') === $tint ? ' checked' : '' ?>> <?= $e($t('settings.brand.logo_tint_' . $tint)) ?></label>
      <?php endforeach; ?>
      <p class="text-sm text-muted"><?= $e($t('settings.brand.logo_tint_help')) ?></p>
    </fieldset>
    <fieldset class="stack-sm">
      <legend class="field__label"><?= $e($t('settings.theme.mode')) ?></legend>
      <?php foreach (['light', 'dark', 'auto'] as $m): ?>
        <label class="choice"><input type="radio" name="mode" value="<?= $m ?>"<?= $form['mode'] === $m ? ' checked' : '' ?>> <?= $e($t('settings.theme.mode_' . $m)) ?></label>
      <?php endforeach; ?>
      <p class="text-sm text-muted"><?= $e($t('settings.theme.mode_help')) ?></p>
    </fieldset>
    <fieldset class="stack-sm">
      <legend class="field__label"><?= $e($t('settings.theme.pattern')) ?></legend>
      <div class="pattern-choices">
        <?php foreach (['dots', 'sparkles'] as $pt): ?>
          <label class="choice pattern-choice"><input type="radio" name="pattern" value="<?= $pt ?>"<?= ($form['pattern'] ?? 'dots') === $pt ? ' checked' : '' ?>>
            <span class="pattern-swatch pattern-swatch--<?= $pt ?>" aria-hidden="true"></span> <?= $e($t('settings.theme.pattern_' . $pt)) ?></label>
        <?php endforeach; ?>
      </div>
      <p class="text-sm text-muted"><?= $e($t('settings.theme.pattern_help')) ?></p>
    </fieldset>
  </section>

  <section class="card stack" aria-labelledby="theme-h">
    <h2 id="theme-h" class="card__title"><?= $e($t('settings.theme.title')) ?></h2>
    <fieldset>
      <legend class="field__label"><?= $e($t('settings.theme.preset')) ?></legend>
      <div class="swatches">
        <?php foreach (array_keys($presets) as $code): ?>
          <label class="swatch-card swatch--<?= $e($code) ?>">
            <input type="radio" name="preset" value="<?= $e($code) ?>"<?= $form['preset'] === $code ? ' checked' : '' ?>>
            <span class="swatch-card__chips" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
            <span class="swatch-card__name"><?= $e($t('settings.theme.preset_' . $code)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <fieldset class="stack">
      <legend class="field__label"><?= $e($t('settings.theme.own_colors')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></legend>
      <p class="text-sm text-muted"><?= $e($t('settings.theme.own_colors_help')) ?></p>
      <?php foreach (array_keys($colorFields) as $f): $id = 'f-' . $f; $err = $errors[$f] ?? null; $own = (string) ($form[$f] ?? ''); ?>
      <div class="field" data-color-row="<?= $e($f) ?>">
        <label class="field__label" for="<?= $e($id) ?>"><?= $e($t('settings.theme.color_' . $f)) ?></label>
        <div class="color-row">
          <input type="color" id="<?= $e($id) ?>-picker" value="<?= $e($own !== '' ? $own : (string) $presetColors[$form['preset']][$f]) ?>" aria-label="<?= $e($t('settings.theme.picker') . ' — ' . $t('settings.theme.color_' . $f)) ?>" data-color-picker>
          <input class="input mono" id="<?= $e($id) ?>" name="<?= $e($f) ?>" value="<?= $e($own) ?>" placeholder="<?= $e((string) $presetColors[$form['preset']][$f]) ?>" maxlength="7" pattern="#[0-9A-Fa-f]{6}" data-color-hex aria-describedby="<?= $e($id) ?>-help<?= $err !== null ? ' ' . $e($id) . '-err' : '' ?>"<?= $err !== null ? ' aria-invalid="true"' : '' ?>>
          <button type="button" class="btn btn--ghost btn--sm" data-color-clear><?= $e($t('settings.theme.use_preset')) ?></button>
        </div>
        <div id="<?= $e($id) ?>-help" class="field__help"><?= $e($t('settings.theme.color_' . $f . '_help')) ?></div>
        <?php if ($err !== null): ?><div id="<?= $e($id) ?>-err" class="field__error" role="alert"><?= $e($err) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <div aria-live="polite">
      <h3 class="text-sm"><?= $e($t('settings.theme.contrast')) ?></h3>
      <ul class="contrast-list" data-contrast-list>
        <?php foreach ($build['checks'] as $c): ?>
          <li class="<?= $c['ok'] ? 'is-ok' : 'is-bad' ?>"><?= $ui('icon', ['name' => $c['ok'] ? 'circle-check' : 'alert-triangle', 'size' => 'sm']) ?>
            <span><?= $e($t('theme.check.' . $c['pair'])) ?></span><span class="mono"><?= $e(number_format((float) $c['ratio'], 2)) ?> : 1</span></li>
        <?php endforeach; ?>
      </ul>
      <p class="text-sm text-danger" data-contrast-suggest<?= $build['ok'] ? ' hidden' : '' ?>><?= $e($t('theme.error.contrast', ['suggestion' => (string) ($build['suggestion'] ?? '—')])) ?></p>
    </div>
  </section>

  <section class="card stack settings-grid__full" aria-labelledby="preview-h">
    <h2 id="preview-h" class="card__title"><?= $e($t('settings.theme.preview')) ?></h2>
    <div class="theme-preview">
      <div class="theme-preview__menu navy-dots on-navy"><span class="theme-preview__item is-active"><?= $e($t('nav.home')) ?></span><span class="theme-preview__item"><?= $e($t('nav.users')) ?></span></div>
      <div class="theme-preview__body stack-sm">
        <div class="row"><?= $ui('button', ['label' => $t('settings.theme.sample_primary'), 'variant' => 'primary', 'type' => 'button']) ?><?= $ui('button', ['label' => $t('settings.theme.sample_secondary'), 'variant' => 'secondary', 'type' => 'button']) ?></div>
        <p><a href="#preview-h"><?= $e($t('settings.theme.sample_link')) ?></a> · <?= $ui('badge', ['tone' => 'success', 'label' => $t('settings.theme.sample_badge')]) ?></p>
      </div>
    </div>
    <p class="text-sm text-muted"><?= $e($t('settings.theme.preview_help')) ?></p>
  </section>

  <div class="row settings-grid__full">
    <?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks', 'attrs' => ['data-save' => '1']]) ?>
    <button class="btn btn--ghost" type="submit" name="reset" value="1"><?= $e($t('settings.theme.reset')) ?></button>
  </div>
</form>

<section class="card stack" id="font" aria-labelledby="font-h">
  <h2 id="font-h" class="card__title"><?= $ui('icon', ['name' => 'typography', 'size' => 'sm']) ?> <?= $e($t('settings.font.title')) ?></h2>
  <?php $fontNow = $look['font'] === 'custom' ? (string) ($look['font_family'] ?? '') : (\Blue\Core\Ui\FontCatalog::FONTS[$look['font']][1] ?? 'IBM Plex Sans Thai'); ?>
  <p class="text-sm"><?= $ui('badge', ['tone' => 'info', 'icon' => 'typography', 'label' => $t('settings.font.current', ['font' => $fontNow])]) ?></p>
  <div class="grid grid--2">
    <form method="post" action="<?= $e($url('/admin/settings/font')) ?>" class="stack-sm" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <?php $opts = []; foreach (\Blue\Core\Ui\FontCatalog::FONTS as $k => [$fam, $label]) { $opts[$k] = $k === 'system' ? $t('look.font_system') : $label; }
        if ($look['font_custom'] !== null && $look['font_family'] !== null) { $opts['custom'] = $t('settings.font.custom', ['family' => (string) $look['font_family']]); } ?>
      <?= $ui('field', ['name' => 'font', 'id' => 'f-org-font', 'label' => $t('settings.font.pick'), 'type' => 'select', 'value' => $look['font'], 'options' => $opts, 'error' => $errors['font'] ?? null]) ?>
      <ul class="font-samples font-samples--compact" aria-hidden="true">
        <?php foreach (['plex', 'sarabun', 'prompt', 'kanit'] as $k): ?><li><span class="font-sample font-sample--<?= $k ?>"><span class="font-sample__name"><?= $e(\Blue\Core\Ui\FontCatalog::FONTS[$k][1]) ?></span><span class="font-sample__text"><?= $e($t('look.font_sample')) ?></span></span></li><?php endforeach; ?>
      </ul>
      <div class="row"><?= $ui('button', ['label' => $t('settings.font.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?></div>
    </form>
    <form method="post" action="<?= $e($url('/admin/settings/font')) ?>" class="stack-sm" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="font" value="custom">
      <?= $ui('field', ['name' => 'font_url', 'label' => $t('settings.font.url'), 'value' => (string) ($errors['_font_url'] ?? $look['font_url'] ?? ''), 'error' => $errors['font_url'] ?? null,
          'help' => $t('settings.font.url_help'), 'placeholder' => 'https://fonts.googleapis.com/css2?family=…', 'inputmode' => 'url']) ?>
      <?= $ui('field', ['name' => 'font_family', 'label' => $t('settings.font.family'), 'value' => (string) ($look['font_family'] ?? ''), 'help' => $t('settings.font.family_help')]) ?>
      <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'info-circle', 'size' => 'sm']) ?> <?= $e($t('settings.font.license')) ?></p>
      <div class="row"><?= $ui('button', ['label' => $t('settings.font.import'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'download']) ?></div>
    </form>
  </div>
</section>

<section class="card stack" id="brand" aria-labelledby="brandfile-h">
  <h2 id="brandfile-h" class="card__title"><?= $e($t('settings.brand.files_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('settings.brand.files_help')) ?></p>
  <div class="grid grid--2">
    <?php foreach (['logo', 'favicon'] as $kind): $has = !empty($look[$kind]); ?>
    <form method="post" action="<?= $e($url('/admin/settings/appearance/brand')) ?>" enctype="multipart/form-data" class="stack-sm brand-file">
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="kind" value="<?= $kind ?>">
      <div class="row">
        <img class="brand-file__preview" src="<?= $e($has ? $url('/brand/' . $kind) . '?v=' . $look['version'] : $url('/brand/' . $kind)) ?>" alt="<?= $e($t('settings.brand.current_' . $kind)) ?>" width="56" height="56">
        <span class="text-sm"><?= $e($t($has ? 'settings.brand.uploaded' : 'settings.brand.default_mark')) ?></span>
      </div>
      <?= $ui('field', ['name' => 'file', 'id' => 'f-file-' . $kind, 'type' => 'file', 'label' => $t('settings.brand.upload_' . $kind), 'accept' => '.png,.jpg,.jpeg,.webp' . ($kind === 'favicon' ? ',.ico' : ''), 'help' => $t('settings.brand.help_' . $kind),
          'data' => ['image-max' => $kind === 'favicon' ? '256' : '512', 'image-bytes' => (string) (900 * 1024)]]) ?>
      <p class="text-sm text-muted" data-image-note data-template="<?= $e($t('settings.brand.shrunk', ['size' => '{size}'])) ?>" role="status" hidden></p>
      <div class="row">
        <?= $ui('button', ['label' => $t('settings.brand.upload'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'upload']) ?>
        <?php if ($has): ?><button class="btn btn--ghost" type="submit" name="reset" value="1"><?= $e($t('settings.brand.reset_' . $kind)) ?></button><?php endif; ?>
      </div>
    </form>
    <?php endforeach; ?>
  </div>
</section>
