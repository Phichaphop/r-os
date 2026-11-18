<?php
/**
 * Month of attendance — my own (own = true) or of a team member / employee for the manager or HR (read only).
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed>|null $me @var array<string, mixed>|null $emp @var array<string, array<string, mixed>> $days @var array<string, mixed> $totals
 * @var bool $own @var array<string, mixed>|null $schedule @var ?string $lastPull @var list<array<string, mixed>> $corrections
 * @var bool $isManager @var bool $isHr @var bool $canManage @var string $tz @var string $base
 * @var string $month @var string $label @var string $prev @var string $next
 */
use Blue\Core\I18n\DateFormat;

include __DIR__ . '/_helpers.php';
$loc = (string) ($v['locale'] ?? 'th');
$actions = '';
if ($own && ($isManager || $isHr)) {
    $actions .= $ui('button', ['label' => $t('attendance.team_title'), 'href' => $url('/attendance/team?month=' . $month), 'variant' => 'secondary', 'icon' => 'users']);
}
if ($own && $canManage) {
    $actions .= ' ' . $ui('button', ['label' => $t('attendance.admin_title'), 'href' => $url('/attendance/admin'), 'variant' => 'ghost', 'icon' => 'settings']);
}
if (!$own) {
    $actions = $ui('button', ['label' => $t('attendance.team_title'), 'href' => $url('/attendance/team?month=' . $month), 'variant' => 'ghost', 'icon' => 'arrow-back-up']);
}
$dayLink = static fn (string $d): string => $own ? '/attendance/day/' . $d : $base . '/day/' . $d;
?>
<?= $ui('page-header', ['title' => $own ? $t('attendance.title') : (string) $title,
    'subtitle' => $own ? $t('attendance.subtitle') : ($emp !== null ? (string) $emp['employee_code'] . ' · ' . $t('attendance.readonly_view') : ''), 'actions' => $actions]) ?>

<?php if ($own && $me === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('attendance.no_employee_title'), 'text' => $t('attendance.error.no_employee')]) ?>
<?php else: ?>
<div class="att-meta">
  <p class="text-sm text-muted" role="status"><?= $ui('icon', ['name' => 'refresh', 'size' => 'sm']) ?>
    <?= $e($lastPull !== null ? $t('attendance.data_as_of', ['time' => $date($lastPull)]) : $t('attendance.data_never')) ?></p>
  <?php if ($schedule === null): ?>
    <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'info-circle', 'size' => 'sm']) ?> <?= $e($t('attendance.no_schedule_note')) ?></p>
  <?php endif; ?>
</div>

