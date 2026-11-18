<?php
/**
 * Trend of ideas over 12 months, totals by state, acceptance rate, average score, most-voted.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, string> $companies @var string $lePid
 * @var array<string, mixed> $rep @var string $here @var bool $canManage @var bool $canSurvey
 */
$max = max(1, ...array_values(array_map(static fn (array $m): int => max($m['sent'], $m['accepted'], $m['verified']), $rep['months'])));
?>
<?= $ui('page-header', ['title' => $t('kaizen.report_title'), 'subtitle' => $t('kaizen.report_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<div class="grid grid--3">
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t('kaizen.total')) ?></h2><p class="kz-big"><?= (int) $rep['total'] ?></p></section>
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t('kaizen.accept_rate')) ?></h2><p class="kz-big"><?= $rep['accept_rate'] === null ? '—' : $e((string) $rep['accept_rate']) . '%' ?></p><p class="text-sm text-muted"><?= $e($t('kaizen.accept_rate_help')) ?></p></section>
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t('kaizen.avg_score')) ?></h2><p class="kz-big"><?= $rep['avg_score'] === null ? '—' : $e((string) $rep['avg_score']) ?></p></section>
</div>
<section class="card card--flush" aria-labelledby="kz-tr">
  <div class="card__head"><h2 id="kz-tr" class="card__title"><?= $e($t('kaizen.trend')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="kz-tr" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('kaizen.col.month')) ?></th><th scope="col"><?= $e($t('kaizen.col.sent')) ?></th><th scope="col"><?= $e($t('kaizen.col.accepted')) ?></th><th scope="col"><?= $e($t('kaizen.col.verified')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rep['months'] as $m => $c): ?>
        <tr><th scope="row" data-label="<?= $e($t('kaizen.col.month')) ?>"><?= $e($date($m . '-01', false)) ?></th>
        <?php foreach (['sent', 'accepted', 'verified'] as $k): ?>
          <td data-label="<?= $e($t('kaizen.col.' . $k)) ?>"><span class="kz-bar"><progress max="<?= $max ?>" value="<?= (int) $c[$k] ?>" aria-label="<?= $e($t('kaizen.col.' . $k) . ' ' . $m) ?>"></progress> <?= (int) $c[$k] ?></span></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="kz-st">
    <h2 id="kz-st" class="card__title"><?= $e($t('kaizen.by_status')) ?></h2>
    <ul class="plain-list stack-sm"><?php foreach ($rep['status'] as $ist => $n): ?><li class="row"><?php include __DIR__ . '/_status.php'; ?> <?= (int) $n ?></li><?php endforeach; ?></ul>
  </section>
  <section class="card stack-sm" aria-labelledby="kz-top">
    <h2 id="kz-top" class="card__title"><?= $e($t('kaizen.top_voted')) ?></h2>
    <?php if ($rep['top'] === []): ?><p class="text-sm text-muted"><?= $e($t('kaizen.none')) ?></p><?php else: ?>
    <ol class="stack-sm"><?php foreach ($rep['top'] as $tp): ?><li><a href="<?= $e($url('/kaizen/i/' . $tp['public_id'])) ?>"><?= $e($tp['ref'] . ' · ' . $tp['title']) ?></a> <span class="text-sm text-muted"><?= $e($t('kaizen.votes_n', ['n' => (string) $tp['votes']])) ?></span></li><?php endforeach; ?></ol>
    <?php endif; ?>
  </section>
</div>
