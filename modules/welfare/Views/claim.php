<?php
/**
 * One welfare claim: details, receipts, the checks (eligibility, limits, duplicates), what the person or the reviewer /
 * approver can do now, and the history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $c @var array<string, string> $errors @var string $oldAmount
 */
use Blue\Core\Support\Money;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$m = static fn (?int $u): string => $u === null ? '—' : Money::format($u);
$md = static fn (mixed $x): string => $x === null ? '—' : Money::format(Money::fromDb($x));
$tones = ['draft' => 'neutral', 'submitted' => 'info', 'reviewing' => 'info', 'need_info' => 'warning', 'approved' => 'success', 'partially_approved' => 'success', 'paid' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral'];
$base = '/welfare/claims/' . $c['public_id'];
$check = is_array($c['check']) ? $c['check'] : null;
$editable = $c['own'] && in_array($c['status'], ['draft', 'need_info'], true);
$task = $c['task'];
$icons = ['created' => 'plus', 'edited' => 'pencil', 'file_removed' => 'trash', 'submitted' => 'send', 'resubmitted' => 'send', 'reviewed' => 'clipboard-check', 'need_info' => 'arrow-back-up',
    'approved' => 'circle-check', 'partially_approved' => 'circle-check', 'rejected' => 'circle-x', 'cancelled' => 'x', 'batched' => 'cash', 'unbatched' => 'arrow-back-up', 'paid' => 'currency-baht',
    'payment_failed' => 'alert-triangle', 'payment_cancelled' => 'alert-triangle'];
?>
<?= $ui('page-header', ['title' => $t('welfare.claim_title', ['no' => (string) ($c['claim_no'] ?? $t('welfare.status.draft'))]), 'subtitle' => $c['type']['code'] . ' · ' . $c['type']['name_th'],
    'crumbs' => [[$t($c['own'] ? 'welfare.title' : 'welfare.admin_title'), $url($c['own'] ? '/welfare' : '/welfare/admin')], [(string) ($c['claim_no'] ?? $t('welfare.status.draft')), null]],
    'actions' => $ui('badge', ['tone' => $tones[$c['status']] ?? 'neutral', 'label' => $t('welfare.status.' . $c['status'])])]) ?>
<?php if ($c['status'] === 'need_info' && $c['decision_note'] !== null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('welfare.need_info_title'), 'text' => (string) $c['decision_note']]) ?>
<?php elseif ($c['status'] === 'rejected'): ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('welfare.rejected_title'), 'text' => (string) ($c['decision_note'] ?? '')]) ?>
<?php elseif ($c['status'] === 'partially_approved'): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('welfare.partial_title'), 'text' => (string) ($c['decision_note'] ?? '')]) ?>
<?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'text' => implode(' · ', $errors)]) ?><?php endif; ?>

