<?php
/**
 * Payroll home of a company: is it ready (rules, components, pay profiles) · runs · new run.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $runs @var array<string, mixed> $ready
 * @var bool $canPrepare @var string $today @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Core\Support\Money;

$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$ok = static fn (bool $b): string => $ui('badge', ['tone' => $b ? 'success' : 'warning', 'label' => $t($b ? 'payroll.ready.ok' : 'payroll.ready.missing')]);
$here = '/payroll';
$status = '';
?>
<?= $ui('page-header', ['title' => $t('payroll.title'), 'subtitle' => $t('payroll.subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?= $ui('alert', ['tone' => 'info', 'text' => $t('payroll.not_legal_notice')]) ?>
<section class="card stack-sm" aria-labelledby="py-r">
  <h2 id="py-r" class="card__title"><?= $e($t('payroll.ready.title')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('payroll.ready.policy')) ?></dt><dd><?= $ok((bool) $ready['policy']) ?></dd></div>
    <div><dt><?= $e($t('payroll.ready.sso')) ?></dt><dd><?= $ok((bool) $ready['sso']) ?></dd></div>
    <div><dt><?= $e($t('payroll.ready.components')) ?></dt><dd><?= $ok((bool) $ready['has_base']) ?> <span class="text-sm text-muted"><?= $e($t('payroll.ready.components_n', ['n' => (string) $ready['components']])) ?></span></dd></div>
    <div><dt><?= $e($t('payroll.ready.profiles')) ?></dt><dd><?= $ok((int) $ready['no_profile'] === 0) ?> <span class="text-sm text-muted"><?= $e($t('payroll.ready.profiles_n', ['n' => (string) ((int) $ready['staff'] - (int) $ready['no_profile']), 'all' => (string) $ready['staff'], 'pending' => (string) $ready['pending_profiles']])) ?></span></dd></div>
  </dl>
</section>
<section class="card stack-sm" aria-labelledby="py-l">
  <h2 id="py-l" class="card__title"><?= $e($t('payroll.runs')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="py-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.run')) ?></th><th scope="col"><?= $e($t('payroll.field.period')) ?></th><th scope="col"><?= $e($t('payroll.col.status')) ?></th><th scope="col"><?= $e($t('payroll.col.people')) ?></th><th scope="col"><?= $e($t('payroll.col.net')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($runs as $r): $status = (string) $r['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.run')) ?>"><a href="<?= $e($url('/payroll/runs/' . $r['public_id'])) ?>"><?= $e('#' . $r['run_no'] . ' · ' . $r['label']) ?></a><?php if ($r['run_type'] !== 'regular'): ?> <?= $ui('badge', ['tone' => $r['run_type'] === 'correction' ? 'warning' : 'info', 'label' => $t('payroll.run_type.' . $r['run_type'])]) ?><?php endif; ?></th>
          <td data-label="<?= $e($t('payroll.field.period')) ?>"><?= $e($date((string) $r['period_start'], false)) ?> – <?= $e($date((string) $r['period_end'], false)) ?></td>
          <td data-label="<?= $e($t('payroll.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?></td>
          <td data-label="<?= $e($t('payroll.col.people')) ?>"><?= (int) $r['employee_count'] ?></td>
          <td data-label="<?= $e($t('payroll.col.net')) ?>"><?= $r['status'] === 'draft' ? '—' : $e(Money::format(Money::fromDb($r['net_total']))) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($runs === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'report-money', 'title' => $t('payroll.runs_none')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php if ($canPrepare): ?>
<section class="card stack" aria-labelledby="py-n">
  <h2 id="py-n" class="card__title"><?= $e($t('payroll.new_run')) ?></h2>
  <form method="post" action="<?= $e($url('/payroll/runs')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'type', 'type' => 'select', 'label' => $t('payroll.field.run_type'), 'required' => true, 'value' => $o('type') !== '' ? $o('type') : 'regular',
          'options' => ['regular' => $t('payroll.run_type.regular'), 'off_cycle' => $t('payroll.run_type.off_cycle')], 'help' => $t('payroll.run_type_help')]) ?>
      <?= $ui('field', ['name' => 'label', 'label' => $t('payroll.field.label'), 'required' => true, 'value' => $o('label'), 'help' => $t('payroll.label_help'), 'error' => $errors['label'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'purpose', 'label' => $t('payroll.field.purpose'), 'value' => $o('purpose'), 'help' => $t('payroll.purpose_offcycle_help'), 'error' => $errors['purpose'] ?? null]) ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'period_start', 'type' => 'date', 'label' => $t('payroll.field.period_start'), 'required' => true, 'value' => $o('period_start'), 'error' => $errors['period_start'] ?? null]) ?>
      <?= $ui('field', ['name' => 'period_end', 'type' => 'date', 'label' => $t('payroll.field.period_end'), 'required' => true, 'value' => $o('period_end'), 'error' => $errors['period_end'] ?? null]) ?>
      <?= $ui('field', ['name' => 'pay_date', 'type' => 'date', 'label' => $t('payroll.field.pay_date'), 'required' => true, 'value' => $o('pay_date'), 'error' => $errors['pay_date'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
