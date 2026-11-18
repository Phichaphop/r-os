<?php
/**
 * The work queue: assigned to me, waiting for a person, the rest of my categories, recently resolved.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{mine: list<array<string, mixed>>, unassigned: list<array<string, mixed>>, team: list<array<string, mixed>>, resolved: list<array<string, mixed>>, member: bool} $q
 * @var string $now @var bool $staff @var bool $canManage
 */
$here = '/helpdesk/queue';
$sections = ['mine' => 'user', 'unassigned' => 'inbox', 'team' => 'users', 'resolved' => 'circle-check'];
?>
<?= $ui('page-header', ['title' => $t('helpdesk.queue_title'), 'subtitle' => $t('helpdesk.queue_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php foreach ($sections as $key => $icon): $rows = $q[$key]; ?>
<section class="card card--flush" aria-labelledby="hq-<?= $e($key) ?>">
  <div class="card__head"><h2 id="hq-<?= $e($key) ?>" class="card__title"><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?> <?= $e($t('helpdesk.queue.' . $key)) ?> (<?= count($rows) ?>)</h2></div>
  <?php if ($rows === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('helpdesk.queue_empty')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="hq-<?= $e($key) ?>" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('helpdesk.col.ref')) ?></th><th scope="col"><?= $e($t('helpdesk.field.title')) ?></th><th scope="col"><?= $e($t('helpdesk.field.category')) ?></th><th scope="col"><?= $e($t('helpdesk.col.requester')) ?></th>
        <th scope="col"><?= $e($t('helpdesk.col.assignee')) ?></th><th scope="col"><?= $e($t('helpdesk.col.resolve_due')) ?></th><th scope="col"><?= $e($t('helpdesk.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $late = $row['resolve_due'] !== null && (string) $row['resolve_due'] < $now && $key !== 'resolved'; ?>
        <tr><th scope="row" data-label="<?= $e($t('helpdesk.col.ref')) ?>"><a href="<?= $e($url('/helpdesk/t/' . $row['public_id'])) ?>"><?= $e((string) $row['ref']) ?></a></th>
          <td data-label="<?= $e($t('helpdesk.field.title')) ?>"><?= $e((string) $row['title']) ?></td>
          <td data-label="<?= $e($t('helpdesk.field.category')) ?>"><?= $e((string) $row['category_name']) ?></td>
          <td data-label="<?= $e($t('helpdesk.col.requester')) ?>"><?= $e((string) $row['requester_name']) ?></td>
          <td data-label="<?= $e($t('helpdesk.col.assignee')) ?>"><?= $e((string) ($row['assignee_name'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('helpdesk.col.resolve_due')) ?>"><?= $e($row['resolve_due'] !== null ? substr((string) $row['resolve_due'], 0, 16) : '—') ?><?= $late ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('helpdesk.breached')]) : '' ?></td>
          <td data-label="<?= $e($t('helpdesk.col.status')) ?>"><?php include __DIR__ . '/_badges.php'; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
