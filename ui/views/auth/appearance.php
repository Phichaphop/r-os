<?php
/**
 * "My display settings" (0.6.1): light / dark / auto, highlight colour, system font — for this person only.
 * Unset = follow the organization (Settings → Appearance). The preview stylesheet is swapped live; nothing is saved
 * until "Save".
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array{theme: ?string, accent: ?string, font: ?string} $look @var array<string, mixed> $org @var array<string, string> $errors
 * @var array{theme: string, accent: string, font: string} $form @var array<string, string> $accents
 * @var array<string, array{0: string, 1: string}> $fonts @var string $orgPrimary
 */
$orgMode = (string) $org['mode'];
$orgModeLabel = ['light' => $t('settings.theme.mode_light'), 'dark' => $t('settings.theme.mode_dark'), 'auto' => $t('settings.theme.mode_auto')][$orgMode] ?? $t('settings.theme.mode_light');
$orgFontLabel = $org['font'] === 'custom' ? (string) ($org['font_family'] ?? '') : ($fonts[$org['font']][1] ?? $fonts['plex'][1]);
$isPreset = in_array($form['accent'], $accents, true);
$customAccent = $form['accent'] !== '' && !$isPreset ? $form['accent'] : '';
$fontChoices = ['org' => $t('look.font_org', ['font' => $orgFontLabel])];
foreach ($fonts as $k => [$fam, $label]) {
    $fontChoices[$k] = $k === 'system' ? $t('look.font_system') : $label;
}
if ($org['font'] === 'custom' && $org['font_family'] !== null) {
    $fontChoices['custom'] = (string) $org['font_family'] . ' · ' . $t('look.font_company');
}
?>
<link rel="stylesheet" id="look-preview" href="<?= $e($url('/me/theme-preview.css') . '?' . http_build_query(['accent' => $form['accent'], 'font' => $form['font'] === 'org' ? '' : $form['font']])) ?>">
<?= $ui('page-header', ['title' => $t('look.title'), 'subtitle' => $t('look.subtitle'),
    'crumbs' => [[$t('nav.my_account'), $url('/me')], [$t('look.title'), null]]]) ?>
