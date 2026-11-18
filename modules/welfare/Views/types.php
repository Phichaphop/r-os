<?php
/**
 * Welfare types of a company (HR, welfare.manage): each code with the version in force, a draft waiting for another
 * person to activate it, the versions before; the form for a new type / a new version.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $overview @var list<array<string, mixed>> $components
 * @var array<string, mixed>|null $base @var int $me @var string $today @var array<string, string> $errors @var array<string, mixed> $old
 * @var array<string, bool> $tabs @var string $here
 */
use Blue\Core\Support\Money;
use Blue\Modules\Welfare\TypeService;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$hidden = '<input type="hidden" name="le" value="' . $e($lePid) . '">';
$md = static fn (mixed $x): string => $x === null ? '—' : Money::format(Money::fromDb($x));
$src = $old !== [] ? $old : ($base !== null ? $base + ['limit_year' => $base['limit_year'] !== null ? Money::format(Money::fromDb($base['limit_year']), 2, false) : '',
    'limit_event' => $base['limit_event'] !== null ? Money::format(Money::fromDb($base['limit_event']), 2, false) : '', 'employment_types' => $base['employment_types'] !== null ? explode(',', (string) $base['employment_types']) : [],
    'effective_from' => $base['status'] === 'draft' ? $base['effective_from'] : '', 'change_note' => $base['status'] === 'draft' ? $base['change_note'] : ''] : ['receipt_required' => true]);
