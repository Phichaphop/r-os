<?php
/**
 * Events for me: what is coming (join / my place), and the ones I took part in.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var list<array<string, mixed>> $rows @var list<array<string, mixed>> $past
 * @var bool $canManage @var string $here
 */
$dt = static fn (string $x): string => $date(substr($x, 0, 10), false) . ' ' . substr($x, 11, 5);
?>
<?= $ui('page-header', ['title' => $t('events.title'), 'subtitle' => $t('events.subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<section class="card card--flush" aria-labelledby="ev-l">
  <div class="card__head"><h2 id="ev-l" class="card__title"><?= $e($t('events.upcoming_n', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('events.none'), 'text' => $t('events.none_help')]) ?></div><?php else: ?>
  <ul class="plain-list card__pad stack">
  <?php foreach ($rows as $ev): $eph = (string) $ev['phase']; ?>
    <li class="fm-item">
      <div class="stack-sm">
        <a href="<?= $e($url('/events/e/' . $ev['public_id'])) ?>"><strong><?= $e((string) $ev['title']) ?></strong></a>
        <span class="text-sm text-muted"><?= $e($dt((string) $ev['starts_at']) . ' · ' . $ev['venue']) ?></span>
      </div>
      <span class="row fm-badges"><?php include __DIR__ . '/_phase.php'; ?><?php if ($ev['mine'] !== null && $ev['mine']['status'] !== 'cancelled'): ?> <?= $ui('badge', ['tone' => 'success', 'label' => $t('events.reg_status.' . $ev['mine']['status'])]) ?><?php endif; ?></span>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if ($past !== []): ?>
<section class="card stack-sm" aria-labelledby="ev-p">
  <h2 id="ev-p" class="card__title"><?= $e($t('events.past')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($past as $p): ?><li><?= $e($p['title'] . ' · ' . $date(substr((string) $p['starts_at'], 0, 10), false)) ?> · <?= $e($t('events.reg_status.' . ($p['status'] === 'registered' ? 'no_show' : $p['status']))) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