<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="cl-d">
    <h2 id="cl-d" class="card__title"><?= $e($t('welfare.details')) ?></h2>
    <dl class="facts">
      <?php if ($c['staff']): ?><div><dt><?= $e($t('welfare.field.employee')) ?></dt><dd><?= $e($c['employee']['employee_code'] . ' · ' . $c['employee']['name_th'] . ' · ' . $c['le_code']) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('welfare.field.type')) ?></dt><dd><?= $e($c['type']['code'] . ' · ' . $c['type']['name_th'] . ' · ' . $t('welfare.version_n', ['n' => (string) $c['type']['version_no']])) ?></dd></div>
      <div><dt><?= $e($t('welfare.field.expense_date')) ?></dt><dd><?= $e($date((string) $c['expense_date'], false)) ?></dd></div>
      <div><dt><?= $e($t('welfare.field.description')) ?></dt><dd><?= $e((string) $c['description']) ?></dd></div>
      <div><dt><?= $e($t('welfare.field.amount')) ?></dt><dd><strong><?= $e($md($c['amount_claimed'])) ?></strong></dd></div>
      <div><dt><?= $e($t('welfare.field.receipt')) ?></dt><dd><?= $e(trim(($c['receipt_no'] ?? '—') . ' · ' . ($c['receipt_from'] ?? '—'))) ?></dd></div>
      <?php if ($c['payee_name'] !== null): ?><div><dt><?= $e($t('welfare.field.payee_name')) ?></dt><dd><?= $e((string) $c['payee_name']) ?></dd></div><?php endif; ?>
      <?php if ($c['approved_amount'] !== null): ?><div><dt><?= $e($t('welfare.field.approved')) ?></dt><dd><strong><?= $e($md($c['approved_amount'])) ?></strong></dd></div><?php endif; ?>
      <?php if ($c['reviewed_by_name'] !== null): ?><div><dt><?= $e($t('welfare.field.reviewed_by')) ?></dt><dd><?= $e((string) $c['reviewed_by_name']) ?></dd></div><?php endif; ?>
      <?php if ($c['decided_by_name'] !== null): ?><div><dt><?= $e($t('welfare.field.decided_by')) ?></dt><dd><?= $e((string) $c['decided_by_name']) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('welfare.field.payout')) ?></dt><dd><?= $e($t('welfare.payout.' . $c['type']['payout'])) ?><?php if ($c['batch'] !== null): ?> · <?= $e((string) $c['batch']['batch_no']) ?><?php endif; ?><?php if ($c['paid_ref'] !== null): ?> · <?= $e($t('welfare.paid_in', ['ref' => (string) $c['paid_ref']])) ?><?php endif; ?></dd></div>
    </dl>
    <h3 class="section-title"><?= $e($t('welfare.field.files')) ?></h3>
    <?php if ($c['files'] === []): ?><p class="text-sm text-muted"><?= $e($t('welfare.no_files')) ?></p><?php else: ?>
    <ul class="plain-list">
      <?php foreach ($c['files'] as $f): ?><li><a href="<?= $e($url($base . '/files/' . $f['id'])) ?>"><?= $ui('icon', ['name' => 'file-text', 'size' => 'sm']) ?> <?= $e((string) $f['filename']) ?></a> <span class="text-sm text-muted"><?= $e($date((string) $f['added_at'])) ?></span></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>

  <section class="card stack-sm" aria-labelledby="cl-c">
    <h2 id="cl-c" class="card__title"><?= $e($t('welfare.check_title')) ?></h2>
    <?php if ($check === null): ?><p class="text-sm text-muted"><?= $e($t('welfare.check_none')) ?></p><?php else: ?>
    <ul class="check-list">
      <?php if ($check['blocking'] === []): ?><li><?= $ui('icon', ['name' => 'circle-check', 'size' => 'sm']) ?> <?= $e($t('welfare.check.all_ok')) ?></li><?php endif; ?>
      <?php foreach ($check['blocking'] as $k): ?><li><?= $ui('icon', ['name' => 'circle-x', 'size' => 'sm', 'label' => $t('welfare.check.blocking')]) ?> <?= $e($t('welfare.check.' . $k)) ?></li><?php endforeach; ?>
      <?php foreach ($check['warnings'] as $k): ?><li><?= $ui('icon', ['name' => 'alert-triangle', 'size' => 'sm', 'label' => $t('welfare.check.warning')]) ?> <?= $e($t('welfare.check.' . $k)) ?></li><?php endforeach; ?>
    </ul>
    <dl class="facts">
      <?php if ($check['limit_year'] !== null): ?>
      <div><dt><?= $e($t('welfare.check.limit_year')) ?></dt><dd><?= $e($m($check['limit_year'])) ?></dd></div>
      <div><dt><?= $e($t('welfare.check.used')) ?></dt><dd><?= $e($m($check['used'])) ?></dd></div>
      <div><dt><?= $e($t('welfare.check.pending')) ?></dt><dd><?= $e($m($check['pending'])) ?></dd></div>
      <div><dt><?= $e($t('welfare.check.remaining')) ?></dt><dd><?= $e($m($check['remaining'])) ?></dd></div>
      <?php endif; ?>
      <?php if ($check['limit_event'] !== null): ?><div><dt><?= $e($t('welfare.check.limit_event')) ?></dt><dd><?= $e($m($check['limit_event'])) ?></dd></div><?php endif; ?>
      <?php if ($check['max_claims'] !== null): ?><div><dt><?= $e($t('welfare.claims_count')) ?></dt><dd><?= $e($t('welfare.n_of_max', ['n' => (string) $check['count'], 'max' => (string) $check['max_claims']])) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('welfare.check.eligible')) ?></dt><dd><strong><?= $e($m($check['eligible'])) ?></strong></dd></div>
    </dl>
    <p class="text-sm text-muted"><?= $e($t('welfare.check_help', ['date' => (string) $check['checked_at']])) ?></p>
    <?php endif; ?>
  </section>
