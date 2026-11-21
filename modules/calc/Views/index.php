<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $asset @var array<string, mixed> $v
 * @var string $tab @var array<string, mixed> $in @var array<string, mixed>|null $out @var array<string, mixed>|null $rule
 * @var array<string, string> $errors @var list<array<string, mixed>> $allowances @var bool $canRates @var list<array<string, mixed>> $shares @var string $today
 */
use Blue\Modules\Calc\CalcController;

$guest = isset($v['ctx']) && is_object($v['ctx']) && $v['ctx']->isGuest(); // 0.8.1: a visitor uses the tool but cannot share or save
$tabs = [...CalcController::CLIENT_TABS, ...CalcController::SERVER_TABS];
if ((string) ($in['at'] ?? '') === '') {
    $in['at'] = $today;
}
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$f = static fn (string $name, string $label, array $extra = []) => $ui('field', ['name' => $name, 'label' => $label, 'value' => (string) ($in[$name] ?? ''), 'error' => $errors[$name] ?? null] + $extra);
?>
<?= $ui('page-header', ['title' => $t('calc.title'), 'subtitle' => $t('calc.subtitle'),
    'actions' => $canRates ? $ui('button', ['label' => $t('calc.rates.title'), 'href' => $url('/calc/rates'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<nav class="tabs__list subnav" aria-label="<?= $e($t('calc.title')) ?>">
  <?php foreach ($tabs as $k): ?>
    <a class="tabs__tab" href="<?= $e($url('/calc?tab=' . $k)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $e($t('calc.tab.' . $k)) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'basic'): ?>
<div class="calc-basic" data-calc="basic">
<section class="card stack calc-pad" aria-labelledby="c-h">
  <div class="row row--between">
    <h2 id="c-h" class="card__title"><?= $e($t('calc.tab.basic')) ?></h2>
    <?= $ui('button', ['label' => $t('calc.clear'), 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'x', 'attrs' => ['data-calc-clear' => '1']]) ?>
  </div>
  <label class="sr-only" for="calc-expr"><?= $e($t('calc.expression')) ?></label>
  <input class="input calc-pad__expr mono" id="calc-expr" inputmode="decimal" autocomplete="off" placeholder="2 × (3 + 4)" data-calc-expr>
  <output class="calc-pad__result mono" aria-live="polite" data-calc-out>0</output>
  <div class="calc-pad__keys" role="group" aria-label="<?= $e($t('calc.keypad')) ?>">
    <?php foreach (['sin', 'cos', 'tan', 'log', 'ln', '√', 'x²', '^', '(', ')', '7', '8', '9', '÷', 'C', '4', '5', '6', '×', '⌫', '1', '2', '3', '−', '%', '0', '.', 'π', 'e', '+', 'deg', '='] as $k):
        $cls = $k === '=' ? ' calc-key--eq' : ($k === 'deg' ? ' calc-key--wide' : (ctype_digit($k) || $k === '.' ? ' calc-key--num' : '')); ?>
      <button type="button" class="calc-key<?= $cls ?>" data-key="<?= $e($k) ?>"<?= $k === 'deg' ? ' aria-pressed="false" data-angle' : '' ?>><?= $e($k === 'deg' ? 'DEG' : $k) ?></button>
    <?php endforeach; ?>
  </div>
  <p class="text-sm text-muted"><?= $e($t('calc.basic_help')) ?></p>
</section>
<section class="card stack-sm calc-history" aria-labelledby="ch-h">
  <div class="row row--between">
    <h2 id="ch-h" class="card__title"><?= $e($t('calc.history')) ?></h2>
    <?= $ui('button', ['label' => $t('calc.history_clear'), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash', 'attrs' => ['data-calc-history-clear' => '1']]) ?>
  </div>
  <p class="text-sm text-muted" data-calc-history-empty><?= $e($t('calc.history_empty')) ?></p>
  <ol class="calc-history__list" data-calc-history aria-live="polite"></ol>
  <p class="text-sm text-muted"><?= $e($t('calc.history_help')) ?></p>
</section>
</div>

<?php elseif ($tab === 'age' || $tab === 'service'): ?>
<section class="card stack" data-calc="<?= $tab ?>" aria-labelledby="c-h">
  <h2 id="c-h" class="card__title"><?= $e($t('calc.tab.' . $tab)) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('calc.about.' . $tab)) ?></p>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'from', 'id' => 'f-c-from', 'type' => 'date', 'label' => $t($tab === 'age' ? 'calc.field.birth' : 'calc.field.start'), 'data' => ['calc-in' => 'from']]) ?>
    <?= $ui('field', ['name' => 'to', 'id' => 'f-c-to', 'type' => 'date', 'label' => $t($tab === 'age' ? 'calc.field.as_of' : 'calc.field.end'), 'value' => $today, 'data' => ['calc-in' => 'to']]) ?>
  </div>
  <?php if ($tab === 'service'): ?><label class="check"><input type="checkbox" data-calc-in="inclusive"> <?= $e($t('calc.field.inclusive')) ?></label><?php endif; ?>
  <output class="calc-result" aria-live="polite" data-calc-out data-t-ymd="<?= $e($t('calc.out.ymd')) ?>" data-t-days="<?= $e($t('calc.out.days')) ?>" data-t-next="<?= $e($t('calc.out.next_birthday')) ?>" data-t-bad="<?= $e($t('calc.error.dates_order')) ?>"></output>
  <div class="row"><?= $ui('button', ['label' => $t('calc.clear'), 'variant' => 'secondary', 'icon' => 'x', 'attrs' => ['data-calc-clear' => '1']]) ?></div>
</section>

<?php elseif ($tab === 'unit'): ?>
<section class="card stack" data-calc="unit" aria-labelledby="c-h">
  <h2 id="c-h" class="card__title"><?= $e($t('calc.tab.unit')) ?></h2>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'kind', 'id' => 'f-u-kind', 'type' => 'select', 'label' => $t('calc.field.unit_kind'), 'data' => ['calc-in' => 'kind'],
        'options' => ['weight' => $t('calc.unit.weight'), 'temperature' => $t('calc.unit.temperature'), 'length' => $t('calc.unit.length'), 'area' => $t('calc.unit.area')]]) ?>
    <?= $ui('field', ['name' => 'value', 'id' => 'f-u-value', 'label' => $t('calc.field.value'), 'value' => '1', 'inputmode' => 'decimal', 'data' => ['calc-in' => 'value']]) ?>
  </div>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'from_unit', 'id' => 'f-u-from', 'type' => 'select', 'label' => $t('calc.field.from_unit'), 'options' => [], 'data' => ['calc-in' => 'from']]) ?>
    <?= $ui('field', ['name' => 'to_unit', 'id' => 'f-u-to', 'type' => 'select', 'label' => $t('calc.field.to_unit'), 'options' => [], 'data' => ['calc-in' => 'to']]) ?>
  </div>
  <output class="calc-result mono" aria-live="polite" data-calc-out data-unit-labels="<?= $e(json_encode([
      'kg' => $t('calc.u.kg'), 'g' => $t('calc.u.g'), 'mg' => $t('calc.u.mg'), 't' => $t('calc.u.t'), 'lb' => $t('calc.u.lb'), 'oz' => $t('calc.u.oz'),
      'C' => $t('calc.u.C'), 'F' => $t('calc.u.F'), 'K' => $t('calc.u.K'),
      'm' => $t('calc.u.m'), 'cm' => $t('calc.u.cm'), 'mm' => $t('calc.u.mm'), 'km' => $t('calc.u.km'), 'in' => $t('calc.u.in'), 'ft' => $t('calc.u.ft'), 'yd' => $t('calc.u.yd'), 'mi' => $t('calc.u.mi'), 'wa' => $t('calc.u.wa'),
      'm2' => $t('calc.u.m2'), 'km2' => $t('calc.u.km2'), 'rai' => $t('calc.u.rai'), 'ngan' => $t('calc.u.ngan'), 'sqwa' => $t('calc.u.sqwa'), 'ha' => $t('calc.u.ha'), 'acre' => $t('calc.u.acre'), 'ft2' => $t('calc.u.ft2'),
  ], JSON_UNESCAPED_UNICODE)) ?>"></output>
  <div class="row"><?= $ui('button', ['label' => $t('calc.clear'), 'variant' => 'secondary', 'icon' => 'x', 'attrs' => ['data-calc-clear' => '1']]) ?></div>
