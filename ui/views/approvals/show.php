<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{instance: array<string,mixed>, decisions: list<array<string,mixed>>, steps: list<array<string,mixed>>, version_no: int, can_decide: bool, open_tasks: list<array<string,mixed>>} $d
 * @var array{rows: list<array{0:string,1:string}>, changes: list<array<string,mixed>>, link: ?string} $summary
 * @var bool $isRequester @var bool $canRetry @var string $from
 */
$inst = $d['instance'];
$self = '/' . $from . '/' . $inst['public_id'];
?>
<?= $ui('page-header', ['title' => (string) $inst['title'], 'subtitle' => $t('workflow.subject.' . $inst['subject_type']) . ' · ' . $t('workflow.version_n', ['n' => $d['version_no']]),
    'actions' => $ui('button', ['label' => $t('common.back'), 'href' => $url('/' . $from), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<div class="grid grid--2">
  <section class="card" aria-labelledby="sum-h">
    <div class="card__head"><h2 id="sum-h" class="card__title"><?= $e($t('workflow.detail.what')) ?></h2><?php $wfStatus = (string) $inst['status']; $wfExec = (string) $inst['execution_status']; include __DIR__ . '/_status.php'; ?></div>
    <dl class="dl">
      <dt><?= $e($t('workflow.field.requester')) ?></dt><dd><?= $e((string) $inst['requester_name']) ?> · <?= $e($date($inst['submitted_at'])) ?></dd>
      <?php foreach ($summary['rows'] as [$k, $val]): ?><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd><?php endforeach; ?>
    </dl>
    <?php if ($summary['changes'] !== []): ?>
      <div class="table-wrap"><table class="table"><caption><?= $e($t('amend.changes')) ?></caption>
        <thead><tr><th scope="col"><?= $e($t('amend.field.field')) ?></th><th scope="col"><?= $e($t('amend.field.old')) ?></th><th scope="col"><?= $e($t('amend.field.new')) ?></th></tr></thead>
        <tbody><?php foreach ($summary['changes'] as $c): ?><tr><td><?= $e((string) $c['field']) ?></td><td class="mono"><?= $e((string) ($c['old_value'] ?? '—')) ?></td><td class="mono"><?= $e((string) ($c['new_value'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
    <?php if ($summary['link'] !== null): ?><p><a href="<?= $e($url($summary['link'])) ?>"><?= $e($t('workflow.detail.open_record')) ?></a></p><?php endif; ?>
    <?php if ($inst['execution_status'] === 'failed'): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('workflow.exec.failed'), 'text' => $t('workflow.exec.failed_text', ['error' => $t((string) $inst['execution_error'])])]) ?><?php endif; ?>
  </section>

  <section class="card" aria-labelledby="hist-h">
    <h2 id="hist-h" class="card__title"><?= $e($t('workflow.detail.history')) ?></h2>
    <ol class="timeline">
      <?php foreach ($d['decisions'] as $dec): ?>
        <li class="timeline__item"><span class="timeline__dot" aria-hidden="true"><?= $ui('icon', ['name' => match ($dec['decision']) { 'approve', 'execute_succeeded' => 'circle-check', 'reject' => 'circle-x', 'return' => 'arrow-back-up', 'escalate' => 'alert-triangle', 'execute_failed' => 'alert-circle', 'cancel' => 'x', default => 'send' }, 'size' => 'sm']) ?></span>
          <div><strong><?= $e($t('workflow.decision.' . $dec['decision'])) ?></strong> · <?= $e($t('workflow.step_n', ['n' => (int) $dec['step_no']])) ?>
            <div class="text-sm text-muted"><?= $e((string) $dec['actor_name']) ?><?= $dec['on_behalf_name'] !== null ? ' ' . $e($t('workflow.for', ['who' => (string) $dec['on_behalf_name']])) : '' ?> · <?= $e($date($dec['decided_at'])) ?></div>
            <?php if ($dec['comment'] !== null && $dec['comment'] !== ''): ?><div class="text-sm">“<?= $e($dec['decision'] === 'execute_failed' ? $t((string) $dec['comment']) : (string) $dec['comment']) ?>”</div><?php endif; ?></div></li>
      <?php endforeach; ?>
    </ol>
    <?php if ($inst['status'] === 'pending' && $d['open_tasks'] !== []): ?>
      <p class="text-sm text-muted"><?= $e($t('workflow.detail.waiting', ['step' => (int) $inst['current_step'], 'of' => count($d['steps']), 'who' => implode(', ', array_unique(array_map(static fn ($x) => (string) $x['display_name'], $d['open_tasks'])))])) ?></p>
    <?php endif; ?>
  </section>
</div>

<?php if ($d['can_decide']): ?>
<section class="card" aria-labelledby="dec-h">
  <h2 id="dec-h" class="card__title"><?= $e($t('workflow.detail.decide')) ?></h2>
  <form method="post" action="<?= $e($url('/approvals/' . $inst['public_id'] . '/decide')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="_back" value="<?= $e($self) ?>">
    <?= $ui('field', ['name' => 'comment', 'label' => $t('workflow.field.comment'), 'type' => 'textarea', 'help' => $t('workflow.comment_help')]) ?>
    <div class="row">
      <button class="btn btn--primary" type="submit" name="decision" value="approve"><?= $ui('icon', ['name' => 'circle-check', 'size' => 'sm']) ?><?= $e($t('workflow.action.approve')) ?></button>
      <button class="btn btn--secondary" type="submit" name="decision" value="return"><?= $ui('icon', ['name' => 'arrow-back-up', 'size' => 'sm']) ?><?= $e($t('workflow.action.return')) ?></button>
      <button class="btn btn--danger" type="submit" name="decision" value="reject"><?= $ui('icon', ['name' => 'circle-x', 'size' => 'sm']) ?><?= $e($t('workflow.action.reject')) ?></button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($canRetry): ?>
<section class="card">
  <form method="post" action="<?= $e($url('/approvals/' . $inst['public_id'] . '/retry')) ?>"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="_back" value="<?= $e($self) ?>">
    <?= $ui('button', ['label' => $t('workflow.action.retry'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?></form>
</section>
<?php endif; ?>

<?php if ($isRequester && in_array($inst['status'], ['pending', 'returned', 'needs_attention'], true)): ?>
<section class="card stack" aria-labelledby="own-h">
  <h2 id="own-h" class="card__title"><?= $e($t('workflow.detail.my_actions')) ?></h2>
  <?php if ($inst['status'] === 'returned'): ?>
  <form method="post" action="<?= $e($url('/requests/' . $inst['public_id'] . '/resubmit')) ?>" class="stack"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'comment', 'label' => $t('workflow.field.comment'), 'type' => 'textarea']) ?>
    <?= $ui('button', ['label' => $t('workflow.action.resubmit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/requests/' . $inst['public_id'] . '/cancel')) ?>" class="row"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <label class="sr-only" for="cx-reason"><?= $e($t('iam.field.reason')) ?></label><input class="input" id="cx-reason" name="reason" required placeholder="<?= $e($t('iam.field.reason')) ?>">
    <?= $ui('button', ['label' => $t('workflow.action.cancel'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'x']) ?></form>
</section>
<?php endif; ?>
