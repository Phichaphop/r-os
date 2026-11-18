<?php
/**
 * The inspection admin's board: to review, reviewed with actions open, overdue, due today.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array<string, list<array<string, mixed>>> $board @var bool $canManage
 */
$here = '/inspection/board';
$sections = ['submitted' => 'checks', 'reviewed' => 'tool', 'overdue' => 'alert-triangle', 'today' => 'calendar-event'];
?>
<?= $ui('page-header', ['title' => $t('inspection.board_title'), 'subtitle' => $t('inspection.board_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('inspection.pick_company')) ?>">
  <?php foreach ($companies as $cp => $code): ?><a href="<?= $e($url('/inspection/board?le=' . rawurlencode($cp))) ?>"<?= $cp === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php foreach ($sections as $key => $icon): $rows = $board[$key]; ?>
<section class="card card--flush" aria-labelledby="ib-<?= $e($key) ?>">
  <div class="card__head"><h2 id="ib-<?= $e($key) ?>" class="card__title"><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t('inspection.board.' . $key)) ?> (<?= count($rows) ?>)</h2></div>
  <?php if ($rows === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('inspection.empty')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ib-<?= $e($key) ?>" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('inspection.col.point')) ?></th><th scope="col"><?= $e($t('inspection.col.due_date')) ?></th><th scope="col"><?= $e($t('inspection.field.inspector')) ?></th><th scope="col"><?= $e($t('inspection.col.open_actions')) ?></th><th scope="col"><?= $e($t('inspection.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr><th scope="row" data-label="<?= $e($t('inspection.col.point')) ?>"><a href="<?= $e($url('/inspection/r/' . $row['public_id'])) ?>"><?= $e($row['code'] . ' · ' . $row['name']) ?></a></th>
          <td data-label="<?= $e($t('inspection.col.due_date')) ?>"><?= $e($date((string) $row['due_date'], false)) ?><?= (int) $row['late'] === 1 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('inspection.late')]) : '' ?></td>
          <td data-label="<?= $e($t('inspection.field.inspector')) ?>"><?= $e((string) $row['inspector_name']) ?></td>
          <td data-label="<?= $e($t('inspection.col.open_actions')) ?>"><?= (int) $row['open_actions'] ?></td>
          <td data-label="<?= $e($t('inspection.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
