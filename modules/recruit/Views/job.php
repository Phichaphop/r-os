<?php
/** One open job (public). @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $job */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
?>
<?= $ui('page-header', ['title' => (string) $job['title'], 'subtitle' => (string) $job['company'] . ($job['location'] !== null ? ' · ' . $job['location'] : ''),
    'crumbs' => [[$t('recruit.jobs_title'), $url('/recruit/jobs')], [(string) $job['title'], null]],
    'actions' => $ui('button', ['label' => $t('recruit.apply_now'), 'href' => $url('/recruit/jobs/' . $job['public_id'] . '/apply'), 'variant' => 'secondary', 'icon' => 'send'])]) ?>
<section class="card stack-sm" aria-labelledby="jd-h">
  <h2 id="jd-h" class="card__title"><?= $e($t('recruit.job_details')) ?></h2>
  <p class="pre-line"><?= $e((string) $job['description']) ?></p>
  <?php if ($job['closes_on'] !== null): ?><p class="text-sm text-muted"><?= $e($t('recruit.closes', ['d' => DateFormat::day((string) $job['closes_on'], $loc)])) ?></p><?php endif; ?>
  <div class="row"><?= $ui('button', ['label' => $t('recruit.apply_now'), 'href' => $url('/recruit/jobs/' . $job['public_id'] . '/apply'), 'variant' => 'primary', 'icon' => 'send']) ?></div>
</section>