<section class="stack-sm" aria-labelledby="am-h">
  <div class="row row--between">
    <h2 id="am-h" class="hub-group__title"><?= $e($label) ?></h2>
    <nav class="row" aria-label="<?= $e($t('attendance.month_nav')) ?>">
      <?= $ui('button', ['label' => $t('attendance.prev_month'), 'href' => $url($base . '?month=' . $prev), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left']) ?>
      <?= $ui('button', ['label' => $t('attendance.next_month'), 'href' => $url($base . '?month=' . $next), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-right']) ?>
    </nav>
  </div>
  <ul class="att-stats" role="list">
    <li class="card att-stat"><span class="att-stat__label"><?= $e($t('attendance.sum.worked_days')) ?></span><span class="att-stat__value"><?= (int) $totals['worked_days'] ?></span>
      <span class="att-stat__meta"><?= $e($t('attendance.sum.hours', ['h' => $attMin($totals['worked_minutes'])])) ?></span></li>
    <li class="card att-stat"><span class="att-stat__label"><?= $e($t('attendance.sum.late')) ?></span><span class="att-stat__value"><?= (int) $totals['late_count'] ?></span>
      <span class="att-stat__meta"><?= $e($t('attendance.sum.minutes', ['n' => (string) $totals['late_minutes']])) ?></span></li>
    <li class="card att-stat"><span class="att-stat__label"><?= $e($t('attendance.sum.missing')) ?></span><span class="att-stat__value"><?= (int) $totals['missing'] ?></span>
      <span class="att-stat__meta"><?= $e($t('attendance.sum.absent', ['n' => (string) $totals['absent']])) ?></span></li>
    <li class="card att-stat"><span class="att-stat__label"><?= $e($t('attendance.sum.leave')) ?></span><span class="att-stat__value"><?= $e(rtrim(rtrim(number_format((float) $totals['leave'], 1, '.', ''), '0'), '.')) ?></span>
      <span class="att-stat__meta"><?= $e($t('attendance.sum.days')) ?></span></li>
  </ul>

  <?php if ($days === []): ?>
    <p class="text-sm text-muted"><?= $e($t('attendance.no_days')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="am-h" tabindex="0">
    <table class="table table--stack att-table">
      <thead><tr>
        <th scope="col"><?= $e($t('attendance.field.date')) ?></th><th scope="col"><?= $e($t('attendance.field.in')) ?></th><th scope="col"><?= $e($t('attendance.field.out')) ?></th>
        <th scope="col"><?= $e($t('attendance.field.worked')) ?></th><th scope="col"><?= $e($t('attendance.field.late')) ?></th><th scope="col"><?= $e($t('attendance.field.early')) ?></th>
        <th scope="col"><?= $e($t('attendance.field.status')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach (array_reverse($days, true) as $d => $row): $off = in_array($row['status'], ['off', 'leave'], true); ?>
        <tr class="<?= $off ? 'att-row--off' : '' ?>">
          <th scope="row" data-label="<?= $e($t('attendance.field.date')) ?>"><a href="<?= $e($url($dayLink($d))) ?>"><?= $e(DateFormat::day($d, $loc)) ?></a><?php if (($row['shift_code'] ?? null) !== null): ?> <span class="shift-chip shift-chip--other" title="<?= $e($t('attendance.shift')) ?>"><?= $e((string) $row['shift_code'] === 'OFF' ? $t('attendance.shift_off') : (string) $row['shift_code']) ?></span><?php endif; ?>
            <?php if (($row['day_name'] ?? '') !== '' || ($row['leave_type'] ?? '') !== ''): ?><span class="att-sub"><?= $e(trim((string) ($row['leave_type'] ?? '') . ' ' . (string) ($row['day_name'] ?? ''))) ?></span><?php endif; ?></th>
          <td data-label="<?= $e($t('attendance.field.in')) ?>"><?= $e($attTime($row['first_in'], $tz)) ?><?= $row['in_source'] === 'correction' ? ' <span class="att-fixed" title="' . $e($t('attendance.from_correction')) . '">✎</span>' : '' ?></td>
          <td data-label="<?= $e($t('attendance.field.out')) ?>"><?= $e($attTime($row['last_out'], $tz)) ?><?= $row['out_source'] === 'correction' ? ' <span class="att-fixed" title="' . $e($t('attendance.from_correction')) . '">✎</span>' : '' ?></td>
          <td data-label="<?= $e($t('attendance.field.worked')) ?>"><?= $e($attMin($row['worked_minutes'])) ?></td>
          <td data-label="<?= $e($t('attendance.field.late')) ?>"><?= $e($row['late_minutes'] === null ? '—' : (string) $row['late_minutes']) ?></td>
          <td data-label="<?= $e($t('attendance.field.early')) ?>"><?= $e($row['early_minutes'] === null ? '—' : (string) $row['early_minutes']) ?></td>
          <td data-label="<?= $e($t('attendance.field.status')) ?>"><?= $attStatus((string) $row['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-sm text-muted"><?= $e($t('attendance.table_help')) ?></p>
  <?php endif; ?>
</section>

<?php if ($corrections !== []): ?>
<section class="card stack-sm" aria-labelledby="ac-h">
  <h2 id="ac-h" class="card__title"><?= $e($t($own ? 'attendance.my_corrections' : 'attendance.corrections')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="ac-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('attendance.field.date')) ?></th><th scope="col"><?= $e($t('attendance.field.kind')) ?></th><th scope="col"><?= $e($t('attendance.field.proposed')) ?></th><th scope="col"><?= $e($t('attendance.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($corrections as $c): ?>
        <tr>
          <td data-label="<?= $e($t('attendance.field.date')) ?>"><a href="<?= $e($url('/attendance/c/' . $c['public_id'])) ?>"><?= $e(DateFormat::day((string) $c['work_date'], $loc)) ?></a></td>
          <td data-label="<?= $e($t('attendance.field.kind')) ?>"><?= $e($t($kindKey((string) $c['kind']))) ?></td>
          <td data-label="<?= $e($t('attendance.field.proposed')) ?>"><?= $e(($c['proposed_in'] !== null ? substr((string) $c['proposed_in'], 0, 5) : '—') . ' → ' . ($c['proposed_out'] !== null ? substr((string) $c['proposed_out'], 0, 5) : '—')) ?></td>
          <td data-label="<?= $e($t('attendance.field.status')) ?>"><?= $corrStatus((string) $c['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>
