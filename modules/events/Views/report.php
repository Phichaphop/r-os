<?php
/**
 * Organisers: registered vs came, walk-ins, no-shows, rate, by department and site, choices — CSV.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $ev @var bool $canManage @var string $here
 */
$pid = (string) $ev['public_id'];
$c = $ev['counts'];
?>
<?= $ui('page-header', ['title' => $t('events.report_title'), 'subtitle' => (string) $ev['title'], 'crumbs' => [[$t('events.manage_title'), $url('/events/manage')], [(string) $ev['title'], $url('/events/m/' . $pid)], [$t('events.report'), null]],
    'actions' => $ui('button', ['label' => $t('events.export'), 'href' => $url('/events/m/' . $pid . '/export'), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (!$ev['started']): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('events.not_started_note')]) ?><?php endif; ?>
<div class="grid grid--3">
  <?php foreach (['expected' => 'events.registered_ahead', 'came' => 'events.came', 'walk_in' => 'events.walk_ins', 'no_show' => 'events.no_shows', 'cancelled' => 'events.cancellations', 'waitlisted' => 'events.still_waiting'] as $k => $label): ?>
  <section class="card stack-sm"><h2 class="card__title"><?= $e($t($label)) ?></h2><p class="kz-big"><?= (int) $c[$k] ?></p></section>
  <?php endforeach; ?>
</div>
<section class="card stack-sm"><h2 class="card__title"><?= $e($t('events.rate')) ?></h2><p class="kz-big"><?= $c['rate'] === null ? '—' : $e((string) $c['rate']) . '%' ?></p><p class="text-sm text-muted"><?= $e($t('events.rate_help')) ?></p></section>
<?php foreach (['dept' => 'events.by_department', 'site' => 'events.by_site'] as $k => $label): $hid = 'ev-by-' . $k; ?>
<section class="card card--flush" aria-labelledby="<?= $e($hid) ?>">
  <div class="card__head"><h2 id="<?= $e($hid) ?>" class="card__title"><?= $e($t($label)) ?></h2></div>
  <?php if ($ev['by'][$k] === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('events.no_people')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="<?= $e($hid) ?>" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t($k === 'dept' ? 'events.csv.department' : 'events.csv.site')) ?></th><th scope="col"><?= $e($t('events.registered_ahead')) ?></th><th scope="col"><?= $e($t('events.came')) ?></th><th scope="col"><?= $e($t('events.walk_ins')) ?></th></tr></thead>
      <tbody><?php foreach ($ev['by'][$k] as $name => $row): ?><tr><th scope="row" data-label="<?= $e($t($k === 'dept' ? 'events.csv.department' : 'events.csv.site')) ?>"><?= $e((string) $name) ?></th><td data-label="<?= $e($t('events.registered_ahead')) ?>"><?= (int) $row['registered'] ?></td><td data-label="<?= $e($t('events.came')) ?>"><?= (int) $row['came'] ?></td><td data-label="<?= $e($t('events.walk_ins')) ?>"><?= (int) $row['walk_in'] ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php if ($ev['choice_counts'] !== []): ?>
<section class="card stack-sm" aria-labelledby="ev-ch"><h2 id="ev-ch" class="card__title"><?= $e((string) $ev['option_label']) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($ev['choice_counts'] as $ch => $n): ?><li><?= $e($ch . ': ' . $n) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
