<?php
/** Lines of a request: requested, over quota, issued. @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $rq */
?>
<div class="table-wrap" role="region" aria-labelledby="rq-lines-h" tabindex="0">
  <table class="table table--stack">
    <thead><tr><th scope="col"><?= $e($t('requisition.field.name')) ?></th><th scope="col"><?= $e($t('requisition.col.qty')) ?></th><th scope="col"><?= $e($t('requisition.col.issued_qty')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rq['lines'] as $l): ?>
      <tr><th scope="row" data-label="<?= $e($t('requisition.field.name')) ?>"><?= $e((string) $l['name']) ?> <span class="text-sm text-muted"><?= $e((string) $l['code']) ?></span></th>
        <td data-label="<?= $e($t('requisition.col.qty')) ?>"><?= $e($l['qty'] . ' ' . $l['unit']) ?><?= (int) $l['over_qty'] > 0 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('requisition.over_by', ['qty' => (string) $l['over_qty']])]) : '' ?></td>
        <td data-label="<?= $e($t('requisition.col.issued_qty')) ?>"><?= $l['qty_issued'] === null ? '—' : $e($l['qty_issued'] . ' ' . $l['unit']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