</section>

<?php elseif ($tab === 'bmi'): ?>
<section class="card stack" data-calc="bmi" aria-labelledby="c-h">
  <h2 id="c-h" class="card__title"><?= $e($t('calc.tab.bmi')) ?></h2>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('calc.bmi_private')]) ?>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'weight', 'id' => 'f-b-w', 'label' => $t('calc.field.weight_kg'), 'inputmode' => 'decimal', 'data' => ['calc-in' => 'w']]) ?>
    <?= $ui('field', ['name' => 'height', 'id' => 'f-b-h', 'label' => $t('calc.field.height_cm'), 'inputmode' => 'decimal', 'data' => ['calc-in' => 'h']]) ?>
  </div>
  <output class="calc-result" aria-live="polite" data-calc-out data-t="<?= $e(json_encode(['value' => $t('calc.out.bmi'), 'under' => $t('calc.bmi.under'), 'normal' => $t('calc.bmi.normal'), 'over' => $t('calc.bmi.over'), 'obese' => $t('calc.bmi.obese')], JSON_UNESCAPED_UNICODE)) ?>"></output>
  <p class="text-sm text-muted"><?= $e($t('calc.bmi_note')) ?></p>
  <div class="row"><?= $ui('button', ['label' => $t('calc.clear'), 'variant' => 'secondary', 'icon' => 'x', 'attrs' => ['data-calc-clear' => '1']]) ?></div>
</section>

