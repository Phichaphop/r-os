<?php
/**
 * Safety home: the board, report button, my reports, incidents I investigate, actions I own, anonymous tracking.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var int|null $le @var array<string, mixed>|null $board
 * @var array{reports: list<array<string, mixed>>, investigating: list<array<string, mixed>>, actions: list<array<string, mixed>>} $mine @var bool $canManage @var string $here
 */
$st = static fn (array $r): string => $r['status'] === 'closed' && $r['closed_reason'] !== null && $r['closed_reason'] !== 'done' ? 'safety.closed_reason.' . $r['closed_reason'] : 'safety.status.' . $r['status'];
?>
<?= $ui('page-header', ['title' => $t('safety.title'), 'subtitle' => $t('safety.subtitle'),
    'actions' => $le !== null ? $ui('button', ['label' => $t('safety.report_now'), 'href' => $url('/safety/report'), 'variant' => 'danger', 'icon' => 'alert-triangle']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($le === null): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('safety.no_company')]) ?><?php else: ?>
<?php include __DIR__ . '/_board.php'; ?>
<?php if ($mine['actions'] !== []): ?>
<section class="card stack-sm" aria-labelledby="sf-ma">
  <h2 id="sf-ma" class="card__title"><?= $e($t('safety.my_actions')) ?></h2>
  <ul class="plain-list stack-sm">
  <?php foreach ($mine['actions'] as $a): ?>
    <li><a href="<?= $e($url('/safety/i/' . $a['incident_pid'])) ?>"><?= $e($a['ref']) ?></a> · <?= $e(mb_substr((string) $a['description'], 0, 120)) ?> <span class="text-sm text-muted">· <?= $e($t('safety.due_on', ['date' => (string) $a['due_on']])) ?></span></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($mine['investigating'] !== []): ?>
<section class="card stack-sm" aria-labelledby="sf-mi">
  <h2 id="sf-mi" class="card__title"><?= $e($t('safety.my_investigations')) ?></h2>
  <ul class="plain-list stack-sm">
  <?php foreach ($mine['investigating'] as $r): ?><li><a href="<?= $e($url('/safety/i/' . $r['public_id'])) ?>"><?= $e($r['ref']) ?></a> · <?= $e($t('safety.kind.' . $r['kind'])) ?> · <?= $e((string) $r['location']) ?> <?= $ui('badge', ['tone' => 'info', 'label' => $t('safety.status.' . $r['status'])]) ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<section class="card card--flush" aria-labelledby="sf-mr">
  <div class="card__head"><h2 id="sf-mr" class="card__title"><?= $e($t('safety.my_reports')) ?></h2></div>
  <?php if ($mine['reports'] === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'shield-check', 'title' => $t('safety.no_reports'), 'text' => $t('safety.no_reports_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="sf-mr" tabindex="0"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('safety.col.ref')) ?></th><th scope="col"><?= $e($t('safety.field.kind')) ?></th><th scope="col"><?= $e($t('safety.field.location')) ?></th><th scope="col"><?= $e($t('safety.col.status')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($mine['reports'] as $r): ?>
      <tr><td><a href="<?= $e($url('/safety/i/' . $r['public_id'])) ?>"><?= $e($r['ref']) ?></a></td><td><?= $e($t('safety.kind.' . $r['kind'])) ?></td><td><?= $e((string) $r['location']) ?></td><td><?= $ui('badge', ['tone' => $r['status'] === 'closed' ? 'neutral' : 'info', 'label' => $t($st($r))]) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<p class="text-sm"><a href="<?= $e($url('/safety/track')) ?>"><?= $e($t('safety.track_link')) ?></a></p>
<?php endif; ?>