</div>

<?php if ($editable): ?>
<section class="card stack-sm" aria-labelledby="cl-o">
  <h2 id="cl-o" class="card__title"><?= $e($t('welfare.next_steps')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t($check !== null && $check['ok'] ? 'welfare.ready_to_send' : 'welfare.fix_first')) ?></p>
  <div class="row">
    <form method="post" action="<?= $e($url($base . '/submit')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t($c['status'] === 'need_info' ? 'welfare.resend' : 'welfare.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send',
        'disabled' => $check === null || !$check['ok'], 'disabledReason' => $t('welfare.fix_first')]) ?></form>
    <?= $ui('button', ['label' => $t('welfare.edit'), 'href' => $url($base . '/edit'), 'variant' => 'secondary', 'icon' => 'pencil']) ?>
  </div>
</section>
<?php endif; ?>

<?php if ($task !== null && $task['mine'] && in_array($c['status'], ['submitted', 'reviewing'], true)): ?>
<section class="card stack-sm" aria-labelledby="cl-x">
  <h2 id="cl-x" class="card__title"><?= $e($t($task['step'] === 1 ? 'welfare.review_title' : 'welfare.approve_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t($task['step'] === 1 ? 'welfare.review_help' : 'welfare.approve_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/decide')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php if ($task['step'] === 2): ?>
      <?= $ui('field', ['name' => 'amount', 'type' => 'money', 'label' => $t('welfare.field.approve_amount'), 'value' => $oldAmount !== '' ? $oldAmount : ($check !== null ? Money::format((int) $check['eligible'], 2, false) : ''),
          'help' => $t('welfare.approve_amount_help', ['v' => $check !== null ? $m($check['eligible']) : '—']), 'error' => $errors['amount'] ?? null]) ?>
    <?php endif; ?>
    <?= $ui('field', ['name' => 'note', 'type' => 'textarea', 'label' => $t('welfare.field.note'), 'help' => $t('welfare.note_help'), 'error' => $errors['note'] ?? null]) ?>
    <div class="row">
      <?php if ($task['step'] === 1): ?>
        <?= $ui('button', ['label' => $t('welfare.decision.pass'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'clipboard-check', 'attrs' => ['name' => 'decision', 'value' => 'pass']]) ?>
      <?php else: ?>
        <?= $ui('button', ['label' => $t('welfare.decision.approve'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check', 'attrs' => ['name' => 'decision', 'value' => 'approve']]) ?>
      <?php endif; ?>
      <?= $ui('button', ['label' => $t('welfare.decision.return'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'arrow-back-up', 'attrs' => ['name' => 'decision', 'value' => 'return']]) ?>
      <?= $ui('button', ['label' => $t('welfare.decision.reject'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'circle-x', 'attrs' => ['name' => 'decision', 'value' => 'reject']]) ?>
    </div>
  </form>
</section>
<?php elseif ($c['staff'] && $task !== null && $task['status'] === 'pending'): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t($task['step'] === 1 ? 'welfare.waiting_review' : 'welfare.waiting_approval')]) ?>
<?php endif; ?>

<?php if ($c['own'] && in_array($c['status'], ['draft', 'submitted', 'reviewing', 'need_info'], true)): ?>
<details class="card">
  <summary><?= $e($t('welfare.cancel_title')) ?></summary>
  <form method="post" action="<?= $e($url($base . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('welfare.field.cancel_reason'), 'id' => 'f-cancel-reason']) ?>
    <div class="row"><?= $ui('button', ['label' => $t('welfare.cancel_claim'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?></div>
  </form>
</details>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="cl-h">
  <h2 id="cl-h" class="card__title"><?= $e($t('welfare.history')) ?></h2>
  <ol class="timeline">
    <?php foreach ($c['events'] as $ev): ?>
    <li class="timeline__item"><span class="timeline__dot" aria-hidden="true"><?= $ui('icon', ['name' => $icons[$ev['event']] ?? 'history', 'size' => 'sm']) ?></span>
      <p><strong><?= $e($t('welfare.event.' . $ev['event'])) ?></strong> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?><?= $ev['display_name'] !== null ? ' · ' . $e((string) $ev['display_name']) : '' ?></span></p>
      <?php if ($ev['note'] !== null): ?><p class="text-sm"><?= $e((string) $ev['note']) ?></p><?php endif; ?></li>
    <?php endforeach; ?>
  </ol>
</section>
