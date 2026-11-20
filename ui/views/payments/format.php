<?php
/**
 * One bank file format version (0.48.0 · R8-3 · DEC-117): its templates (editable while a draft), the variables and
 * options, a preview on made-up data, switching it on (another person, checked against the bank's specification),
 * retiring it, its versions.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $f @var array{text: ?string, problems: list<array<string, mixed>>, lines: list<string>} $preview
 * @var list<array<string, mixed>> $versions @var list<array<string, mixed>> $tokens @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Core\Payments\BankFormats;
use Blue\Core\Payments\BankTemplate;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/payments/formats/' . $f['public_id'];
$o = static fn (string $k, string $d): string => array_key_exists($k, $old) ? (string) $old[$k] : $d;
$params = implode("\n", array_map(static fn ($k, $x): string => $k . '=' . $x, array_keys((array) $f['params']), (array) $f['params']));
$tone = ['draft' => 'neutral', 'active' => 'success', 'retired' => 'warning'];
$opts = static fn (array $list): array => array_combine($list, $list);
?>
<?= $ui('page-header', ['title' => $t('payment.format.one', ['code' => (string) $f['code'], 'v' => (string) $f['version']]), 'subtitle' => (string) $f['name'],
    'crumbs' => [[$t('payment.title'), $url('/payments')], [$t('payment.format.title'), $url('/payments/formats')], [$f['code'] . ' v' . $f['version'], null]]]) ?>
<?php if ((int) $f['from_sample'] === 1): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('payment.format.from_sample'), 'text' => $t('payment.format.sample_warning')]) ?><?php endif; ?>
<section class="card" aria-labelledby="bf-s">
  <h2 id="bf-s" class="card__title"><?= $e($t('payment.format.summary')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('payment.field.status')) ?></dt><dd><?= $ui('badge', ['tone' => $tone[$f['status']] ?? 'neutral', 'label' => $t('payment.format.status.' . $f['status'])]) ?></dd></div>
    <div><dt><?= $e($t('finance.field.company')) ?></dt><dd><?= $e((string) $f['le_code']) ?></dd></div>
    <div><dt><?= $e($t('payment.format.file')) ?></dt><dd>.<?= $e((string) $f['extension']) ?> · <?= $e((string) $f['encoding']) ?> · <?= $e((string) $f['line_end']) ?></dd></div>
    <div><dt><?= $e($t('payment.format.made_by')) ?></dt><dd><?= $e((string) ($f['names']['created'] ?? '—')) ?></dd></div>
    <?php if ($f['activated_at'] !== null): ?>
      <div><dt><?= $e($t('payment.format.activated_by')) ?></dt><dd><?= $e((string) ($f['names']['activated'] ?? '—')) ?> · <?= $e($date((string) $f['activated_at'])) ?></dd></div>
      <div><dt><?= $e($t('payment.format.reference')) ?></dt><dd><?= $e((string) $f['spec_reference']) ?></dd></div>
    <?php endif; ?>
  </dl>
</section>

<section class="card stack" id="preview" aria-labelledby="bf-p">
  <h2 id="bf-p" class="card__title"><?= $e($t('payment.format.preview')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('payment.format.preview_help')) ?></p>
  <?php if ($preview['problems'] !== []): ?>
    <?= $ui('alert', ['tone' => 'danger', 'text' => implode(' · ', array_map(static fn (array $p): string => $t((string) $p['key'], (array) $p['params']), $preview['problems']))]) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-label="<?= $e($t('payment.format.preview')) ?>" tabindex="0">
      <pre class="bf-preview"><?php foreach ($preview['lines'] as $i => $l): ?><span class="bf-preview__no" aria-hidden="true"><?= str_pad((string) mb_strlen($l), 4, ' ', STR_PAD_LEFT) ?></span> <?= $e(str_replace(' ', '·', $l)) . "\n" ?><?php endforeach; ?></pre>
    </div>
    <p class="text-sm text-muted"><?= $e($t('payment.format.preview_legend')) ?></p>
  <?php endif; ?>
</section>

<?php if ($f['can_edit']): ?>
<section class="card stack" aria-labelledby="bf-e">
  <h2 id="bf-e" class="card__title"><?= $e($t('payment.format.edit')) ?></h2>
  <?php if ($tokens !== []): ?>
    <?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?>
    <ul class="text-sm"><?php foreach ($tokens as $tk): ?><li><?= $e($t('payment.format.part.' . $tk['part'])) ?>: <?= $e($t((string) $tk['key'], (array) $tk['params'])) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <form method="post" action="<?= $e($url($base . '/save')) ?>" class="stack" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'name', 'label' => $t('payment.format.name'), 'required' => true, 'value' => $o('name', (string) $f['name']), 'error' => $errors['name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'bank_code', 'label' => $t('payment.format.bank_code'), 'value' => $o('bank_code', (string) ($f['bank_code'] ?? '')), 'help' => $t('payment.format.bank_code_help'), 'error' => $errors['bank_code'] ?? null]) ?>
    </div>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'extension', 'type' => 'select', 'label' => $t('payment.format.extension'), 'options' => $opts(BankFormats::EXTENSIONS), 'value' => $o('extension', (string) $f['extension'])]) ?>
      <?= $ui('field', ['name' => 'encoding', 'type' => 'select', 'label' => $t('payment.format.encoding'), 'options' => $opts(BankFormats::ENCODINGS), 'value' => $o('encoding', (string) $f['encoding']), 'help' => $t('payment.format.encoding_help')]) ?>
      <?= $ui('field', ['name' => 'line_end', 'type' => 'select', 'label' => $t('payment.format.line_end'), 'options' => $opts(BankFormats::LINE_ENDS), 'value' => $o('line_end', (string) $f['line_end'])]) ?>
    </div>
    <label class="check"><input type="checkbox" name="final_newline" value="1"<?= $o('final_newline', (string) $f['final_newline']) === '1' ? ' checked' : '' ?>> <?= $e($t('payment.format.final_newline')) ?></label>
    <?php foreach (['header', 'detail', 'footer'] as $part): ?>
      <?= $ui('field', ['name' => $part, 'id' => 'bf-' . $part, 'type' => 'textarea', 'label' => $t('payment.format.part.' . $part), 'required' => $part === 'detail', 'value' => $o($part, (string) ($f[$part . '_tpl'] ?? '')),
          'help' => $t('payment.format.part_help.' . $part), 'error' => $errors[$part] ?? null, 'full' => true]) ?>
    <?php endforeach; ?>
    <?= $ui('field', ['name' => 'params', 'id' => 'bf-params', 'type' => 'textarea', 'label' => $t('payment.format.params'), 'value' => $o('params', $params), 'help' => $t('payment.format.params_help'), 'error' => $errors['params'] ?? null, 'full' => true]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('payment.format.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
  <form method="post" action="<?= $e($url($base . '/discard')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payment.format.discard'), 'type' => 'submit', 'icon' => 'trash']) ?></form>
</section>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="bf-t">
  <h2 id="bf-t" class="card__title"><?= $e($t('payment.format.templates')) ?></h2>
  <?php foreach (['header', 'detail', 'footer'] as $part): if ((string) ($f[$part . '_tpl'] ?? '') === '') { continue; } ?>
    <p class="text-sm"><strong><?= $e($t('payment.format.part.' . $part)) ?></strong></p>
    <div class="table-wrap" role="region" aria-label="<?= $e($t('payment.format.part.' . $part)) ?>" tabindex="0"><pre class="bf-preview"><?= $e((string) $f[$part . '_tpl']) ?></pre></div>
  <?php endforeach; ?>
  <?php if ($params !== ''): ?><p class="text-sm"><strong><?= $e($t('payment.format.params')) ?></strong></p>
    <div class="table-wrap" role="region" aria-label="<?= $e($t('payment.format.params')) ?>" tabindex="0"><pre class="bf-preview"><?= $e($params) ?></pre></div><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($f['status'] === 'draft'): ?>
<section class="card stack" aria-labelledby="bf-a">
  <h2 id="bf-a" class="card__title"><?= $e($t('payment.format.activate_title')) ?></h2>
  <?php if ($f['can_activate']): ?>
  <p class="text-sm text-muted"><?= $e($t('payment.format.activate_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/activate')) ?>" class="stack" novalidate><?= $csrf ?>
    <label class="check"><input type="checkbox" name="checked" value="1"<?= !empty($errors['checked']) ? ' aria-invalid="true"' : '' ?>> <?= $e($t('payment.format.checked')) ?></label>
    <?php if (!empty($errors['checked'])): ?><p class="field__error"><?= $e($errors['checked']) ?></p><?php endif; ?>
    <?= $ui('field', ['name' => 'reference', 'label' => $t('payment.format.reference'), 'required' => true, 'value' => (string) ($old['reference'] ?? ''), 'help' => $t('payment.format.reference_help'), 'error' => $errors['reference'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('payment.format.activate'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
  <?php else: ?><p class="text-sm"><?= $e($t('payment.format.activate_other')) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($f['can_retire'] || $f['can_copy']): ?>
<section class="card stack" aria-labelledby="bf-r">
  <h2 id="bf-r" class="card__title"><?= $e($t('payment.format.change_title')) ?></h2>
  <?php if ($f['can_copy']): ?>
    <form method="post" action="<?= $e($url($base . '/copy')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('payment.format.copy'), 'type' => 'submit', 'icon' => 'copy']) ?></form>
  <?php endif; ?>
  <?php if ($f['can_retire']): ?>
  <form method="post" action="<?= $e($url($base . '/retire')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'bf-reason', 'label' => $t('payment.format.retire_reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $errors['reason'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('payment.format.retire'), 'type' => 'submit', 'icon' => 'ban']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card stack-sm bf-vars" aria-labelledby="bf-vars">
  <h2 id="bf-vars" class="card__title"><?= $e($t('payment.format.vars_title')) ?></h2>
  <p class="text-sm"><?= $e($t('payment.format.vars_common')) ?> <span class="mono"><?= $e(implode(' ', array_map(static fn (string $x): string => '{' . $x . '}', BankTemplate::COMMON))) ?> {p.…}</span></p>
  <p class="text-sm"><?= $e($t('payment.format.vars_detail')) ?> <span class="mono"><?= $e(implode(' ', array_map(static fn (string $x): string => '{' . $x . '}', BankTemplate::DETAIL))) ?></span></p>
  <p class="text-sm"><?= $e($t('payment.format.vars_options')) ?></p>
  <ul class="text-sm mono">
    <li>{payee_name|w:30|cut} · {account|digits|w:11|pad:0|right} · {amount|nodot|w:13|pad:0|right}</li>
    <li>{amount|dec:2} · {pay_date|date:DDMMBBBB} · {payee_name|upper} · {payee_name|q} · {{ }}</li>
  </ul>
</section>

<?php if (count($versions) > 1): ?>
<section class="card stack-sm" aria-labelledby="bf-v">
  <h2 id="bf-v" class="card__title"><?= $e($t('payment.format.versions')) ?></h2>
  <ul class="list-plain"><?php foreach ($versions as $x): ?><li><a href="<?= $e($url('/payments/formats/' . $x['public_id'])) ?>" class="mono">v<?= (int) $x['version'] ?></a> <?= $ui('badge', ['tone' => $tone[$x['status']] ?? 'neutral', 'label' => $t('payment.format.status.' . $x['status'])]) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
