<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $req @var list<array<string, mixed>> $history @var bool $canDoc @var string $today @var bool $isHr
 * @var list<array{0: string, 1: string}> $summary
 */
use Blue\Core\I18n\DateFormat;

include __DIR__ . '/_status.php';
$loc = (string) ($v['locale'] ?? 'th');
$own = $req['access'] === 'own';
$st = (string) $req['status'];
$canWithdraw = $own && in_array($st, ['pending', 'returned'], true);
$canAskCancel = $own && $st === 'approved' && (string) $req['start_date'] > $today;
$canHrCancel = $isHr && !$own && in_array($st, ['pending', 'returned', 'approved', 'taken', 'cancel_pending'], true);
$approvalPid = $st === 'cancel_pending' ? $req['cancel_instance_pid'] : $req['instance_pid'];
?>
<?= $ui('page-header', ['title' => $t('leave.request_title'), 'subtitle' => (string) ($summary[0][1] ?? ''),
    'actions' => $ui('button', ['label' => $t('leave.title'), 'href' => $url('/leave'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>

<section class="card stack-sm" aria-labelledby="ls-h">
  <h2 id="ls-h" class="card__title"><?= $e($t('leave.details')) ?> <?= $leaveStatus($st) ?></h2>
  <dl class="dl">
    <?php foreach ($summary as [$k, $val]): ?><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd><?php endforeach; ?>
    <dt><?= $e($t('leave.field.working_days')) ?></dt>
    <dd><?= $e(implode(', ', array_map(static fn (string $d): string => DateFormat::day($d, $loc), $req['days']))) ?></dd>
  </dl>
  <div class="row">
    <?php if ($req['document_pid'] !== null && $canDoc): ?>
      <?= $ui('button', ['label' => $t('leave.download_document'), 'href' => $url('/leave/r/' . $req['public_id'] . '/document'), 'variant' => 'secondary', 'icon' => 'download']) ?>
    <?php endif; ?>
    <?php if ($own && $st === 'returned'): ?>
      <?= $ui('button', ['label' => $t('leave.edit_resend'), 'href' => $url('/leave/r/' . $req['public_id'] . '/edit'), 'variant' => 'primary', 'icon' => 'pencil']) ?>
    <?php endif; ?>
    <?php if (!$own && $approvalPid !== null && in_array($st, ['pending', 'cancel_pending'], true)): ?>
      <?= $ui('button', ['label' => $t('leave.open_approval'), 'href' => $url('/approvals/' . $approvalPid), 'variant' => 'primary', 'icon' => 'checks']) ?>
    <?php endif; ?>
  </div>
</section>

<?php if ($history !== []): ?>
<section class="card stack-sm" aria-labelledby="lh-h">
  <h2 id="lh-h" class="card__title"><?= $e($t('leave.history')) ?></h2>
  <ol class="timeline">
    <?php foreach ($history as $h): ?>
      <li class="timeline__item"><span class="timeline__dot" aria-hidden="true"><?= $ui('icon', ['name' => match ((string) $h['decision']) { 'approve', 'execute_succeeded' => 'circle-check', 'reject' => 'circle-x', 'return' => 'arrow-back-up', 'cancel' => 'x', default => 'send' }, 'size' => 'sm']) ?></span>
        <div><strong><?= $e($t('workflow.decision.' . $h['decision'])) ?></strong> · <?= $e((string) ($h['display_name'] ?? '')) ?> · <span class="text-muted"><?= $e($date((string) $h['decided_at'])) ?></span>
          <?php if (($h['comment'] ?? '') !== '' && $h['decision'] !== 'execute_failed'): ?><div class="text-sm">“<?= $e((string) $h['comment']) ?>”</div><?php endif; ?></div></li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<?php if ($canWithdraw || $canAskCancel || $canHrCancel): ?>
<section class="card stack-sm" id="cancel" aria-labelledby="lc-h">
  <h2 id="lc-h" class="card__title"><?= $e($t($canWithdraw ? 'leave.withdraw' : ($canAskCancel ? 'leave.ask_cancel' : 'leave.hr_cancel'))) ?></h2>
  <p class="text-sm text-muted"><?= $e($t($canWithdraw ? 'leave.withdraw_help' : ($canAskCancel ? 'leave.ask_cancel_help' : 'leave.hr_cancel_help'))) ?></p>
  <form method="post" action="<?= $e($url('/leave/r/' . $req['public_id'] . '/cancel')) ?>" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'reason', 'label' => $t('leave.field.cancel_reason'), 'required' => true]) ?>
    <div class="row"><?= $ui('button', ['label' => $t($canWithdraw ? 'leave.withdraw' : ($canAskCancel ? 'leave.ask_cancel' : 'leave.hr_cancel')), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?></div>
  </form>
</section>
<?php endif; ?>
