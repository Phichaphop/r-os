<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{rows: list<array<string, mixed>>, total: int} $list @var string $status @var int $page @var int $per @var int $le
 */
$rstatus = ['received' => ['info', 'inbox'], 'needs_attention' => ['danger', 'alert-triangle'], 'draft_journal' => ['warning', 'pencil'], 'posted' => ['success', 'checks'],
    'reversed' => ['neutral', 'arrow-back-up'], 'rejected' => ['neutral', 'circle-x']];
?>
<?= $ui('page-header', ['title' => $t('finance.requests.title'), 'subtitle' => $t('finance.requests.subtitle')]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<form method="get" action="<?= $e($url('/finance/requests')) ?>" class="filter-bar" role="search">
  <input type="hidden" name="le" value="<?= (int) $le ?>">
  <?= $ui('field', ['name' => 'status', 'label' => $t('finance.field.status'), 'type' => 'select', 'value' => $status,
      'options' => ['' => $t('common.all')] + array_combine(array_keys($rstatus), array_map(static fn ($s) => $t('finance.rstatus.' . $s), array_keys($rstatus)))]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<div class="table-wrap" role="region" aria-label="<?= $e($t('finance.requests.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('finance.requests.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('finance.field.received')) ?></th><th scope="col"><?= $e($t('finance.field.source')) ?></th><th scope="col"><?= $e($t('finance.field.date')) ?></th>
      <th scope="col" class="num"><?= $e($t('finance.field.amount')) ?></th><th scope="col"><?= $e($t('finance.field.status')) ?></th><th scope="col"><?= $e($t('finance.field.journal')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($list['rows'] as $q): [$tone, $icon] = $rstatus[$q['status']] ?? ['neutral', 'info-circle']; ?>
      <tr>
        <td data-label="<?= $e($t('finance.field.received')) ?>"><a href="<?= $e($url('/finance/requests/' . $q['public_id'])) ?>"><?= $e($date($q['created_at'])) ?></a></td>
        <td data-label="<?= $e($t('finance.field.source')) ?>"><span class="mono"><?= $e($q['source_module']) ?></span> · <?= $e($q['source_document_type'] . ' ' . $q['source_document_id']) ?></td>
        <td data-label="<?= $e($t('finance.field.date')) ?>"><?= $e((string) $q['period_date']) ?></td>
        <td data-label="<?= $e($t('finance.field.amount')) ?>" class="num"><?= $e(\Blue\Core\Support\Money::format(\Blue\Core\Support\Money::fromDb($q['total_amount']))) ?> <?= $e($q['currency_code']) ?></td>
        <td data-label="<?= $e($t('finance.field.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('finance.rstatus.' . $q['status'])]) ?>
          <?php if ($q['error_key'] !== null && $q['status'] === 'needs_attention'): ?><div class="text-sm text-danger"><?= $e($t((string) $q['error_key'], (array) json_decode((string) ($q['error_detail'] ?? '{}'), true))) ?></div><?php endif; ?></td>
        <td data-label="<?= $e($t('finance.field.journal')) ?>"><?php if ($q['journal_pid'] !== null): ?><a class="mono" href="<?= $e($url('/finance/journals/' . $q['journal_pid'])) ?>"><?= $e($q['journal_no'] ?? $t('finance.jstatus.' . $q['journal_status'])) ?></a><?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($list['rows'] === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'inbox', 'title' => $t('finance.requests.empty'), 'text' => $t('finance.requests.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= $ui('pagination', ['path' => '/finance/requests', 'query' => array_filter(['le' => $le, 'status' => $status]), 'page' => $page, 'per' => $per, 'total' => $list['total']]) ?>
