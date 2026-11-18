<?php
/**
 * Payroll rules of a company (Config Registry): company pay rules and social security parameters — values in force,
 * changes waiting for approval, propose a new value (with its source; another person approves).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array{policy: ?\Blue\Core\Registry\ResolvedConfig, sso: ?\Blue\Core\Registry\ResolvedConfig} $rules
 * @var list<array{0: string, 1: string}> $policyRows @var list<array{0: string, 1: string}> $ssoRows @var list<array{0: string, 1: string}> $whtRows @var list<array{0: string, 1: string}> $payerRows
 * @var list<array{0: string, 1: string}> $ssoEmployerRows @var list<array{0: string, 1: string}> $dueRows
 * @var array<string, int> $pending @var bool $canPrepare @var string $today @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
use Blue\Modules\Payroll\PayrollRules;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$here = '/payroll/settings';
$o = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$common = static function (string $f) use ($ui, $t, $o, $err, $today): string {
    return '<div class="grid grid--2">'
        . $ui('field', ['name' => 'effective_from', 'id' => "f-{$f}-from", 'type' => 'date', 'label' => $t('payroll.field.effective_from'), 'required' => true, 'value' => $o($f, 'effective_from', $today), 'error' => $err($f, 'effective_from')])
        . $ui('field', ['name' => 'source_reference', 'id' => "f-{$f}-src", 'label' => $t('payroll.field.source_reference'), 'required' => true, 'value' => $o($f, 'source_reference'), 'help' => $t('payroll.source_help_' . $f), 'error' => $err($f, 'source_reference')])
        . '</div>'
        . $ui('field', ['name' => 'reason', 'id' => "f-{$f}-reason", 'label' => $t('payroll.field.reason'), 'required' => true, 'value' => $o($f, 'reason'), 'error' => $err($f, 'reason')]);
};
?>
<?= $ui('page-header', ['title' => $t('payroll.settings_title'), 'subtitle' => $t('payroll.settings_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?= $ui('alert', ['tone' => 'warning', 'text' => $t('payroll.no_defaults')]) ?>
<div class="grid grid--2">
<section class="card stack" aria-labelledby="ps-p">
  <h2 id="ps-p" class="card__title"><?= $e($t('payroll.policy_title')) ?></h2>
  <dl class="facts">
    <?php foreach ($policyRows as [$k, $val]): ?><div><dt><?= $e($k) ?></dt><dd><?= $e($val !== '' ? $val : '—') ?></dd></div><?php endforeach; ?>
  </dl>
  <?php if ($pending['payroll.policy'] > 0): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'info', 'label' => $t('payroll.change_waiting')]) ?></p><?php endif; ?>
</section>
<section class="card stack" aria-labelledby="ps-s">
  <h2 id="ps-s" class="card__title"><?= $e($t('payroll.sso_title')) ?></h2>
  <dl class="facts">
    <?php foreach ($ssoRows as [$k, $val]): ?><div><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd></div><?php endforeach; ?>
  </dl>
  <?php if ($pending['payroll.sso'] > 0): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'info', 'label' => $t('payroll.change_waiting')]) ?></p><?php endif; ?>
</section>
</div>
<div class="grid grid--2">
<section class="card stack" aria-labelledby="ps-w">
  <h2 id="ps-w" class="card__title"><?= $e($t('payroll.wht_title')) ?></h2>
  <?php if ($whtRows === []): ?><p class="text-sm text-muted"><?= $e($t('payroll.wht_none')) ?></p><?php else: ?>
  <dl class="facts"><?php foreach ($whtRows as [$k, $val]): ?><div><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd></div><?php endforeach; ?></dl>
  <?php endif; ?>
  <?php if ($pending['payroll.wht'] > 0): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'info', 'label' => $t('payroll.change_waiting')]) ?></p><?php endif; ?>
</section>
<section class="card stack" aria-labelledby="ps-y">
  <h2 id="ps-y" class="card__title"><?= $e($t('payroll.payer_title')) ?></h2>
  <dl class="facts"><?php foreach ($payerRows as [$k, $val]): ?><div><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd></div><?php endforeach; ?></dl>
  <?php if ($pending['payroll.wht_payer'] > 0): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'info', 'label' => $t('payroll.change_waiting')]) ?></p><?php endif; ?>
</section>
</div>
<div class="grid grid--2">
<section class="card stack" aria-labelledby="ps-se">
  <h2 id="ps-se" class="card__title"><?= $e($t('payroll.sso_employer_title')) ?></h2>
  <dl class="facts"><?php foreach ($ssoEmployerRows as [$k, $val]): ?><div><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd></div><?php endforeach; ?></dl>
  <?php if ($pending['payroll.sso_employer'] > 0): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'info', 'label' => $t('payroll.change_waiting')]) ?></p><?php endif; ?>
</section>
<section class="card stack" aria-labelledby="ps-du">
  <h2 id="ps-du" class="card__title"><?= $e($t('payroll.filing_due_title')) ?></h2>
  <dl class="facts"><?php foreach ($dueRows as [$k, $val]): ?><div><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd></div><?php endforeach; ?></dl>
  <p class="text-sm text-muted"><?= $e($t('payroll.filing_due_help')) ?></p>
  <?php if ($pending['payroll.filing_due'] > 0): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'info', 'label' => $t('payroll.change_waiting')]) ?></p><?php endif; ?>
</section>
</div>
<?php if ($canPrepare): ?>
<div class="grid grid--2">
<section class="card stack" aria-labelledby="ps-pn">
  <h2 id="ps-pn" class="card__title"><?= $e($t('payroll.propose_policy')) ?></h2>
  <form method="post" action="<?= $e($url('/payroll/settings/policy')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'monthly_divisor', 'type' => 'number', 'label' => $t('payroll.rules.monthly_divisor'), 'required' => true, 'value' => $o('policy', 'monthly_divisor'), 'help' => $t('payroll.rules.monthly_divisor_help'), 'error' => $err('policy', 'monthly_divisor')]) ?>
      <?= $ui('field', ['name' => 'hours_per_day', 'label' => $t('payroll.rules.hours_per_day'), 'required' => true, 'value' => $o('policy', 'hours_per_day'), 'inputmode' => 'decimal', 'error' => $err('policy', 'hours_per_day')]) ?>
    </div>
    <?= $ui('field', ['name' => 'proration', 'type' => 'select', 'label' => $t('payroll.rules.proration'), 'required' => true, 'value' => $o('policy', 'proration'), 'options' => ['' => '—', 'calendar_days' => $t('payroll.proration.calendar_days'), 'divisor_days' => $t('payroll.proration.divisor_days')], 'error' => $err('policy', 'proration')]) ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('payroll.rules.daily_paid')) ?></legend>
      <?php foreach (PayrollRules::DAILY_PAID as $d): ?><label class="check"><input type="checkbox" name="daily_paid[]" value="<?= $e($d) ?>"<?= $form === 'policy' && in_array($d, (array) ($old['daily_paid'] ?? []), true) ? ' checked' : '' ?>> <?= $e($t('payroll.daily_paid.' . $d)) ?></label><?php endforeach; ?>
      <?php if ($err('policy', 'daily_paid') !== null): ?><p class="field__error"><?= $e($t((string) $err('policy', 'daily_paid'))) ?></p><?php endif; ?>
    </fieldset>
    <?= $common('policy') ?>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.propose'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<section class="card stack" aria-labelledby="ps-sn">
  <h2 id="ps-sn" class="card__title"><?= $e($t('payroll.propose_sso')) ?></h2>
  <form method="post" action="<?= $e($url('/payroll/settings/sso')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'rate_employee', 'label' => $t('payroll.rules.rate_employee'), 'required' => true, 'value' => $o('sso', 'rate_employee'), 'inputmode' => 'decimal', 'error' => $err('sso', 'rate_employee')]) ?>
      <?= $ui('field', ['name' => 'rate_employer', 'label' => $t('payroll.rules.rate_employer'), 'required' => true, 'value' => $o('sso', 'rate_employer'), 'inputmode' => 'decimal', 'error' => $err('sso', 'rate_employer')]) ?>
      <?= $ui('field', ['name' => 'wage_floor', 'type' => 'money', 'label' => $t('payroll.rules.wage_floor'), 'required' => true, 'value' => $o('sso', 'wage_floor'), 'error' => $err('sso', 'wage_floor')]) ?>
      <?= $ui('field', ['name' => 'wage_cap', 'type' => 'money', 'label' => $t('payroll.rules.wage_cap'), 'required' => true, 'value' => $o('sso', 'wage_cap'), 'error' => $err('sso', 'wage_cap')]) ?>
    </div>
    <?= $ui('field', ['name' => 'rounding', 'type' => 'select', 'label' => $t('payroll.rules.rounding'), 'required' => true, 'value' => $o('sso', 'rounding'), 'options' => ['' => '—', 'half_up_baht' => $t('payroll.rounding.half_up_baht'), 'down_baht' => $t('payroll.rounding.down_baht'), 'half_up_satang' => $t('payroll.rounding.half_up_satang')], 'error' => $err('sso', 'rounding')]) ?>
    <?= $common('sso') ?>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.propose'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
</div>
<section class="card stack" aria-labelledby="ps-wn">
  <h2 id="ps-wn" class="card__title"><?= $e($t('payroll.propose_wht')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('payroll.wht_help')) ?></p>
  <form method="post" action="<?= $e($url('/payroll/settings/wht')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?= $ui('field', ['name' => 'method', 'type' => 'select', 'label' => $t('payroll.tax.method'), 'required' => true, 'value' => $o('wht', 'method'), 'options' => ['' => '—', 'cumulative' => $t('payroll.tax.method.cumulative'), 'annualize_current' => $t('payroll.tax.method.annualize_current')], 'help' => $t('payroll.tax.method_help'), 'error' => $err('wht', 'method')]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'expense_rate', 'label' => $t('payroll.tax.expense_rate'), 'required' => true, 'value' => $o('wht', 'expense_rate'), 'inputmode' => 'decimal', 'error' => $err('wht', 'expense_rate')]) ?>
      <?= $ui('field', ['name' => 'expense_cap', 'type' => 'money', 'label' => $t('payroll.tax.expense_cap'), 'required' => true, 'value' => $o('wht', 'expense_cap'), 'error' => $err('wht', 'expense_cap')]) ?>
      <?= $ui('field', ['name' => 'personal_allowance', 'type' => 'money', 'label' => $t('payroll.tax.personal_allowance'), 'required' => true, 'value' => $o('wht', 'personal_allowance'), 'error' => $err('wht', 'personal_allowance')]) ?>
      <?= $ui('field', ['name' => 'sso_allowance_cap', 'type' => 'money', 'label' => $t('payroll.tax.sso_allowance_cap'), 'value' => $o('wht', 'sso_allowance_cap'), 'help' => $t('payroll.tax.sso_allowance_help'), 'error' => $err('wht', 'sso_allowance_cap')]) ?>
    </div>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('payroll.tax.brackets')) ?></legend>
      <p class="text-sm text-muted"><?= $e($t('payroll.tax.brackets_help')) ?></p>
      <?php $ob = $form === 'wht' ? array_values((array) ($old['brackets'] ?? [])) : []; ?>
      <?php for ($i = 0; $i < 8; $i++): ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => "brackets[{$i}][up_to]", 'id' => "f-br-{$i}-u", 'type' => 'money', 'label' => $t('payroll.tax.up_to', ['n' => (string) ($i + 1)]), 'value' => (string) ($ob[$i]['up_to'] ?? ''), 'error' => $err('wht', "brackets.{$i}.up_to")]) ?>
        <?= $ui('field', ['name' => "brackets[{$i}][rate]", 'id' => "f-br-{$i}-r", 'label' => $t('payroll.tax.rate', ['n' => (string) ($i + 1)]), 'inputmode' => 'decimal', 'value' => (string) ($ob[$i]['rate'] ?? ''), 'error' => $err('wht', "brackets.{$i}.rate")]) ?>
      </div>
      <?php endfor; ?>
      <?php if ($err('wht', 'brackets') !== null): ?><p class="field__error"><?= $e($t((string) $err('wht', 'brackets'))) ?></p><?php endif; ?>
    </fieldset>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('payroll.tax.allowances')) ?></legend>
      <p class="text-sm text-muted"><?= $e($t('payroll.tax.allowances_help')) ?></p>
      <?php $oa = $form === 'wht' ? array_values((array) ($old['allowances'] ?? [])) : []; ?>
      <?php for ($i = 0; $i < 8; $i++): ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => "allowances[{$i}][code]", 'id' => "f-al-{$i}-c", 'label' => $t('payroll.tax.a_code', ['n' => (string) ($i + 1)]), 'value' => (string) ($oa[$i]['code'] ?? ''), 'error' => $err('wht', "allowances.{$i}.code")]) ?>
        <?= $ui('field', ['name' => "allowances[{$i}][name]", 'id' => "f-al-{$i}-n", 'label' => $t('payroll.tax.a_name'), 'value' => (string) ($oa[$i]['name'] ?? ''), 'error' => $err('wht', "allowances.{$i}.name")]) ?>
        <?= $ui('field', ['name' => "allowances[{$i}][unit]", 'id' => "f-al-{$i}-t", 'type' => 'select', 'label' => $t('payroll.tax.a_unit'), 'value' => (string) ($oa[$i]['unit'] ?? 'amount'), 'options' => ['amount' => $t('payroll.tax.unit.amount'), 'count' => $t('payroll.tax.unit.count')]]) ?>
        <?= $ui('field', ['name' => "allowances[{$i}][per_unit]", 'id' => "f-al-{$i}-p", 'type' => 'money', 'label' => $t('payroll.tax.a_per_unit'), 'value' => (string) ($oa[$i]['per_unit'] ?? ''), 'error' => $err('wht', "allowances.{$i}.per_unit")]) ?>
        <?= $ui('field', ['name' => "allowances[{$i}][cap]", 'id' => "f-al-{$i}-x", 'type' => 'money', 'label' => $t('payroll.tax.a_cap'), 'value' => (string) ($oa[$i]['cap'] ?? ''), 'error' => $err('wht', "allowances.{$i}.cap")]) ?>
      </div>
      <?php endfor; ?>
    </fieldset>
    <?= $ui('field', ['name' => 'rounding', 'id' => 'f-wht-rounding', 'type' => 'select', 'label' => $t('payroll.tax.rounding'), 'required' => true, 'value' => $o('wht', 'rounding'), 'options' => ['' => '—', 'half_up_satang' => $t('payroll.rounding.half_up_satang'), 'half_up_baht' => $t('payroll.rounding.half_up_baht'), 'down_baht' => $t('payroll.rounding.down_baht')], 'error' => $err('wht', 'rounding')]) ?>
    <?= $common('wht') ?>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.propose'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<section class="card stack" aria-labelledby="ps-yn">
  <h2 id="ps-yn" class="card__title"><?= $e($t('payroll.propose_payer')) ?></h2>
  <form method="post" action="<?= $e($url('/payroll/settings/wht_payer')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?= $ui('field', ['name' => 'tax_id', 'label' => $t('payroll.payer.tax_id'), 'required' => true, 'value' => $o('wht_payer', 'tax_id'), 'inputmode' => 'numeric', 'error' => $err('wht_payer', 'tax_id')]) ?>
    <?= $ui('field', ['name' => 'name', 'id' => 'f-payer-name', 'label' => $t('payroll.payer.name'), 'required' => true, 'value' => $o('wht_payer', 'name'), 'error' => $err('wht_payer', 'name')]) ?>
    <?= $ui('field', ['name' => 'address', 'type' => 'textarea', 'label' => $t('payroll.payer.address'), 'required' => true, 'value' => $o('wht_payer', 'address'), 'error' => $err('wht_payer', 'address')]) ?>
    <?= $ui('field', ['name' => 'branch', 'label' => $t('payroll.payer.branch'), 'value' => $o('wht_payer', 'branch'), 'help' => $t('payroll.payer.branch_help'), 'error' => $err('wht_payer', 'branch')]) ?>
    <?= $common('wht_payer') ?>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.propose'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<div class="grid grid--2">
<section class="card stack" aria-labelledby="ps-sen">
  <h2 id="ps-sen" class="card__title"><?= $e($t('payroll.propose_sso_employer')) ?></h2>
  <form method="post" action="<?= $e($url('/payroll/settings/sso_employer')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'account_no', 'label' => $t('payroll.sso_employer.account_no'), 'required' => true, 'value' => $o('sso_employer', 'account_no'), 'inputmode' => 'numeric', 'help' => $t('payroll.sso_employer.account_help'), 'error' => $err('sso_employer', 'account_no')]) ?>
      <?= $ui('field', ['name' => 'branch_no', 'label' => $t('payroll.sso_employer.branch_no'), 'value' => $o('sso_employer', 'branch_no'), 'inputmode' => 'numeric', 'error' => $err('sso_employer', 'branch_no')]) ?>
    </div>
    <?= $ui('field', ['name' => 'name', 'id' => 'f-sse-name', 'label' => $t('payroll.sso_employer.name'), 'required' => true, 'value' => $o('sso_employer', 'name'), 'error' => $err('sso_employer', 'name')]) ?>
    <?= $common('sso_employer') ?>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.propose'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<section class="card stack" aria-labelledby="ps-dun">
  <h2 id="ps-dun" class="card__title"><?= $e($t('payroll.propose_filing_due')) ?></h2>
  <form method="post" action="<?= $e($url('/payroll/settings/filing_due')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'wht_day', 'type' => 'number', 'label' => $t('payroll.filing_due.wht_day'), 'required' => true, 'value' => $o('filing_due', 'wht_day'), 'help' => $t('payroll.filing_due.day_help'), 'error' => $err('filing_due', 'wht_day')]) ?>
      <?= $ui('field', ['name' => 'sso_day', 'type' => 'number', 'label' => $t('payroll.filing_due.sso_day'), 'required' => true, 'value' => $o('filing_due', 'sso_day'), 'help' => $t('payroll.filing_due.day_help'), 'error' => $err('filing_due', 'sso_day')]) ?>
    </div>
    <?= $common('filing_due') ?>
    <div class="row"><?= $ui('button', ['label' => $t('payroll.propose'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
</div>
<?php endif; ?>
