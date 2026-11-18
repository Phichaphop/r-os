<?php
/**
 * QR form fields shared by the generator and the edit page (0.38.0): what goes in (cards, like "kind"), the fields of
 * that type, colours and decoration, logo. Showing only the chosen type's fields is CSS (:has) — without it every
 * group stays visible and still works.
 * @var callable $e @var callable $t @var callable $ui @var array<string, string> $in @var array<string, string> $errors
 * @var bool $managed the code is (or will be) managed — always a link @var bool $logoBuiltin
 * @var string $qrPrimary the system primary colour (default code colour)
 * @var bool|null $styleOnly only colours, decoration and logo (editing a managed code — its link changes elsewhere)
 */
$val = static fn (string $k, string $d = ''): string => (string) ($in[$k] ?? $d);
$type = $managed ? 'url' : ($val('content_type') !== '' ? $val('content_type') : 'url');
$typeIcon = ['url' => 'link', 'text' => 'file-text', 'tel' => 'phone', 'email' => 'mail', 'wifi' => 'wifi', 'vcard' => 'id-badge-2'];
$colorRow = static function (string $name, string $label, string $help, string $placeholder) use ($e, $t, $val, $errors): string {
    $id = 'qr-' . $name;
    $err = $errors[$name] ?? null;
    $v = $val($name);
    return '<div class="field qr-color" data-qr-color>'
        . '<label class="field__label" for="' . $e($id) . '">' . $e($label) . '</label>'
        . '<div class="color-row"><input type="color" value="' . $e($v !== '' ? $v : $placeholder) . '" aria-label="' . $e($t('qr.style.picker', ['what' => $label])) . '" data-qr-picker>'
        . '<input class="input mono" id="' . $e($id) . '" name="' . $e($name) . '" value="' . $e($v) . '" placeholder="' . $e($placeholder) . '" maxlength="7" data-qr-hex aria-describedby="' . $e($id) . '-help' . ($err !== null ? ' ' . $e($id) . '-err' : '') . '"' . ($err !== null ? ' aria-invalid="true"' : '') . '></div>'
        . '<div id="' . $e($id) . '-help" class="field__help">' . $e($help) . '</div>'
        . ($err !== null ? '<div id="' . $e($id) . '-err" class="field__error" role="alert">' . $e($err) . '</div>' : '')
        . '</div>';
};
?>
<?php if (empty($styleOnly)): ?>
<?php if (!$managed): ?>
<fieldset class="stack-sm qr-types">
  <legend class="field__label"><?= $e($t('qr.field.content_type')) ?></legend>
  <div class="qr-type-grid">
  <?php foreach (\Blue\Modules\Qr\QrPolicy::TYPES as $ty): ?>
    <label class="choice choice--block qr-type"><input type="radio" name="content_type" value="<?= $ty ?>"<?= $type === $ty ? ' checked' : '' ?>>
      <span><strong><?= $ui('icon', ['name' => $typeIcon[$ty], 'size' => 'sm']) ?> <?= $e($t('qr.content_type.' . $ty)) ?></strong><span class="text-sm text-muted d-block"><?= $e($t('qr.content_type_help.' . $ty)) ?></span></span></label>
  <?php endforeach; ?>
  </div>
</fieldset>
<?php else: ?>
<input type="hidden" name="content_type" value="url">
<?php endif; ?>

<div class="qr-fields stack-sm" data-qr-type="url text">
  <?= $ui('field', ['name' => 'content', 'type' => 'textarea', 'label' => $t('qr.field.content'), 'required' => true, 'value' => $val('content'),
      'placeholder' => 'https://', 'error' => $errors['content'] ?? null, 'help' => $t('qr.content_help')]) ?>
  <div class="qr-url-only stack-sm">
    <label class="check"><input type="checkbox" name="open_external" value="1"<?= $val('open_external') === '1' ? ' checked' : '' ?>> <?= $e($t('qr.field.open_external')) ?></label>
    <p class="text-sm text-muted"><?= $e($t('qr.open_external_help')) ?></p>
  </div>
</div>
<?php if (!$managed): ?>
<div class="qr-fields stack-sm" data-qr-type="tel">
  <?= $ui('field', ['name' => 'tel', 'required' => true, 'type' => 'tel', 'label' => $t('qr.field.tel'), 'value' => $val('tel'), 'placeholder' => '+66 81 234 5678', 'error' => $errors['tel'] ?? null, 'help' => $t('qr.tel_help')]) ?>
</div>
<div class="qr-fields stack-sm" data-qr-type="email">
  <?= $ui('field', ['name' => 'email_to', 'required' => true, 'type' => 'email', 'label' => $t('qr.field.email_to'), 'value' => $val('email_to'), 'error' => $errors['email_to'] ?? null]) ?>
  <?= $ui('field', ['name' => 'email_subject', 'label' => $t('qr.field.email_subject'), 'value' => $val('email_subject')]) ?>
  <?= $ui('field', ['name' => 'email_body', 'type' => 'textarea', 'label' => $t('qr.field.email_body'), 'value' => $val('email_body')]) ?>
