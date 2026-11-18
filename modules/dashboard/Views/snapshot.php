<?php
/**
 * One snapshot: what the dashboard showed, with versions and times, as kept for a decision (read only).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var array<string, mixed> $snap @var list<array<string, mixed>> $lines @var array<string, mixed> $filter @var string $lePid
 */
include __DIR__ . '/_fmt.php';
?>
<?= $ui('page-header', ['title' => $t('dashboard.snapshot_title'), 'subtitle' => (string) $snap['note'], 'crumbs' => [[$t('dashboard.snapshots_title'), $url('/dashboard/snapshots?le=' . rawurlencode($lePid))], [(string) $snap['period'], null]]]) ?>
<section class="card stack-sm" aria-labelledby="dp-d">
  <h2 id="dp-d" class="card__title"><?= $e($t('dashboard.details')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('dashboard.field.month')) ?></dt><dd><?= $e((string) $snap['period']) ?></dd></div>
    <div><dt><?= $e($t('dashboard.filters')) ?></dt><dd><?= $e(($filter['sites'] ?? []) === [] && ($filter['departments'] ?? []) === [] ? $t('dashboard.all') : $t('dashboard.filtered_n', ['s' => (string) count((array) ($filter['sites'] ?? [])), 'd' => (string) count((array) ($filter['departments'] ?? []))])) ?></dd></div>
    <div><dt><?= $e($t('dashboard.saved_by')) ?></dt><dd><?= $e((string) ($snap['actor'] ?? '—')) ?> · <?= $e($date((string) $snap['created_at'])) ?></dd></div>
  </dl>
</section>
<section class="card stack-sm" aria-labelledby="dp-l">
  <h2 id="dp-l" class="card__title"><?= $e($t('dashboard.cards')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="dp-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('dashboard.col.kpi')) ?></th><th scope="col"><?= $e($t('dashboard.col.value')) ?></th><th scope="col"><?= $e($t('dashboard.col.status')) ?></th><th scope="col"><?= $e($t('dashboard.col.computed_at')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): ?>
        <tr><th scope="row" data-label="<?= $e($t('dashboard.col.kpi')) ?>"><?= $e($l['code'] . ' · ' . $l['name']) ?> <span class="text-sm text-muted">v<?= (int) $l['version'] ?></span><span class="d-block text-sm text-muted"><?= $e((string) $l['formula']) ?></span></th>
          <td data-label="<?= $e($t('dashboard.col.value')) ?>"><?= $e($fmt($l['value'], (string) $l['unit'])) ?></td>
          <td data-label="<?= $e($t('dashboard.col.status')) ?>"><?= $ui('badge', ['tone' => $statusTone((string) $l['status']), 'label' => $t('dashboard.status.' . $l['status'])]) ?></td>
          <td data-label="<?= $e($t('dashboard.col.computed_at')) ?>"><?= $e($l['computed_at'] !== null ? $date((string) $l['computed_at']) : '—') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
