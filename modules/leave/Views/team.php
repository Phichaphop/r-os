<?php
/**
 * Team / company leave calendar for one month: one row per person, one column per day.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array{employees: array<int, array<string, mixed>>, leaves: list<array<string, mixed>>, team: bool, hr: bool} $cal
 * @var string $month @var string $today @var array<int, string> $companies @var int $le
 * @var array<int, int> $days day of month => weekday (0 = Sunday) @var string $label @var string $prev @var string $next
 */
$loc = (string) ($v['locale'] ?? 'th');
$dow = $loc === 'th' ? ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'] : ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
// employee id → day → [code, status, part, request]
$grid = [];
foreach ($cal['leaves'] as $l) {
    foreach ((array) json_decode((string) $l['days_json'], true) as $d) {
        if (str_starts_with((string) $d, $month)) {
            $grid[(int) $l['employee_id']][(int) substr((string) $d, 8, 2)] = $l;
        }
    }
}
$typeName = static fn (array $l): string => $loc === 'en' && ($l['type_en'] ?? '') !== '' ? (string) $l['type_en'] : (string) $l['type_th'];
$q = $le > 0 ? '&le=' . $le : '';
?>
<?= $ui('page-header', ['title' => $t('leave.team_title'), 'subtitle' => $t($cal['hr'] ? 'leave.team_subtitle_hr' : 'leave.team_subtitle'),
    'actions' => $ui('button', ['label' => $t('leave.title'), 'href' => $url('/leave'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<div class="row row--between">
  <nav class="row" aria-label="<?= $e($t('leave.month_nav')) ?>">
    <?= $ui('button', ['label' => $t('leave.prev_month'), 'href' => $url('/leave/team?month=' . $prev . $q), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left']) ?>
    <h2 class="hub-group__title leave-month"><?= $e($label) ?></h2>
    <?= $ui('button', ['label' => $t('leave.next_month'), 'href' => $url('/leave/team?month=' . $next . $q), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-right']) ?>
  </nav>
  <?php if (count($companies) > 1): ?>
  <form method="get" action="<?= $e($url('/leave/team')) ?>" class="filter-bar">
    <input type="hidden" name="month" value="<?= $e($month) ?>">
    <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('employees.field.company'), 'value' => $le > 0 ? (string) $le : '', 'options' => ['' => $t('employees.all')] + $companies]) ?>
    <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
  </form>
  <?php endif; ?>
</div>
<?php if ($cal['employees'] === []): ?>
  <p class="text-sm text-muted"><?= $e($t('leave.team_empty')) ?></p>
<?php else: ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('leave.team_title') . ' ' . $label) ?>" tabindex="0">
  <table class="table leave-grid">
    <caption class="sr-only"><?= $e($t('leave.team_title') . ' ' . $label) ?></caption>
    <thead><tr><th scope="col" class="leave-grid__name"><?= $e($t('leave.field.employee')) ?></th>
      <?php foreach ($days as $d => $w): $isToday = sprintf('%s-%02d', $month, $d) === $today; ?>
        <th scope="col" class="leave-grid__day<?= $w === 0 || $w === 6 ? ' is-weekend' : '' ?><?= $isToday ? ' is-today' : '' ?>"><span class="leave-grid__dow"><?= $e($dow[$w]) ?></span><?= $d ?></th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($cal['employees'] as $id => $emp): ?>
      <tr>
        <th scope="row" class="leave-grid__name"><?= $e((string) $emp['employee_code']) ?> · <?= $e((string) (($emp['preferred_name'] ?? '') !== '' ? $emp['preferred_name'] : $emp['name_th'])) ?></th>
        <?php foreach (array_keys($days) as $d): $l = $grid[$id][$d] ?? null; ?>
          <?php $wk = $days[$d] === 0 || $days[$d] === 6 ? ' is-weekend' : ''; ?>
          <?php if ($l === null): ?><td class="leave-grid__cell<?= $wk ?>"></td>
          <?php else: $pending = in_array($l['status'], ['pending', 'cancel_pending'], true); $txt = $typeName($l) . ($l['part'] !== 'full' ? ' (' . $t('leave.part.' . $l['part']) . ')' : '') . ' · ' . $t('leave.status.' . $l['status']); ?>
            <td class="leave-grid__cell<?= $wk ?>"><a class="leave-chip<?= $pending ? ' is-pending' : '' ?><?= $l['part'] !== 'full' ? ' is-half' : '' ?>" href="<?= $e($url('/leave/r/' . $l['public_id'])) ?>"
              title="<?= $e($txt) ?>" aria-label="<?= $e($txt) ?>"><?= $e(mb_substr((string) $l['type_code'], 0, 2)) ?></a></td>
          <?php endif; ?>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="text-sm text-muted leave-legend">
  <span class="leave-chip" aria-hidden="true">AN</span> <?= $e($t('leave.legend_approved')) ?>
  <span class="leave-chip is-pending" aria-hidden="true">AN</span> <?= $e($t('leave.legend_pending')) ?>
  <span class="leave-chip is-half" aria-hidden="true">AN</span> <?= $e($t('leave.legend_half')) ?>
  · <?= $e($t('leave.legend_codes')) ?>
</p>
<?php endif; ?>
