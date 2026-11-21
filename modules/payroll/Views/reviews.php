<?php
/**
 * Payroll Checker reviews (R8-4 · Q-11): approved runs of a company by pay period — each run reviewed on its own
 * (regular, off-cycle, correction) — with the latest review and a filter by state. Amounts are not shown here.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var array<string, string> $companies @var string $lePid @var list<array{start: string, end: string, runs: list<array<string, mixed>>}> $periods
 * @var array{waiting: int, ok: int, issues: int} $counts @var string $state @var bool $appointed @var bool $canReview
 */
$here = '/payroll/reviews';
$stateTone = ['waiting' => 'warning', 'ok' => 'success', 'issues' => 'danger'];
$stateIcon = ['waiting' => 'clock', 'ok' => 'circle-check', 'issues' => 'alert-triangle'];
?>
<?= $ui('page-header', ['title' => $t('payroll.review.title'), 'subtitle' => $t('payroll.review.subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (!$appointed): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('payroll.review.none_title'), 'text' => $t('payroll.review.none_text')]) ?>
<?php elseif ($canReview): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('payroll.review.how')]) ?>
<?php endif; ?>
<nav class="segmented-links" aria-label="<?= $e($t('payroll.review.filter')) ?>">
  <a href="<?= $e($url($here . '?le=' . rawurlencode($lePid))) ?>"<?= $state === '' ? ' aria-current="page"' : '' ?>><?= $e($t('payroll.review.all')) ?></a>
  <?php foreach ($counts as $k => $n): ?>
    <a href="<?= $e($url($here . '?le=' . rawurlencode($lePid) . '&state=' . $k)) ?>"<?= $state === $k ? ' aria-current="page"' : '' ?>><?= $e($t('payroll.review.state.' . $k)) ?> (<?= (int) $n ?>)</a>
  <?php endforeach; ?>
</nav>
<?php foreach ($periods as $i => $p): ?>
<section class="card stack-sm" aria-labelledby="rv-<?= (int) $i ?>">
  <h2 id="rv-<?= (int) $i ?>" class="card__title"><?= $e($t('payroll.review.period', ['from' => $date($p['start'], false), 'to' => $date($p['end'], false)])) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="rv-<?= (int) $i ?>" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('payroll.field.run')) ?></th><th scope="col"><?= $e($t('payroll.field.run_type')) ?></th><th scope="col"><?= $e($t('payroll.field.pay_date')) ?></th><th scope="col"><?= $e($t('payroll.review.col.approved')) ?></th><th scope="col"><?= $e($t('payroll.review.col.state')) ?></th><th scope="col"><?= $e($t('payroll.review.col.by')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($p['runs'] as $r): ?>
        <tr><th scope="row" data-label="<?= $e($t('payroll.field.run')) ?>"><a href="<?= $e($url('/payroll/runs/' . $r['public_id'] . '#review')) ?>"><?= $e('#' . $r['run_no'] . ' · ' . $r['label']) ?></a></th>
          <td data-label="<?= $e($t('payroll.field.run_type')) ?>"><?= $ui('badge', ['tone' => $r['run_type'] === 'correction' ? 'warning' : ($r['run_type'] === 'off_cycle' ? 'info' : 'neutral'), 'label' => $t('payroll.run_type.' . $r['run_type'])]) ?></td>
          <td data-label="<?= $e($t('payroll.field.pay_date')) ?>"><?= $e($date((string) $r['pay_date'], false)) ?></td>
          <td data-label="<?= $e($t('payroll.review.col.approved')) ?>"><?= $e($date((string) $r['approved_at'], false)) ?></td>
          <td data-label="<?= $e($t('payroll.review.col.state')) ?>"><span><?= $ui('badge', ['tone' => $stateTone[$r['state']], 'icon' => $stateIcon[$r['state']], 'label' => $t('payroll.review.state.' . $r['state'])]) ?><?php if ((int) $r['review_count'] > 1): ?> <span class="text-sm text-muted"><?= $e($t('payroll.review.times', ['n' => (string) $r['review_count']])) ?></span><?php endif; ?></span></td>
          <td data-label="<?= $e($t('payroll.review.col.by')) ?>"><?= $r['reviewer'] !== null ? $e($r['reviewer'] . ' · ' . $date((string) $r['reviewed_at'], false)) : '—' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endforeach; ?>
<?php if ($periods === []): ?>
  <section class="card"><?= $ui('states', ['icon' => 'clipboard-check', 'title' => $t($state === '' ? 'payroll.review.empty' : 'payroll.review.empty_state')]) ?></section>
<?php endif; ?>
