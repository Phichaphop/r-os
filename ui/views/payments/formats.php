<?php
/**
 * Bank file formats (0.48.0 · R8-3 · DEC-117): the formats of the companies the person may see, and a new one — blank or
 * from a made-up sample.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $formats @var array<int, string> $companies @var array<string, array<string, mixed>> $samples
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$tone = ['draft' => 'neutral', 'active' => 'success', 'retired' => 'warning'];
?>
<?= $ui('page-header', ['title' => $t('payment.format.title'), 'subtitle' => $t('payment.format.subtitle'), 'crumbs' => [[$t('payment.title'), $url('/payments')], [$t('payment.format.title'), null]]]) ?>
<section class="card card--flush" aria-labelledby="bf-l">
  <div class="card__head"><h2 id="bf-l" class="card__title"><?= $e($t('payment.format.list')) ?></h2></div>
  <?php if ($formats === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'file-text', 'title' => $t('payment.format.empty'), 'text' => $t('payment.format.empty_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="bf-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payment.format.code')) ?></th><th scope="col"><?= $e($t('payment.format.name')) ?></th><th scope="col"><?= $e($t('finance.field.company')) ?></th>
        <th scope="col"><?= $e($t('payment.format.file')) ?></th><th scope="col"><?= $e($t('payment.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($formats as $f): ?>
        <tr><th scope="row" data-label="<?= $e($t('payment.format.code')) ?>"><a href="<?= $e($url('/payments/formats/' . $f['public_id'])) ?>" class="mono"><?= $e($f['code'] . ' v' . $f['version']) ?></a></th>
          <td data-label="<?= $e($t('payment.format.name')) ?>"><?= $e((string) $f['name']) ?><?php if ((int) $f['from_sample'] === 1): ?> <?= $ui('badge', ['tone' => 'info', 'label' => $t('payment.format.from_sample')]) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('finance.field.company')) ?>"><?= $e((string) $f['le_code']) ?></td>
          <td data-label="<?= $e($t('payment.format.file')) ?>">.<?= $e((string) $f['extension']) ?> · <?= $e((string) $f['encoding']) ?></td>
          <td data-label="<?= $e($t('payment.field.status')) ?>"><?= $ui('badge', ['tone' => $tone[$f['status']] ?? 'neutral', 'label' => $t('payment.format.status.' . $f['status'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($companies !== []): ?>
<section class="card stack" aria-labelledby="bf-n">
  <h2 id="bf-n" class="card__title"><?= $e($t('payment.format.new')) ?></h2>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('payment.format.sample_note')]) ?>
  <form method="post" action="<?= $e($url('/payments/formats/new')) ?>" class="stack" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'legal_entity', 'type' => 'select', 'label' => $t('finance.field.company'), 'required' => true, 'options' => array_map('strval', $companies), 'value' => $o('legal_entity')]) ?>
      <?= $ui('field', ['name' => 'code', 'label' => $t('payment.format.code'), 'required' => true, 'value' => $o('code'), 'help' => $t('payment.format.code_help'), 'error' => $errors['code'] ?? null, 'autocomplete' => 'off']) ?>
      <?php $from = ['blank' => $t('payment.format.from_blank')]; foreach ($samples as $k => $s) { $from['sample:' . $k] = $s['name']; } ?>
      <?= $ui('field', ['name' => 'from', 'type' => 'select', 'label' => $t('payment.format.start_from'), 'required' => true, 'options' => $from, 'value' => $o('from', 'sample:fixed'), 'error' => $errors['from'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('payment.format.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="bf-h">
  <h2 id="bf-h" class="card__title"><?= $e($t('payment.format.how_title')) ?></h2>
  <p class="text-sm"><?= $e($t('payment.format.how')) ?></p>
</section>
