<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<int, string> $companies @var array<string, string> $types @var array<string, string> $errors @var array<string, string> $old
 */
?>
<?= $ui('page-header', ['title' => $t('payment.new.title'), 'subtitle' => $t('payment.new.subtitle'),
    'crumbs' => [[$t('payment.title'), $url('/payments')], [$t('payment.new.title'), null]]]) ?>
<?php if ($types === []): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('payment.new.no_manual')]) ?>
<?php else: ?>
<section class="card">
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/payments/new')) ?>" class="form-grid" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'legal_entity', 'label' => $t('finance.field.company'), 'type' => 'select', 'required' => true, 'value' => $old['legal_entity'], 'options' => $companies, 'error' => $errors['legal_entity'] ?? null]) ?>
    <?= $ui('field', ['name' => 'type', 'label' => $t('payment.field.type'), 'type' => 'select', 'required' => true, 'value' => $old['type'], 'options' => $types, 'error' => $errors['payment_type'] ?? null, 'help' => $t('payment.new.type_help')]) ?>
    <?= $ui('field', ['name' => 'pay_date', 'label' => $t('payment.field.pay_date'), 'type' => 'date', 'required' => true, 'value' => $old['pay_date'], 'error' => $errors['pay_date'] ?? null]) ?>
    <?= $ui('field', ['name' => 'period', 'label' => $t('payment.field.period'), 'value' => $old['period'], 'placeholder' => '2026-12']) ?>
    <?= $ui('field', ['name' => 'note', 'label' => $t('payment.field.note'), 'type' => 'textarea', 'full' => true, 'value' => $old['note']]) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('payment.new.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
