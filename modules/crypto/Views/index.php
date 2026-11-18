<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var string $tool @var array<string, mixed> $old @var array{value: string, facts: array<string, string|int>, warnings: list<string>}|null $result
 * @var array<string, string> $errors @var bool $argon2
 */
use Blue\Modules\Crypto\CryptoController;
use Blue\Modules\Crypto\CryptoTools;

$opt = static fn (array $values, callable $label): array => array_combine($values, array_map($label, $values));
$hidden = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '"><input type="hidden" name="tool" value="' . $e($tool) . '">';
?>
<?= $ui('page-header', ['title' => $t('crypto.title'), 'subtitle' => $t('crypto.subtitle')]) ?>
<?= $ui('alert', ['tone' => 'info', 'text' => $t('crypto.notice')]) ?>
<nav class="tabs__list subnav" aria-label="<?= $e($t('crypto.title')) ?>">
  <?php foreach (CryptoController::TOOLS as $k): ?>
    <a class="tabs__tab" href="<?= $e($url('/crypto?tool=' . $k)) ?>"<?= $tool === $k ? ' aria-current="page"' : '' ?>><?= $e($t('crypto.tool.' . $k)) ?></a>
  <?php endforeach; ?>
</nav>
<div class="settings-grid">
<form method="post" action="<?= $e($url('/crypto')) ?>" class="card stack" autocomplete="off" novalidate data-forget<?= $tool === 'digest' ? ' enctype="multipart/form-data"' : '' ?> aria-labelledby="tool-h">
  <h2 id="tool-h" class="card__title"><?= $e($t('crypto.tool.' . $tool)) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('crypto.about.' . $tool)) ?></p>
  <?= $hidden ?>
  <?php if ($tool === 'hash'): ?>
    <?= $ui('field', ['name' => 'password', 'type' => 'password', 'label' => $t('crypto.field.password'), 'required' => true, 'autocomplete' => 'new-password', 'error' => $errors['password'] ?? null]) ?>
    <?= $ui('field', ['name' => 'algo', 'type' => 'select', 'label' => $t('crypto.field.algorithm'), 'value' => (string) ($old['algo'] ?? 'bcrypt'),
        'options' => ['bcrypt' => 'bcrypt'] + ($argon2 ? ['argon2id' => 'Argon2id'] : [])]) ?>
    <?= $ui('field', ['name' => 'cost', 'type' => 'select', 'label' => $t('crypto.field.cost'), 'value' => (string) ($old['cost'] ?? '12'), 'help' => $t('crypto.cost_help'),
        'options' => ['10' => '10', '11' => '11', '12' => '12', '13' => '13']]) ?>
  <?php elseif ($tool === 'verify'): ?>
    <?= $ui('field', ['name' => 'password', 'type' => 'password', 'label' => $t('crypto.field.password'), 'required' => true, 'autocomplete' => 'off', 'error' => $errors['password'] ?? null]) ?>
    <?= $ui('field', ['name' => 'hash', 'label' => $t('crypto.field.hash'), 'required' => true, 'value' => (string) ($old['hash'] ?? ''), 'placeholder' => '$2y$12$…', 'error' => $errors['hash'] ?? null]) ?>
  <?php elseif ($tool === 'digest'): ?>
    <?= $ui('field', ['name' => 'text', 'type' => 'textarea', 'label' => $t('crypto.field.text'), 'value' => (string) ($old['text'] ?? ''), 'error' => $errors['text'] ?? null]) ?>
    <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('crypto.field.file'), 'help' => $t('crypto.file_help'), 'error' => $errors['file'] ?? null]) ?>
    <?= $ui('field', ['name' => 'algo', 'type' => 'select', 'label' => $t('crypto.field.algorithm'), 'value' => (string) ($old['algo'] ?? 'sha256'),
        'options' => $opt(CryptoTools::DIGESTS, static fn ($a) => strtoupper($a) . (in_array($a, CryptoTools::LEGACY, true) ? ' — ' . $t('crypto.legacy') : ''))]) ?>
  <?php elseif ($tool === 'hmac'): ?>
    <?= $ui('field', ['name' => 'text', 'type' => 'textarea', 'label' => $t('crypto.field.message'), 'value' => (string) ($old['text'] ?? ''), 'error' => $errors['text'] ?? null]) ?>
    <?= $ui('field', ['name' => 'key', 'type' => 'password', 'label' => $t('crypto.field.key'), 'required' => true, 'autocomplete' => 'off', 'error' => $errors['key'] ?? null]) ?>
    <?= $ui('field', ['name' => 'algo', 'type' => 'select', 'label' => $t('crypto.field.algorithm'), 'value' => (string) ($old['algo'] ?? 'sha256'),
        'options' => $opt(CryptoTools::HMACS, static fn ($a) => 'HMAC-' . strtoupper($a))]) ?>
  <?php elseif ($tool === 'encode'): ?>
    <?= $ui('field', ['name' => 'text', 'type' => 'textarea', 'label' => $t('crypto.field.text'), 'value' => (string) ($old['text'] ?? ''), 'error' => $errors['text'] ?? null]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'codec', 'type' => 'select', 'label' => $t('crypto.field.codec'), 'value' => (string) ($old['codec'] ?? 'base64'),
          'options' => $opt(CryptoTools::CODECS, static fn ($c) => $t('crypto.codec.' . $c))]) ?>
      <?= $ui('field', ['name' => 'direction', 'type' => 'select', 'label' => $t('crypto.field.direction'), 'value' => (string) ($old['direction'] ?? 'encode'),
          'options' => ['encode' => $t('crypto.encode'), 'decode' => $t('crypto.decode')]]) ?>
    </div>
  <?php else: ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('crypto.field.kind'), 'value' => (string) ($old['kind'] ?? 'password'),
          'options' => ['password' => $t('crypto.kind.password'), 'hex' => $t('crypto.kind.hex'), 'base64url' => $t('crypto.kind.base64url'), 'uuid' => 'UUID v4']]) ?>
      <?= $ui('field', ['name' => 'length', 'type' => 'number', 'label' => $t('crypto.field.length'), 'value' => (string) ($old['length'] ?? '20'), 'min' => '8', 'max' => '128', 'help' => $t('crypto.length_help')]) ?>
    </div>
    <fieldset class="stack-sm">
      <legend class="field__label"><?= $e($t('crypto.field.sets')) ?></legend>
      <div class="checks checks--inline">
        <?php $sets = (array) ($old['sets'] ?? ['lower', 'upper', 'digits']); foreach (['lower', 'upper', 'digits', 'symbols'] as $s): ?>
          <label class="check"><input type="checkbox" name="sets[]" value="<?= $s ?>"<?= in_array($s, $sets, true) ? ' checked' : '' ?>> <?= $e($t('crypto.set.' . $s)) ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endif; ?>
  <div class="row"><?= $ui('button', ['label' => $t('crypto.run.' . $tool), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'key']) ?></div>
