<?php
/**
 * My requests (newest first).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var list<array<string, mixed>> $rows @var string $here @var bool $canIssue @var bool $canManage
 */
?>
<?= $ui('page-header', ['title' => $t('requisition.mine_title'), 'subtitle' => $t('requisition.mine_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<section class="card card--flush" aria-labelledby="rm-h">
  <div class="card__head"><h2 id="rm-h" class="card__title"><?= $e($t('requisition.mine_title')) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'inbox', 'title' => $t('requisition.none'), 'text' => $t('requisition.none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rm-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('requisition.col.ref')) ?></th><th scope="col"><?= $e($t('requisition.col.submitted')) ?></th><th scope="col"><?= $e($t('requisition.field.site')) ?></th><th scope="col"><?= $e($t('requisition.col.lines')) ?></th><th scope="col"><?= $e($t('requisition.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $st = (string) $row['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('requisition.col.ref')) ?>"><a href="<?= $e($url('/requisition/r/' . $row['public_id'])) ?>"><?= $e((string) $row['ref']) ?></a></th>
          <td data-label="<?= $e($t('requisition.col.submitted')) ?>"><?= $row['submitted_at'] !== null ? $e($date(substr((string) $row['submitted_at'], 0, 10), false) . ' ' . substr((string) $row['submitted_at'], 11, 5)) : '—' ?></td>
          <td data-label="<?= $e($t('requisition.field.site')) ?>"><?= $e((string) $row['site_code']) ?></td>
          <td data-label="<?= $e($t('requisition.col.lines')) ?>"><?= (int) $row['line_count'] ?></td>
          <td data-label="<?= $e($t('requisition.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?><?= (int) $row['over_quota'] === 1 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('requisition.over_quota_badge')]) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
