<?php
/**
 * Public job board (only postings of approved requisitions; no salary, budget or requester). @var callable $e @var callable $t @var callable $ui @var callable $url
 * @var list<array<string, mixed>> $rows @var string $q
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$status = $ui('button', ['label' => $t('recruit.status_title'), 'href' => $url('/recruit/status'), 'variant' => 'secondary', 'icon' => 'search']);
?>
<?= $ui('page-header', ['title' => $t('recruit.jobs_title'), 'subtitle' => $t('recruit.jobs_subtitle'), 'actions' => $status]) ?>
<form method="get" action="<?= $e($url('/recruit/jobs')) ?>" class="row" role="search">
  <?= $ui('field', ['name' => 'q', 'label' => $t('recruit.jobs_search'), 'value' => $q, 'placeholder' => $t('recruit.jobs_search_example')]) ?>
  <?= $ui('button', ['label' => $t('recruit.search'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'search']) ?>
</form>
<?php if ($rows === []): ?>
  <?= $ui('states', ['kind' => 'empty', 'icon' => 'briefcase', 'title' => $t('recruit.jobs_none'), 'text' => $t('recruit.jobs_none_text')]) ?>
<?php else: ?>
<ul class="job-list">
  <?php foreach ($rows as $j): ?>
  <li class="job-card card">
    <h2 class="job-card__title"><a href="<?= $e($url('/recruit/jobs/' . $j['public_id'])) ?>"><?= $e((string) $j['title']) ?></a></h2>
    <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e((string) $j['company']) ?><?php if ($j['location'] !== null): ?> · <?= $ui('icon', ['name' => 'map-pin', 'size' => 'sm']) ?> <?= $e((string) $j['location']) ?><?php endif; ?>
      <?php if ($j['closes_on'] !== null): ?> · <?= $e($t('recruit.closes', ['d' => DateFormat::day((string) $j['closes_on'], $loc)])) ?><?php endif; ?></p>
    <p class="job-card__text"><?= $e(mb_strimwidth((string) $j['description'], 0, 220, '…')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('recruit.apply_now'), 'href' => $url('/recruit/jobs/' . $j['public_id'] . '/apply'), 'variant' => 'primary', 'icon' => 'send']) ?>
      <a href="<?= $e($url('/recruit/jobs/' . $j['public_id'])) ?>"><?= $e($t('recruit.details')) ?></a></div>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
