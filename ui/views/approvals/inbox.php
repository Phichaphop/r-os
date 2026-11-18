<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{rows: list<array<string,mixed>>, total: int} $list @var int $page @var int $per
 * @var list<array<string,mixed>> $delegations
 */
?>
<?= $ui('page-header', ['title' => $t('workflow.inbox.title'), 'subtitle' => $t('workflow.inbox.subtitle'),
    'actions' => $ui('button', ['label' => $t('workflow.mine.title'), 'href' => $url('/requests'), 'variant' => 'secondary', 'icon' => 'send'])
        . ' ' . $ui('button', ['label' => $t('nav.delegate'), 'href' => $url('/approvals/delegate'), 'variant' => 'ghost', 'icon' => 'users'])]) ?>
<?php $activeDel = array_values(array_filter($delegations, static fn (array $d): bool => $d['status'] === 'active')); ?>
<?php if ($activeDel !== []): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('workflow.delegate.active_note', ['who' => implode(', ', array_map(static fn (array $d): string => (string) $d['to_name'], $activeDel))])]) ?>
<?php endif; ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('workflow.inbox.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('workflow.inbox.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('workflow.field.request')) ?></th><th scope="col"><?= $e($t('workflow.field.type')) ?></th>
      <th scope="col"><?= $e($t('workflow.field.requester')) ?></th><th scope="col"><?= $e($t('workflow.field.submitted')) ?></th><th scope="col"><?= $e($t('workflow.field.due')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($list['rows'] as $row): $late = $row['due_at'] !== null && (string) $row['due_at'] < (string) ($v['now_utc'] ?? ''); ?>
      <tr>
        <td data-label="<?= $e($t('workflow.field.request')) ?>"><a href="<?= $e($url('/approvals/' . $row['public_id'])) ?>"><?= $e($row['title']) ?></a>
          <?php if ((int) $row['escalated'] === 1): ?> <?= $ui('badge', ['tone' => 'warning', 'icon' => 'alert-triangle', 'label' => $t('workflow.escalated')]) ?><?php endif; ?>
          <?php if ($row['delegated_from'] !== null): ?> <?= $ui('badge', ['tone' => 'info', 'icon' => 'users', 'label' => $t('workflow.on_behalf')]) ?><?php endif; ?></td>
        <td data-label="<?= $e($t('workflow.field.type')) ?>"><?= $e($t('workflow.subject.' . $row['subject_type'])) ?></td>
        <td data-label="<?= $e($t('workflow.field.requester')) ?>"><?= $e($row['requester_name']) ?></td>
        <td data-label="<?= $e($t('workflow.field.submitted')) ?>"><?= $e($date($row['submitted_at'])) ?></td>
        <td data-label="<?= $e($t('workflow.field.due')) ?>"><?= $row['due_at'] === null ? '—' : ($late ? $ui('badge', ['tone' => 'danger', 'icon' => 'clock', 'label' => $date($row['due_at'])]) : $e($date($row['due_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($list['rows'] === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'checks', 'title' => $t('workflow.inbox.empty'), 'text' => $t('workflow.inbox.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= $ui('pagination', ['path' => '/approvals', 'query' => [], 'page' => $page, 'per' => $per, 'total' => $list['total']]) ?>


