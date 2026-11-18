<?php
/**
 * My payslips: one line per pay date (no amounts here — they are shown after the password).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var list<array<string, mixed>> $rows
 */
?>
<?= $ui('page-header', ['title' => $t('payroll.my_title'), 'subtitle' => $t('payroll.my_subtitle')]) ?>
<nav class="segmented-links" aria-label="<?= $e($t('payroll.my_sections')) ?>">
  <a href="<?= $e($url('/me/payslips')) ?>" aria-current="page"><?= $ui('icon', ['name' => 'receipt', 'size' => 'sm']) ?> <?= $e($t('payroll.my_title')) ?></a>
  <a href="<?= $e($url('/me/tax')) ?>"><?= $ui('icon', ['name' => 'file-text', 'size' => 'sm']) ?> <?= $e($t('payroll.my_tax_title')) ?></a>
</nav>
<section class="card stack-sm" aria-labelledby="ms-l">
  <h2 id="ms-l" class="card__title"><?= $e($t('payroll.my_list')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="ms-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.pay_date')) ?></th><th scope="col"><?= $e($t('payroll.field.run')) ?></th><th scope="col"><?= $e($t('payroll.field.period')) ?></th><th scope="col"><?= $e($t('payroll.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.pay_date')) ?>"><a href="<?= $e($url('/me/payslips/' . $s['public_id'])) ?>"><?= $e($date((string) $s['pay_date'], false)) ?></a></th>
          <td data-label="<?= $e($t('payroll.field.run')) ?>"><?= $e($s['le_code'] . ' · ' . $s['label']) ?></td>
          <td data-label="<?= $e($t('payroll.field.period')) ?>"><?= $e($date((string) $s['period_start'], false)) ?> – <?= $e($date((string) $s['period_end'], false)) ?></td>
          <td data-label="<?= $e($t('payroll.col.status')) ?>"><?= $s['first_viewed_at'] === null ? $ui('badge', ['tone' => 'info', 'label' => $t('payroll.slip_new')]) : $ui('badge', ['tone' => 'neutral', 'label' => $t('payroll.slip_seen')]) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="4"><?= $ui('states', ['icon' => 'receipt', 'title' => $t('payroll.my_none')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <p class="text-sm text-muted"><?= $e($t('payroll.my_help')) ?></p>
</section>
