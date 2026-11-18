<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $register @var bool $canManage @var string $tab
 */
?>
<?= $ui('page-header', ['title' => $t('records.title'), 'subtitle' => $t('records.subtitle')]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<?= $ui('alert', ['tone' => 'warning', 'title' => $t('records.rules.warning_title'), 'text' => $t('records.rules.warning')]) ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('records.rules.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('records.rules.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('records.field.record_class')) ?></th><th scope="col"><?= $e($t('records.field.in_force')) ?></th>
      <th scope="col"><?= $e($t('records.field.pending')) ?></th><th scope="col"><?= $e($t('records.field.documents')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($register as $row): $cur = $row['current']; $pen = $row['pending']; ?>
      <tr>
        <td data-label="<?= $e($t('records.field.record_class')) ?>"><a href="<?= $e($url('/admin/records/rules/' . $row['record_class'])) ?>"><?= $e($t('records.class.' . $row['record_class'])) ?></a>
          <div class="text-sm text-muted mono"><?= $e($row['record_class']) ?></div></td>
        <td data-label="<?= $e($t('records.field.in_force')) ?>"><?php if ($cur === null): ?><?= $ui('badge', ['tone' => 'neutral', 'icon' => 'info-circle', 'label' => $t('records.no_rule')]) ?>
          <?php else: ?><?= $e($t('records.rule_summary', ['months' => (int) $cur['duration_months'], 'trigger' => $t('records.trigger.' . $cur['trigger_event']), 'action' => $t('records.action.' . $cur['disposition_action'])])) ?>
          <div class="text-sm text-muted">v<?= (int) $cur['version_no'] ?> · <?= $e($cur['source_reference'] ?? '') ?></div><?php endif; ?></td>
        <td data-label="<?= $e($t('records.field.pending')) ?>"><?php if ($pen === null): ?>—<?php else: ?>
          <?= $ui('badge', ['tone' => (int) $pen['is_ai_draft'] === 1 ? 'warning' : 'info', 'icon' => (int) $pen['is_ai_draft'] === 1 ? 'robot-off' : 'pencil',
              'label' => $t((int) $pen['is_ai_draft'] === 1 ? 'records.ai_draft' : 'records.rule_status.' . $pen['status'])]) ?>
          <span class="text-sm"><?= $e($t('records.months_n', ['n' => (int) $pen['duration_months']])) ?></span><?php endif; ?></td>
        <td data-label="<?= $e($t('records.field.documents')) ?>" class="num"><?= (int) $row['documents'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
