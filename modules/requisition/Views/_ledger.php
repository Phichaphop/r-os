<?php
/** Stock movements table. @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var list<array<string, mixed>> $rows */
if ($rows === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('requisition.no_moves')) ?></p></div><?php else: ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('requisition.latest_moves')) ?>" tabindex="0">
  <table class="table table--stack">
    <thead><tr><th scope="col"><?= $e($t('requisition.col.when')) ?></th><th scope="col"><?= $e($t('requisition.field.name')) ?></th><th scope="col"><?= $e($t('requisition.col.kind')) ?></th><th scope="col"><?= $e($t('requisition.col.qty')) ?></th><th scope="col"><?= $e($t('requisition.col.balance')) ?></th><th scope="col"><?= $e($t('requisition.col.ref')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $l): ?>
      <tr><th scope="row" data-label="<?= $e($t('requisition.col.when')) ?>"><?= $e($date(substr((string) $l['at_local'], 0, 10), false) . ' ' . substr((string) $l['at_local'], 11, 5)) ?></th>
        <td data-label="<?= $e($t('requisition.field.name')) ?>"><?= $e($l['code'] . ' · ' . $l['name'] . ' · ' . $l['site_code']) ?></td>
        <td data-label="<?= $e($t('requisition.col.kind')) ?>"><?= $e($t('requisition.kind.' . $l['kind'])) ?></td>
        <td data-label="<?= $e($t('requisition.col.qty')) ?>"><?= $e(((int) $l['qty'] > 0 ? '+' : '') . (int) $l['qty']) ?></td>
        <td data-label="<?= $e($t('requisition.col.balance')) ?>"><?= (int) $l['balance_after'] ?></td>
        <td data-label="<?= $e($t('requisition.col.ref')) ?>"><?= $l['request_pid'] !== null ? '<a href="' . $e($url('/requisition/r/' . $l['request_pid'])) . '">' . $e((string) $l['ref']) . '</a>' : $e((string) ($l['note'] ?? '')) ?> <span class="text-sm text-muted"><?= $e((string) ($l['actor_name'] ?? '')) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif;
