<?php
/**
 * HR settings: leave types, the leave policy (Config Registry, maker-checker) and balances.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<int, string> $companies @var int $le @var list<array<string, mixed>> $types @var array<string, mixed>|null $policy
 * @var list<array<string, mixed>> $versions @var bool $canPolicy @var string $q @var list<array<string, mixed>> $found
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $today
 */
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Leave\LeaveService;

$loc = (string) ($v['locale'] ?? 'th');
$day = static fn (?string $d): string => $d === null || $d === '' ? '—' : DateFormat::day(substr($d, 0, 10), $loc);
$name = static fn (array $ty): string => $loc === 'en' && ($ty['name_en'] ?? '') !== '' ? (string) $ty['name_en'] : (string) $ty['name_th'];
$cur = (array) ($policy['value']['types'] ?? []);
$active = array_values(array_filter($types, static fn (array $ty): bool => $ty['status'] === 'active'));
$pol = $form === 'policy' ? (array) ($old['types'] ?? []) : $cur;
$pv = static function (string $code, string $k) use ($pol): string {
    $x = $pol[$code][$k] ?? null;
    if (is_bool($x)) {
        return $x ? '1' : '';
    }
    return is_scalar($x) ? (string) $x : '';
};
$yes = static fn (mixed $b): string => $b ? '✓' : '—';
$num = static fn (mixed $x): string => $x === null || $x === '' ? '—' : LeaveService::units((float) $x);
$vtone = ['draft' => ['neutral', 'pencil'], 'in_review' => ['warning', 'hourglass'], 'returned' => ['warning', 'arrow-back-up'], 'approved' => ['success', 'circle-check'],
    'rejected' => ['danger', 'circle-x'], 'cancelled' => ['neutral', 'x']];
