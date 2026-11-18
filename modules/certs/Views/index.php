<?php
/**
 * Ask for a certificate (no approval) + my requests.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{employee: array<string, mixed>|null, types: list<array<string, mixed>>, requests: list<array<string, mixed>>, unrated: array<string, mixed>|null} $mine
 * @var array<string, string> $errors @var array<string, mixed> $old @var bool $canManage
 */
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$en = ($v['locale'] ?? 'th') === 'en';
$typeName = static fn (array $x): string => (string) ($en && ($x['name_en'] ?? null) !== null ? $x['name_en'] : $x['name_th']);
?>
<?= $ui('page-header', ['title' => $t('certs.title'), 'subtitle' => $t('certs.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('certs.nav_admin'), 'href' => $url('/certs/admin'), 'variant' => 'secondary', 'icon' => 'inbox']) : '']) ?>
<?php if ($mine['employee'] === null || $mine['employee']['job'] === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('certs.error.not_employee')]) ?>
<?php else: ?>
<?php if ($mine['unrated'] !== null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('certs.rate_first_title'), 'text' => $t('certs.error.rate_first')]) ?>
  <p><?= $ui('button', ['label' => $t('certs.rate_now', ['no' => (string) $mine['unrated']['request_no']]), 'href' => $url('/certs/r/' . $mine['unrated']['public_id']), 'variant' => 'primary', 'icon' => 'circle-check']) ?></p>
<?php elseif ($mine['types'] === []): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('certs.no_types')]) ?>
<?php else: ?>
<section class="card stack" aria-labelledby="ci-n">
  <h2 id="ci-n" class="card__title"><?= $e($t('certs.new')) ?></h2>
  <form method="post" action="<?= $e($url('/certs')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('certs.field.type')) ?> <span class="field__req"><?= $e($t('form.required_tag')) ?></span></legend>
      <?php foreach ($mine['types'] as $i => $x): ?>
        <label class="choice-card"><input type="radio" name="type" value="<?= $e((string) $x['public_id']) ?>"<?= $o('type', $i === 0 ? (string) $x['public_id'] : '') === (string) $x['public_id'] ? ' checked' : '' ?>>
          <span><strong><?= $e($typeName($x)) ?></strong><?php if ($x['description'] !== null): ?><span class="d-block text-sm text-muted"><?= $e((string) $x['description']) ?></span><?php endif; ?>
          <span class="d-block text-sm text-muted"><?= $e($t('certs.langs_copies', ['langs' => implode(', ', array_filter([$x['template_th_id'] !== null ? $t('certs.lang.th') : '', $x['template_en_id'] !== null ? $t('certs.lang.en') : ''])), 'n' => (string) $x['max_copies']])) ?></span></span></label>
      <?php endforeach; ?>
      <?php if (isset($errors['type'])): ?><p class="field__error"><?= $e($errors['type']) ?></p><?php endif; ?>
    </fieldset>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'language', 'type' => 'select', 'label' => $t('certs.field.language'), 'value' => $o('language', 'th'), 'options' => ['th' => $t('certs.lang.th'), 'en' => $t('certs.lang.en')], 'error' => $errors['language'] ?? null]) ?>
      <?= $ui('field', ['name' => 'copies', 'type' => 'number', 'label' => $t('certs.field.copies'), 'value' => $o('copies', '1'), 'min' => '1', 'max' => '10', 'required' => true, 'error' => $errors['copies'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'purpose', 'label' => $t('certs.field.purpose'), 'required' => true, 'value' => $o('purpose'), 'placeholder' => $t('certs.purpose_hint'), 'error' => $errors['purpose'] ?? null]) ?>
    <p class="text-sm text-muted"><?= $e($t('certs.no_approval')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('certs.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="ci-m">
  <h2 id="ci-m" class="card__title"><?= $e($t('certs.my_requests')) ?></h2>
  <?php if ($mine['requests'] === []): ?><p class="text-sm text-muted"><?= $e($t('certs.none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($mine['requests'] as $x): $st = (string) $x['status']; ?>
      <li><a href="<?= $e($url('/certs/r/' . $x['public_id'])) ?>"><?= $e((string) $x['request_no']) ?></a> · <?= $e($typeName($x)) ?> <?php include __DIR__ . '/_status.php'; ?>
        <span class="d-block text-sm text-muted"><?= $e($date((string) $x['created_at'])) ?> · <?= $e((string) $x['purpose']) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