</div>
<div class="qr-fields stack-sm" data-qr-type="wifi">
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'wifi_ssid', 'required' => true, 'label' => $t('qr.field.wifi_ssid'), 'value' => $val('wifi_ssid'), 'error' => $errors['wifi_ssid'] ?? null]) ?>
    <?= $ui('field', ['name' => 'wifi_enc', 'type' => 'select', 'label' => $t('qr.field.wifi_enc'), 'value' => $val('wifi_enc', 'WPA'),
        'options' => ['WPA' => 'WPA / WPA2 / WPA3', 'WEP' => 'WEP', 'nopass' => $t('qr.wifi.nopass')]]) ?>
  </div>
  <?= $ui('field', ['name' => 'wifi_pass', 'required' => true, 'label' => $t('qr.field.wifi_pass'), 'value' => $val('wifi_pass'), 'autocomplete' => 'off', 'error' => $errors['wifi_pass'] ?? null, 'help' => $t('qr.wifi_help')]) ?>
  <label class="check"><input type="checkbox" name="wifi_hidden" value="1"<?= $val('wifi_hidden') === '1' ? ' checked' : '' ?>> <?= $e($t('qr.field.wifi_hidden')) ?></label>
</div>
<div class="qr-fields stack-sm" data-qr-type="vcard">
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'vc_name', 'required' => true, 'label' => $t('qr.field.vc_name'), 'value' => $val('vc_name'), 'error' => $errors['vc_name'] ?? null]) ?>
    <?= $ui('field', ['name' => 'vc_org', 'label' => $t('qr.field.vc_org'), 'value' => $val('vc_org')]) ?>
    <?= $ui('field', ['name' => 'vc_title', 'label' => $t('qr.field.vc_title'), 'value' => $val('vc_title')]) ?>
    <?= $ui('field', ['name' => 'vc_tel', 'type' => 'tel', 'label' => $t('qr.field.vc_tel'), 'value' => $val('vc_tel')]) ?>
    <?= $ui('field', ['name' => 'vc_email', 'type' => 'email', 'label' => $t('qr.field.vc_email'), 'value' => $val('vc_email'), 'error' => $errors['vc_email'] ?? null]) ?>
    <?= $ui('field', ['name' => 'vc_url', 'type' => 'url', 'label' => $t('qr.field.vc_url'), 'value' => $val('vc_url'), 'placeholder' => 'https://']) ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<details class="qr-style stack-sm"<?= isset($errors['fg']) || isset($errors['bg']) || isset($errors['eye']) ? ' open' : '' ?>>
  <summary class="qr-style__summary"><?= $ui('icon', ['name' => 'palette', 'size' => 'sm']) ?> <?= $e($t('qr.style.title')) ?></summary>
  <p class="text-sm text-muted"><?= $e($t('qr.style.help')) ?></p>
  <div class="grid grid--3">
    <?= $colorRow('fg', $t('qr.style.fg'), $t('qr.style.fg_help'), $val('fg') !== '' ? $val('fg') : $qrPrimary) ?>
    <?= $colorRow('bg', $t('qr.style.bg'), $t('qr.style.bg_help'), \Blue\Core\Support\Qr\QrRender::DEFAULT_BG) ?>
    <?= $colorRow('eye', $t('qr.style.eye'), $t('qr.style.eye_help'), $val('fg') !== '' ? $val('fg') : $qrPrimary) ?>
  </div>
  <div class="grid grid--2">
    <fieldset class="stack-sm">
      <legend class="field__label"><?= $e($t('qr.style.module')) ?></legend>
      <?php foreach (\Blue\Core\Support\Qr\QrRender::MODULES as $mo): ?>
        <label class="choice"><input type="radio" name="module" value="<?= $mo ?>"<?= $val('module', 'square') === $mo ? ' checked' : '' ?>> <?= $e($t('qr.style.module_' . $mo)) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <fieldset class="stack-sm">
      <legend class="field__label"><?= $e($t('qr.style.eye_style')) ?></legend>
      <?php foreach (\Blue\Core\Support\Qr\QrRender::EYES as $ey): ?>
        <label class="choice"><input type="radio" name="eye_style" value="<?= $ey ?>"<?= $val('eye_style', 'square') === $ey ? ' checked' : '' ?>> <?= $e($t('qr.style.eye_' . $ey)) ?></label>
      <?php endforeach; ?>
      <p class="text-sm text-muted"><?= $e($t('qr.style.eye_help_note')) ?></p>
    </fieldset>
  </div>
</details>

<div class="stack-sm">
  <label class="check"><input type="checkbox" name="logo" value="1"<?= $val('logo') === '1' ? ' checked' : '' ?> aria-describedby="qr-logo-why"> <?= $e($t('qr.field.logo')) ?></label>
  <p class="text-sm text-muted" id="qr-logo-why"><?= $e($t($logoBuiltin ? 'qr.logo_builtin' : 'qr.logo_help')) ?></p>
  <?= $ui('field', ['name' => 'logo_size', 'type' => 'select', 'label' => $t('qr.field.logo_size'), 'value' => $val('logo_size', \Blue\Modules\Qr\QrService::DEFAULT_LOGO_SIZE),
      'options' => ['s' => $t('qr.logo_size.s'), 'm' => $t('qr.logo_size.m'), 'l' => $t('qr.logo_size.l'), 'xl' => $t('qr.logo_size.xl')], 'help' => $t('qr.logo_size_help')]) ?>
  <?php if (isset($errors['logo'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['logo']]) ?><?php endif; ?>
</div>