<form method="post" action="<?= $e($url('/me/appearance')) ?>" class="look-grid" novalidate data-look-form
  data-preview-url="<?= $e($url('/me/theme-preview.css')) ?>" data-org-mode="<?= $e($orgMode) ?>">
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <div class="stack">
    <section class="card stack" aria-labelledby="lk-theme">
      <h2 id="lk-theme" class="card__title"><?= $ui('icon', ['name' => 'moon', 'size' => 'sm']) ?> <?= $e($t('look.theme')) ?></h2>
      <div class="look-modes" role="radiogroup" aria-labelledby="lk-theme">
        <?php foreach (['light' => 'sun', 'dark' => 'moon', 'auto' => 'refresh', 'org' => 'building'] as $m => $icon): ?>
        <label class="look-mode look-mode--<?= $e($m) ?>">
          <input type="radio" name="theme" value="<?= $e($m) ?>"<?= $form['theme'] === $m ? ' checked' : '' ?>>
          <span class="look-mode__art" aria-hidden="true"><span></span><span></span><span></span></span>
          <span class="look-mode__name"><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t('look.mode.' . $m, ['mode' => $orgModeLabel])) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if (isset($errors['theme'])): ?><p class="field__error" role="alert"><?= $e($errors['theme']) ?></p><?php endif; ?>
      <p class="text-sm text-muted"><?= $e($t('look.theme_help')) ?></p>
    </section>

    <section class="card stack" aria-labelledby="lk-accent">
      <h2 id="lk-accent" class="card__title"><?= $ui('icon', ['name' => 'palette', 'size' => 'sm']) ?> <?= $e($t('look.accent')) ?></h2>
      <div class="accent-grid" role="radiogroup" aria-labelledby="lk-accent">
        <label class="accent-chip accent-chip--org" title="<?= $e($t('look.accent_org')) ?>">
          <input type="radio" name="accent_pick" value=""<?= $form['accent'] === '' ? ' checked' : '' ?> data-accent-hex="">
          <span class="accent-chip__dot" aria-hidden="true"></span><span class="accent-chip__name"><?= $e($t('look.accent_org')) ?></span>
        </label>
        <?php foreach ($accents as $k => $hex): ?>
        <label class="accent-chip accent-swatch--<?= $e($k) ?>">
          <input type="radio" name="accent_pick" value="<?= $e($hex) ?>"<?= $form['accent'] === $hex ? ' checked' : '' ?> data-accent-hex="<?= $e($hex) ?>">
          <span class="accent-chip__dot" aria-hidden="true"></span><span class="accent-chip__name"><?= $e($t('look.color.' . $k)) ?></span>
        </label>
        <?php endforeach; ?>
        <label class="accent-chip accent-chip--custom">
          <input type="radio" name="accent_pick" value="custom"<?= $customAccent !== '' ? ' checked' : '' ?> data-accent-custom>
          <span class="accent-chip__dot" aria-hidden="true"><?= $ui('icon', ['name' => 'plus', 'size' => 'sm']) ?></span><span class="accent-chip__name"><?= $e($t('look.accent_custom')) ?></span>
        </label>
      </div>
      <div class="color-row" data-accent-row<?= $customAccent === '' ? ' hidden' : '' ?>>
        <input type="color" id="lk-picker" value="<?= $e($customAccent !== '' ? $customAccent : $orgPrimary) ?>" aria-label="<?= $e($t('settings.theme.picker')) ?>" data-accent-picker>
        <label class="sr-only" for="lk-hex"><?= $e($t('look.accent_hex')) ?></label>
        <input class="input mono" id="lk-hex" value="<?= $e($customAccent) ?>" placeholder="#RRGGBB" maxlength="7" pattern="#[0-9A-Fa-f]{6}" data-accent-hexinput>
      </div>
      <input type="hidden" name="accent" value="<?= $e($form['accent']) ?>" data-accent-value>
      <p class="field__error" role="alert" data-accent-error<?= isset($errors['accent']) ? '' : ' hidden' ?>><?= $e($errors['accent'] ?? '') ?></p>
      <p class="text-sm text-muted"><?= $e($t('look.accent_help')) ?></p>
    </section>
  </div>

  <div class="stack">
    <section class="card stack" aria-labelledby="lk-font">
      <h2 id="lk-font" class="card__title"><?= $ui('icon', ['name' => 'typography', 'size' => 'sm']) ?> <?= $e($t('look.font')) ?></h2>
      <?= $ui('field', ['name' => 'font', 'label' => $t('look.font_pick'), 'type' => 'select', 'value' => $form['font'], 'options' => $fontChoices, 'error' => $errors['font'] ?? null,
          'data' => ['look-font' => '1'], 'help' => $t('look.font_help')]) ?>
      <ul class="font-samples" aria-label="<?= $e($t('look.font_samples')) ?>">
        <?php foreach ($fonts as $k => [$fam, $label]): ?>
          <li><button type="button" class="font-sample font-sample--<?= $e($k) ?><?= $form['font'] === $k ? ' is-current' : '' ?>" data-font-pick="<?= $e($k) ?>">
            <span class="font-sample__name"><?= $e($k === 'system' ? $t('look.font_system') : $label) ?></span>
            <span class="font-sample__text"><?= $e($t('look.font_sample')) ?></span></button></li>
        <?php endforeach; ?>
        <?php if (isset($fontChoices['custom'])): ?>
          <li><button type="button" class="font-sample font-sample--custom<?= $form['font'] === 'custom' ? ' is-current' : '' ?>" data-font-pick="custom">
            <span class="font-sample__name"><?= $e($fontChoices['custom']) ?></span><span class="font-sample__text"><?= $e($t('look.font_sample')) ?></span></button></li>
        <?php endif; ?>
      </ul>
    </section>

    <section class="card stack-sm look-preview" aria-labelledby="lk-prev">
      <h2 id="lk-prev" class="card__title"><?= $e($t('look.preview')) ?></h2>
      <p><?= $e($t('look.preview_text')) ?> <a href="#lk-prev"><?= $e($t('look.preview_link')) ?></a></p>
      <div class="row">
        <?= $ui('button', ['label' => $t('look.preview_primary'), 'variant' => 'primary', 'icon' => 'checks', 'attrs' => ['tabindex' => '-1']]) ?>
        <?= $ui('button', ['label' => $t('look.preview_secondary'), 'variant' => 'secondary', 'attrs' => ['tabindex' => '-1']]) ?>
        <?= $ui('badge', ['status' => 'processing', 'label' => $t('look.preview_badge')]) ?>
      </div>
    </section>

    <div class="row">
      <?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks', 'attrs' => ['data-look-save' => '1']]) ?>
      <button type="submit" name="reset" value="1" class="btn btn--ghost"><?= $ui('icon', ['name' => 'arrow-back-up', 'size' => 'sm']) ?><?= $e($t('look.reset')) ?></button>
    </div>
  </div>
</form>
