<?php
/**
 * One room: what it has and its rules, its timeline for a day, the booking form (once or weekly) and — for the
 * workplace admin — change the room, close/open it, closures.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $room @var string $day @var string $today @var list<string> $slots @var list<array<string, mixed>> $cells
 * @var list<array<string, mixed>> $sites @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var list<string> $problems @var string $startAt
 */
use Blue\Modules\Rooms\RoomService;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$base = '/rooms/r/' . $room['public_id'];
$times = [];
$from = (int) substr((string) $room['open_from'], 0, 2) * 60 + (int) substr((string) $room['open_from'], 3, 2);
$to = (int) substr((string) $room['open_to'], 0, 2) * 60 + (int) substr((string) $room['open_to'], 3, 2);
for ($m = $from; $m <= $to; $m += RoomService::STEP) {
    $times[sprintf('%02d:%02d', intdiv($m, 60), $m % 60)] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}
$startQ = $startAt;
$startDefault = isset($times[$startQ]) ? $startQ : '';
$endDefault = '';
if ($startDefault !== '') {
    $em = (int) substr($startDefault, 0, 2) * 60 + (int) substr($startDefault, 3, 2) + 60;
    $endDefault = isset($times[sprintf('%02d:%02d', intdiv($em, 60), $em % 60)]) ? sprintf('%02d:%02d', intdiv($em, 60), $em % 60) : '';
}
$dayNames = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
$openDays = array_map('intval', explode(',', (string) $room['open_days']));
$d = RoomService::ymd($day);
?>
<?= $ui('page-header', ['title' => $room['code'] . ' · ' . $room['name'], 'subtitle' => $t('rooms.room_subtitle', ['n' => (string) $room['capacity']]),
    'crumbs' => [[$t('rooms.title'), $url('/rooms?date=' . $day)], [(string) $room['code'], null]]]) ?>
