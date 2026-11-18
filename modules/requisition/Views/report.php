<?php
/**
 * Report of a month (optionally one site): requests by status, quota use this period, stock of the site, movements;
 * Excel export.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var string $month @var list<array<string, mixed>> $sites @var ?int $siteId
 * @var array<string, mixed> $report @var string $here @var bool $canIssue @var bool $canManage
 */
$sitePid = '';
foreach ($sites as $s) {
    if ((int) $s['id'] === $siteId) {
        $sitePid = (string) $s['public_id'];
    }
}
$q = http_build_query(array_filter(['le' => $lePid, 'month' => $month, 'site' => $sitePid]));
?>
<?= $ui('page-header', ['title' => $t('requisition.report_title'), 'subtitle' => $t('requisition.report_subtitle'),
    'actions' => $ui('button', ['label' => $t('requisition.export'), 'href' => $url('/requisition/report/export?' . $q), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<form method="get" action="<?= $e($url('/requisition/report')) ?>" class="card row">
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?= $ui('field', ['name' => 'month', 'type' => 'month', 'label' => $t('requisition.field.month'), 'value' => $month]) ?>
  <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('requisition.field.site'), 'value' => $sitePid, 'options' => ['' => $t('requisition.all_sites')] + array_column($sites, 'code', 'public_id')]) ?>
  <?= $ui('button', ['label' => $t('requisition.show'), 'type' => 'submit', 'variant' => 'secondary']) ?>
</form>
<section class="card stack-sm" aria-labelledby="rr-s-h">
  <h2 id="rr-s-h" class="card__title"><?= $e($t('requisition.by_status', ['n' => (string) count($report['requests'])])) ?></h2>
  <?php if ($report['by_status'] === []): ?><p class="text-muted"><?= $e($t('requisition.none_month')) ?></p><?php else: ?>
  <p class="row"><?php foreach ($report['by_status'] as $st => $n): ?><span><?php include __DIR__ . '/_status.php'; ?> <?= (int) $n ?></span><?php endforeach; ?></p>
  <?php endif; ?>
</section>
<section class="card card--flush" aria-labelledby="rr-q-h">
  <div class="card__head"><h2 id="rr-q-h" class="card__title"><?= $e($t('requisition.quota_use')) ?></h2></div>
  <?php if ($report['quota'] === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('requisition.no_quota_set')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rr-q-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('requisition.col.department')) ?></th><th scope="col"><?= $e($t('requisition.field.name')) ?></th><th scope="col"><?= $e($t('requisition.col.used')) ?></th><th scope="col"><?= $e($t('requisition.col.left')) ?></th><th scope="col"><?= $e($t('requisition.col.period')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report['quota'] as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('requisition.col.department')) ?>"><?= $e((string) $x['dept_code']) ?></th>
          <td data-label="<?= $e($t('requisition.field.name')) ?>"><?= $e($x['item_code'] . ' · ' . $x['item_name']) ?></td>
          <td data-label="<?= $e($t('requisition.col.used')) ?>"><?= $e($x['used'] . ' / ' . $x['qty'] . ' ' . $x['unit']) ?><?= $x['used'] > (int) $x['qty'] ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('requisition.over_quota_badge')]) : '' ?></td>
          <td data-label="<?= $e($t('requisition.col.left')) ?>"><?= (int) $x['left'] ?></td>
          <td data-label="<?= $e($t('requisition.col.period')) ?>"><?= $e($date((string) $x['from'], false) . ' – ' . $date((string) $x['to'], false)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card card--flush" aria-labelledby="rr-r-h">
  <div class="card__head"><h2 id="rr-r-h" class="card__title"><?= $e($t('requisition.requests_month')) ?></h2></div>
  <?php if ($report['requests'] === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('requisition.none_month')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rr-r-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('requisition.col.ref')) ?></th><th scope="col"><?= $e($t('requisition.col.requester')) ?></th><th scope="col"><?= $e($t('requisition.field.site')) ?></th><th scope="col"><?= $e($t('requisition.col.lines')) ?></th><th scope="col"><?= $e($t('requisition.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report['requests'] as $row): $st = (string) $row['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('requisition.col.ref')) ?>"><a href="<?= $e($url('/requisition/r/' . $row['public_id'])) ?>"><?= $e((string) $row['ref']) ?></a></th>
          <td data-label="<?= $e($t('requisition.col.requester')) ?>"><?= $e($row['requester_name'] . ($row['dept_code'] !== null ? ' · ' . $row['dept_code'] : '')) ?></td>
          <td data-label="<?= $e($t('requisition.field.site')) ?>"><?= $e((string) $row['site_code']) ?></td>
          <td data-label="<?= $e($t('requisition.col.lines')) ?>"><?= (int) $row['line_count'] ?></td>
          <td data-label="<?= $e($t('requisition.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?><?= (int) $row['over_quota'] === 1 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('requisition.over_quota_badge')]) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card card--flush" aria-labelledby="rr-l-h">
  <div class="card__head"><h2 id="rr-l-h" class="card__title"><?= $e($t('requisition.moves_month')) ?></h2></div>
  <?php $rows = $report['ledger']; include __DIR__ . '/_ledger.php'; ?>
</section>