<?php else: /* legal calculators — server side, approved tables */ ?>
<div class="settings-grid">
  <form method="post" action="<?= $e($url('/calc/' . $tab)) ?>" class="card stack" novalidate aria-labelledby="c-h">
    <h2 id="c-h" class="card__title"><?= $e($t('calc.tab.' . $tab)) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('calc.about.' . $tab)) ?></p>
    <?= $csrf ?>
    <?= $f('at', $t('calc.field.reference_date'), ['type' => 'date', 'help' => $t('calc.reference_help')]) ?>
    <?php if ($tab === 'sso' || $tab === 'ewf'): ?>
      <?= $f('wage', $t('calc.field.monthly_wage'), ['required' => true, 'inputmode' => 'decimal', 'prefix' => 'THB']) ?>
    <?php elseif ($tab === 'severance' || $tab === 'retirement'): ?>
      <div class="grid grid--2">
        <?= $f('start', $t('calc.field.start'), ['type' => 'date', 'required' => true]) ?>
        <?= $f('end', $t($tab === 'retirement' ? 'calc.field.retire_date' : 'calc.field.end_employment'), ['type' => 'date', 'required' => true]) ?>
      </div>
      <div class="grid grid--2">
        <?= $f('wage', $t('calc.field.last_wage'), ['required' => true, 'inputmode' => 'decimal', 'prefix' => 'THB']) ?>
        <?= $ui('field', ['name' => 'wage_type', 'type' => 'select', 'label' => $t('calc.field.wage_type'), 'value' => (string) ($in['wage_type'] ?? 'monthly'),
            'options' => ['monthly' => $t('calc.wage_type.monthly'), 'daily' => $t('calc.wage_type.daily')]]) ?>
      </div>
    <?php elseif ($tab === 'prize'): ?>
      <?= $f('value', $t('calc.field.prize_value'), ['required' => true, 'inputmode' => 'decimal', 'prefix' => 'THB', 'help' => $t('calc.prize_value_help')]) ?>
      <?= $ui('field', ['name' => 'mode', 'id' => 'f-mode', 'type' => 'select', 'label' => $t('calc.field.prize_mode'), 'value' => (string) ($in['mode'] ?? 'withhold'),
          'options' => ['withhold' => $t('calc.prize_mode.withhold'), 'grossup' => $t('calc.prize_mode.grossup')], 'help' => $t('calc.prize_mode_help')]) ?>
    <?php else: ?>
      <?= $f('income', $t('calc.field.annual_income'), ['required' => true, 'inputmode' => 'decimal', 'prefix' => 'THB', 'help' => $t('calc.income_help')]) ?>
      <?php foreach ($allowances as $i => $a): ?>
        <?= $ui('field', ['name' => 'allowance[' . $i . ']', 'id' => 'f-allow-' . $i, 'label' => (string) $a['label'], 'value' => (string) (($in['allowance'] ?? [])[$i] ?? ''), 'inputmode' => 'decimal',
            'prefix' => 'THB', 'help' => $t('calc.allowance_cap', ['cap' => (string) $a['cap']])]) ?>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="row">
      <?= $ui('button', ['label' => $t('calc.calculate'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'calculator', 'disabled' => ($rule['state'] ?? '') !== 'ok' && $out === null, 'disabledReason' => $t('calc.no_rule_short')]) ?>
      <?= $ui('button', ['label' => $t('calc.clear'), 'href' => $url('/calc?tab=' . $tab), 'variant' => 'ghost', 'icon' => 'x']) ?>
      <?php if ($out !== null && $out['result'] !== null && !$guest): ?>
        <?= $ui('button', ['label' => $t('calc.share'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'send', 'attrs' => ['formaction' => $url('/calc/' . $tab . '/share')]]) ?>
      <?php endif; ?>
    </div>
  </form>

  <div class="stack">
    <?php include __DIR__ . '/_rule.php'; ?>
    <?php if ($out !== null && $out['result'] !== null): $res = $out['result']; ?>
    <section class="card stack-sm" aria-labelledby="r-h" aria-live="polite">
      <h2 id="r-h" class="card__title"><?= $e($t('calc.result')) ?></h2>
      <div class="calc-outputs">
        <?php foreach ($res['outputs'] as $k => $val): ?>
          <div class="stat"><div class="stat__label"><?= $e($t('calc.out.' . $tab . '.' . $k)) ?></div><div class="stat__value stat__value--sm mono"><?= $e($val) ?></div></div>
        <?php endforeach; ?>
      </div>
      <?php include __DIR__ . '/_steps.php'; ?>
      <?= $ui('alert', ['tone' => 'warning', 'text' => $t('calc.estimate_only')]) ?>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($shares !== []): ?>
<section class="card stack-sm" aria-labelledby="sh-h">
  <h2 id="sh-h" class="card__title"><?= $e($t('calc.my_shares')) ?></h2>
  <ul class="plain-list">
    <?php foreach ($shares as $s): ?>
      <li><a href="<?= $e($url('/calc/s/' . $s['token'])) ?>"><?= $e($t('calc.tab.' . $s['calculator'])) ?></a> · <span class="text-sm text-muted"><?= $e($t('calc.expires_on', ['at' => $date((string) $s['expires_at'])])) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<script src="<?= $e($asset('ui/js/calc.js')) ?>" defer></script>
