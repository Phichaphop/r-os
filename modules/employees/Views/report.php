<?php
/**
 * Personnel-file completeness (M09 A3 — HR / internal audit with employees.report): counts per employee, no file
 * contents and no personal data. Export to Excel is audited.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var int $le @var array<string, array<int, string>> $org
 */
include __DIR__ . '/_helpers.php';
$configured = array_filter($rows, static fn (array $r): bool => (bool) $r['configured']);
$full = count(array_filter($configured, static fn (array $r): bool => (int) $r['percent'] === 100));
$sum = static fn (string $k): int => array_sum(array_map(static fn (array $r): int => (int) $r[$k], $rows));
?>
<?= $ui('page-header', ['title' => $t('employees.report_title'), 'subtitle' => $t('employees.report_subtitle'),
    'actions' => $ui('button', ['label' => $t('employees.export_xlsx'), 'href' => $url('/employees/report/export' . ($le ? '?le=' . $le : '')), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php if (count($org['legal_entity']) > 1): ?>
<form method="get" action="<?= $e($url('/employees/report')) ?>" class="filter-bar">
  <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('employees.field.company'), 'value' => (string) ($le ?: ''), 'options' => ['' => $t('employees.all')] + $org['legal_entity']]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<?php endif; ?>

<div class="grid grid--4">
  <div class="card stat-card"><div class="stat__label"><?= $e($t('employees.report_people')) ?></div><div class="stat__value"><?= count($rows) ?></div></div>
  <div class="card stat-card"><div class="stat__label"><?= $e($t('employees.report_complete')) ?></div><div class="stat__value"><?= $full ?></div></div>
  <div class="card stat-card<?= $sum('pending') > 0 ? ' stat-card--warn' : '' ?>"><div class="stat__label"><?= $e($t('employees.report_pending')) ?></div><div class="stat__value"><?= $sum('pending') ?></div></div>
  <div class="card stat-card<?= $sum('expired') + $sum('missing') > 0 ? ' stat-card--bad' : '' ?>"><div class="stat__label"><?= $e($t('employees.report_problems')) ?></div><div class="stat__value"><?= $sum('expired') + $sum('missing') ?></div></div>
</div>
<?php if (count($configured) < count($rows)): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('employees.completeness_no_rules'), 'text' => $t('employees.report_no_rules')]) ?>
<?php endif; ?>

<section class="card card--flush" aria-labelledby="er-h">
  <div class="card__head"><h2 id="er-h" class="card__title"><?= $e($t('employees.report_list')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="er-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.field.code')) ?></th><th scope="col"><?= $e($t('employees.field.name')) ?></th><th scope="col"><?= $e($t('employees.field.status')) ?></th>
        <th scope="col" class="num"><?= $e($t('employees.completeness')) ?></th><th scope="col" class="num"><?= $e($t('employees.req_state.missing')) ?></th>
        <th scope="col" class="num"><?= $e($t('employees.req_state.pending')) ?></th><th scope="col" class="num"><?= $e($t('employees.req_state.expired')) ?></th>
        <th scope="col" class="num"><?= $e($t('employees.expiring')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td data-label="<?= $e($t('employees.field.code')) ?>"><a href="<?= $e($url('/employees/' . $r['public_id'] . '?tab=file')) ?>"><?= $e((string) $r['employee_code']) ?></a></td>
          <td data-label="<?= $e($t('employees.field.name')) ?>"><?= $e((string) $r['name_th']) ?></td>
          <td data-label="<?= $e($t('employees.field.status')) ?>"><?= $statusBadge((string) $r['status']) ?></td>
          <td data-label="<?= $e($t('employees.completeness')) ?>" class="num"><?= $r['configured'] ? (int) $r['percent'] . '%' : '—' ?></td>
          <td data-label="<?= $e($t('employees.req_state.missing')) ?>" class="num"><?= (int) $r['missing'] ?></td>
          <td data-label="<?= $e($t('employees.req_state.pending')) ?>" class="num"><?= (int) $r['pending'] ?></td>
          <td data-label="<?= $e($t('employees.req_state.expired')) ?>" class="num"><?= (int) $r['expired'] ?></td>
          <td data-label="<?= $e($t('employees.expiring')) ?>" class="num"><?= (int) $r['expiring'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="8"><?= $e($t('employees.none')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
