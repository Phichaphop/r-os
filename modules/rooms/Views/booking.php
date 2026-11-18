<?php
/**
 * One booking: when, which room, who booked and who was invited, check-in, move to another time, cancel (one or the
 * rest of the weekly series), history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $b @var string $today @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$base = '/rooms/b/' . $b['public_id'];
$status = (string) $b['status'];
?>
<?= $ui('page-header', ['title' => (string) $b['title'], 'subtitle' => $b['room_code'] . ' · ' . $b['room_name'],
    'crumbs' => [[$t('rooms.title'), $url('/rooms?date=' . substr((string) $b['starts_at'], 0, 10))], [$t('rooms.booking'), null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($b['can_checkin']): ?>
  <form method="post" action="<?= $e($url($base . '/checkin')) ?>" class="card row"><?= $csrf ?><p><?= $e($t('rooms.checkin_now', ['m' => (string) $b['release_minutes']])) ?></p>
    <?= $ui('button', ['label' => $t('rooms.checkin'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
<?php endif; ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="rb-d">
  <h2 id="rb-d" class="card__title"><?= $e($t('rooms.booking')) ?> <?php include __DIR__ . '/_status.php'; ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('rooms.col.when')) ?></dt><dd><?= $e($date(substr((string) $b['starts_at'], 0, 10), false) . ' ' . substr((string) $b['starts_at'], 11, 5) . '–' . substr((string) $b['ends_at'], 11, 5)) ?></dd></div>
    <div><dt><?= $e($t('rooms.col.room')) ?></dt><dd><a href="<?= $e($url('/rooms/r/' . $b['room_pid'] . '?date=' . substr((string) $b['starts_at'], 0, 10))) ?>"><?= $e($b['room_code'] . ' · ' . $b['room_name']) ?></a></dd></div>
    <div><dt><?= $e($t('rooms.booked_by')) ?></dt><dd><?= $e((string) $b['booker']) ?></dd></div>
    <div><dt><?= $e($t('rooms.field.attendee_count')) ?></dt><dd><?= (int) $b['attendee_count'] ?></dd></div>
    <?php if ($b['invited'] !== []): ?><div><dt><?= $e($t('rooms.field.attendees')) ?></dt><dd><?= $e(implode(', ', array_column($b['invited'], 'name'))) ?></dd></div><?php endif; ?>
    <?php if ($b['series_id'] !== null): ?><div><dt><?= $e($t('rooms.field.repeat')) ?></dt><dd><?= $e($t('rooms.series_text', ['n' => (string) $b['every_weeks'], 'until' => $date((string) $b['until_date'], false), 'left' => (string) $b['series_left']])) ?></dd></div><?php endif; ?>
    <?php if ($b['cancel_reason'] !== null): ?><div><dt><?= $e($t('rooms.field.reason')) ?></dt><dd><?= $e((string) $b['cancel_reason']) ?></dd></div><?php endif; ?>
  </dl>
</section>
<section class="card stack-sm" aria-labelledby="rb-h">
  <h2 id="rb-h" class="card__title"><?= $e($t('rooms.history')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($b['events'] as $ev): ?><li><?= $e($t('rooms.event.' . $ev['event'])) ?> · <?= $e((string) ($ev['actor'] ?? $t('rooms.system'))) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
</div>
<?php if ($b['future'] && $status === 'confirmed' && ($b['mine'] || $b['can_manage'])): ?>
<div class="grid grid--2">
<?php if ($b['mine']): ?>
<section class="card stack-sm" aria-labelledby="rb-m">
  <h2 id="rb-m" class="card__title"><?= $e($t('rooms.move')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/move')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'date', 'id' => 'f-move-date', 'type' => 'date', 'label' => $t('rooms.field.date'), 'required' => true, 'value' => $o('move', 'date', substr((string) $b['starts_at'], 0, 10)), 'min' => $today, 'error' => $err('move', 'date')]) ?>
      <?= $ui('field', ['name' => 'start', 'id' => 'f-move-start', 'type' => 'time', 'label' => $t('rooms.field.start'), 'required' => true, 'value' => $o('move', 'start', substr((string) $b['starts_at'], 11, 5)), 'error' => $err('move', 'start')]) ?>
      <?= $ui('field', ['name' => 'end', 'id' => 'f-move-end', 'type' => 'time', 'label' => $t('rooms.field.end'), 'required' => true, 'value' => $o('move', 'end', substr((string) $b['ends_at'], 11, 5)), 'error' => $err('move', 'end')]) ?>
    </div>
    <p class="text-sm text-muted"><?= $e($t('rooms.move_help')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('rooms.move'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?></div>
  </form>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="rb-c">
  <h2 id="rb-c" class="card__title"><?= $e($t('rooms.cancel')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('rooms.field.reason'), 'required' => !$b['mine'], 'value' => $o('cancel', 'reason'), 'help' => $t($b['mine'] ? 'rooms.cancel_help_mine' : 'rooms.cancel_help_admin'), 'error' => $err('cancel', 'reason')]) ?>
    <?php if ($b['series_id'] !== null && $b['series_left'] > 1): ?><label class="check"><input type="checkbox" name="series" value="1"> <?= $e($t('rooms.cancel_series', ['n' => (string) $b['series_left']])) ?></label><?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('rooms.cancel'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?></div>
  </form>
</section>
</div>
<?php endif; ?>
