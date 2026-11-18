<?php
/**
 * Shift planning: patterns, rosters, company rules. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var int $le @var list<array<string, mixed>> $patterns @var list<array<string, mixed>> $rosters
 * @var array<string, string> $sites @var array<string, string> $units @var bool $canCompany @var bool $canPolicy @var array<string, mixed>|null $rules
 * @var array<string, string> $errors @var array<string, string> $old @var string $form @var array<string, mixed>|null $edit @var string $today
 * @var \Blue\Modules\Shifts\ShiftService $svc
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$day = static fn (string $d): string => DateFormat::day($d, $loc);
$err = static fn (string $f, string $k) => $form === $f ? ($errors[$k] ?? null) : null;
$p = $form === 'pattern' ? $old : ($edit !== null ? ['id' => $edit['public_id'], 'code' => $edit['code'], 'name' => $edit['name'], 'start' => $edit['start_time'], 'end' => $edit['end_time'],
    'break_minutes' => (string) $edit['break_minutes'], 'late_grace_minutes' => (string) $edit['late_grace_minutes'], 'early_grace_minutes' => (string) $edit['early_grace_minutes'],
    'tone' => $edit['tone'], 'site' => (string) ($edit['site_pid'] ?? ''), 'status' => $edit['status']] : []);
$ro = $form === 'roster' ? $old : [];
$ru = $form === 'rules' ? $old : [];
$tones = [];
foreach (['morning', 'afternoon', 'night', 'other'] as $tn) {
    $tones[$tn] = $t('shifts.tone.' . $tn);
}
?>
<?= $ui('page-header', ['title' => $t('shifts.admin_title'), 'subtitle' => $t('shifts.admin_subtitle'),
    'actions' => $ui('button', ['label' => $t('shifts.title'), 'href' => $url('/shifts'), 'variant' => 'secondary', 'icon' => 'calendar-event'])]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('shifts.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?>
    <a href="<?= $e($url('/shifts/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<section class="card stack" aria-labelledby="ro-h">
  <h2 id="ro-h" class="card__title"><?= $e($t('shifts.rosters')) ?></h2>
  <?php if ($rosters === []): ?><p class="text-sm text-muted"><?= $e($t('shifts.rosters_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ro-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('shifts.field.name')) ?></th><th scope="col"><?= $e($t('shifts.field.range')) ?></th><th scope="col"><?= $e($t('shifts.field.for')) ?></th>
        <th scope="col"><?= $e($t('shifts.field.people')) ?></th><th scope="col"><?= $e($t('shifts.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rosters as $r): $ph = $svc->phase($r); ?>
        <tr>
          <td data-label="<?= $e($t('shifts.field.name')) ?>"><a href="<?= $e($url('/shifts/roster/' . $r['public_id'])) ?>"><?= $e((string) $r['name']) ?></a><?php if ((int) $r['revision'] > 1): ?> <span class="text-sm text-muted"><?= $e($t('shifts.revision_n', ['n' => (string) $r['revision']])) ?></span><?php endif; ?></td>
          <td data-label="<?= $e($t('shifts.field.range')) ?>"><?= $e($day((string) $r['start_date']) . ' – ' . $day((string) $r['end_date'])) ?></td>
          <td data-label="<?= $e($t('shifts.field.for')) ?>"><?= $e(trim(($r['site_code'] ?? '') . ' ' . ($r['dept_code'] ?? '')) !== '' ? trim(($r['site_code'] ?? '') . ' ' . ($r['dept_code'] ?? '')) : $t('shifts.whole_company')) ?></td>
          <td data-label="<?= $e($t('shifts.field.people')) ?>" class="num"><?= (int) $r['people'] ?></td>
          <td data-label="<?= $e($t('shifts.field.status')) ?>"><?= $ui('badge', ['tone' => ['draft' => 'warning', 'published' => 'info', 'active' => 'success', 'completed' => 'neutral', 'superseded' => 'neutral'][$ph] ?? 'neutral', 'label' => $t('shifts.phase.' . $ph)]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <details<?= $form === 'roster' ? ' open' : '' ?>>
    <summary><?= $e($t('shifts.roster_new')) ?></summary>
    <form method="post" action="<?= $e($url('/shifts/admin/roster')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
      <?= $ui('field', ['name' => 'name', 'id' => 'ro-name', 'label' => $t('shifts.field.name'), 'required' => true, 'value' => $ro['name'] ?? '', 'error' => $err('roster', 'name'), 'placeholder' => $t('shifts.roster_name_example')]) ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'start_date', 'id' => 'ro-start', 'type' => 'date', 'label' => $t('shifts.field.start_date'), 'required' => true, 'value' => $ro['start_date'] ?? '', 'error' => $err('roster', 'start_date')]) ?>
        <?= $ui('field', ['name' => 'end_date', 'id' => 'ro-end', 'type' => 'date', 'label' => $t('shifts.field.end_date'), 'required' => true, 'value' => $ro['end_date'] ?? '', 'error' => $err('roster', 'end_date'), 'help' => $t('shifts.range_help')]) ?>
        <?= $ui('field', ['name' => 'site', 'id' => 'ro-site', 'type' => 'select', 'label' => $t('shifts.field.site'), 'value' => $ro['site'] ?? '', 'options' => ['' => $t('shifts.all_sites')] + $sites, 'error' => $err('roster', 'site')]) ?>
        <?= $ui('field', ['name' => 'department', 'id' => 'ro-dept', 'type' => 'select', 'label' => $t('shifts.field.department'), 'value' => $ro['department'] ?? '', 'options' => ['' => $t('shifts.all_units')] + $units, 'error' => $err('roster', 'department'), 'help' => $t('shifts.unit_help')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('shifts.roster_create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
    </form>
  </details>
</section>

<section class="card stack" aria-labelledby="pt-h">
  <h2 id="pt-h" class="card__title"><?= $e($t('shifts.patterns')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('shifts.patterns_help')) ?></p>
  <?php if ($patterns === []): ?><p class="text-sm text-muted"><?= $e($t('shifts.patterns_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="pt-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('shifts.field.code')) ?></th><th scope="col"><?= $e($t('shifts.field.name')) ?></th><th scope="col"><?= $e($t('shifts.field.time')) ?></th>
        <th scope="col"><?= $e($t('shifts.field.break')) ?></th><th scope="col"><?= $e($t('shifts.field.site')) ?></th><th scope="col"><?= $e($t('shifts.field.status')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('common.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($patterns as $pt): $s0 = (int) \Blue\Modules\Attendance\WorkSchedule::minutes((string) $pt['start_time']); $e0 = (int) \Blue\Modules\Attendance\WorkSchedule::minutes((string) $pt['end_time']); ?>
        <tr>
          <td data-label="<?= $e($t('shifts.field.code')) ?>"><?= $shChip(['off' => false, 'tone' => $pt['tone'], 'code' => $pt['code'], 'start' => '', 'end' => '', 'overnight' => false], false) ?></td>
          <td data-label="<?= $e($t('shifts.field.name')) ?>"><?= $e((string) $pt['name']) ?></td>
          <td data-label="<?= $e($t('shifts.field.time')) ?>"><?= $e($pt['start_time'] . '–' . $pt['end_time']) ?><?= $e0 <= $s0 ? ' <span class="text-sm text-muted">(' . $e($t('shifts.overnight')) . ')</span>' : '' ?></td>
          <td data-label="<?= $e($t('shifts.field.break')) ?>"><?= $e($t('shifts.minutes_n', ['n' => (string) $pt['break_minutes']])) ?></td>
          <td data-label="<?= $e($t('shifts.field.site')) ?>"><?= $e((string) ($pt['site_code'] ?? $t('shifts.all_sites'))) ?></td>
          <td data-label="<?= $e($t('shifts.field.status')) ?>"><?= $ui('badge', ['tone' => $pt['status'] === 'active' ? 'success' : 'neutral', 'label' => $t('shifts.pstatus.' . $pt['status'])]) ?></td>
          <td><?php if ($canCompany): ?><?= $ui('button', ['label' => $t('common.edit') . ' ' . $pt['code'], 'iconOnly' => true, 'icon' => 'pencil', 'variant' => 'ghost', 'size' => 'sm', 'href' => $url('/shifts/admin?le=' . rawurlencode($lePid) . '&pattern=' . $pt['public_id']) . '#pt-form']) ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($canCompany): ?>
  <details<?= $form === 'pattern' || $edit !== null ? ' open' : '' ?> id="pt-form">
    <summary><?= $e($t(($p['id'] ?? '') !== '' ? 'shifts.pattern_edit' : 'shifts.pattern_new')) ?></summary>
    <form method="post" action="<?= $e($url('/shifts/admin/pattern')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
      <input type="hidden" name="id" value="<?= $e($p['id'] ?? '') ?>">
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'code', 'id' => 'pt-code', 'label' => $t('shifts.field.code'), 'required' => true, 'value' => $p['code'] ?? '', 'error' => $err('pattern', 'code'), 'help' => $t('shifts.code_help')]) ?>
        <?= $ui('field', ['name' => 'name', 'id' => 'pt-name', 'label' => $t('shifts.field.name'), 'required' => true, 'value' => $p['name'] ?? '', 'error' => $err('pattern', 'name')]) ?>
        <?= $ui('field', ['name' => 'tone', 'id' => 'pt-tone', 'type' => 'select', 'label' => $t('shifts.field.tone'), 'value' => $p['tone'] ?? 'other', 'options' => $tones]) ?>
        <?= $ui('field', ['name' => 'start', 'id' => 'pt-start', 'label' => $t('shifts.field.start'), 'required' => true, 'value' => $p['start'] ?? '', 'placeholder' => '08:00', 'inputmode' => 'numeric', 'error' => $err('pattern', 'start')]) ?>
        <?= $ui('field', ['name' => 'end', 'id' => 'pt-end', 'label' => $t('shifts.field.end'), 'required' => true, 'value' => $p['end'] ?? '', 'placeholder' => '17:00', 'inputmode' => 'numeric', 'error' => $err('pattern', 'end'), 'help' => $t('shifts.end_help')]) ?>
        <?= $ui('field', ['name' => 'break_minutes', 'id' => 'pt-break', 'type' => 'number', 'label' => $t('shifts.field.break_minutes'), 'value' => $p['break_minutes'] ?? '60', 'min' => '0', 'max' => '240', 'error' => $err('pattern', 'break_minutes')]) ?>
        <?= $ui('field', ['name' => 'late_grace_minutes', 'id' => 'pt-late', 'type' => 'number', 'label' => $t('shifts.field.late_grace'), 'value' => $p['late_grace_minutes'] ?? '0', 'min' => '0', 'max' => '120', 'error' => $err('pattern', 'late_grace_minutes')]) ?>
        <?= $ui('field', ['name' => 'early_grace_minutes', 'id' => 'pt-early', 'type' => 'number', 'label' => $t('shifts.field.early_grace'), 'value' => $p['early_grace_minutes'] ?? '0', 'min' => '0', 'max' => '120', 'error' => $err('pattern', 'early_grace_minutes')]) ?>
        <?= $ui('field', ['name' => 'site', 'id' => 'pt-site', 'type' => 'select', 'label' => $t('shifts.field.site'), 'value' => $p['site'] ?? '', 'options' => ['' => $t('shifts.all_sites')] + $sites, 'error' => $err('pattern', 'site')]) ?>
      </div>
      <?php if (($p['id'] ?? '') !== ''): ?>
        <div class="grid grid--2">
          <?= $ui('field', ['name' => 'status', 'id' => 'pt-status', 'type' => 'select', 'label' => $t('shifts.field.status'), 'value' => $p['status'] ?? 'active', 'options' => ['active' => $t('shifts.pstatus.active'), 'inactive' => $t('shifts.pstatus.inactive')]]) ?>
          <?= $ui('field', ['name' => 'reason', 'id' => 'pt-reason', 'label' => $t('shifts.field.reason'), 'value' => $p['reason'] ?? '', 'help' => $t('shifts.reason_times_help'), 'error' => $err('pattern', 'reason')]) ?>
        </div>
      <?php endif; ?>
      <div class="row"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>

<section class="card stack" aria-labelledby="ru-h">
  <h2 id="ru-h" class="card__title"><?= $e($t('shifts.rules.title')) ?></h2>
  <?php if ($rules === null): ?>
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('shifts.rules.none')]) ?>
  <?php else: ?>
    <dl class="facts">
      <div><dt><?= $e($t('shifts.rules.min_rest_hours')) ?></dt><dd><?= $e($rules['min_rest_hours'] === null ? '—' : $t('shifts.hours', ['n' => (string) $rules['min_rest_hours']])) ?></dd></div>
      <div><dt><?= $e($t('shifts.rules.max_consecutive_days')) ?></dt><dd><?= $e($rules['max_consecutive_days'] === null ? '—' : $t('shifts.days_n', ['n' => (string) $rules['max_consecutive_days']])) ?></dd></div>
    </dl>
  <?php endif; ?>
  <?php if ($canPolicy): ?>
  <details<?= $form === 'rules' ? ' open' : '' ?>>
    <summary><?= $e($t('shifts.rules.propose')) ?></summary>
    <form method="post" action="<?= $e($url('/shifts/admin/rules')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
      <p class="text-sm text-muted"><?= $e($t('shifts.rules.help')) ?></p>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'min_rest_hours', 'id' => 'ru-rest', 'type' => 'number', 'label' => $t('shifts.rules.min_rest_hours'), 'value' => $ru['min_rest_hours'] ?? '', 'min' => '1', 'max' => '24', 'error' => $err('rules', 'min_rest_hours')]) ?>
        <?= $ui('field', ['name' => 'max_consecutive_days', 'id' => 'ru-max', 'type' => 'number', 'label' => $t('shifts.rules.max_consecutive_days'), 'value' => $ru['max_consecutive_days'] ?? '', 'min' => '1', 'max' => '31', 'error' => $err('rules', 'max_consecutive_days')]) ?>
        <?= $ui('field', ['name' => 'effective_from', 'id' => 'ru-from', 'type' => 'date', 'label' => $t('shifts.rules.effective_from'), 'required' => true, 'value' => $ru['effective_from'] ?? $today, 'error' => $err('rules', 'effective_from')]) ?>
      </div>
      <?= $ui('field', ['name' => 'source_reference', 'id' => 'ru-src', 'label' => $t('shifts.rules.source'), 'required' => true, 'value' => $ru['source_reference'] ?? '', 'help' => $t('shifts.rules.source_help'), 'error' => $err('rules', 'source_reference')]) ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'ru-reason', 'label' => $t('shifts.field.reason'), 'required' => true, 'value' => $ru['reason'] ?? '', 'error' => $err('rules', 'reason')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('shifts.rules.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