?>
<?= $ui('page-header', ['title' => $t('leave.admin_title'), 'subtitle' => $t('leave.admin_subtitle'),
    'actions' => $ui('button', ['label' => $t('leave.title'), 'href' => $url('/leave'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<?php if (count($companies) > 1): ?>
<form method="get" action="<?= $e($url('/leave/admin')) ?>" class="filter-bar">
  <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('employees.field.company'), 'value' => (string) $le, 'options' => $companies]) ?>
  <?= $ui('button', ['label' => $t('leave.switch_company'), 'type' => 'submit', 'icon' => 'building']) ?>
</form>
<?php endif; ?>
<nav class="tabs__list subnav" aria-label="<?= $e($t('leave.admin_title')) ?>">
  <a class="tabs__tab" href="#types"><?= $e($t('leave.types')) ?></a>
  <a class="tabs__tab" href="#policy"><?= $e($t('leave.policy')) ?></a>
  <a class="tabs__tab" href="#balances"><?= $e($t('leave.balances_hr')) ?></a>
</nav>

<section class="card stack-sm" id="types" aria-labelledby="lt-h">
  <h2 id="lt-h" class="card__title"><?= $e($t('leave.types')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('leave.types_help')) ?></p>
  <?php if ($types !== []): ?>
  <div class="table-wrap" role="region" aria-labelledby="lt-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('leave.field.type_code')) ?></th><th scope="col"><?= $e($t('leave.field.type')) ?></th><th scope="col"><?= $e($t('leave.statutory')) ?></th>
        <th scope="col"><?= $e($t('leave.field.allow_document')) ?></th><th scope="col"><?= $e($t('leave.field.status')) ?></th><th scope="col"><?= $e($t('leave.edit')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($types as $ty): $open = $form === 'type:' . $ty['public_id']; $o = $open ? $old : $ty; ?>
        <tr>
          <td data-label="<?= $e($t('leave.field.type_code')) ?>"><code><?= $e($ty['code']) ?></code></td>
          <td data-label="<?= $e($t('leave.field.type')) ?>"><?= $e($name($ty)) ?></td>
          <td data-label="<?= $e($t('leave.statutory')) ?>"><?= $e($yes((int) $ty['is_statutory'] === 1)) ?></td>
          <td data-label="<?= $e($t('leave.field.allow_document')) ?>"><?= $e($yes((int) $ty['allow_document'] === 1)) ?></td>
          <td data-label="<?= $e($t('leave.field.status')) ?>"><?= $ui('badge', ['tone' => $ty['status'] === 'active' ? 'success' : 'neutral', 'icon' => $ty['status'] === 'active' ? 'circle-check' : 'x', 'label' => $t('leave.type_status.' . $ty['status'])]) ?></td>
          <td data-label="<?= $e($t('leave.edit')) ?>">
            <details<?= $open ? ' open' : '' ?>><summary><?= $e($t('leave.edit')) ?> <span class="sr-only"><?= $e($ty['code']) ?></span></summary>
              <form method="post" action="<?= $e($url('/leave/admin/types')) ?>" class="stack-sm" novalidate>
                <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $le ?>"><input type="hidden" name="pid" value="<?= $e($ty['public_id']) ?>">
                <input type="hidden" name="code" value="<?= $e($ty['code']) ?>">
                <?= $ui('field', ['name' => 'name_th', 'id' => 'tn-' . $ty['public_id'], 'label' => $t('leave.field.name_th'), 'required' => true, 'value' => (string) ($o['name_th'] ?? ''), 'error' => $open ? ($errors['name_th'] ?? null) : null]) ?>
                <?= $ui('field', ['name' => 'name_en', 'id' => 'te-' . $ty['public_id'], 'label' => $t('leave.field.name_en'), 'value' => (string) ($o['name_en'] ?? '')]) ?>
                <?= $ui('field', ['name' => 'sort_order', 'id' => 'ts-' . $ty['public_id'], 'label' => $t('leave.field.sort'), 'value' => (string) ($o['sort_order'] ?? '100'), 'inputmode' => 'numeric']) ?>
                <?= $ui('field', ['name' => 'status', 'id' => 'tt-' . $ty['public_id'], 'type' => 'select', 'label' => $t('leave.field.status'), 'value' => (string) ($o['status'] ?? 'active'),
                    'options' => ['active' => $t('leave.type_status.active'), 'inactive' => $t('leave.type_status.inactive')]]) ?>
                <label class="check"><input type="checkbox" name="is_statutory" value="1"<?= !empty($o['is_statutory']) ? ' checked' : '' ?>> <?= $e($t('leave.field.is_statutory')) ?></label>
                <label class="check"><input type="checkbox" name="allow_document" value="1"<?= !empty($o['allow_document']) ? ' checked' : '' ?>> <?= $e($t('leave.field.allow_document')) ?></label>
                <div class="row"><?= $ui('button', ['label' => $t('leave.save'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm', 'icon' => 'circle-check']) ?></div>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <details<?= $form === 'type:new' ? ' open' : '' ?>>
    <summary><?= $e($t('leave.add_type')) ?></summary>
    <?php $o = $form === 'type:new' ? $old : []; ?>
    <form method="post" action="<?= $e($url('/leave/admin/types')) ?>" class="stack-sm" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $le ?>">
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'code', 'label' => $t('leave.field.type_code'), 'required' => true, 'value' => (string) ($o['code'] ?? ''), 'error' => $form === 'type:new' ? ($errors['code'] ?? null) : null, 'help' => $t('leave.type_code_help')]) ?>
        <?= $ui('field', ['name' => 'name_th', 'label' => $t('leave.field.name_th'), 'required' => true, 'value' => (string) ($o['name_th'] ?? ''), 'error' => $form === 'type:new' ? ($errors['name_th'] ?? null) : null]) ?>
        <?= $ui('field', ['name' => 'name_en', 'label' => $t('leave.field.name_en'), 'value' => (string) ($o['name_en'] ?? '')]) ?>
        <?= $ui('field', ['name' => 'sort_order', 'label' => $t('leave.field.sort'), 'value' => (string) ($o['sort_order'] ?? '100'), 'inputmode' => 'numeric']) ?>
      </div>
      <label class="check"><input type="checkbox" name="is_statutory" value="1"<?= !empty($o['is_statutory']) ? ' checked' : '' ?>> <?= $e($t('leave.field.is_statutory')) ?></label>
      <label class="check"><input type="checkbox" name="allow_document" value="1"<?= !empty($o['allow_document']) ? ' checked' : '' ?>> <?= $e($t('leave.field.allow_document')) ?></label>
      <div class="row"><?= $ui('button', ['label' => $t('leave.add_type'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
    </form>
  </details>
</section>

<section class="card stack-sm" id="policy" aria-labelledby="lp-h">
  <h2 id="lp-h" class="card__title"><?= $e($t('leave.policy')) ?></h2>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('leave.policy_rules_title'), 'text' => $t('leave.policy_rules_text')]) ?>
  <?php if ($policy === null): ?>
    <p class="text-sm"><?= $ui('badge', ['tone' => 'warning', 'icon' => 'alert-triangle', 'label' => $t('leave.no_policy_short')]) ?> <?= $e($t('leave.no_policy_admin')) ?></p>
  <?php else: ?>
    <p class="text-sm text-muted"><?= $e($t('leave.policy_in_force', ['from' => $date((string) $policy['from'], false)])) ?></p>
    <div class="table-wrap" role="region" aria-label="<?= $e($t('leave.policy')) ?>" tabindex="0">
      <table class="table table--stack">
        <thead><tr><th scope="col"><?= $e($t('leave.field.type')) ?></th><th scope="col"><?= $e($t('leave.pf.legal_minimum_days')) ?></th><th scope="col"><?= $e($t('leave.pf.entitlement_days')) ?></th>
          <th scope="col"><?= $e($t('leave.pf.min_service_months')) ?></th><th scope="col"><?= $e($t('leave.pf.carry_forward_max_days')) ?></th><th scope="col"><?= $e($t('leave.pf.half_day')) ?></th>
          <th scope="col"><?= $e($t('leave.pf.document_from_days')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($types as $ty): $r = $cur[(string) $ty['code']] ?? null; ?>
          <tr>
            <td data-label="<?= $e($t('leave.field.type')) ?>"><?= $e($name($ty)) ?></td>
            <?php if ($r === null): ?>
              <td colspan="6" data-label="<?= $e($t('leave.policy')) ?>"><span class="text-muted"><?= $e($t('leave.no_policy_short')) ?></span></td>
            <?php else: $groups = (array) ($r['group_entitlements'] ?? []); ?>
              <td data-label="<?= $e($t('leave.pf.legal_minimum_days')) ?>"><?= $e($num($r['legal_minimum_days'])) ?></td>
              <td data-label="<?= $e($t('leave.pf.entitlement_days')) ?>"><?= $r['entitlement_days'] === null ? $e($t('leave.not_tracked')) : $e($num($r['entitlement_days'])) ?><?php foreach ($groups as $g => $gv): ?><br><span class="text-sm text-muted"><?= $e($g) ?>: <?= $e($num($gv)) ?></span><?php endforeach; ?></td>
              <td data-label="<?= $e($t('leave.pf.min_service_months')) ?>"><?= $e((string) $r['min_service_months']) ?><?= $r['proration'] === 'monthly' ? ' · ' . $e($t('leave.proration.monthly')) : '' ?></td>
              <td data-label="<?= $e($t('leave.pf.carry_forward_max_days')) ?>"><?= $e($num($r['carry_forward_max_days'])) ?></td>
              <td data-label="<?= $e($t('leave.pf.half_day')) ?>"><?= $e($yes($r['half_day'])) ?></td>
              <td data-label="<?= $e($t('leave.pf.document_from_days')) ?>"><?= $e($num($r['document_from_days'])) ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($canPolicy && $active !== []): ?>
  <details<?= $form === 'policy' ? ' open' : '' ?>>
    <summary><?= $e($t('leave.propose_policy')) ?></summary>
    <?php if (isset($errors['_form'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['_form']]) ?><?php endif; ?>
    <form method="post" action="<?= $e($url('/leave/admin/policy')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $le ?>">
      <p class="text-sm text-muted"><?= $e($t('leave.propose_help')) ?></p>
      <?php foreach ($active as $ty): $c = (string) $ty['code']; $n = 'types[' . $c . ']'; $err = static fn (string $k): ?string => $errors[$c . '.' . $k] ?? null; ?>
      <fieldset class="leave-policy-type">
        <legend><?= $e($name($ty)) ?> <code><?= $e($c) ?></code></legend>
        <div class="grid grid--3">
          <?= $ui('field', ['name' => $n . '[legal_minimum_days]', 'id' => 'p-' . $c . '-legal', 'label' => $t('leave.pf.legal_minimum_days'), 'value' => $pv($c, 'legal_minimum_days'), 'inputmode' => 'decimal', 'error' => $err('legal_minimum_days'), 'help' => $t('leave.pf_help.legal_minimum_days')]) ?>
          <?= $ui('field', ['name' => $n . '[entitlement_days]', 'id' => 'p-' . $c . '-ent', 'label' => $t('leave.pf.entitlement_days'), 'value' => $pv($c, 'entitlement_days'), 'inputmode' => 'decimal', 'error' => $err('entitlement_days'), 'help' => $t('leave.pf_help.entitlement_days')]) ?>
          <?= $ui('field', ['name' => $n . '[min_service_months]', 'id' => 'p-' . $c . '-svc', 'label' => $t('leave.pf.min_service_months'), 'value' => $pv($c, 'min_service_months'), 'inputmode' => 'numeric', 'error' => $err('min_service_months')]) ?>
          <?= $ui('field', ['name' => $n . '[proration]', 'id' => 'p-' . $c . '-pro', 'type' => 'select', 'label' => $t('leave.pf.proration'), 'value' => $pv($c, 'proration') ?: 'none',
              'options' => ['none' => $t('leave.proration.none'), 'monthly' => $t('leave.proration.monthly')]]) ?>
          <?= $ui('field', ['name' => $n . '[carry_forward_max_days]', 'id' => 'p-' . $c . '-carry', 'label' => $t('leave.pf.carry_forward_max_days'), 'value' => $pv($c, 'carry_forward_max_days'), 'inputmode' => 'decimal', 'error' => $err('carry_forward_max_days')]) ?>
          <?= $ui('field', ['name' => $n . '[document_from_days]', 'id' => 'p-' . $c . '-doc', 'label' => $t('leave.pf.document_from_days'), 'value' => $pv($c, 'document_from_days'), 'inputmode' => 'decimal', 'error' => $err('document_from_days')]) ?>
          <?= $ui('field', ['name' => $n . '[min_notice_days]', 'id' => 'p-' . $c . '-notice', 'label' => $t('leave.pf.min_notice_days'), 'value' => $pv($c, 'min_notice_days'), 'inputmode' => 'numeric', 'error' => $err('min_notice_days')]) ?>
          <?= $ui('field', ['name' => $n . '[max_backdate_days]', 'id' => 'p-' . $c . '-back', 'label' => $t('leave.pf.max_backdate_days'), 'value' => $pv($c, 'max_backdate_days'), 'inputmode' => 'numeric', 'error' => $err('max_backdate_days')]) ?>
          <?php $groups = (array) ($pol[$c]['group_entitlements'] ?? []); $gtext = $pol[$c]['group_text'] ?? implode(', ', array_map(static fn ($g, $d) => $g . '=' . LeaveService::units((float) $d), array_keys($groups), $groups)); ?>
          <?= $ui('field', ['name' => $n . '[group_text]', 'id' => 'p-' . $c . '-grp', 'label' => $t('leave.pf.group_entitlements'), 'value' => (string) $gtext,
              'error' => $err('group_entitlements'), 'help' => $t('leave.pf_help.group_entitlements'), 'placeholder' => 'DAILY=6, PART_TIME=3']) ?>
        </div>
        <div class="row">
          <label class="check"><input type="checkbox" name="<?= $e($n) ?>[half_day]" value="1"<?= $pv($c, 'half_day') !== '' ? ' checked' : '' ?>> <?= $e($t('leave.pf.half_day')) ?></label>
          <label class="check"><input type="checkbox" name="<?= $e($n) ?>[allow_negative]" value="1"<?= $pv($c, 'allow_negative') !== '' ? ' checked' : '' ?>> <?= $e($t('leave.pf.allow_negative')) ?></label>
        </div>
      </fieldset>
      <?php endforeach; ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'effective_from', 'type' => 'date', 'label' => $t('leave.field.effective_from'), 'required' => true, 'value' => (string) ($old['effective_from'] ?? $today), 'error' => $errors['effective_from'] ?? null]) ?>
        <?= $ui('field', ['name' => 'source_reference', 'label' => $t('leave.field.source_reference'), 'required' => true, 'value' => (string) ($old['source_reference'] ?? ''), 'error' => $errors['source_reference'] ?? null, 'help' => $t('leave.source_help')]) ?>
        <?= $ui('field', ['name' => 'reason', 'label' => $t('leave.field.reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $errors['reason'] ?? null]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('leave.send_policy'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </details>
  <?php endif; ?>

  <?php if ($versions !== []): ?>
  <h3 class="card__title"><?= $e($t('leave.policy_versions')) ?></h3>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('leave.policy_versions')) ?>" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('leave.field.effective_from')) ?></th><th scope="col"><?= $e($t('leave.field.status')) ?></th><th scope="col"><?= $e($t('leave.field.source_reference')) ?></th>
        <th scope="col"><?= $e($t('leave.proposed_by')) ?></th><th scope="col"><?= $e($t('leave.decided_by')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($versions as $ver): [$tn, $ic] = $vtone[$ver['status']] ?? ['neutral', 'info-circle']; ?>
        <tr>
          <td data-label="<?= $e($t('leave.field.effective_from')) ?>"><?= $e($date((string) $ver['effective_from'], false)) ?></td>
          <td data-label="<?= $e($t('leave.field.status')) ?>"><?php if ($ver['instance_pid'] !== null): ?><a href="<?= $e($url('/approvals/' . $ver['instance_pid'])) ?>"><?= $ui('badge', ['tone' => $tn, 'icon' => $ic, 'label' => $t('config.status.' . $ver['status'])]) ?></a><?php else: ?><?= $ui('badge', ['tone' => $tn, 'icon' => $ic, 'label' => $t('config.status.' . $ver['status'])]) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('leave.field.source_reference')) ?>"><?= $e((string) ($ver['source_reference'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('leave.proposed_by')) ?>"><?= $e((string) ($ver['proposed_name'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('leave.decided_by')) ?>"><?= $e((string) ($ver['decided_name'] ?? '—')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card stack-sm" id="balances" aria-labelledby="lbal-h">
  <h2 id="lbal-h" class="card__title"><?= $e($t('leave.balances_hr')) ?></h2>
  <form method="get" action="<?= $e($url('/leave/admin')) ?>#balances" class="filter-bar" role="search">
    <input type="hidden" name="le" value="<?= $le ?>">
    <?= $ui('field', ['name' => 'q', 'label' => $t('common.search'), 'value' => $q, 'placeholder' => $t('employees.search_placeholder')]) ?>
    <?= $ui('button', ['label' => $t('common.search'), 'type' => 'submit', 'icon' => 'search']) ?>
  </form>
  <?php if ($q !== ''): ?>
    <?php if ($found === []): ?><p class="text-sm text-muted"><?= $e($t('employees.none')) ?></p><?php else: ?>
    <ul class="stack-sm" role="list">
      <?php foreach ($found as $f): ?><li><a href="<?= $e($url('/leave/admin/employee/' . $f['public_id'])) ?>"><?= $e($f['employee_code']) ?> · <?= $e($f['name_th']) ?></a></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
  <?php endif; ?>
  <div class="row">
    <?= $ui('button', ['label' => $t('leave.import.title'), 'href' => $url('/import/leave.opening?le=' . $le), 'variant' => 'secondary', 'icon' => 'upload']) ?>
  </div>
  <p class="text-sm text-muted"><?= $e($t('leave.import.about')) ?></p>
</section>