</form>

<section class="card stack-sm" aria-labelledby="res-h" aria-live="polite" data-forget-result>
  <h2 id="res-h" class="card__title"><?= $e($t('crypto.result')) ?></h2>
  <?php if ($result === null): ?>
    <p class="text-sm text-muted"><?= $e($t('crypto.result_none')) ?></p>
  <?php else: ?>
    <?php $isVerdict = str_starts_with($result['value'], 'crypto.verify.'); ?>
    <?php if ($isVerdict): ?>
      <?= $ui('alert', ['tone' => $result['value'] === 'crypto.verify.match' ? 'success' : 'danger', 'text' => $t($result['value'])]) ?>
    <?php else: ?>
      <pre class="code-box code-box--wrap" id="crypto-out"><?= $e($result['value']) ?></pre>
      <div class="row"><button type="button" class="btn btn--secondary btn--sm" data-copy-target="crypto-out" data-copied-text="<?= $e($t('common.copied')) ?>"><?= $ui('icon', ['name' => 'copy', 'size' => 'sm']) ?><span><?= $e($t('common.copy')) ?></span></button></div>
    <?php endif; ?>
    <dl class="facts">
      <?php foreach ($result['facts'] as $k => $val): ?>
        <div><dt><?= $e($t($k)) ?></dt><dd class="mono break-all"><?= $e((string) $val) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <?php foreach ($result['warnings'] as $w): ?>
      <?= $ui('alert', ['tone' => 'warning', 'text' => $t($w)]) ?>
    <?php endforeach; ?>
  <?php endif; ?>
  <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('crypto.not_stored')) ?></p>
</section>
</div>
