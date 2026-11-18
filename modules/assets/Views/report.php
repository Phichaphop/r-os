<?php
/**
 * Report: counts and recorded cost by site, type, cost centre and status; who holds what; leavers still holding
 * assets; warranties ending in 90 days. Cost = recorded purchase cost (no depreciation).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, string> $companies @var string $lePid
 * @var array<string, mixed> $report @var string $here @var bool $canView @var bool $canManage
 */
$money = static fn (mixed $x): string => number_format((float) $x, 2);
$table = static function (string $id, string $title, string $col, array $rows, bool $status = false) use ($e, $t, $money): string {
    $h = '<section class="card card--flush" aria-labelledby="' . $id . '"><div class="card__head"><h2 id="' . $id . '" class="card__title">' . $e($title) . '</h2></div>';
    if ($rows === []) {
        return $h . '<div class="card__pad"><p class="text-muted">' . $e($t('assets.none')) . '</p></div></section>';
    }
    $h .= '<div class="table-wrap" role="region" aria-labelledby="' . $id . '" tabindex="0"><table class="table table--stack"><thead><tr><th scope="col">' . $e($col) . '</th><th scope="col">' . $e($t('assets.col.count')) . '</th><th scope="col">'
        . $e($t('assets.col.cost_total')) . '</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><th scope="row" data-label="' . $e($col) . '">' . $e($status ? $t('assets.status.' . $r['label']) : (string) $r['label']) . '</th><td data-label="' . $e($t('assets.col.count')) . '">' . (int) $r['n'] . '</td><td data-label="'
            . $e($t('assets.col.cost_total')) . '">' . $e($money($r['cost'])) . ((int) $r['no_cost'] > 0 ? ' <span class="text-sm text-muted">' . $e($t('assets.no_cost_n', ['n' => (string) $r['no_cost']])) . '</span>' : '') . '</td></tr>';
    }
    return $h . '</tbody></table></div></section>';
};
?>
<?= $ui('page-header', ['title' => $t('assets.report_title'), 'subtitle' => $t('assets.report_subtitle'),
    'actions' => $ui('button', ['label' => $t('assets.export'), 'href' => $url('/assets/export?le=' . rawurlencode($lePid)), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<p class="text-sm text-muted"><?= $e($t('assets.cost_note')) ?></p>
<section class="card card--flush" aria-labelledby="ar-l">
  <div class="card__head"><h2 id="ar-l" class="card__title"><?= $e($t('assets.leavers', ['n' => (string) count($report['leavers'])])) ?></h2></div>
  <?php if ($report['leavers'] === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('assets.leavers_none')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ar-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('assets.field.code')) ?></th><th scope="col"><?= $e($t('assets.field.custodian')) ?></th><th scope="col"><?= $e($t('assets.col.left_on')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report['leavers'] as $l): ?>
        <tr><th scope="row" data-label="<?= $e($t('assets.field.code')) ?>"><a href="<?= $e($url('/assets/a/' . $l['public_id'])) ?>"><?= $e($l['code'] . ' · ' . $l['name']) ?></a></th>
          <td data-label="<?= $e($t('assets.field.custodian')) ?>"><?= $e($l['custodian_name'] . ($l['employee_code'] !== null ? ' (' . $l['employee_code'] . ')' : '')) ?></td>
          <td data-label="<?= $e($t('assets.col.left_on')) ?>"><?= $e($l['termination_date'] !== null ? $date((string) $l['termination_date'], false) : $t('assets.account_inactive')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<div class="grid grid--2">
  <?= $table('ar-s', $t('assets.by_site'), $t('assets.field.site'), $report['by_site']) ?>
  <?= $table('ar-t', $t('assets.by_type'), $t('assets.field.type'), $report['by_type']) ?>
  <?= $table('ar-d', $t('assets.by_department'), $t('assets.field.department'), $report['by_department']) ?>
  <?= $table('ar-st', $t('assets.by_status'), $t('assets.field.status'), $report['by_status'], true) ?>
</div>
<section class="card card--flush" aria-labelledby="ar-h">
  <div class="card__head"><h2 id="ar-h" class="card__title"><?= $e($t('assets.holders')) ?></h2></div>
  <?php if ($report['holders'] === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('assets.none')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ar-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('assets.field.custodian')) ?></th><th scope="col"><?= $e($t('assets.col.count')) ?></th><th scope="col"><?= $e($t('assets.not_acknowledged')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report['holders'] as $h): ?>
        <tr><th scope="row" data-label="<?= $e($t('assets.field.custodian')) ?>"><?= $e($h['label'] . ' (' . $h['username'] . ')') ?></th><td data-label="<?= $e($t('assets.col.count')) ?>"><?= (int) $h['n'] ?></td><td data-label="<?= $e($t('assets.not_acknowledged')) ?>"><?= (int) $h['unacknowledged'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="ar-w">
  <h2 id="ar-w" class="card__title"><?= $e($t('assets.warranty_ending')) ?></h2>
  <?php if ($report['warranty'] === []): ?><p class="text-muted"><?= $e($t('assets.none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm"><?php foreach ($report['warranty'] as $w): ?><li><a href="<?= $e($url('/assets/a/' . $w['public_id'])) ?>"><?= $e($w['code'] . ' · ' . $w['name']) ?></a> — <?= $e($date((string) $w['warranty_until'], false)) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
