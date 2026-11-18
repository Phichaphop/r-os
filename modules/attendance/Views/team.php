<?php
/**
 * Team / HR month: one line per employee with totals; exceptions stand out. Reads stored results — the button
 * recalculates the month for everyone listed.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $team @var int $le @var array<int, string> $companies @var ?string $lastPull
 * @var string $month @var string $label @var string $prev @var string $next
 */
include __DIR__ . '/_helpers.php';
$q = static fn (string $m): string => '/attendance/team?month=' . $m . ($le > 0 ? '&le=' . $le : '');
?>
<?= $ui('page-header', ['title' => $t('attendance.team_title'), 'subtitle' => $t('attendance.team_subtitle'),
    'actions' => $ui('button', ['label' => $t('attendance.title'), 'href' => $url('/attendance'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<?php if ($companies !== []): ?>
<form method="get" action="<?= $e($url('/attendance/team')) ?>" class="filter-bar">
  <input type="hidden" name="month" value="<?= $e($month) ?>">
  <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('employees.field.company'), 'value' => (string) $le, 'options' => [0 => $t('attendance.all_companies')] + $companies]) ?>
  <?= $ui('button', ['label' => $t('attendance.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<?php endif; ?>
<div class="row row--between">
  <h2 class="hub-group__title" id="tm-h"><?= $e($label) ?></h2>
  <nav class="row" aria-label="<?= $e($t('attendance.month_nav')) ?>">
    <?= $ui('button', ['label' => $t('attendance.prev_month'), 'href' => $url($q($prev)), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left']) ?>
    <?= $ui('button', ['label' => $t('attendance.next_month'), 'href' => $url($q($next)), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-right']) ?>
  </nav>
</div>
<div class="att-meta">
  <p class="text-sm text-muted"><?= $e($lastPull !== null ? $t('attendance.data_as_of', ['time' => $date($lastPull)]) : $t('attendance.data_never')) ?>
    · <?= $e($team['calculated_at'] !== null ? $t('attendance.calculated_at', ['time' => $date((string) $team['calculated_at'])]) : $t('attendance.not_calculated')) ?></p>
  <form method="post" action="<?= $e($url('/attendance/team/recalc')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="month" value="<?= $e($month) ?>"><input type="hidden" name="le" value="<?= $le ?>">
    <?= $ui('button', ['label' => $t('attendance.recalc'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'refresh']) ?>
  </form>
</div>
<?php if ($team['employees'] === []): ?>
  <p class="text-sm text-muted"><?= $e($t('attendance.team_empty')) ?></p>
<?php else: ?>
<div class="table-wrap" role="region" aria-labelledby="tm-h" tabindex="0">
  <table class="table table--stack">
    <thead><tr><th scope="col"><?= $e($t('attendance.field.employee')) ?></th><th scope="col"><?= $e($t('attendance.sum.worked_days')) ?></th><th scope="col"><?= $e($t('attendance.sum.late')) ?></th>
      <th scope="col"><?= $e($t('attendance.sum.missing')) ?></th><th scope="col"><?= $e($t('attendance.sum.absent_col')) ?></th><th scope="col"><?= $e($t('attendance.sum.leave')) ?></th><th scope="col"><?= $e($t('attendance.sum.pending')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($team['employees'] as $emp): $s = $team['totals'][(int) $emp['id']] ?? []; ?>
      <tr>
        <th scope="row" data-label="<?= $e($t('attendance.field.employee')) ?>"><a href="<?= $e($url('/attendance/e/' . $emp['public_id'] . '?month=' . $month)) ?>"><?= $e($emp['employee_code']) ?> · <?= $e((string) $emp['name_th']) ?></a></th>
        <td data-label="<?= $e($t('attendance.sum.worked_days')) ?>"><?= (int) ($s['worked_days'] ?? 0) ?> · <?= $e($attMin($s['worked_minutes'] ?? 0)) ?></td>
        <td data-label="<?= $e($t('attendance.sum.late')) ?>"><?= (int) ($s['late_count'] ?? 0) > 0 ? $ui('badge', ['tone' => 'warning', 'label' => $t('attendance.late_n', ['n' => (string) $s['late_count'], 'm' => (string) $s['late_minutes']])]) : '0' ?></td>
        <td data-label="<?= $e($t('attendance.sum.missing')) ?>"><?= (int) ($s['missing'] ?? 0) > 0 ? $ui('badge', ['tone' => 'danger', 'label' => (string) $s['missing']]) : '0' ?></td>
        <td data-label="<?= $e($t('attendance.sum.absent_col')) ?>"><?= (int) ($s['absent'] ?? 0) > 0 ? $ui('badge', ['tone' => 'danger', 'label' => (string) $s['absent']]) : '0' ?></td>
        <td data-label="<?= $e($t('attendance.sum.leave')) ?>"><?= $e(rtrim(rtrim(number_format((float) ($s['leave'] ?? 0), 1, '.', ''), '0'), '.')) ?></td>
        <td data-label="<?= $e($t('attendance.sum.pending')) ?>"><?= (int) ($s['pending'] ?? 0) > 0 ? $ui('badge', ['tone' => 'warning', 'icon' => 'hourglass', 'label' => (string) $s['pending']]) : '0' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