$o = static fn (string $k): string => is_scalar($src[$k] ?? null) ? (string) $src[$k] : '';
$empTypes = array_map('strval', (array) ($src['employment_types'] ?? []));
$compOpts = ['' => '—'];
foreach ($components as $cp) {
    $compOpts[(string) $cp['code']] = $cp['code'] . ' · ' . $cp['name_th'];
}
$summary = static function (array $ty) use ($t, $md): string {
    $parts = [];
    if ($ty['limit_year'] !== null) {
        $parts[] = $t('welfare.type.limit_year', ['v' => $md($ty['limit_year'])]);
    }
    if ($ty['limit_event'] !== null) {
        $parts[] = $t('welfare.type.limit_event', ['v' => $md($ty['limit_event'])]);
    }
    if ($ty['max_claims'] !== null) {
        $parts[] = $t('welfare.type.max_claims', ['n' => (string) $ty['max_claims']]);
    }
    if ($ty['window_days'] !== null) {
        $parts[] = $t('welfare.type.window', ['n' => (string) $ty['window_days']]);
    }
    if ($ty['min_service_days'] !== null) {
        $parts[] = $t('welfare.type.service', ['n' => (string) $ty['min_service_days']]);
    }
    $parts[] = $ty['employment_types'] === null ? $t('welfare.type.all_staff') : implode(', ', array_map(static fn (string $x): string => $t('welfare.emp.' . $x), explode(',', (string) $ty['employment_types'])));
    $parts[] = $t('welfare.payout.' . $ty['payout']) . ($ty['component_code'] !== null ? ' (' . $ty['component_code'] . ')' : '');
    if ((int) $ty['receipt_required'] === 1) {
        $parts[] = $t('welfare.type.receipt_required');
    }
    return implode(' · ', $parts);
};
?>
<?= $ui('page-header', ['title' => $t('welfare.types_title'), 'subtitle' => $t('welfare.types_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?= $ui('alert', ['tone' => 'info', 'text' => $t('welfare.types_notice')]) ?>
<?php if ($overview === []): ?><?= $ui('states', ['icon' => 'gift', 'title' => $t('welfare.types_none')]) ?><?php endif; ?>
<?php foreach ($overview as $g): $cur = $g['current']; $draft = $g['draft']; ?>
<section class="card stack-sm" aria-labelledby="ty-<?= $e(strtolower((string) $g['code'])) ?>">
  <h2 id="ty-<?= $e(strtolower((string) $g['code'])) ?>" class="card__title"><?= $e($g['code']) ?> · <?= $e((string) (($cur ?? $draft ?? $g['versions'][0])['name_th'])) ?>
    <?php if ($cur !== null): ?><?= $ui('badge', ['tone' => 'success', 'label' => $t('welfare.type.active_v', ['n' => (string) $cur['version_no']])]) ?><?php endif; ?>
    <?php if ($draft !== null): ?><?= $ui('badge', ['tone' => 'info', 'label' => $t('welfare.type.draft_v', ['n' => (string) $draft['version_no']])]) ?><?php endif; ?></h2>
  <?php if ($cur !== null): ?>
    <p class="text-sm"><?= $e($summary($cur)) ?></p>
    <p class="text-sm text-muted"><?= $e($t('welfare.type.effective', ['from' => (string) $cur['effective_from'], 'to' => (string) ($cur['effective_to'] ?? '—')])) ?> · <?= $e((string) $cur['policy_ref']) ?></p>
  <?php endif; ?>
  <?php if ($draft !== null): ?>
  <div class="card card--flush card__pad stack-sm">
    <p class="text-sm"><strong><?= $e($t('welfare.type.draft_by', ['who' => (string) ($draft['creator'] ?? '—'), 'from' => (string) $draft['effective_from']])) ?></strong></p>
    <p class="text-sm"><?= $e($summary($draft)) ?></p>
    <p class="text-sm text-muted"><?= $e((string) $draft['policy_ref']) ?><?= $draft['change_note'] !== null ? ' · ' . $e((string) $draft['change_note']) : '' ?></p>
    <div class="row">
      <?php if ((int) $draft['created_by'] !== $me && (int) $draft['updated_by'] !== $me): ?>
        <form method="post" action="<?= $e($url('/welfare/types/' . $draft['public_id'] . '/activate')) ?>"><?= $csrf . $hidden ?><?= $ui('button', ['label' => $t('welfare.type.activate'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
      <?php else: ?>
        <?= $ui('button', ['label' => $t('welfare.type.edit_draft'), 'href' => $url('/welfare/types?le=' . rawurlencode($lePid) . '&edit=' . $draft['public_id'] . '#ty-form'), 'variant' => 'secondary', 'icon' => 'pencil']) ?>
        <span class="text-sm text-muted"><?= $e($t('welfare.type.needs_other')) ?></span>
      <?php endif; ?>
      <form method="post" action="<?= $e($url('/welfare/types/' . $draft['public_id'] . '/discard')) ?>"><?= $csrf . $hidden ?><?= $ui('button', ['label' => $t('welfare.type.discard'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?></form>
    </div>
  </div>
  <?php elseif ($cur !== null): ?>
    <div class="row">
      <?= $ui('button', ['label' => $t('welfare.type.new_version'), 'href' => $url('/welfare/types?le=' . rawurlencode($lePid) . '&edit=' . $cur['public_id'] . '#ty-form'), 'variant' => 'secondary', 'icon' => 'pencil']) ?>
    </div>
    <details>
      <summary><?= $e($t('welfare.type.retire')) ?></summary>
      <form method="post" action="<?= $e($url('/welfare/types/' . $cur['public_id'] . '/retire')) ?>" class="stack-sm" novalidate><?= $csrf . $hidden ?>
        <div class="grid grid--2">
          <?= $ui('field', ['name' => 'last_day', 'id' => 'f-last-' . strtolower((string) $g['code']), 'type' => 'date', 'label' => $t('welfare.field.last_day'), 'min' => $today]) ?>
          <?= $ui('field', ['name' => 'reason', 'id' => 'f-retire-' . strtolower((string) $g['code']), 'label' => $t('welfare.field.reason')]) ?>
        </div>
        <div class="row"><?= $ui('button', ['label' => $t('welfare.type.retire'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
      </form>
    </details>
  <?php endif; ?>
  <?php if (count($g['versions']) > 1): ?>
  <details><summary><?= $e($t('welfare.type.versions')) ?></summary>
    <ul class="plain-list text-sm">
      <?php foreach ($g['versions'] as $ver): ?><li><?= $e($t('welfare.version_n', ['n' => (string) $ver['version_no']]) . ' · ' . $t('welfare.type.status.' . $ver['status']) . ' · ' . $ver['effective_from'] . ' – ' . ($ver['effective_to'] ?? '…')) ?> · <?= $e($summary($ver)) ?></li><?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="card stack" aria-labelledby="ty-form">
  <h2 id="ty-form" class="card__title"><?= $e($base !== null ? $t('welfare.type.form_version', ['code' => (string) $base['code']]) : $t('welfare.type.form_new')) ?></h2>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/welfare/types')) ?>" class="stack" novalidate><?= $csrf . $hidden ?>
    <?php if ($base !== null): ?><input type="hidden" name="base" value="<?= $e((string) $base['public_id']) ?>"><?php endif; ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'label' => $t('welfare.field.code'), 'required' => true, 'value' => $base !== null ? (string) $base['code'] : $o('code'), 'disabled' => $base !== null, 'help' => $t('welfare.code_help'), 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name_th', 'label' => $t('welfare.field.name_th'), 'required' => true, 'value' => $o('name_th'), 'error' => $errors['name_th'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name_en', 'label' => $t('welfare.field.name_en'), 'value' => $o('name_en'), 'error' => $errors['name_en'] ?? null]) ?>
    </div>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('welfare.field.category'), 'required' => true, 'value' => $o('category'),
          'options' => ['' => '—'] + array_combine(TypeService::CATEGORIES, array_map(static fn (string $x): string => $t('welfare.category.' . $x), TypeService::CATEGORIES)), 'help' => $t('welfare.category_help'), 'error' => $errors['category'] ?? null]) ?>
      <?= $ui('field', ['name' => 'effective_from', 'type' => 'date', 'label' => $t('welfare.field.effective_from'), 'required' => true, 'value' => $o('effective_from'), 'error' => $errors['effective_from'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('welfare.field.type_description'), 'value' => $o('description'), 'error' => $errors['description'] ?? null]) ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('welfare.limits')) ?></legend>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'limit_year', 'type' => 'money', 'label' => $t('welfare.field.limit_year'), 'value' => $o('limit_year'), 'help' => $t('welfare.limit_year_help'), 'error' => $errors['limit_year'] ?? null]) ?>
        <?= $ui('field', ['name' => 'limit_event', 'type' => 'money', 'label' => $t('welfare.field.limit_event'), 'value' => $o('limit_event'), 'error' => $errors['limit_event'] ?? null]) ?>
        <?= $ui('field', ['name' => 'max_claims', 'type' => 'number', 'label' => $t('welfare.field.max_claims'), 'value' => $o('max_claims'), 'error' => $errors['max_claims'] ?? null]) ?>
      </div>
    </fieldset>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('welfare.eligibility')) ?></legend>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'window_days', 'type' => 'number', 'label' => $t('welfare.field.window_days'), 'value' => $o('window_days'), 'help' => $t('welfare.window_help'), 'error' => $errors['window_days'] ?? null]) ?>
        <?= $ui('field', ['name' => 'min_service_days', 'type' => 'number', 'label' => $t('welfare.field.min_service_days'), 'value' => $o('min_service_days'), 'help' => $t('welfare.service_help'), 'error' => $errors['min_service_days'] ?? null]) ?>
      </div>
      <p class="field__label"><?= $e($t('welfare.field.employment_types')) ?></p>
      <div class="row">
        <?php foreach (TypeService::EMPLOYMENT_TYPES as $et): ?><label class="check"><input type="checkbox" name="employment_types[]" value="<?= $e($et) ?>"<?= in_array($et, $empTypes, true) ? ' checked' : '' ?>> <?= $e($t('welfare.emp.' . $et)) ?></label><?php endforeach; ?>
      </div>
      <p class="text-sm text-muted"><?= $e($t('welfare.employment_help')) ?></p>
      <label class="check"><input type="checkbox" name="receipt_required" value="1"<?= !empty($src['receipt_required']) ? ' checked' : '' ?>> <?= $e($t('welfare.field.receipt_required')) ?></label>
    </fieldset>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'payout', 'type' => 'select', 'label' => $t('welfare.field.payout'), 'required' => true, 'value' => $o('payout'),
          'options' => ['' => '—', 'payroll' => $t('welfare.payout.payroll'), 'payment' => $t('welfare.payout.payment')], 'help' => $t('welfare.payout_help'), 'error' => $errors['payout'] ?? null]) ?>
      <?= $ui('field', ['name' => 'component_code', 'type' => 'select', 'label' => $t('welfare.field.component_code'), 'value' => $o('component_code'), 'options' => $compOpts, 'help' => $t('welfare.component_help'), 'error' => $errors['component_code'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'policy_ref', 'label' => $t('welfare.field.policy_ref'), 'required' => true, 'value' => $o('policy_ref'), 'help' => $t('welfare.policy_ref_help'), 'error' => $errors['policy_ref'] ?? null]) ?>
    <?= $ui('field', ['name' => 'change_note', 'label' => $t('welfare.field.change_note'), 'value' => $o('change_note'), 'error' => $errors['change_note'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('welfare.type.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?>
      <?php if ($base !== null): ?><?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url('/welfare/types?le=' . rawurlencode($lePid)), 'variant' => 'ghost']) ?><?php endif; ?></div>
    <p class="text-sm text-muted"><?= $e($t('welfare.type.save_help')) ?></p>
  </form>
</section>
