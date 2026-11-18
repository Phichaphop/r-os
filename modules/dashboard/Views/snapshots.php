<?php
/**
 * Snapshots kept as evidence (company — or only one's own when the viewer sees part of the company).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows
 */
?>
<?= $ui('page-header', ['title' => $t('dashboard.snapshots_title'), 'subtitle' => $t('dashboard.snapshots_subtitle'), 'crumbs' => [[$t('dashboard.title'), $url('/dashboard?le=' . rawurlencode($lePid))], [$t('dashboard.snapshots_title'), null]]]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('dashboard.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/dashboard/snapshots?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="ds-l">
  <h2 id="ds-l" class="card__title"><?= $e($t('dashboard.snapshots_title')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('dashboard.none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($rows as $s): ?><li><a href="<?= $e($url('/dashboard/snapshots/' . $s['public_id'])) ?>"><?= $e((string) $s['note']) ?></a>
      <span class="d-block text-sm text-muted"><?= $e($t('dashboard.snapshot_line', ['month' => (string) $s['period'], 'who' => (string) ($s['actor'] ?? '—'), 'when' => $date((string) $s['created_at'])])) ?></span></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
