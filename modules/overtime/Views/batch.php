<?php
/** One payroll batch. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, mixed> $b */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$day = static fn (string $d): string => DateFormat::day($d, $loc);
$tot = (array) $b['totals'];
?>
<?= $ui('page-header', ['title' => $t('overtime.batch_title', ['n' => (string) $b['batch_no']]), 'subtitle' => $b['le_code'] . ' · ' . $day((string) $b['period_start']) . ' – ' . $day((string) $b['period_end']),
    'crumbs' => [[$t('overtime.admin_title'), $url('/overtime/admin')], [$t('overtime.batch_title', ['n' => (string) $b['batch_no']]), null]],
    'actions' => $b['intact'] ? $ui('button', ['label' => $t('overtime.download_xlsx'), 'href' => $url('/overtime/batch/' . $b['public_id'] . '/file'), 'variant' => 'primary', 'icon' => 'download'])
        . $ui('button', ['label' => 'CSV', 'href' => $url('/overtime/batch/' . $b['public_id'] . '/file?format=csv'), 'variant' => 'secondary', 'icon' => 'download']) : '']) ?>
<?php if (!$b['intact']): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $t('overtime.error.batch_damaged')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="ct-h">
  <h2 id="ct-h" class="card__title"><?= $e($t('overtime.control_totals')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('overtime.field.lines')) ?></dt><dd><?= (int) $b['line_count'] ?></dd></div>
    <div><dt><?= $e($t('overtime.field.total')) ?></dt><dd><?= $e($hm((int) $b['total_minutes'])) ?></dd></div>
    <?php foreach (['workday', 'dayoff', 'holiday', 'unknown'] as $d): if ((int) ($tot[$d] ?? 0) === 0 && $d === 'unknown') { continue; } ?>
      <div><dt><?= $e($t('overtime.day_type.' . $d)) ?></dt><dd><?= $e($hm((int) ($tot[$d] ?? 0))) ?></dd></div>
    <?php endforeach; ?>
    <div><dt><?= $e($t('overtime.meta.hash')) ?></dt><dd class="mono break-all"><?= $e((string) $b['lines_hash']) ?></dd></div>
  </dl>
</section>
<section class="card stack-sm" aria-labelledby="ln-h">
  <h2 id="ln-h" class="card__title"><?= $e($t('overtime.field.lines')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="ln-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('overtime.field.employee')) ?></th><th scope="col"><?= $e($t('overtime.field.work_date')) ?></th><th scope="col"><?= $e($t('overtime.field.day_type')) ?></th>
        <th scope="col"><?= $e($t('overtime.rules.class')) ?></th><th scope="col"><?= $e($t('overtime.field.requested')) ?></th><th scope="col"><?= $e($t('overtime.field.actual')) ?></th><th scope="col"><?= $e($t('overtime.field.payable')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($b['lines'] as $l): ?>
        <tr><td data-label="<?= $e($t('overtime.field.employee')) ?>"><?= $e($l['employee_code'] . ' · ' . $l['employee_name']) ?></td>
          <td data-label="<?= $e($t('overtime.field.work_date')) ?>"><?= $e($day((string) $l['work_date']) . ' ' . $l['start_local'] . '–' . $l['end_local']) ?></td>
          <td data-label="<?= $e($t('overtime.field.day_type')) ?>"><?= $e($t('overtime.day_type.' . $l['day_type'])) ?></td>
          <td data-label="<?= $e($t('overtime.rules.class')) ?>"><?= $e(trim($l['rate_class'] . ' ' . ($l['multiplier'] !== '' ? '× ' . $l['multiplier'] : ''))) ?: '—' ?></td>
          <td data-label="<?= $e($t('overtime.field.requested')) ?>" class="num"><?= $e($hm((int) $l['requested_minutes'])) ?></td>
          <td data-label="<?= $e($t('overtime.field.actual')) ?>" class="num"><?= $e($hm($l['actual_minutes'] === null ? null : (int) $l['actual_minutes'])) ?></td>
          <td data-label="<?= $e($t('overtime.field.payable')) ?>" class="num"><?= $e($hm((int) $l['payable_minutes'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