<?php if ($room['status'] === 'closed'): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('rooms.closed_now'), 'text' => (string) $room['closed_note']]) ?><?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="rr-i">
  <h2 id="rr-i" class="card__title"><?= $e($t('rooms.about')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('rooms.field.site')) ?></dt><dd><?= $e((string) ($room['site_code'] ?? $t('rooms.all_sites'))) ?></dd></div>
    <div><dt><?= $e($t('rooms.field.capacity')) ?></dt><dd><?= $e($t('rooms.seats', ['n' => (string) $room['capacity']])) ?></dd></div>
    <?php if ($room['location'] !== null): ?><div><dt><?= $e($t('rooms.field.location')) ?></dt><dd><?= $e((string) $room['location']) ?></dd></div><?php endif; ?>
    <?php if ($room['equipment'] !== null): ?><div><dt><?= $e($t('rooms.field.equipment')) ?></dt><dd><?= $e((string) $room['equipment']) ?></dd></div><?php endif; ?>
    <div><dt><?= $e($t('rooms.field.hours')) ?></dt><dd><?= $e(implode(' ', array_map(static fn (int $x): string => $t('rooms.day.' . $dayNames[$x]), $openDays)) . ' · ' . substr((string) $room['open_from'], 0, 5) . '–' . substr((string) $room['open_to'], 0, 5)) ?></dd></div>
    <div><dt><?= $e($t('rooms.rules')) ?></dt><dd><?= $e($t('rooms.rules_text', ['max' => (string) $room['max_minutes'], 'days' => (string) $room['advance_days'], 'buffer' => (string) $room['buffer_minutes']])) ?>
      <?= (int) $room['checkin_required'] === 1 ? '<br>' . $e($t('rooms.checkin_rule', ['m' => (string) $room['release_minutes']])) : '' ?></dd></div>
  </dl>
  <?php if ($room['closures'] !== []): ?>
  <h3 class="section-title"><?= $e($t('rooms.closures')) ?></h3>
  <ul class="plain-list stack-sm">
    <?php foreach ($room['closures'] as $c): ?><li class="row"><span><?= $e(substr((string) $c['starts_at'], 0, 16) . ' – ' . substr((string) $c['ends_at'], 0, 16) . ' · ' . $c['reason']) ?></span>
      <?php if ($room['can_manage']): ?><form method="post" action="<?= $e($url($base . '/closures/' . $c['id'] . '/remove')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('rooms.remove_closure'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash']) ?></form><?php endif; ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="rr-b">
  <h2 id="rr-b" class="card__title"><?= $e($t('rooms.book_title')) ?></h2>
  <?php if ($room['status'] !== 'active'): ?><p class="text-sm text-muted"><?= $e($t('rooms.closed_now')) ?></p><?php else: ?>
  <form method="post" action="<?= $e($url($base . '/book')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'title', 'label' => $t('rooms.field.title'), 'required' => true, 'value' => $o('book', 'title'), 'help' => $t('rooms.title_help'), 'error' => $err('book', 'title')]) ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'date', 'type' => 'date', 'label' => $t('rooms.field.date'), 'required' => true, 'value' => $o('book', 'date', $day), 'min' => $today, 'error' => $err('book', 'date')]) ?>
      <?= $ui('field', ['name' => 'start', 'type' => 'select', 'label' => $t('rooms.field.start'), 'required' => true, 'value' => $o('book', 'start', $startDefault), 'options' => ['' => '—'] + $times, 'error' => $err('book', 'start')]) ?>
      <?= $ui('field', ['name' => 'end', 'type' => 'select', 'label' => $t('rooms.field.end'), 'required' => true, 'value' => $o('book', 'end', $endDefault), 'options' => ['' => '—'] + $times, 'error' => $err('book', 'end')]) ?>
    </div>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'attendee_count', 'type' => 'number', 'label' => $t('rooms.field.attendee_count'), 'required' => true, 'value' => $o('book', 'attendee_count'), 'help' => $t('rooms.count_help', ['n' => (string) $room['capacity']]), 'error' => $err('book', 'attendee_count')]) ?>
      <?= $ui('field', ['name' => 'attendees', 'type' => 'textarea', 'label' => $t('rooms.field.attendees'), 'value' => $o('book', 'attendees'), 'help' => $t('rooms.attendees_help'), 'error' => $err('book', 'attendees')]) ?>
    </div>
    <?php if ($form === 'book' && $problems !== [] && isset($errors['attendees'])): ?><ul class="plain-list text-sm field__error"><?php foreach ($problems as $p): ?><li><?= $e($p) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('rooms.field.repeat')) ?></legend>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'repeat', 'type' => 'select', 'label' => $t('rooms.field.repeat_kind'), 'value' => $o('book', 'repeat', 'none'), 'options' => ['none' => $t('rooms.repeat.none'), 'weekly' => $t('rooms.repeat.weekly')], 'error' => $err('book', 'repeat')]) ?>
        <?= $ui('field', ['name' => 'every_weeks', 'type' => 'select', 'label' => $t('rooms.field.every_weeks'), 'value' => $o('book', 'every_weeks', '1'), 'options' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4'], 'error' => $err('book', 'every_weeks')]) ?>
        <?= $ui('field', ['name' => 'until', 'type' => 'date', 'label' => $t('rooms.field.until'), 'value' => $o('book', 'until'), 'error' => $err('book', 'until')]) ?>
      </div>
      <label class="check"><input type="checkbox" name="skip_conflicts" value="1"<?= $form === 'book' && !empty($old['skip_conflicts']) ? ' checked' : '' ?>> <?= $e($t('rooms.skip_conflicts')) ?></label>
      <?php if ($form === 'book' && $problems !== [] && isset($errors['repeat'])): ?><p class="field__error"><?= $e($t('rooms.clash_dates')) ?> <?= $e(implode(', ', array_map(static fn (string $x): string => $date($x, false), $problems))) ?></p><?php endif; ?>
    </fieldset>
    <div class="row"><?= $ui('button', ['label' => $t('rooms.book'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'calendar-event']) ?></div>
    <p class="text-sm text-muted"><?= $e($t('rooms.no_approval_note')) ?></p>
  </form>
  <?php endif; ?>
</section>
</div>
<section class="card stack-sm" aria-labelledby="rr-t">
  <div class="row cal-nav">
    <?php if ($d !== null): ?><?= $ui('button', ['label' => $t('rooms.prev_day'), 'href' => $url($base . '?date=' . $d->modify('-1 day')->format('Y-m-d')), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-left']) ?><?php endif; ?>
    <h2 id="rr-t" class="card__title"><?= $e($date($day, false)) ?></h2>
    <?php if ($d !== null): ?><?= $ui('button', ['label' => $t('rooms.next_day'), 'href' => $url($base . '?date=' . $d->modify('+1 day')->format('Y-m-d')), 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'chevron-right']) ?><?php endif; ?>
  </div>
  <?php $tlRooms = [$room]; $tlRows = [(int) $room['id'] => $cells]; $tlDay = $day; $tlLabel = $t('rooms.grid_label', ['d' => $day]); include __DIR__ . '/_timeline.php'; ?>
</section>

<?php if ($room['can_manage']): ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="rr-c">
  <h2 id="rr-c" class="card__title"><?= $e($t('rooms.add_closure')) ?></h2>
  <form method="post" action="<?= $e($url($base . '/closures')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'closure_from', 'type' => 'datetime-local', 'label' => $t('rooms.field.from'), 'required' => true, 'value' => $o('closure', 'closure_from'), 'error' => $err('closure', 'closure_from')]) ?>
      <?= $ui('field', ['name' => 'closure_to', 'type' => 'datetime-local', 'label' => $t('rooms.field.to'), 'required' => true, 'value' => $o('closure', 'closure_to'), 'error' => $err('closure', 'closure_to')]) ?>
    </div>
    <?= $ui('field', ['name' => 'closure_reason', 'label' => $t('rooms.field.reason'), 'required' => true, 'value' => $o('closure', 'closure_reason'), 'error' => $err('closure', 'closure_reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('rooms.add_closure'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'lock']) ?></div>
  </form>
  <h3 class="section-title"><?= $e($t($room['status'] === 'active' ? 'rooms.close_room' : 'rooms.open_room')) ?></h3>
  <form method="post" action="<?= $e($url($base . '/' . ($room['status'] === 'active' ? 'close' : 'open'))) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php if ($room['status'] === 'active'): ?><?= $ui('field', ['name' => 'note', 'label' => $t('rooms.field.reason'), 'required' => true, 'value' => $o('state', 'note'), 'help' => $t('rooms.close_help'), 'error' => $err('state', 'note')]) ?><?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t($room['status'] === 'active' ? 'rooms.close_room' : 'rooms.open_room'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => $room['status'] === 'active' ? 'lock' : 'lock-open']) ?></div>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="rr-e">
  <h2 id="rr-e" class="card__title"><?= $e($t('rooms.edit_room')) ?></h2>
  <?php $rf = 'edit'; $rv = $form === 'edit' ? $old : ['code' => $room['code'], 'name' => $room['name'], 'site' => '', 'capacity' => (string) $room['capacity'], 'equipment' => (string) ($room['equipment'] ?? ''),
      'location' => (string) ($room['location'] ?? ''), 'open_from' => substr((string) $room['open_from'], 0, 5), 'open_to' => substr((string) $room['open_to'], 0, 5), 'open_days' => array_map('strval', $openDays),
      'buffer_minutes' => (string) $room['buffer_minutes'], 'max_minutes' => (string) $room['max_minutes'], 'advance_days' => (string) $room['advance_days'],
      'checkin_required' => (int) $room['checkin_required'] === 1 ? '1' : '', 'release_minutes' => (string) ($room['release_minutes'] ?? '')];
      foreach ($sites as $s) { if ((int) $s['id'] === (int) ($room['site_id'] ?? 0) && $form !== 'edit') { $rv['site'] = (string) $s['public_id']; } }
      $rAction = $base . '/edit'; $rLe = ''; $rErr = $form === 'edit' ? $errors : []; include __DIR__ . '/_room_form.php'; ?>
</section>
</div>
<?php endif; ?>
