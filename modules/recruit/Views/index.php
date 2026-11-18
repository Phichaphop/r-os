<?php
/**
 * Recruitment overview of a company: funnel, approved requisitions waiting for a posting, postings.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, string> $companies @var string $lePid
 * @var array{stages: array<string, array{count: int, days: float|null}>, postings: list<array<string, mixed>>} $funnel @var list<array<string, mixed>> $waiting @var bool $notice
 */
$actions = $ui('button', ['label' => $t('recruit.req_title'), 'href' => $url('/recruit/requisitions'), 'variant' => 'secondary', 'icon' => 'send'])
    . $ui('button', ['label' => $t('recruit.jobs_title'), 'href' => $url('/recruit/jobs'), 'variant' => 'ghost', 'icon' => 'external-link']);
?>
<?= $ui('page-header', ['title' => $t('recruit.title'), 'subtitle' => $t('recruit.subtitle'), 'actions' => $actions]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('recruit.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/recruit?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if (!$notice): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('recruit.error.no_notice'), 'title' => $t('recruit.notice_missing')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="ri-f">
  <h2 id="ri-f" class="card__title"><?= $e($t('recruit.funnel')) ?></h2>
  <ul class="funnel">
    <?php foreach ($funnel['stages'] as $s => $x): ?>
      <li><span class="text-sm text-muted"><?= $e($t('recruit.stage.' . $s)) ?></span><strong><?= (int) $x['count'] ?></strong><span class="text-sm text-muted"><?= $e($x['days'] !== null ? $t('recruit.avg_days', ['d' => (string) $x['days']]) : '') ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php if ($waiting !== []): ?>
<section class="card stack-sm" aria-labelledby="ri-w">
  <h2 id="ri-w" class="card__title"><?= $e($t('recruit.waiting_posting')) ?></h2>
  <ul class="plain-list"><?php foreach ($waiting as $w): ?><li><a href="<?= $e($url('/recruit/r/' . $w['public_id'])) ?>"><?= $e((string) $w['position_title'] . ' × ' . (int) $w['headcount']) ?></a></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="ri-p">
  <h2 id="ri-p" class="card__title"><?= $e($t('recruit.postings')) ?></h2>
  <?php if ($funnel['postings'] === []): ?><p class="text-sm text-muted"><?= $e($t('recruit.no_postings')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ri-p" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('recruit.field.post_title')) ?></th><th scope="col"><?= $e($t('recruit.col.status')) ?></th><th scope="col"><?= $e($t('recruit.col.applicants')) ?></th><th scope="col"><?= $e($t('recruit.col.hired')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($funnel['postings'] as $p): ?>
        <tr><th scope="row" data-label="<?= $e($t('recruit.field.post_title')) ?>"><a href="<?= $e($url('/recruit/p/' . $p['public_id'])) ?>"><?= $e((string) $p['title']) ?></a></th>
          <td data-label="<?= $e($t('recruit.col.status')) ?>"><?= $e($t('recruit.post_status.' . $p['status'])) ?></td>
          <td data-label="<?= $e($t('recruit.col.applicants')) ?>"><?= (int) $p['applicants'] ?></td>
          <td data-label="<?= $e($t('recruit.col.hired')) ?>"><?= (int) $p['hired'] ?> / <?= (int) $p['headcount'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
