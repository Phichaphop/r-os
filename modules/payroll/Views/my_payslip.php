<?php
/**
 * One of my payslips (after the password): earnings, deductions, net, year-to-date, PDF.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $slip
 */
use Blue\Core\Support\Money;

$m = static fn (mixed $x): string => Money::format(Money::fromDb($x));
$u = static fn (int $x): string => Money::format($x);
$part = static fn (string $kind): array => array_values(array_filter($slip['lines'], static fn (array $l): bool => $l['kind'] === $kind));
?>
<?= $ui('page-header', ['title' => (string) $slip['label'], 'subtitle' => $slip['le_code'] . ' · ' . $t('payroll.field.pay_date') . ' ' . $date((string) $slip['pay_date'], false), 'crumbs' => [[$t('payroll.my_title'), $url('/me/payslips')], [$date((string) $slip['pay_date'], false), null]]]) ?>
<div class="grid grid--2">
<?php foreach (['earning' => 'payroll.kind.earning', 'deduction' => 'payroll.kind.deduction'] as $kind => $label): ?>
<section class="card stack-sm" aria-labelledby="mp-<?= $e($kind) ?>">
  <h2 id="mp-<?= $e($kind) ?>" class="card__title"><?= $e($t($label)) ?></h2>
  <dl class="facts">
    <?php foreach ($part($kind) as $l): ?><div><dt><?= $e((string) $l['name_th']) ?></dt><dd><?= $e($m($l['amount'])) ?></dd></div><?php endforeach; ?>
    <?php if ($part($kind) === []): ?><div><dt>—</dt><dd>0.00</dd></div><?php endif; ?>
    <div><dt><strong><?= $e($t($kind === 'earning' ? 'payroll.col.gross' : 'payroll.col.deductions')) ?></strong></dt><dd><strong><?= $e($m($slip[$kind === 'earning' ? 'gross' : 'deductions'])) ?></strong></dd></div>
  </dl>
</section>
<?php endforeach; ?>
</div>
<section class="card stack-sm" aria-labelledby="mp-n">
  <h2 id="mp-n" class="card__title"><?= $e($t('payroll.col.net')) ?>: <?= $e($m($slip['net'])) ?></h2>
  <p><?= $ui('button', ['label' => $t('payroll.download_pdf'), 'href' => $url('/me/payslips/' . $slip['public_id'] . '/pdf'), 'variant' => 'primary', 'icon' => 'download']) ?></p>
</section>
<section class="card stack-sm" aria-labelledby="mp-y">
  <h2 id="mp-y" class="card__title"><?= $e($t('payroll.ytd_title', ['date' => $date((string) $slip['pay_date'], false)])) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('payroll.ytd.gross')) ?></dt><dd><?= $e($u($slip['ytd']['gross'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.ytd.taxable')) ?></dt><dd><?= $e($u($slip['ytd']['taxable'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.ytd.wht')) ?></dt><dd><?= $e($u($slip['ytd']['wht'])) ?></dd></div>
    <div><dt><?= $e($t('payroll.ytd.sso')) ?></dt><dd><?= $e($u($slip['ytd']['sso'])) ?></dd></div>
  </dl>
  <p class="text-sm text-muted"><?= $e($t('payroll.slip_help')) ?></p>
</section>
