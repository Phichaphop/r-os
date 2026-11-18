<?php
/**
 * One locker: its status, the holder (admins / the holder), what the person can do (reserve, cancel, confirm the
 * key, ask to give it back) and what the admin can do (hand the key over, record the return, out of use), history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $locker @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$l = $locker;
$a = $l['current'];
$base = '/lockers/l/' . $l['public_id'];
$lstatus = (string) $l['status'];
$btn = static fn (string $action, string $label, string $variant = 'secondary', string $icon = 'circle-check'): string => '<form method="post" action="' . $e($url($base . '/' . $action)) . '">' . $csrf
    . $ui('button', ['label' => $t($label), 'type' => 'submit', 'variant' => $variant, 'icon' => $icon]) . '</form>';
?>
<?= $ui('page-header', ['title' => $t('lockers.locker_title', ['code' => (string) $l['code']]), 'subtitle' => $l['zone_code'] . ' · ' . $l['zone_name'],
    'crumbs' => [[$t('lockers.title'), $url('/lockers')], [(string) $l['zone_code'], $url('/lockers/z/' . $l['zone_pid'])], [(string) $l['code'], null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="ll-d">
  <h2 id="ll-d" class="card__title"><?= $e($t('lockers.details')) ?> <?php include __DIR__ . '/_badge.php'; ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('lockers.col.position')) ?></dt><dd><?= $e($t('lockers.position', ['r' => (string) $l['row_no'], 'c' => (string) $l['col_no']])) ?></dd></div>
    <div><dt><?= $e($t('lockers.field.size')) ?></dt><dd><?= $e((string) $l['size']) ?></dd></div>
    <?php if ($l['status_note'] !== null && ($l['can_manage'] || $l['mine'])): ?><div><dt><?= $e($t('lockers.field.note')) ?></dt><dd><?= $e((string) $l['status_note']) ?></dd></div><?php endif; ?>
    <?php if ($a !== null): ?>
    <div><dt><?= $e($t('lockers.holder')) ?></dt><dd><?= $e($a['employee_code'] . ' · ' . $a['name_th']) ?><?= $a['employee_status'] === 'former' || ($a['termination_date'] !== null) ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('lockers.leaving')]) : '' ?></dd></div>
    <div><dt><?= $e($t('lockers.col.state')) ?></dt><dd><?= $e($t('lockers.astatus.' . $a['status'])) ?><?= $a['status'] === 'reserved' ? ' · ' . $e($t('lockers.pick_key_by', ['d' => $date((string) $a['hold_until'], false)])) : '' ?></dd></div>
    <?php if ($a['key_no'] !== null): ?><div><dt><?= $e($t('lockers.field.key_no')) ?></dt><dd><?= $e((string) $a['key_no']) ?> · <?= $e($date((string) $a['key_issued_at'])) ?><?= $a['received_ack_at'] !== null ? ' · ' . $e($t('lockers.acked')) : '' ?></dd></div><?php endif; ?>
    <?php if ($a['return_reason'] !== null): ?><div><dt><?= $e($t('lockers.return_reason')) ?></dt><dd><?= $e($t('lockers.reason.' . $a['return_reason'])) ?></dd></div><?php endif; ?>
    <?php endif; ?>
  </dl>
</section>
<section class="card stack-sm" aria-labelledby="ll-a">
  <h2 id="ll-a" class="card__title"><?= $e($t('lockers.what_next')) ?></h2>
  <?php if ($lstatus === 'available' && $l['zone_status'] === 'active'): ?>
    <?php if ($l['has_one']): ?><p class="text-sm"><?= $e($t('lockers.have_one')) ?></p><?php else: ?>
      <p class="text-sm"><?= $e($t('lockers.reserve_help', ['d' => (string) $l['hold_days']])) ?></p><div class="row"><?= $btn('reserve', 'lockers.reserve', 'primary', 'key') ?></div>
    <?php endif; ?>
  <?php elseif ($l['mine'] && $a !== null && $a['status'] === 'reserved'): ?>
    <p class="text-sm"><?= $e($t('lockers.go_pick')) ?></p><div class="row"><?= $btn('cancel', 'lockers.cancel_reservation', 'ghost', 'x') ?></div>
  <?php elseif ($l['mine'] && $a !== null && $a['status'] === 'assigned'): ?>
    <div class="row"><?php if ($a['received_ack_at'] === null): ?><?= $btn('ack', 'lockers.ack', 'primary') ?><?php endif; ?><?= $btn('ask_return', 'lockers.ask_return', 'secondary', 'arrow-back-up') ?></div>
    <p class="text-sm text-muted"><?= $e($t('lockers.ack_help')) ?></p>
  <?php elseif ($l['mine'] && $a !== null && $a['status'] === 'return_pending'): ?>
    <p class="text-sm"><?= $e($t('lockers.bring_key')) ?></p>
  <?php elseif (!$l['can_manage']): ?>
    <p class="text-sm text-muted"><?= $e($t('lockers.not_free')) ?></p>
  <?php endif; ?>

  <?php if ($l['can_manage']): ?>
    <?php if ($a !== null && $a['status'] === 'reserved'): ?>
    <form method="post" action="<?= $e($url($base . '/issue')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <h3 class="section-title"><?= $e($t('lockers.issue_key')) ?></h3>
      <?= $ui('field', ['name' => 'key_no', 'label' => $t('lockers.field.key_no'), 'required' => true, 'value' => $o('issue', 'key_no'), 'help' => $t('lockers.key_help'), 'error' => $err('issue', 'key_no')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('lockers.issue_key'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'key']) ?></div>
    </form>
    <div class="row"><?= $btn('cancel', 'lockers.cancel_reservation', 'ghost', 'x') ?></div>
    <?php elseif ($a !== null && in_array($a['status'], ['assigned', 'return_pending'], true)): ?>
    <form method="post" action="<?= $e($url($base . '/return')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <h3 class="section-title"><?= $e($t('lockers.record_return')) ?></h3>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'key_returned', 'type' => 'select', 'label' => $t('lockers.field.key_returned'), 'required' => true, 'value' => $o('return', 'key_returned'), 'options' => ['' => '—', '1' => $t('lockers.yes'), '0' => $t('lockers.no')], 'error' => $err('return', 'key_returned')]) ?>
        <?= $ui('field', ['name' => 'condition', 'type' => 'select', 'label' => $t('lockers.field.condition'), 'required' => true, 'value' => $o('return', 'condition'), 'options' => ['' => '—', 'good' => $t('lockers.condition.good'), 'damaged' => $t('lockers.condition.damaged')], 'error' => $err('return', 'condition')]) ?>
      </div>
      <?= $ui('field', ['name' => 'note', 'label' => $t('lockers.field.note'), 'value' => $o('return', 'note'), 'help' => $t('lockers.return_note_help'), 'error' => $err('return', 'note')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('lockers.record_return'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'arrow-back-up']) ?></div>
    </form>
    <?php elseif ($a === null): ?>
    <form method="post" action="<?= $e($url($base . '/status')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <h3 class="section-title"><?= $e($t('lockers.change_status')) ?></h3>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('lockers.field.status'), 'required' => true, 'value' => $o('status', 'status', $lstatus), 'options' => ['available' => $t('lockers.status.available'), 'maintenance' => $t('lockers.status.maintenance'), 'blocked' => $t('lockers.status.blocked')]]) ?>
        <?= $ui('field', ['name' => 'status_note', 'label' => $t('lockers.field.note'), 'value' => $o('status', 'status_note'), 'help' => $t('lockers.status_note_help'), 'error' => $err('status', 'status_note')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('lockers.change_status'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'tool']) ?></div>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</section>
</div>
<?php if ($l['can_manage']): ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="ll-h">
  <h2 id="ll-h" class="card__title"><?= $e($t('lockers.holders')) ?></h2>
  <?php if ($l['history'] === []): ?><p class="text-sm text-muted"><?= $e($t('lockers.no_history')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm"><?php foreach ($l['history'] as $h): ?><li><?= $e($h['employee_code'] . ' · ' . $h['name_th']) ?> · <?= $e($t('lockers.astatus.' . $h['status'])) ?> <span class="text-sm text-muted"><?= $e($date((string) $h['created_at'], false)) ?><?= $h['returned_at'] !== null ? ' → ' . $e($date((string) $h['returned_at'], false)) : '' ?></span></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="ll-e">
  <h2 id="ll-e" class="card__title"><?= $e($t('lockers.history')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($l['events'] as $ev): ?><li><?= $e($t('lockers.event.' . $ev['event'])) ?> · <?= $e((string) ($ev['actor'] ?? $t('lockers.system'))) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
</div>
<?php endif; ?>
