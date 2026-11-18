<?php
/**
 * Company personnel-file rules (Config Registry employees.file_rules): required documents by employment type, how many
 * days before expiry HR is told, and the exit checklist. Nothing is in force until another HR person approves.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, array<int, string>> $org @var int $le @var array<string, mixed>|null $current @var list<array<string, mixed>> $versions
 * @var list<array{0: string, 1: string}> $describe @var array<string, string> $errors @var array<string, mixed> $old @var string $today
 */
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Employees\FileRules;

$loc = (string) ($v['locale'] ?? 'th');
$src = $old !== [] ? $old : ($current ?? []);
$req = (array) ($src['required'] ?? []);
$off = (array) ($src['offboarding'] ?? []);
if ($off === [] && $current === null && $old === []) {
    foreach (FileRules::SUGGESTED_OFFBOARDING as $s) {
        $off[] = $s + ['label' => $t('employees.offb_suggest.' . $s['code'])];
    }
}
while (count($off) < count(FileRules::SUGGESTED_OFFBOARDING) + 2) {
    $off[] = ['code' => '', 'label' => '', 'owner' => 'hr', 'days' => '0'];
}
$owners = [];
foreach (FileRules::OFFBOARD_OWNERS as $o) {
    $owners[$o] = $t('employees.owner.' . $o);
}
$typeCols = array_merge(['*'], EmployeeService::TYPES);
$docsByCat = [];
foreach (FileRules::DOC_TYPES as $d => $c) {
    if ($c !== '*') {
        $docsByCat[$c][] = $d;
    }
}
$vstatus = ['draft' => 'neutral', 'in_review' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'returned' => 'warning', 'cancelled' => 'neutral'];
?>
<?= $ui('page-header', ['title' => $t('employees.rules_title'), 'subtitle' => $t('employees.rules_subtitle'),
    'actions' => $ui('button', ['label' => $t('employees.title'), 'href' => $url('/employees'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<?php if (count($org['legal_entity']) > 1): ?>
<form method="get" action="<?= $e($url('/employees/rules')) ?>" class="filter-bar">
  <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('employees.field.company'), 'value' => (string) $le, 'options' => $org['legal_entity']]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'building']) ?>
</form>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="ru-now">
  <h2 id="ru-now" class="card__title"><?= $e($t('employees.rules_current')) ?> · <?= $e($org['legal_entity'][$le] ?? '') ?></h2>
  <?php if ($current === null): ?>
    <?= $ui('alert', ['tone' => 'info', 'title' => $t('employees.rules_none_title'), 'text' => $t('employees.rules_none_text')]) ?>
  <?php else: ?>
    <dl class="dl"><?php foreach ($describe as [$k, $val]): ?><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd><?php endforeach; ?></dl>
  <?php endif; ?>
</section>

<section class="card stack" aria-labelledby="ru-new">
  <h2 id="ru-new" class="card__title"><?= $e($t('employees.rules_propose')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('employees.rules_propose_help')) ?></p>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/employees/rules')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= (int) $le ?>">
    <fieldset class="stack-sm">
      <legend class="card__title"><?= $e($t('employees.rules_required')) ?></legend>
      <?php if (isset($errors['required'])): ?><p class="field__error" role="alert"><?= $e($t($errors['required'])) ?></p><?php endif; ?>
      <div class="table-wrap" role="region" aria-label="<?= $e($t('employees.rules_required')) ?>" tabindex="0">
        <table class="table table--compact rules-matrix">
          <thead><tr><th scope="col"><?= $e($t('employees.field.doc_type')) ?></th>
            <?php foreach ($typeCols as $ty): ?><th scope="col" class="num"><?= $e($ty === '*' ? $t('employees.rules_all_types') : $t('employees.type.' . $ty)) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($docsByCat as $cat => $docs): ?>
            <tr class="rules-matrix__cat"><th scope="rowgroup" colspan="<?= count($typeCols) + 1 ?>"><?= $e($cat . ' · ' . $t('employees.category.' . $cat)) ?></th></tr>
            <?php foreach ($docs as $d): ?>
              <tr><th scope="row"><?= $e($t('employees.doc.' . $d)) ?></th>
                <?php foreach ($typeCols as $ty): ?>
                  <td class="num"><input type="checkbox" name="required[<?= $e($ty) ?>][]" value="<?= $e($d) ?>"<?= in_array($d, (array) ($req[$ty] ?? []), true) ? ' checked' : '' ?>
                    aria-label="<?= $e($t('employees.doc.' . $d) . ' · ' . ($ty === '*' ? $t('employees.rules_all_types') : $t('employees.type.' . $ty))) ?>"></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </fieldset>

    <?= $ui('field', ['name' => 'expiry_notice_days', 'label' => $t('employees.rules_expiry_days'), 'required' => true, 'inputmode' => 'numeric',
        'value' => (string) ($src['expiry_notice_days'] ?? ''), 'error' => $errors['expiry_notice_days'] ?? null, 'help' => $t('employees.rules_expiry_help')]) ?>

    <fieldset class="stack-sm">
      <legend class="card__title"><?= $e($t('employees.rules_offboarding')) ?></legend>
      <p class="text-sm text-muted"><?= $e($t('employees.rules_offboarding_help')) ?></p>
      <?php if (isset($errors['offboarding'])): ?><p class="field__error" role="alert"><?= $e($t($errors['offboarding'])) ?></p><?php endif; ?>
      <?php foreach ($off as $i => $o): ?>
        <div class="grid grid--4">
          <?= $ui('field', ['name' => 'offboarding[' . $i . '][code]', 'id' => 'ob-' . $i . '-code', 'label' => $t('employees.rules_item_code'), 'value' => (string) ($o['code'] ?? '')]) ?>
          <?= $ui('field', ['name' => 'offboarding[' . $i . '][label]', 'id' => 'ob-' . $i . '-label', 'label' => $t('employees.rules_item_label'), 'value' => (string) ($o['label'] ?? '')]) ?>
          <?= $ui('field', ['name' => 'offboarding[' . $i . '][owner]', 'id' => 'ob-' . $i . '-owner', 'type' => 'select', 'label' => $t('employees.field.owner'), 'value' => (string) ($o['owner'] ?? 'hr'), 'options' => $owners]) ?>
          <?= $ui('field', ['name' => 'offboarding[' . $i . '][days]', 'id' => 'ob-' . $i . '-days', 'label' => $t('employees.rules_item_days'), 'inputmode' => 'numeric', 'value' => (string) ($o['days'] ?? '0')]) ?>
        </div>
      <?php endforeach; ?>
    </fieldset>

    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'effective_from', 'type' => 'date', 'label' => $t('employees.field.effective_from'), 'required' => true, 'value' => (string) ($old['effective_from'] ?? $today),
          'error' => $errors['effective_from'] ?? null]) ?>
      <?= $ui('field', ['name' => 'source_reference', 'label' => $t('employees.rules_source'), 'required' => true, 'value' => (string) ($old['source_reference'] ?? ''),
          'error' => $errors['source_reference'] ?? null, 'help' => $t('employees.rules_source_help')]) ?>
      <?= $ui('field', ['name' => 'reason', 'label' => $t('employees.field.reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $errors['reason'] ?? null, 'full' => true]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('employees.rules_send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>

<?php if ($versions !== []): ?>
<section class="card card--flush" aria-labelledby="ru-v">
  <div class="card__head"><h2 id="ru-v" class="card__title"><?= $e($t('employees.rules_versions')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="ru-v" tabindex="0">
    <table class="table table--stack table--compact">
      <thead><tr><th scope="col"><?= $e($t('employees.field.effective_from')) ?></th><th scope="col"><?= $e($t('employees.field.status')) ?></th><th scope="col"><?= $e($t('employees.rules_source')) ?></th>
        <th scope="col"><?= $e($t('employees.field.who')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($versions as $ver): ?>
        <tr>
          <td data-label="<?= $e($t('employees.field.effective_from')) ?>"><?= $e(DateFormat::day((string) $ver['effective_from'], $loc)) ?></td>
          <td data-label="<?= $e($t('employees.field.status')) ?>"><?= $ui('badge', ['tone' => $vstatus[$ver['status']] ?? 'neutral', 'label' => $t('config.status.' . $ver['status'])]) ?>
            <?php if ($ver['instance_pid'] !== null): ?><a class="text-sm" href="<?= $e($url('/requests/' . $ver['instance_pid'])) ?>"><?= $e($t('employees.see_request')) ?></a><?php endif; ?></td>
          <td data-label="<?= $e($t('employees.rules_source')) ?>"><?= $e((string) ($ver['source_reference'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('employees.field.who')) ?>"><?= $e((string) ($ver['proposed_name'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
