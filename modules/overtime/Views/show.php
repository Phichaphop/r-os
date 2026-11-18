<?php
/**
 * One OT request + certify hours. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $req @var bool $canVerify @var bool $ended @var array{actual: ?int, payable: int, round: int}|null $proposal @var array<string, string> $errors @var bool $canCancel
 */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$q = $req;
?>
<?= $ui('page-header', ['title' => $t('overtime.request_title'), 'subtitle' => $q['employee_code'] . ' · ' . $q['name_th'] . ' — ' . $otWhen($q),
    'crumbs' => [[$t('overtime.title'), $url('/overtime')], [$t('overtime.request_title'), null]], 'actions' => $otStatus((string) $q['status'])]) ?>
<?php if ((int) $q['cap_warning'] === 1): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('overtime.cap_warning')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="d-h">
  <h2 id="d-h" class="card__title"><?= $e($t('overtime.details')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('overtime.field.when')) ?></dt><dd><?= $e($otWhen($q)) ?></dd></div>
    <div><dt><?= $e($t('overtime.field.day_type')) ?></dt><dd><?= $e($t('overtime.day_type.' . $q['day_type'])) ?><?= $q['shift_code'] !== null ? ' · ' . $e($t('overtime.shift', ['code' => (string) $q['shift_code']])) : '' ?></dd></div>
    <div><dt><?= $e($t('overtime.field.requested')) ?></dt><dd><?= $e($hm((int) $q['minutes_requested'])) ?></dd></div>
    <div><dt><?= $e($t('overtime.field.actual')) ?></dt><dd><?= $e($q['actual_minutes'] !== null ? $hm((int) $q['actual_minutes']) : '—') ?></dd></div>
    <div><dt><?= $e($t('overtime.field.payable')) ?></dt><dd><?= $e($q['payable_minutes'] !== null ? $hm((int) $q['payable_minutes']) : '—') ?></dd></div>
    <div><dt><?= $e($t('overtime.field.cost_center')) ?></dt><dd><?= $e((string) ($q['cost_center_code'] ?? '—')) ?></dd></div>
    <div><dt><?= $e($t('overtime.field.reason')) ?></dt><dd><?= $e((string) $q['reason']) ?></dd></div>
    <?php if ($q['verify_note'] !== null): ?><div><dt><?= $e($t('overtime.field.note')) ?></dt><dd><?= $e((string) $q['verify_note']) ?></dd></div><?php endif; ?>
    <?php if ($q['batch_no'] !== null): ?><div><dt><?= $e($t('overtime.field.batch')) ?></dt><dd><?= $e($t('overtime.batch_title', ['n' => (string) $q['batch_no']])) ?></dd></div><?php endif; ?>
  </dl>
  <?php if ($q['instance_pid'] !== null): ?><p class="text-sm"><a href="<?= $e($url('/approvals/' . $q['instance_pid'])) ?>"><?= $e($t('overtime.approval_link')) ?></a></p><?php endif; ?>
</section>

<?php if ($canVerify && $proposal !== null): ?>
<section class="card stack" aria-labelledby="v-h">
  <h2 id="v-h" class="card__title"><?= $e($t('overtime.verify.title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($proposal['actual'] === null ? $t('overtime.verify.no_scan') : $t('overtime.verify.proposal', ['actual' => $hm($proposal['actual']), 'payable' => $hm($proposal['payable']), 'round' => (string) $proposal['round']])) ?></p>
  <form method="post" action="<?= $e($url('/overtime/r/' . $q['public_id'] . '/verify')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'payable_minutes', 'type' => 'number', 'label' => $t('overtime.verify.minutes'), 'value' => (string) $proposal['payable'], 'min' => '0', 'max' => (string) $q['minutes_requested'], 'error' => $errors['payable_minutes'] ?? null]) ?>
      <?= $ui('field', ['name' => 'note', 'label' => $t('overtime.field.note'), 'value' => '', 'help' => $t('overtime.verify.note_help'), 'error' => $errors['note'] ?? null]) ?>
    </div>
    <div class="row">
      <?= $ui('button', ['label' => $t('overtime.verify.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks', 'attrs' => ['name' => 'action', 'value' => 'verify']]) ?>
      <?= $ui('button', ['label' => $t('overtime.verify.dispute'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'alert-triangle', 'attrs' => ['name' => 'action', 'value' => 'dispute']]) ?>
      <?php if ($q['status'] === 'disputed' && $q['access'] === 'hr'): ?><?= $ui('button', ['label' => $t('overtime.verify.reject'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'circle-x', 'attrs' => ['name' => 'action', 'value' => 'reject']]) ?><?php endif; ?>
    </div>
  </form>
</section>
<?php elseif (in_array($q['status'], ['approved'], true) && !$ended): ?>
  <p class="text-sm text-muted"><?= $e($t('overtime.verify.after_end')) ?></p>
<?php endif; ?>

<?php if ($canCancel): ?>
<form method="post" action="<?= $e($url('/overtime/r/' . $q['public_id'] . '/cancel')) ?>" class="row">
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <input type="hidden" name="reason" value="withdrawn">
  <?= $ui('button', ['label' => $t('overtime.cancel'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'x']) ?>
</form>
<?php endif; ?>
