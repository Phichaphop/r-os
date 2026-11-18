<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, array{current: array<string, mixed>, versions: list<array<string, mixed>>}> $tables
 * @var array<string, string> $errors @var string $errType @var array<string, mixed> $old @var array<int, string> $companies
 */
use Blue\Modules\Calc\CalcRules;

$val = static function (string $type, string $field) use ($errType, $old, $tables): string {
    if ($errType === $type) {
        return (string) ($old[$field] ?? '');
    }
    $cur = $tables[$type]['current']['rule'] ?? [];
    return is_scalar($cur[$field] ?? null) ? (string) $cur[$field] : (is_bool($cur[$field] ?? null) ? ($cur[$field] ? '1' : '') : '');
};
$rows = static function (string $type, string $list, array $fields) use ($errType, $old, $tables): array {
    if ($errType === $type) {
        $src = (array) ($old[$list] ?? []);
        $out = [];
        foreach ($fields as $fld) {
            foreach ((array) ($src[$fld] ?? []) as $i => $x) {
                $out[$i][$fld] = (string) $x;
            }
        }
    } else {
        $out = array_map(static fn ($r) => array_map(static fn ($x) => $x === null ? '' : (string) $x, (array) $r), (array) ($tables[$type]['current']['rule'][$list] ?? []));
    }
    $out = array_values($out);
    while (count($out) < 8) {
        $out[] = array_fill_keys($fields, '');
    }
    return array_slice($out, 0, CalcRules::MAX_ROWS);
};
$err = static fn (string $type, string $field): ?string => $errType === $type ? ($errors[$field] ?? null) : null;
$stTone = ['approved' => 'success', 'in_review' => 'info', 'draft' => 'neutral', 'rejected' => 'danger', 'returned' => 'warning', 'cancelled' => 'neutral'];
$rounding = array_combine(CalcRules::ROUNDING, array_map(static fn ($r) => $t('calc.rounding.' . $r), CalcRules::ROUNDING));
?>
<?= $ui('page-header', ['title' => $t('calc.rates.title'), 'subtitle' => $t('calc.rates.subtitle')]) ?>
<?= $ui('alert', ['tone' => 'warning', 'title' => $t('calc.rates.no_values_title'), 'text' => $t('calc.rates.no_values_text')]) ?>
<p><a href="<?= $e($url('/calc')) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('calc.back')) ?></a></p>
<?php foreach (CalcRules::TYPES as $type): $cur = $tables[$type]['current']; ?>
<section class="card stack" id="t-<?= $type ?>" aria-labelledby="h-<?= $type ?>">
  <div class="row row--between">
    <h2 id="h-<?= $type ?>" class="card__title"><?= $e($t('calc.rates.table.' . $type)) ?></h2>
    <?= ($cur['state'] ?? '') === 'ok' ? $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('calc.rates.in_force', ['at' => $date((string) $cur['effective_from'], false)])])
        : $ui('badge', ['tone' => 'warning', 'icon' => 'alert-triangle', 'label' => $t('calc.rates.none')]) ?>
  </div>
  <details<?= $errType === $type ? ' open' : '' ?>>
    <summary class="text-sm"><?= $e($t('calc.rates.propose')) ?></summary>
    <form method="post" action="<?= $e($url('/calc/rates/' . $type)) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'effective_from', 'id' => "f-{$type}-from", 'type' => 'date', 'label' => $t('calc.rates.effective_from'), 'required' => true, 'value' => (string) ($errType === $type ? ($old['effective_from'] ?? '') : ''), 'error' => $err($type, 'effective_from')]) ?>
        <?= $ui('field', ['name' => 'legal_entity_id', 'id' => "f-{$type}-le", 'type' => 'select', 'label' => $t('calc.rates.scope'), 'value' => (string) ($errType === $type ? ($old['legal_entity_id'] ?? '') : ''), 'options' => ['' => $t('calc.rates.scope_all')] + $companies]) ?>
        <?= $ui('field', ['name' => 'source_reference', 'id' => "f-{$type}-src", 'label' => $t('calc.rates.source'), 'required' => true, 'value' => (string) ($errType === $type ? ($old['source_reference'] ?? '') : ''), 'error' => $err($type, 'source_reference'), 'help' => $t('calc.rates.source_help')]) ?>
      </div>
      <?php if ($type === 'sso' || $type === 'ewf'): ?>
        <div class="grid grid--3">
          <?= $ui('field', ['name' => 'employee_rate', 'id' => "f-{$type}-er", 'label' => $t('calc.rates.employee_rate'), 'required' => true, 'value' => $val($type, 'employee_rate'), 'error' => $err($type, 'employee_rate'), 'inputmode' => 'decimal']) ?>
          <?= $ui('field', ['name' => 'employer_rate', 'id' => "f-{$type}-rr", 'label' => $t('calc.rates.employer_rate'), 'required' => true, 'value' => $val($type, 'employer_rate'), 'error' => $err($type, 'employer_rate'), 'inputmode' => 'decimal']) ?>
          <?= $ui('field', ['name' => 'rounding', 'id' => "f-{$type}-rd", 'type' => 'select', 'label' => $t('calc.rates.rounding'), 'value' => $val($type, 'rounding') ?: 'none', 'options' => $rounding, 'error' => $err($type, 'rounding')]) ?>
        </div>
        <div class="grid grid--3">
          <?php if ($type === 'sso'): ?>
            <?= $ui('field', ['name' => 'wage_floor', 'id' => "f-{$type}-fl", 'label' => $t('calc.rates.wage_floor'), 'required' => true, 'value' => $val($type, 'wage_floor'), 'error' => $err($type, 'wage_floor'), 'inputmode' => 'decimal']) ?>
          <?php endif; ?>
          <?= $ui('field', ['name' => 'wage_ceiling', 'id' => "f-{$type}-cl", 'label' => $t('calc.rates.wage_ceiling'), 'required' => $type === 'sso', 'value' => $val($type, 'wage_ceiling'), 'error' => $err($type, 'wage_ceiling'), 'inputmode' => 'decimal']) ?>
        </div>
      <?php elseif ($type === 'severance'): ?>
        <fieldset class="stack-sm">
          <legend class="field__label"><?= $e($t('calc.rates.tiers')) ?></legend>
          <p class="text-sm text-muted"><?= $e($t('calc.rates.tiers_help')) ?></p>
          <?php if ($err($type, 'tiers') !== null): ?><p class="field__error" role="alert"><?= $e((string) $err($type, 'tiers')) ?></p><?php endif; ?>
          <div class="rate-rows">
            <div class="rate-rows__head" aria-hidden="true"><span><?= $e($t('calc.rates.min_days')) ?></span><span><?= $e($t('calc.rates.days_pay')) ?></span></div>
            <?php foreach ($rows($type, 'tiers', ['min_days', 'days_pay']) as $i => $r): ?>
              <div class="rate-rows__row">
                <input class="input" name="tiers[min_days][]" value="<?= $e($r['min_days'] ?? '') ?>" inputmode="numeric" aria-label="<?= $e($t('calc.rates.min_days') . ' ' . ($i + 1)) ?>">
                <input class="input" name="tiers[days_pay][]" value="<?= $e($r['days_pay'] ?? '') ?>" inputmode="decimal" aria-label="<?= $e($t('calc.rates.days_pay') . ' ' . ($i + 1)) ?>">
              </div>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <div class="grid grid--3">
          <?= $ui('field', ['name' => 'monthly_divisor', 'id' => "f-{$type}-div", 'label' => $t('calc.rates.monthly_divisor'), 'required' => true, 'value' => $val($type, 'monthly_divisor'), 'error' => $err($type, 'monthly_divisor'), 'inputmode' => 'numeric', 'help' => $t('calc.rates.monthly_divisor_help')]) ?>
          <?= $ui('field', ['name' => 'rounding', 'id' => "f-{$type}-rd", 'type' => 'select', 'label' => $t('calc.rates.rounding'), 'value' => $val($type, 'rounding') ?: 'none', 'options' => $rounding]) ?>
        </div>
        <label class="check"><input type="checkbox" name="count_end_day" value="1"<?= $val($type, 'count_end_day') === '1' ? ' checked' : '' ?>> <?= $e($t('calc.rates.count_end_day')) ?></label>
      <?php else: ?>
        <div class="grid grid--3">
          <?= $ui('field', ['name' => 'expense_rate', 'id' => 'f-pit-xr', 'label' => $t('calc.rates.expense_rate'), 'required' => true, 'value' => $val($type, 'expense_rate'), 'error' => $err($type, 'expense_rate'), 'inputmode' => 'decimal']) ?>
          <?= $ui('field', ['name' => 'expense_cap', 'id' => 'f-pit-xc', 'label' => $t('calc.rates.expense_cap'), 'required' => true, 'value' => $val($type, 'expense_cap'), 'error' => $err($type, 'expense_cap'), 'inputmode' => 'decimal']) ?>
          <?= $ui('field', ['name' => 'personal_allowance', 'id' => 'f-pit-pa', 'label' => $t('calc.rates.personal_allowance'), 'required' => true, 'value' => $val($type, 'personal_allowance'), 'error' => $err($type, 'personal_allowance'), 'inputmode' => 'decimal']) ?>
        </div>
        <fieldset class="stack-sm">
          <legend class="field__label"><?= $e($t('calc.rates.brackets')) ?></legend>
          <p class="text-sm text-muted"><?= $e($t('calc.rates.brackets_help')) ?></p>
          <?php if ($err($type, 'brackets') !== null): ?><p class="field__error" role="alert"><?= $e((string) $err($type, 'brackets')) ?></p><?php endif; ?>
          <div class="rate-rows">
            <div class="rate-rows__head" aria-hidden="true"><span><?= $e($t('calc.rates.up_to')) ?></span><span><?= $e($t('calc.rates.rate')) ?></span></div>
            <?php foreach ($rows($type, 'brackets', ['up_to', 'rate']) as $i => $r): ?>
              <div class="rate-rows__row">
                <input class="input" name="brackets[up_to][]" value="<?= $e($r['up_to'] ?? '') ?>" inputmode="decimal" aria-label="<?= $e($t('calc.rates.up_to') . ' ' . ($i + 1)) ?>">
                <input class="input" name="brackets[rate][]" value="<?= $e($r['rate'] ?? '') ?>" inputmode="decimal" aria-label="<?= $e($t('calc.rates.rate') . ' ' . ($i + 1)) ?>">
              </div>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <fieldset class="stack-sm">
          <legend class="field__label"><?= $e($t('calc.rates.allowances')) ?></legend>
          <p class="text-sm text-muted"><?= $e($t('calc.rates.allowances_help')) ?></p>
          <?php if ($err($type, 'allowances') !== null): ?><p class="field__error" role="alert"><?= $e((string) $err($type, 'allowances')) ?></p><?php endif; ?>
          <div class="rate-rows">
            <div class="rate-rows__head" aria-hidden="true"><span><?= $e($t('calc.rates.allowance_label')) ?></span><span><?= $e($t('calc.rates.allowance_cap')) ?></span></div>
            <?php foreach ($rows($type, 'allowances', ['label', 'cap']) as $i => $r): ?>
              <div class="rate-rows__row">
                <input class="input" name="allowances[label][]" value="<?= $e($r['label'] ?? '') ?>" aria-label="<?= $e($t('calc.rates.allowance_label') . ' ' . ($i + 1)) ?>">
                <input class="input" name="allowances[cap][]" value="<?= $e($r['cap'] ?? '') ?>" inputmode="decimal" aria-label="<?= $e($t('calc.rates.allowance_cap') . ' ' . ($i + 1)) ?>">
              </div>
            <?php endforeach; ?>
          </div>
        </fieldset>
      <?php endif; ?>
      <?= $ui('field', ['name' => 'reason', 'id' => "f-{$type}-reason", 'label' => $t('calc.rates.reason'), 'required' => true, 'value' => (string) ($errType === $type ? ($old['reason'] ?? '') : ''), 'error' => $err($type, 'reason')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('calc.rates.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </details>
  <?php if ($tables[$type]['versions'] !== []): ?>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('calc.rates.history')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('calc.rates.history')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('calc.rates.effective_from')) ?></th><th scope="col"><?= $e($t('calc.rates.source')) ?></th><th scope="col"><?= $e($t('calc.rates.status')) ?></th><th scope="col"><?= $e($t('calc.rates.people')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($tables[$type]['versions'] as $ver): ?>
        <tr><td data-label="<?= $e($t('calc.rates.effective_from')) ?>"><?= $e($date((string) $ver['effective_from'], false)) ?></td>
          <td data-label="<?= $e($t('calc.rates.source')) ?>" class="break-all"><?= $e((string) ($ver['source_reference'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('calc.rates.status')) ?>"><?= $ui('badge', ['tone' => $stTone[$ver['status']] ?? 'neutral', 'label' => $t('config.status.' . $ver['status'])]) ?></td>
          <td data-label="<?= $e($t('calc.rates.people')) ?>" class="text-sm"><?= $e((string) $ver['proposed_name']) ?><?= $ver['decided_name'] !== null ? ' → ' . $e((string) $ver['decided_name']) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
