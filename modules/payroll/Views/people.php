<?php
/**
 * People of a company and their pay profile in force today (no amounts in the list — open a person to see them).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows
 */
$here = '/payroll/people';
?>
<?= $ui('page-header', ['title' => $t('payroll.people_title'), 'subtitle' => $t('payroll.people_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<section class="card stack-sm" aria-labelledby="pp-l">
  <h2 id="pp-l" class="card__title"><?= $e($t('payroll.people_title')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="pp-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.employee')) ?></th><th scope="col"><?= $e($t('payroll.field.basis')) ?></th><th scope="col"><?= $e($t('payroll.field.effective_from')) ?></th><th scope="col"><?= $e($t('payroll.field.bank')) ?></th><th scope="col"><?= $e($t('payroll.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): $pr = $p['profile']; ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.employee')) ?>"><a href="<?= $e($url('/payroll/people/' . $p['public_id'])) ?>"><?= $e($p['employee_code'] . ' · ' . $p['name_th']) ?></a></th>
          <td data-label="<?= $e($t('payroll.field.basis')) ?>"><?= $pr !== null ? $e($t('payroll.basis.' . $pr['pay_basis'])) : '—' ?></td>
          <td data-label="<?= $e($t('payroll.field.effective_from')) ?>"><?= $pr !== null ? $e($date((string) $pr['effective_from'], false)) : '—' ?></td>
          <td data-label="<?= $e($t('payroll.field.bank')) ?>"><?= $pr !== null && $pr['account_last4'] !== null ? $e('••••' . $pr['account_last4']) : '—' ?></td>
          <td data-label="<?= $e($t('payroll.col.status')) ?>"><?= $pr === null ? $ui('badge', ['tone' => 'warning', 'label' => $t('payroll.no_profile')]) : $ui('badge', ['tone' => 'success', 'label' => $t('payroll.has_profile')]) ?><?= (int) $p['pending'] > 0 ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('payroll.pending_change')]) : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'users', 'title' => $t('payroll.people_none')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
