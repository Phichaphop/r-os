<?php
/**
 * Organisers: the company's events with registrations and attendance.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, string> $companies @var string $lePid
 * @var list<array<string, mixed>> $rows @var bool $canManage @var string $here
 */
$dt = static fn (string $x): string => $date(substr($x, 0, 10), false) . ' ' . substr($x, 11, 5);
?>
<?= $ui('page-header', ['title' => $t('events.manage_title'), 'subtitle' => $t('events.manage_subtitle'),
    'actions' => $ui('button', ['label' => $t('events.new_event'), 'href' => $url('/events/manage/new?le=' . rawurlencode($lePid)), 'variant' => 'primary', 'icon' => 'plus'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<section class="card card--flush" aria-labelledby="ev-ml">
  <div class="card__head"><h2 id="ev-ml" class="card__title"><?= $e($t('events.events_n', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('events.manage_none'), 'text' => $t('events.manage_none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ev-ml" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('events.field.title')) ?></th><th scope="col"><?= $e($t('events.col.when')) ?></th><th scope="col"><?= $e($t('events.col.status')) ?></th><th scope="col"><?= $e($t('events.col.registered')) ?></th><th scope="col"><?= $e($t('events.col.came')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $ev): $eph = (string) $ev['phase']; ?>
        <tr><th scope="row" data-label="<?= $e($t('events.field.title')) ?>"><a href="<?= $e($url('/events/m/' . $ev['public_id'])) ?>"><?= $e((string) $ev['title']) ?></a></th>
          <td data-label="<?= $e($t('events.col.when')) ?>"><?= $e($dt((string) $ev['starts_at'])) ?></td>
          <td data-label="<?= $e($t('events.col.status')) ?>"><?php include __DIR__ . '/_phase.php'; ?></td>
          <td data-label="<?= $e($t('events.col.registered')) ?>"><?= (int) $ev['taken'] ?><?= $ev['quota'] !== null ? ' / ' . (int) $ev['quota'] : '' ?></td>
          <td data-label="<?= $e($t('events.col.came')) ?>"><?= (int) $ev['came'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
