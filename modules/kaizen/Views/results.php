<?php
/**
 * HR: aggregated results (nothing but the count below the survey's minimum), eNPS, CSV export.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $s @var string $here
 * @var bool $canManage @var bool $canSurvey
 */
$pid = (string) $s['public_id'];
$sst = (string) $s['status'];
?>
<?= $ui('page-header', ['title' => $t('kaizen.results_title', ['title' => (string) $s['title']]), 'subtitle' => $t('kaizen.window', ['from' => $date((string) $s['opens_on'], false), 'to' => $date((string) $s['closes_on'], false)]),
    'crumbs' => [[$t('kaizen.survey_admin_title'), $url('/kaizen/surveys/admin')], [(string) $s['title'], $url('/kaizen/surveys/m/' . $pid)], [$t('kaizen.results'), null]],
    'actions' => $s['shown'] ? $ui('button', ['label' => $t('kaizen.export'), 'href' => $url('/kaizen/surveys/m/' . $pid . '/export'), 'variant' => 'secondary', 'icon' => 'download']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="grid grid--3">
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t('kaizen.col.answers')) ?></h2><p class="kz-big"><?= (int) $s['n'] ?></p></section>
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t('kaizen.response_rate')) ?></h2><p class="kz-big"><?= $s['rate'] === null ? '—' : $e((string) $s['rate']) . '%' ?></p><p class="text-sm text-muted"><?= $e($t('kaizen.people_n', ['n' => (string) $s['audience_size']])) ?></p></section>
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t('kaizen.col.status')) ?></h2><p><?php include __DIR__ . '/_sstatus.php'; ?></p></section>
</div>
<?php if (!$s['shown']): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('kaizen.error.below_minimum', ['n' => (string) $s['min_results']])]) ?>
<?php else: ?>
<?php if ($sst === 'analyzed'): ?><section class="card stack-sm" aria-labelledby="kz-sum"><h2 id="kz-sum" class="card__title"><?= $e($t('kaizen.summary')) ?></h2><p class="kz-text"><?= $e((string) $s['summary']) ?></p></section><?php endif; ?>
<?php foreach ($s['results'] as $n => $r): $q = $r['question']; $hid = 'kz-r' . $n; ?>
<section class="card stack-sm" aria-labelledby="<?= $e($hid) ?>">
  <h2 id="<?= $e($hid) ?>" class="card__title"><?= $e(($n + 1) . '. ' . $q['text']) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('kaizen.kind.' . $q['kind'])) ?> · <?= $e($t('kaizen.answered_n', ['n' => (string) $r['answered']])) ?><?= isset($r['avg']) && $r['avg'] !== null ? ' · ' . $e($t('kaizen.average', ['n' => (string) $r['avg']])) : '' ?></p>
  <?php if (isset($r['enps'])): ?><p><strong><?= $e($t('kaizen.enps_value', ['n' => (string) $r['enps']])) ?></strong> <span class="text-sm text-muted"><?= $e($t('kaizen.enps_detail', ['p' => (string) $r['promoters'], 'd' => (string) $r['detractors']])) ?></span></p><?php endif; ?>
  <?php if (isset($r['dist'])): $tot = max(1, $r['answered']); ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($r['dist'] as $label => $cnt): ?>
    <li class="kz-dist"><span class="kz-dist__label"><?= $e((string) $label) ?></span><progress max="<?= $tot ?>" value="<?= (int) $cnt ?>" aria-label="<?= $e($label . ': ' . $cnt) ?>"></progress><span class="text-sm"><?= (int) $cnt ?> (<?= $e((string) round($cnt * 100 / $tot)) ?>%)</span></li>
    <?php endforeach; ?>
  </ul>
  <?php elseif (isset($r['texts'])): ?>
    <?php if ($r['texts'] === []): ?><p class="text-sm text-muted"><?= $e($t('kaizen.none')) ?></p><?php else: ?><ul class="stack-sm"><?php foreach ($r['texts'] as $tx): ?><li class="kz-text"><?= $e($tx) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
