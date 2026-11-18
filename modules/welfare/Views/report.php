<?php
/**
 * Welfare use per person and type in a year (by expense date) — claims, claimed, approved, paid, the yearly limit
 * and what is left; export to Excel (password first, recorded).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var int $year @var list<array<string, mixed>> $rows @var array<string, bool> $tabs @var string $here
 */
use Blue\Core\Support\Money;

$m = static fn (?int $u): string => $u === null ? '—' : Money::format($u);
$tot = ['claims' => 0, 'claimed' => 0, 'approved' => 0, 'paid' => 0];
foreach ($rows as $r) {
    foreach ($tot as $k => $_) {
        $tot[$k] += (int) $r[$k];
    }
}
?>
<?= $ui('page-header', ['title' => $t('welfare.report_title'), 'subtitle' => $t('welfare.report_subtitle'),
    'actions' => $ui('button', ['label' => $t('welfare.export'), 'href' => $url('/welfare/report/export?le=' . rawurlencode($lePid) . '&year=' . $year), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<nav class="segmented-links" aria-label="<?= $e($t('welfare.year')) ?>">
  <?php foreach ([$year - 1, $year] as $y): ?><a href="<?= $e($url('/welfare/report?le=' . rawurlencode($lePid) . '&year=' . $y)) ?>"<?= $y === $year ? ' aria-current="page"' : '' ?>><?= (int) $y ?></a><?php endforeach; ?>
</nav>
<section class="card card--flush" aria-labelledby="rp-t">
  <div class="card__head"><h2 id="rp-t" class="card__title"><?= $e($t('welfare.report_year', ['year' => (string) $year])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><p class="text-sm text-muted"><?= $e($t('welfare.report_none')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rp-t" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('welfare.col.employee')) ?></th><th scope="col"><?= $e($t('welfare.col.type')) ?></th><th scope="col" class="num"><?= $e($t('welfare.col.claims')) ?></th>
        <th scope="col" class="num"><?= $e($t('welfare.col.claimed')) ?></th><th scope="col" class="num"><?= $e($t('welfare.col.approved')) ?></th><th scope="col" class="num"><?= $e($t('welfare.col.paid')) ?></th>
        <th scope="col" class="num"><?= $e($t('welfare.col.limit')) ?></th><th scope="col" class="num"><?= $e($t('welfare.col.remaining')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t('welfare.col.employee')) ?>"><?= $e($r['employee_code'] . ' · ' . $r['name_th']) ?></th>
          <td data-label="<?= $e($t('welfare.col.type')) ?>"><?= $e($r['code'] . ' · ' . $r['type_name']) ?><?php if ((int) $r['waiting'] > 0): ?> <?= $ui('badge', ['tone' => 'info', 'label' => $t('welfare.waiting_n', ['n' => (string) $r['waiting']])]) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('welfare.col.claims')) ?>" class="num"><?= (int) $r['claims'] ?></td>
          <td data-label="<?= $e($t('welfare.col.claimed')) ?>" class="num"><?= $e($m($r['claimed'])) ?></td>
          <td data-label="<?= $e($t('welfare.col.approved')) ?>" class="num"><?= $e($m($r['approved'])) ?></td>
          <td data-label="<?= $e($t('welfare.col.paid')) ?>" class="num"><?= $e($m($r['paid'])) ?></td>
          <td data-label="<?= $e($t('welfare.col.limit')) ?>" class="num"><?= $e($m($r['limit'])) ?></td>
          <td data-label="<?= $e($t('welfare.col.remaining')) ?>" class="num"><?= $e($m($r['remaining'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th scope="row" colspan="2"><?= $e($t('welfare.total')) ?></th><td class="num"><?= (int) $tot['claims'] ?></td><td class="num"><?= $e($m($tot['claimed'])) ?></td><td class="num"><?= $e($m($tot['approved'])) ?></td><td class="num"><?= $e($m($tot['paid'])) ?></td><td></td><td></td></tr></tfoot>
    </table>
  </div>
  <?php endif; ?>
</section>
<p class="text-sm text-muted"><?= $e($t('welfare.report_help')) ?></p>
