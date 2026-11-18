<?php
/**
 * One day: result, every raw punch (source, device, how verified), corrections, and — for the employee — the
 * correction request form (also used to edit a returned request).
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $emp @var string $access @var array<string, mixed>|null $day @var list<array<string, mixed>> $punches
 * @var list<array<string, mixed>> $corrections @var array<string, mixed>|null $rule @var string $today @var string $tz
 * @var array<string, string> $errors @var array<string, string> $old @var array<string, mixed>|null $editing @var string $back
 */
use Blue\Core\I18n\DateFormat;

include __DIR__ . '/_helpers.php';
$loc = (string) ($v['locale'] ?? 'th');
$d = (string) $old['work_date'];
$f = static fn (string $k, string $def = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $def;
$own = $access === 'own';
$hasOpen = false;
foreach ($corrections as $c) {
    $hasOpen = $hasOpen || in_array($c['status'], ['pending', 'returned'], true);
}
$canAsk = $own && $d <= $today && ($editing !== null || !$hasOpen);
$verify = static fn (string $m): string => $t(match ($m) {
    'finger' => 'attendance.verify.finger', 'face' => 'attendance.verify.face', 'card' => 'attendance.verify.card', 'password' => 'attendance.verify.password', 'file' => 'attendance.verify.file', 'gps' => 'attendance.verify.gps',
    default => 'attendance.verify.unknown',
});
?>
<?= $ui('page-header', ['title' => DateFormat::day($d, $loc, true), 'subtitle' => $own ? $t('attendance.day_subtitle') : (string) $emp['employee_code'] . ' · ' . (string) $emp['name_th'],
    'actions' => $ui('button', ['label' => $t('attendance.back_month'), 'href' => $url($back), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>

<section class="card stack-sm" aria-labelledby="ad-h">
  <h2 id="ad-h" class="card__title"><?= $e($t('attendance.day_result')) ?> <?= $day !== null ? $attStatus((string) $day['status']) : '' ?></h2>
  <?php if ($day === null): ?>
    <p class="text-sm text-muted"><?= $e($t($d > $today ? 'attendance.day_future' : 'attendance.day_none')) ?></p>
  <?php else: ?>
  <dl class="dl">
    <dt><?= $e($t('attendance.field.day_type')) ?></dt><dd><?= $e($t(match ((string) $day['day_type']) { 'working' => 'attendance.daytype.working', 'holiday' => 'attendance.daytype.holiday', 'weekend' => 'attendance.daytype.weekend', 'special' => 'attendance.daytype.special', default => 'attendance.daytype.no_calendar' })) ?><?= ($day['day_name'] ?? '') !== '' ? ' · ' . $e((string) $day['day_name']) : '' ?></dd>
    <?php if ($day['leave_type'] !== null): ?><dt><?= $e($t('attendance.field.leave')) ?></dt><dd><?= $e((string) $day['leave_type']) ?> (<?= $e($t(match ((string) $day['leave_part']) { 'am' => 'attendance.part.am', 'pm' => 'attendance.part.pm', default => 'attendance.part.full' })) ?>)</dd><?php endif; ?>
    <dt><?= $e($t('attendance.field.schedule')) ?></dt><dd><?= $e($rule !== null ? $rule['start'] . '–' . $rule['end'] . ' · ' . $t('attendance.break_n', ['n' => (string) $rule['break_minutes']]) : $t('attendance.no_schedule_short')) ?></dd>
    <dt><?= $e($t('attendance.field.in')) ?></dt><dd><?= $e($attTime($day['first_in'], $tz)) ?><?= $day['in_source'] === 'correction' ? ' · ' . $e($t('attendance.from_correction')) : '' ?></dd>
    <dt><?= $e($t('attendance.field.out')) ?></dt><dd><?= $e($attTime($day['last_out'], $tz)) ?><?= $day['out_source'] === 'correction' ? ' · ' . $e($t('attendance.from_correction')) : '' ?></dd>
    <dt><?= $e($t('attendance.field.worked')) ?></dt><dd><?= $e($attMin($day['worked_minutes'])) ?></dd>
    <dt><?= $e($t('attendance.field.late')) ?> / <?= $e($t('attendance.field.early')) ?></dt><dd><?= $e(($day['late_minutes'] ?? '—') . ' / ' . ($day['early_minutes'] ?? '—')) ?> <?= $e($t('attendance.minutes')) ?></dd>
  </dl>
  <?php endif; ?>
</section>

<section class="card stack-sm" aria-labelledby="ap-h">
  <h2 id="ap-h" class="card__title"><?= $e($t('attendance.raw_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('attendance.raw_help')) ?></p>
  <?php if ($punches === []): ?>
    <p class="text-sm"><?= $e($t('attendance.no_punches')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ap-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('attendance.field.time')) ?></th><th scope="col"><?= $e($t('attendance.field.source')) ?></th><th scope="col"><?= $e($t('attendance.field.device')) ?></th><th scope="col"><?= $e($t('attendance.field.verify')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($punches as $p): ?>
        <tr>
          <td data-label="<?= $e($t('attendance.field.time')) ?>"><?= $e($p['at']->setTimezone(new \DateTimeZone($tz))->format('H:i:s')) ?><?= $p['direction'] !== null ? ' (' . $e($t($p['direction'] === 'in' ? 'attendance.dir.in' : 'attendance.dir.out')) . ')' : '' ?></td>
          <td data-label="<?= $e($t('attendance.field.source')) ?>"><?= $e($t(match ((string) $p['source']) { 'api' => 'attendance.source.api', 'gps' => 'attendance.source.gps', default => 'attendance.source.file' })) ?></td>
          <td data-label="<?= $e($t('attendance.field.device')) ?>"><?= $e((string) ($p['device_name'] ?? $p['dev_code'] ?? $p['device_code'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('attendance.field.verify')) ?>"><?= $e($verify((string) $p['verify_method'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($corrections !== []): ?>
<section class="card stack-sm" aria-labelledby="ax-h">
  <h2 id="ax-h" class="card__title"><?= $e($t('attendance.corrections')) ?></h2>
  <ul class="stack-sm" role="list">
    <?php foreach ($corrections as $c): ?>
      <li><a href="<?= $e($url('/attendance/c/' . $c['public_id'])) ?>"><?= $e($t($kindKey((string) $c['kind']))) ?> · <?= $e(($c['proposed_in'] !== null ? substr((string) $c['proposed_in'], 0, 5) : '—') . ' → ' . ($c['proposed_out'] !== null ? substr((string) $c['proposed_out'], 0, 5) : '—')) ?></a> <?= $corrStatus((string) $c['status']) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($canAsk): ?>
<section class="card stack-sm" id="ask" aria-labelledby="aq-h">
  <h2 id="aq-h" class="card__title"><?= $e($t($editing !== null ? 'attendance.edit_request' : 'attendance.ask_title')) ?></h2>
  <?php if ($editing !== null): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('attendance.returned_title'), 'text' => $t('attendance.returned_text')]) ?><?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('attendance.ask_help')) ?></p>
  <?php if (isset($errors['_form'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['_form']]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url($editing !== null ? '/attendance/c/' . $editing['public_id'] : '/attendance/corrections')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="work_date" value="<?= $e($d) ?>">
    <?php if (isset($errors['work_date'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['work_date']]) ?><?php endif; ?>
    <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('attendance.field.kind'), 'required' => true, 'value' => $f('kind'), 'error' => $errors['kind'] ?? null, 'options' => [
        '' => '—', 'missing_in' => $t('attendance.kind.missing_in'), 'missing_out' => $t('attendance.kind.missing_out'), 'wrong_time' => $t('attendance.kind.wrong_time'), 'other' => $t('attendance.kind.other')]]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'proposed_in', 'type' => 'time', 'label' => $t('attendance.field.proposed_in'), 'value' => $f('proposed_in'), 'error' => $errors['proposed_in'] ?? null]) ?>
      <?= $ui('field', ['name' => 'proposed_out', 'type' => 'time', 'label' => $t('attendance.field.proposed_out'), 'value' => $f('proposed_out'), 'error' => $errors['proposed_out'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'reason', 'type' => 'textarea', 'label' => $t('attendance.field.reason'), 'required' => true, 'value' => $f('reason'), 'error' => $errors['reason'] ?? null, 'help' => $t('attendance.reason_help')]) ?>
    <?= $ui('field', ['name' => 'evidence', 'type' => 'file', 'label' => $t('attendance.field.evidence'), 'error' => $errors['evidence'] ?? null, 'accept' => '.pdf,.png,.jpg,.jpeg,.webp',
        'help' => $t($editing !== null && $editing['document_pid'] !== null ? 'attendance.evidence_keep' : 'attendance.evidence_help')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t($editing !== null ? 'attendance.resend' : 'attendance.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php elseif ($own && $hasOpen): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('attendance.has_open')]) ?>
<?php endif; ?>
