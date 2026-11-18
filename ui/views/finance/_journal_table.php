<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var list<array<string, mixed>> $recent */
$money = static fn ($v): string => \Blue\Core\Support\Money::format(\Blue\Core\Support\Money::fromDb($v));
$jstatus = ['draft' => 'draft', 'in_review' => 'pending', 'returned' => 'returned', 'posted' => 'posted', 'reversed' => 'reversed', 'cancelled' => 'cancelled'];
?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('finance.journals.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('finance.journals.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('finance.field.journal_no')) ?></th><th scope="col"><?= $e($t('finance.field.date')) ?></th><th scope="col"><?= $e($t('finance.field.description')) ?></th>
      <th scope="col"><?= $e($t('finance.field.source')) ?></th><th scope="col" class="num"><?= $e($t('finance.field.amount')) ?></th><th scope="col"><?= $e($t('finance.field.status')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $j): ?>
      <tr>
        <td data-label="<?= $e($t('finance.field.journal_no')) ?>"><a href="<?= $e($url('/finance/journals/' . $j['public_id'])) ?>" class="mono"><?= $e($j['journal_no'] ?? $t('finance.no_number_yet')) ?></a></td>
        <td data-label="<?= $e($t('finance.field.date')) ?>"><?= $e((string) $j['journal_date']) ?></td>
        <td data-label="<?= $e($t('finance.field.description')) ?>"><?= $e($j['description']) ?><?php if ($j['reversal_of_id'] !== null): ?> <?= $ui('badge', ['tone' => 'info', 'icon' => 'arrow-back-up', 'label' => $t('finance.reversal')]) ?><?php endif; ?></td>
        <td data-label="<?= $e($t('finance.field.source')) ?>"><?= $e($j['source_module'] === 'manual' ? $t('finance.source.manual') : $j['source_module']) ?></td>
        <td data-label="<?= $e($t('finance.field.amount')) ?>" class="num"><?= $e($money($j['total_debit'])) ?></td>
        <td data-label="<?= $e($t('finance.field.status')) ?>"><?= $ui('badge', ['status' => $jstatus[$j['status']] ?? 'draft', 'label' => $t('finance.jstatus.' . $j['status'])]) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($recent === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'receipt', 'title' => $t('finance.journals.empty'), 'text' => $t('finance.journals.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
