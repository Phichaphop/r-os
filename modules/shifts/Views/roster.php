<?php
/**
 * One roster: the grid (people × dates). Draft = editable selects + fill/copy tools + checks + publish;
 * published = read-only team calendar + revise + change log.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $ro @var list<string> $dates @var list<array<string, mixed>> $people @var array<int, array<string, ?int>> $cells
 * @var list<array<string, mixed>> $patterns @var array<int, array<string, mixed>> $byId @var bool $draft
 * @var array{errors: list<array{key: string, params: array<string, string>}>, warnings: list<array{key: string, params: array<string, string>}>, rules: ?array<string, mixed>}|null $check
 * @var int $diff @var list<array<string, mixed>> $changes @var array<string, string> $holidays @var array<string, string> $errors @var string $today
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$day = static fn (string $d): string => DateFormat::day($d, $loc);
$short = static fn (string $d): string => (string) (int) substr($d, 8, 2) . '/' . (string) (int) substr($d, 5, 2);
$active = array_values(array_filter($patterns, static fn (array $p): bool => $p['status'] === 'active'));
$opts = ['' => '·', 'OFF' => $t('shifts.off_short')];
foreach ($active as $p) {
    $opts[(string) $p['public_id']] = (string) $p['code'];
}
$pidOf = [];
foreach ($patterns as $p) {
    $pidOf[(int) $p['id']] = (string) $p['public_id'];
}
$cellShift = static function (int $emp, string $d) use ($cells, $byId): ?array {
    if (!array_key_exists($d, $cells[$emp] ?? [])) {
        return null;
    }
    $pid = $cells[$emp][$d];
    if ($pid === null) {
        return ['off' => true];
    }
    $p = $byId[$pid] ?? null;
    if ($p === null) {
        return null;
    }
    $s = (int) \Blue\Modules\Attendance\WorkSchedule::minutes((string) $p['start_time']);
    $en = (int) \Blue\Modules\Attendance\WorkSchedule::minutes((string) $p['end_time']);
    return ['off' => false, 'tone' => $p['tone'], 'code' => $p['code'], 'start' => $p['start_time'], 'end' => $p['end_time'], 'overnight' => $en <= $s];
};
$msg = static fn (array $m): string => $t('shifts.check.' . preg_replace('/^shifts\.check\./', '', (string) $m['key']), $m['params']);
$phase = (string) $ro['phase'];
$statusTone = ['draft' => 'warning', 'published' => 'info', 'active' => 'success', 'completed' => 'neutral', 'superseded' => 'neutral', 'cancelled' => 'neutral'][$phase] ?? 'neutral';
?>
<?= $ui('page-header', ['title' => (string) $ro['name'],
    'subtitle' => $day((string) $ro['start_date']) . ' – ' . $day((string) $ro['end_date']) . ' · ' . $ro['le_code'] . ($ro['site_code'] !== null ? ' · ' . $ro['site_code'] : '') . ($ro['dept_code'] !== null ? ' · ' . $ro['dept_code'] : '')
        . ((int) $ro['revision'] > 1 ? ' · ' . $t('shifts.revision_n', ['n' => (string) $ro['revision']]) : ''),
    'crumbs' => [[$t('shifts.admin_title'), $url('/shifts/admin?le=' . rawurlencode((string) $ro['le_pid']))], [(string) $ro['name'], null]],
    'actions' => $ui('badge', ['tone' => $statusTone, 'label' => $t('shifts.phase.' . $phase)])]) ?>
<?php if (isset($errors['publish'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['publish']]) ?><?php endif; ?>
<?php if (isset($errors['cells'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['cells']]) ?><?php endif; ?>
<?php if ($ro['status'] === 'superseded'): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('shifts.superseded_note')]) ?>
<?php elseif ($draft && $ro['supersedes_pid'] !== null): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('shifts.revision_note', ['n' => (string) $diff])]) ?>
<?php endif; ?>
<?php if ($patterns === []): ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('shifts.patterns_first')]) ?>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="grid-h">
  <div class="row row--between"><h2 id="grid-h" class="card__title"><?= $e($t($draft ? 'shifts.grid_edit' : 'shifts.grid_view')) ?></h2>
    <span class="text-sm text-muted"><?= $e($t('shifts.legend')) ?></span></div>
  <?php if ($people === []): ?><p class="text-sm text-muted"><?= $e($t('shifts.no_people')) ?></p><?php else: ?>
  <?php if ($draft): ?><form method="post" action="<?= $e($url('/shifts/roster/' . $ro['public_id'] . '/cells')) ?>" class="stack-sm" id="grid-form"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><?php endif; ?>
  <div class="table-wrap shift-grid-wrap" role="region" aria-labelledby="grid-h" tabindex="0">
    <table class="table shift-grid">
      <thead><tr><th scope="col" class="shift-grid__who"><?= $e($t('shifts.field.employee')) ?></th>
        <?php foreach ($dates as $d): ?><th scope="col" class="shift-grid__day<?= isset($holidays[$d]) ? ' is-' . $e($holidays[$d]) : '' ?><?= $d === $today ? ' is-today' : '' ?>"><span class="d-block text-sm"><?= $e($wd($d)) ?></span><?= $e($short($d)) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($people as $p): $eid = (int) $p['id']; ?>
        <tr>
          <th scope="row" class="shift-grid__who"><span class="mono"><?= $e((string) $p['employee_code']) ?></span> <span class="text-sm"><?= $e((string) $p['name_th']) ?></span></th>
          <?php foreach ($dates as $d): $c = $cellShift($eid, $d); ?>
            <td class="shift-grid__cell<?= isset($holidays[$d]) ? ' is-' . $e($holidays[$d]) : '' ?>">
              <?php if ($draft): $cur = $c === null ? '' : ($c['off'] ? 'OFF' : ($pidOf[(int) ($cells[$eid][$d] ?? 0)] ?? '')); ?>
                <label class="sr-only" for="c-<?= $eid ?>-<?= $e($d) ?>"><?= $e($p['employee_code'] . ' ' . $day($d)) ?></label>
                <select class="select select--sm shift-grid__select" id="c-<?= $eid ?>-<?= $e($d) ?>" name="cell[<?= $e((string) $p['public_id']) ?>][<?= $e($d) ?>]">
                  <?php foreach ($opts as $val => $lab): ?><option value="<?= $e((string) $val) ?>"<?= (string) $val === $cur ? ' selected' : '' ?>><?= $e($lab) ?></option><?php endforeach; ?>
                </select>
              <?php else: ?>
                <?= $c === null ? '<span class="text-muted">·</span>' : ($c['off'] ? '<span class="shift-chip shift-chip--off">' . $e($t('shifts.off_short')) . '</span>' : $shChip($c, false)) ?>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($draft): ?><div class="row"><?= $ui('button', ['label' => $t('shifts.grid_save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?></div></form><?php endif; ?>
  <?php endif; ?>
  <?php if ($patterns !== []): ?>
  <p class="text-sm text-muted"><?php foreach ($active as $pt): ?><?= $shChip(['off' => false, 'tone' => $pt['tone'], 'code' => $pt['code'], 'start' => $pt['start_time'], 'end' => $pt['end_time'], 'overnight' => (int) \Blue\Modules\Attendance\WorkSchedule::minutes((string) $pt['end_time']) <= (int) \Blue\Modules\Attendance\WorkSchedule::minutes((string) $pt['start_time'])]) ?> <?php endforeach; ?></p>
  <?php endif; ?>
</section>

<?php if ($draft): ?>
<div class="grid grid--2">
  <section class="card stack" aria-labelledby="fill-h">
    <h2 id="fill-h" class="card__title"><?= $e($t('shifts.fill.title')) ?></h2>
    <form method="post" action="<?= $e($url('/shifts/roster/' . $ro['public_id'] . '/fill')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('shifts.fill.people')) ?></legend>
        <?php if (isset($errors['employees'])): ?><p class="field__error"><?= $e($errors['employees']) ?></p><?php endif; ?>
        <div class="check-list">
        <?php foreach ($people as $p): ?><label class="check"><input type="checkbox" name="employees[]" value="<?= $e((string) $p['public_id']) ?>"> <?= $e($p['employee_code'] . ' ' . $p['name_th']) ?></label><?php endforeach; ?>
        </div>
      </fieldset>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'from', 'id' => 'f-from', 'type' => 'date', 'label' => $t('shifts.fill.from'), 'value' => (string) $ro['start_date'], 'error' => $errors['from'] ?? null]) ?>
        <?= $ui('field', ['name' => 'to', 'id' => 'f-to', 'type' => 'date', 'label' => $t('shifts.fill.to'), 'value' => (string) $ro['end_date'], 'error' => $errors['to'] ?? null]) ?>
      </div>
      <fieldset><legend class="field__label"><?= $e($t('shifts.fill.weekdays')) ?></legend>
        <?php if (isset($errors['weekdays'])): ?><p class="field__error"><?= $e($errors['weekdays']) ?></p><?php endif; ?>
        <div class="row row--tight">
        <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $w): ?><label class="check"><input type="checkbox" name="weekdays[]" value="<?= $w ?>"<?= $w >= 1 && $w <= 5 ? ' checked' : '' ?>> <?= $e($t('shifts.wd.' . $w)) ?></label><?php endforeach; ?>
        </div>
      </fieldset>
      <?= $ui('field', ['name' => 'pattern', 'id' => 'f-pattern', 'type' => 'select', 'label' => $t('shifts.fill.pattern'), 'options' => ['' => $t('shifts.fill.clear')] + $opts, 'value' => '', 'error' => $errors['pattern'] ?? null]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('shifts.fill.submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'checks']) ?></div>
    </form>
  </section>
  <section class="card stack" aria-labelledby="copy-h">
    <h2 id="copy-h" class="card__title"><?= $e($t('shifts.copy.title')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('shifts.copy.help')) ?></p>
    <form method="post" action="<?= $e($url('/shifts/roster/' . $ro['public_id'] . '/copy')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'source', 'id' => 'cp-src', 'type' => 'date', 'label' => $t('shifts.copy.source'), 'value' => (string) $ro['start_date'], 'error' => $errors['source'] ?? null]) ?>
        <?= $ui('field', ['name' => 'target', 'id' => 'cp-dst', 'type' => 'date', 'label' => $t('shifts.copy.target'), 'value' => $dates[7] ?? '', 'error' => $errors['target'] ?? null]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('shifts.copy.submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'copy']) ?></div>
    </form>
  </section>
</div>

<section class="card stack" aria-labelledby="pub-h">
  <h2 id="pub-h" class="card__title"><?= $e($t('shifts.publish.title')) ?></h2>
  <?php if ($check !== null && $check['errors'] !== []): ?>
    <?= $ui('alert', ['tone' => 'danger', 'title' => $t('shifts.publish.blocked'), 'text' => implode(' · ', array_map($msg, array_slice($check['errors'], 0, 8)))]) ?>
  <?php endif; ?>
  <?php if ($check !== null && $check['warnings'] !== []): ?>
    <?= $ui('alert', ['tone' => 'warning', 'title' => $t('shifts.publish.warnings', ['n' => (string) count($check['warnings'])]), 'text' => implode(' · ', array_map($msg, array_slice($check['warnings'], 0, 8)))]) ?>
  <?php endif; ?>
  <?php if ($check !== null && $check['rules'] === null): ?><p class="text-sm text-muted"><?= $e($t('shifts.publish.no_rules')) ?></p><?php endif; ?>
  <form method="post" action="<?= $e($url('/shifts/roster/' . $ro['public_id'] . '/publish')) ?>" class="stack-sm">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?php if ($check !== null && $check['warnings'] !== []): ?>
      <label class="check"><input type="checkbox" name="acknowledge" value="1"> <?= $e($t('shifts.publish.ack')) ?></label>
      <?php if (isset($errors['acknowledge'])): ?><p class="field__error"><?= $e($errors['acknowledge']) ?></p><?php endif; ?>
    <?php endif; ?>
    <p class="text-sm text-muted"><?= $e($t($ro['supersedes_pid'] !== null ? 'shifts.publish.help_revision' : 'shifts.publish.help')) ?></p>
    <div class="row">
      <?= $ui('button', ['label' => $t('shifts.publish.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'disabled' => $check !== null && $check['errors'] !== [], 'disabledReason' => $t('shifts.publish.blocked')]) ?>
    </div>
  </form>
  <form method="post" action="<?= $e($url('/shifts/roster/' . $ro['public_id'] . '/discard')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('button', ['label' => $t('shifts.discard'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?>
  </form>
</section>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="hist-h">
  <div class="row row--between">
    <h2 id="hist-h" class="card__title"><?= $e($t('shifts.history')) ?></h2>
    <?php if ($ro['status'] === 'published'): ?>
      <form method="post" action="<?= $e($url('/shifts/roster/' . $ro['public_id'] . '/revise')) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
        <?= $ui('button', ['label' => $t($ro['next_draft_pid'] !== null ? 'shifts.revise_open' : 'shifts.revise'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'pencil']) ?>
      </form>
    <?php endif; ?>
  </div>
  <p class="text-sm text-muted"><?= $e($t('shifts.published_by', ['who' => (string) ($ro['published_name'] ?? '—'), 'at' => substr((string) $ro['published_at'], 0, 16) . ' UTC'])) ?>
    <?php if ($ro['supersedes_pid'] !== null): ?> · <a href="<?= $e($url('/shifts/roster/' . $ro['supersedes_pid'])) ?>"><?= $e($t('shifts.previous_revision')) ?></a><?php endif; ?></p>
  <?php if ($changes === []): ?><p class="text-sm text-muted"><?= $e($t('shifts.no_changes')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="hist-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('shifts.field.employee')) ?></th><th scope="col"><?= $e($t('shifts.field.date')) ?></th><th scope="col"><?= $e($t('shifts.field.change')) ?></th><th scope="col"><?= $e($t('shifts.field.source')) ?></th><th scope="col"><?= $e($t('shifts.field.by')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($changes as $c): $f = (int) $c['from_set'] === 0 ? '·' : ($c['from_code'] ?? $t('shifts.off_short')); $to2 = (int) $c['to_set'] === 0 ? '·' : ($c['to_code'] ?? $t('shifts.off_short')); ?>
        <tr><td data-label="<?= $e($t('shifts.field.employee')) ?>" class="mono"><?= $e((string) $c['employee_code']) ?></td><td data-label="<?= $e($t('shifts.field.date')) ?>"><?= $e($day((string) $c['work_date'])) ?></td>
          <td data-label="<?= $e($t('shifts.field.change')) ?>"><?= $e($f . ' → ' . $to2) ?></td><td data-label="<?= $e($t('shifts.field.source')) ?>"><?= $e($t('shifts.source.' . $c['source'])) ?></td>
          <td data-label="<?= $e($t('shifts.field.by')) ?>"><?= $e((string) ($c['actor_name'] ?? '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
