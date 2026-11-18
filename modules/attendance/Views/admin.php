<?php
/**
 * HR settings of M34: work schedule (Config Registry, maker-checker), time-clock user codes, import from file.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<int, string> $companies @var int $le @var array<string, mixed>|null $schedule @var list<array<string, mixed>> $versions @var bool $canPolicy
 * @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $unmapped @var array<string, string> $devices @var string $today
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var bool $canCollector @var list<array{0: string, 1: string}> $scheduleRows
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$day = static fn (?string $d): string => $d === null || $d === '' ? '—' : DateFormat::day(substr($d, 0, 10), $loc);
$cur = $schedule['value'] ?? null;
$sv = $form === 'schedule' ? $old : ['day_start' => $cur['day_start'] ?? '', 'correction_max_days' => (string) ($cur['correction_max_days'] ?? ''), 'groups' => []];
$rows = [];
if ($form === 'schedule') {
    $rows = array_values((array) ($old['groups'] ?? []));
} elseif (is_array($cur)) {
    foreach ((array) $cur['groups'] as $g => $r) {
        $rows[] = ['group' => (string) $g] + (array) $r;
    }
}
$blank = ['group' => '', 'start' => '', 'end' => '', 'break_minutes' => '', 'late_grace_minutes' => '', 'early_grace_minutes' => ''];
if ($rows === []) {
    $rows[] = ['group' => '*'] + $blank;
}
$rows[] = $blank; // one spare row for another employee group
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$m = $form === 'map' ? $old : [];
$vtone = ['draft' => ['neutral', 'pencil'], 'in_review' => ['warning', 'hourglass'], 'returned' => ['warning', 'arrow-back-up'], 'approved' => ['success', 'circle-check'],
    'rejected' => ['danger', 'circle-x'], 'cancelled' => ['neutral', 'x']];
?>
<?= $ui('page-header', ['title' => $t('attendance.admin_title'), 'subtitle' => $t('attendance.admin_subtitle'),
    'actions' => ($canCollector ? $ui('button', ['label' => $t('attendance.collector_title'), 'href' => $url('/attendance/collector'), 'variant' => 'secondary', 'icon' => 'database']) . ' ' : '')
        . $ui('button', ['label' => $t('attendance.title'), 'href' => $url('/attendance'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<?php if (count($companies) > 1): ?>
<form method="get" action="<?= $e($url('/attendance/admin')) ?>" class="filter-bar">
  <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('employees.field.company'), 'value' => (string) $le, 'options' => $companies]) ?>
  <?= $ui('button', ['label' => $t('attendance.switch_company'), 'type' => 'submit', 'icon' => 'building']) ?>
</form>
<?php endif; ?>
<nav class="tabs__list subnav" aria-label="<?= $e($t('attendance.admin_title')) ?>">
  <a class="tabs__tab" href="#schedule"><?= $e($t('attendance.schedule')) ?></a>
  <a class="tabs__tab" href="#codes"><?= $e($t('attendance.codes')) ?></a>
  <a class="tabs__tab" href="#import"><?= $e($t('attendance.import.title')) ?></a>
</nav>

<section class="card stack-sm" id="schedule" aria-labelledby="sc-h">
  <h2 id="sc-h" class="card__title"><?= $e($t('attendance.schedule')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('attendance.schedule_help')) ?></p>
  <?php if ($schedule === null): ?>
    <?= $ui('alert', ['tone' => 'warning', 'title' => $t('attendance.no_schedule_title'), 'text' => $t('attendance.no_schedule_text')]) ?>
  <?php else: ?>
    <p class="text-sm"><?= $e($t('attendance.schedule_in_force', ['from' => $date((string) $schedule['from'], false)])) ?></p>
    <dl class="dl"><?php foreach ($scheduleRows as [$k, $val]): ?><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd><?php endforeach; ?></dl>
  <?php endif; ?>

  <?php if ($versions !== []): ?>
  <div class="table-wrap" role="region" aria-labelledby="sv-h" tabindex="0">
    <table class="table table--stack">
      <caption id="sv-h" class="sr-only"><?= $e($t('attendance.schedule_versions')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('attendance.field.effective_from')) ?></th><th scope="col"><?= $e($t('attendance.field.status')) ?></th><th scope="col"><?= $e($t('attendance.field.source_reference')) ?></th><th scope="col"><?= $e($t('attendance.field.people')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($versions as $ver): [$tone, $icon] = $vtone[(string) $ver['status']] ?? ['neutral', 'info-circle']; ?>
        <tr>
          <td data-label="<?= $e($t('attendance.field.effective_from')) ?>"><?= $e($date((string) $ver['effective_from'], false)) ?></td>
          <td data-label="<?= $e($t('attendance.field.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('config.status.' . $ver['status'])]) ?>
            <?php if ($ver['instance_pid'] !== null && $ver['status'] === 'in_review'): ?> <a href="<?= $e($url('/approvals/' . $ver['instance_pid'])) ?>"><?= $e($t('attendance.open_approval')) ?></a><?php endif; ?></td>
          <td data-label="<?= $e($t('attendance.field.source_reference')) ?>"><?= $e((string) ($ver['source_reference'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('attendance.field.people')) ?>"><?= $e((string) ($ver['proposed_name'] ?? '')) ?><?= $ver['decided_name'] !== null ? ' → ' . $e((string) $ver['decided_name']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($canPolicy): ?>
  <details<?= $form === 'schedule' ? ' open' : '' ?>>
    <summary><?= $e($t($schedule === null ? 'attendance.propose_first' : 'attendance.propose_change')) ?></summary>
    <form method="post" action="<?= $e($url('/attendance/admin/schedule')) ?>" class="stack-sm" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $le ?>">
      <?php if (isset($errors['_form'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['_form']]) ?><?php endif; ?>
      <p class="text-sm text-muted"><?= $e($t('attendance.schedule_rules_help')) ?></p>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'day_start', 'type' => 'time', 'label' => $t('attendance.sf.day_start'), 'required' => true, 'value' => (string) ($sv['day_start'] ?? ''), 'error' => $err('day_start'), 'help' => $t('attendance.sf.day_start_help')]) ?>
        <?= $ui('field', ['name' => 'correction_max_days', 'label' => $t('attendance.sf.correction_max_days'), 'value' => (string) ($sv['correction_max_days'] ?? ''), 'inputmode' => 'numeric', 'error' => $err('correction_max_days'), 'help' => $t('attendance.sf.correction_max_help')]) ?>
      </div>
      <div class="table-wrap" role="region" aria-labelledby="sg-h" tabindex="0">
        <table class="table att-sched">
          <caption id="sg-h" class="text-sm"><?= $e($t('attendance.sf.groups')) ?></caption>
          <thead><tr><th scope="col"><?= $e($t('attendance.sf.group')) ?></th><th scope="col"><?= $e($t('attendance.sf.start')) ?></th><th scope="col"><?= $e($t('attendance.sf.end')) ?></th>
            <th scope="col"><?= $e($t('attendance.sf.break_minutes')) ?></th><th scope="col"><?= $e($t('attendance.sf.late_grace_minutes')) ?></th><th scope="col"><?= $e($t('attendance.sf.early_grace_minutes')) ?></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $i => $g): ?>
            <tr>
              <?php foreach (['group' => 'text', 'start' => 'time', 'end' => 'time', 'break_minutes' => 'text', 'late_grace_minutes' => 'text', 'early_grace_minutes' => 'text'] as $k => $type): $ek = "groups.{$i}.{$k}"; ?>
              <td><input class="input" type="<?= $type ?>" name="groups[<?= $i ?>][<?= $k ?>]" value="<?= $e((string) ($g[$k] ?? '')) ?>" aria-label="<?= $e($t('attendance.sf.' . $k)) ?> <?= $i + 1 ?>"
                <?= $type === 'text' && $k !== 'group' ? ' inputmode="numeric"' : '' ?><?= isset($errors[$ek]) ? ' aria-invalid="true" aria-describedby="sg-' . $i . '-' . $k . '"' : '' ?>>
                <?php if (isset($errors[$ek])): ?><div class="field__error" id="sg-<?= $i ?>-<?= $k ?>"><?= $e($errors[$ek]) ?></div><?php endif; ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="text-sm text-muted"><?= $e($t('attendance.sf.group_help')) ?></p>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'effective_from', 'type' => 'date', 'label' => $t('attendance.field.effective_from'), 'required' => true, 'value' => (string) ($old['effective_from'] ?? $today), 'error' => $err('effective_from'), 'help' => $t('attendance.effective_help')]) ?>
        <?= $ui('field', ['name' => 'source_reference', 'label' => $t('attendance.field.source_reference'), 'required' => true, 'value' => (string) ($old['source_reference'] ?? ''), 'error' => $err('source_reference'), 'help' => $t('attendance.source_help')]) ?>
      </div>
      <?= $ui('field', ['name' => 'reason', 'label' => $t('attendance.field.reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $err('reason')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('attendance.send_schedule'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>

<section class="card stack-sm" id="codes" aria-labelledby="cd-h">
  <h2 id="cd-h" class="card__title"><?= $e($t('attendance.codes')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('attendance.codes_help')) ?></p>
  <?php if ($unmapped !== []): ?>
    <?= $ui('alert', ['tone' => 'warning', 'title' => $t('attendance.unmapped_title', ['n' => (string) count($unmapped)]), 'text' => $t('attendance.unmapped_text')]) ?>
    <div class="table-wrap" role="region" aria-labelledby="um-h" tabindex="0">
      <table class="table table--stack">
        <caption id="um-h" class="sr-only"><?= $e($t('attendance.unmapped_caption')) ?></caption>
        <thead><tr><th scope="col"><?= $e($t('attendance.field.user_code')) ?></th><th scope="col"><?= $e($t('attendance.field.device')) ?></th><th scope="col"><?= $e($t('attendance.field.punch_count')) ?></th><th scope="col"><?= $e($t('attendance.field.period')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($unmapped as $u): ?>
          <tr>
            <td data-label="<?= $e($t('attendance.field.user_code')) ?>"><code><?= $e((string) $u['device_user_code']) ?></code></td>
            <td data-label="<?= $e($t('attendance.field.device')) ?>"><?= $e((string) ($u['device_name'] ?? $u['device_code'] ?? '—')) ?></td>
            <td data-label="<?= $e($t('attendance.field.punch_count')) ?>"><?= (int) $u['punches'] ?></td>
            <td data-label="<?= $e($t('attendance.field.period')) ?>"><?= $e($date((string) $u['first_at'], false)) ?> – <?= $e($date((string) $u['last_at'], false)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <details<?= $form === 'map' || $unmapped !== [] ? ' open' : '' ?>>
    <summary><?= $e($t('attendance.add_code')) ?></summary>
    <form method="post" action="<?= $e($url('/attendance/admin/codes')) ?>" class="stack-sm" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $le ?>">
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'code', 'label' => $t('attendance.field.user_code'), 'required' => true, 'value' => (string) ($m['code'] ?? ($unmapped[0]['device_user_code'] ?? '')), 'error' => $err('code'), 'help' => $t('attendance.user_code_help')]) ?>
        <?= $ui('field', ['name' => 'employee_code', 'label' => $t('employees.field.code'), 'required' => true, 'value' => (string) ($m['employee_code'] ?? ''), 'error' => $err('employee_code')]) ?>
        <?= $ui('field', ['name' => 'device', 'type' => 'select', 'label' => $t('attendance.field.device'), 'value' => (string) ($m['device'] ?? ''), 'options' => ['' => $t('attendance.any_device')] + $devices, 'error' => $err('device')]) ?>
        <?= $ui('field', ['name' => 'valid_from', 'type' => 'date', 'label' => $t('attendance.field.valid_from'), 'required' => true, 'value' => (string) ($m['valid_from'] ?? ($unmapped !== [] ? substr((string) $unmapped[0]['first_at'], 0, 10) : $today)), 'error' => $err('valid_from')]) ?>
      </div>
      <?= $ui('field', ['name' => 'note', 'label' => $t('attendance.field.note'), 'value' => (string) ($m['note'] ?? '')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('attendance.save_code'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
    </form>
  </details>

  <?php if ($mappings === []): ?>
    <p class="text-sm text-muted"><?= $e($t('attendance.no_codes')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="mp-h" tabindex="0">
    <table class="table table--stack">
      <caption id="mp-h" class="sr-only"><?= $e($t('attendance.codes')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('attendance.field.user_code')) ?></th><th scope="col"><?= $e($t('attendance.field.employee')) ?></th><th scope="col"><?= $e($t('attendance.field.device')) ?></th>
        <th scope="col"><?= $e($t('attendance.field.period')) ?></th><th scope="col"><?= $e($t('attendance.end_code')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($mappings as $mp): ?>
        <tr>
          <td data-label="<?= $e($t('attendance.field.user_code')) ?>"><code><?= $e((string) $mp['device_user_code']) ?></code></td>
          <td data-label="<?= $e($t('attendance.field.employee')) ?>"><?= $e((string) $mp['employee_code']) ?> · <?= $e((string) $mp['name_th']) ?></td>
          <td data-label="<?= $e($t('attendance.field.device')) ?>"><?= $e($mp['device_code'] !== null ? (string) ($mp['device_name'] ?? $mp['device_code']) : $t('attendance.any_device')) ?></td>
          <td data-label="<?= $e($t('attendance.field.period')) ?>"><?= $e($day((string) $mp['valid_from'])) ?> – <?= $e($mp['valid_to'] !== null ? $day((string) $mp['valid_to']) : $t('attendance.open_ended')) ?></td>
          <td data-label="<?= $e($t('attendance.end_code')) ?>">
            <?php if ($mp['valid_to'] === null): ?>
            <details><summary><?= $e($t('attendance.end_code')) ?> <span class="sr-only"><?= $e((string) $mp['device_user_code']) ?></span></summary>
              <form method="post" action="<?= $e($url('/attendance/admin/codes/' . $mp['public_id'] . '/end')) ?>" class="stack-sm" novalidate>
                <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $le ?>">
                <?= $ui('field', ['name' => 'valid_to', 'id' => 'vt-' . $mp['public_id'], 'type' => 'date', 'label' => $t('attendance.field.valid_to'), 'required' => true, 'value' => $today]) ?>
                <div class="row"><?= $ui('button', ['label' => $t('attendance.end_code'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm', 'icon' => 'x']) ?></div>
              </form>
            </details>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card stack-sm" id="import" aria-labelledby="im-h">
  <h2 id="im-h" class="card__title"><?= $e($t('attendance.import.title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('attendance.import.about')) ?></p>
  <div class="row">
    <?= $ui('button', ['label' => $t('attendance.import.open'), 'href' => $url('/import/attendance.punches?le=' . $le), 'variant' => 'secondary', 'icon' => 'upload']) ?>
    <?= $ui('button', ['label' => $t('attendance.import.template'), 'href' => $url('/import/attendance.punches/template?le=' . $le), 'variant' => 'ghost', 'icon' => 'download']) ?>
  </div>
</section>
