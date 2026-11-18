<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{rows: list<array<string,mixed>>, total: int} $list @var int $page @var int $per @var string $status
 */
$statuses = ['pending', 'returned', 'approved', 'rejected', 'cancelled', 'needs_attention'];
?>
<?= $ui('page-header', ['title' => $t('workflow.mine.title'), 'subtitle' => $t('workflow.mine.subtitle'),
    'actions' => $ui('button', ['label' => $t('access.request.new'), 'href' => $url('/me/access'), 'variant' => 'secondary', 'icon' => 'key'])]) ?>
<form method="get" action="<?= $e($url('/requests')) ?>" class="filter-bar" role="search">
  <?= $ui('field', ['name' => 'status', 'label' => $t('workflow.field.status'), 'type' => 'select', 'value' => $status,
      'options' => ['' => $t('common.all')] + array_combine($statuses, array_map(static fn ($s) => $t('workflow.status.' . $s), $statuses))]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<div class="table-wrap" role="region" aria-label="<?= $e($t('workflow.mine.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('workflow.mine.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('workflow.field.request')) ?></th><th scope="col"><?= $e($t('workflow.field.type')) ?></th>
      <th scope="col"><?= $e($t('workflow.field.status')) ?></th><th scope="col"><?= $e($t('workflow.field.waiting_on')) ?></th><th scope="col"><?= $e($t('workflow.field.submitted')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($list['rows'] as $row): ?>
      <tr>
        <td data-label="<?= $e($t('workflow.field.request')) ?>"><a href="<?= $e($url('/requests/' . $row['public_id'])) ?>"><?= $e($row['title']) ?></a></td>
        <td data-label="<?= $e($t('workflow.field.type')) ?>"><?= $e($t('workflow.subject.' . $row['subject_type'])) ?></td>
        <td data-label="<?= $e($t('workflow.field.status')) ?>"><?php $wfStatus = (string) $row['status']; $wfExec = (string) $row['execution_status']; include __DIR__ . '/_status.php'; ?></td>
        <td data-label="<?= $e($t('workflow.field.waiting_on')) ?>"><?= $e($row['status'] === 'pending' ? ((string) ($row['waiting_on'] ?? '—')) : '—') ?></td>
        <td data-label="<?= $e($t('workflow.field.submitted')) ?>"><?= $e($date($row['submitted_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($list['rows'] === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'send', 'title' => $t('workflow.mine.empty')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= $ui('pagination', ['path' => '/requests', 'query' => ['status' => $status], 'page' => $page, 'per' => $per, 'total' => $list['total']]) ?>
