<?php
/**
 * One application: details, files, stage, interviews + evaluator links, offer, hire-readiness and hire, history.
 * The manager who asked sees the application without contact details or the ID (read only).
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $c @var list<array<string, mixed>> $events @var list<array<string, mixed>> $files @var list<array<string, mixed>> $interviews
 * @var list<array<string, mixed>> $offers @var array<string, bool> $ready @var list<string> $required @var array<string, string> $managers @var string|null $evalLink
 * @var bool $talent @var bool $canErase @var string $today @var array<string, string> $errors @var string $form @var string|null $empPid @var string $tz
 */
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Recruit\Lists;

$loc = (string) ($v['locale'] ?? 'th');
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/recruit/c/' . $c['public_id'];
$pr = (array) $c['profile'];
$sum = $pr;
$rec = (bool) $c['recruiter'];
$live = $c['anonymized_at'] === null;
$stage = (string) $c['stage'];
$money = static fn (mixed $x): string => $x !== null && $x !== '' ? number_format((float) $x, 2) : '—';
$byGroup = [];
foreach ($files as $f) {
    $byGroup[(string) $f['group_code']] = $f;
}
$openOffer = null;
foreach ($offers as $of) {
    if (in_array($of['status'], ['pending', 'approved', 'returned', 'accepted'], true)) {
        $openOffer ??= $of;
    }
}
$utc = new DateTimeZone('UTC');
$tz = new DateTimeZone($tz);
$when = static function (string $s) use ($utc, $tz, $loc): string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $s, $utc);
    return $d === false ? $s : DateFormat::day($d->setTimezone($tz)->format('Y-m-d'), $loc) . ' ' . $d->setTimezone($tz)->format('H:i');
};
?>
<?= $ui('page-header', ['title' => (string) $c['name'], 'subtitle' => (string) $c['posting_title'] . ' · ' . $t('recruit.stage.' . $stage),
    'crumbs' => [[$t('recruit.req_title'), $url('/recruit/requisitions')], [(string) $c['position_title'], $url('/recruit/r/' . $c['req_pid'])], [(string) $c['posting_title'], $url('/recruit/p/' . $c['posting_pid'])], [(string) $c['name'], null]]]) ?>
<?php if ($evalLink !== null): ?>
  <section class="card stack-sm" aria-labelledby="el-h"><h2 id="el-h" class="card__title"><?= $ui('icon', ['name' => 'send', 'size' => 'sm']) ?> <?= $e($t('recruit.eval_link_title')) ?></h2>
    <p class="eval-link mono"><?= $e($evalLink) ?></p>
    <p class="text-sm text-muted"><?= $e($t('recruit.eval_link_help')) ?></p></section>
<?php endif; ?>
<?php if (!$live): ?><?= $ui('alert', ['tone' => 'neutral', 'text' => $t('recruit.anonymized')]) ?><?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => implode(' · ', array_values($errors))]) ?><?php endif; ?>
<div class="settings-grid">
  <div class="stack">
    <section class="card stack-sm" aria-labelledby="cd-h">
      <h2 id="cd-h" class="card__title"><?= $e($t('recruit.application')) ?></h2>
      <dl class="facts">
        <?php if ($rec): ?>
        <div><dt><?= $e($t('recruit.field.app_code')) ?></dt><dd class="mono"><?= $e((string) $c['app_code']) ?></dd></div>
        <div><dt><?= $e($t('recruit.field.phone')) ?></dt><dd><?= $e((string) $c['phone']) ?></dd></div>
        <div><dt><?= $e($t('recruit.field.email')) ?></dt><dd><?= $e((string) ($c['email'] ?? '—')) ?></dd></div>
        <div><dt><?= $e($t('recruit.field.id_number')) ?></dt><dd><?= $e($c['id_last4'] !== null ? $t('employees.id_type.' . $c['id_type']) . ' ••••' . $c['id_last4'] : '—') ?></dd></div>
        <?php endif; ?>
        <div><dt><?= $e($t('recruit.field.expected_salary')) ?></dt><dd><?= $e($money($c['expected_salary'])) ?></dd></div>
        <div><dt><?= $e($t('recruit.field.available_from')) ?></dt><dd><?= $e($c['available_from'] !== null ? DateFormat::day((string) $c['available_from'], $loc) : '—') ?></dd></div>
        <?php if ($rec): ?>
        <div><dt><?= $e($t('recruit.field.consent')) ?></dt><dd><?= $e($c['consent_at'] !== null ? $date((string) $c['consent_at']) . ' · ' . $t('recruit.notice_version', ['n' => (string) $c['notice_version']]) . ' · ' . $c['consent_name'] : $t('recruit.source.' . $c['source'])) ?></dd></div>
        <div><dt><?= $e($t('recruit.field.talent_consent')) ?></dt><dd><?= $e($t((int) $c['talent_consent'] === 1 ? 'recruit.yes' : 'recruit.no')) ?></dd></div>
        <div><dt><?= $e($t('recruit.step.personal')) ?></dt><dd><?= $e(implode(' · ', array_filter([(string) ($pr['nickname'] ?? ''), ($pr['birth_date'] ?? '') !== '' ? DateFormat::day((string) $pr['birth_date'], $loc) : '', ($pr['gender'] ?? '') !== '' ? $t('recruit.gender.' . $pr['gender']) : '', (string) ($pr['nationality'] ?? '')]))) ?: '—' ?></dd></div>
        <div><dt><?= $e($t('recruit.step.address')) ?></dt><dd><?= $e(trim((string) ($pr['address'] ?? '') . ' ' . ($pr['province'] ?? '') . ' ' . ($pr['postcode'] ?? ''))) ?: '—' ?></dd></div>
        <div><dt><?= $e($t('recruit.step.status')) ?></dt><dd><?= $e(implode(' · ', array_filter([($pr['marital'] ?? '') !== '' ? $t('recruit.marital.' . $pr['marital']) : '', ($pr['military'] ?? '') !== '' ? $t('recruit.military.' . $pr['military']) : '']))) ?: '—' ?></dd></div>
        <div><dt><?= $e($t('recruit.emergency')) ?></dt><dd><?= $e(trim((string) ($pr['emergency_name'] ?? '') . ' ' . ($pr['emergency_phone'] ?? '') . ' ' . ($pr['emergency_relation'] ?? ''))) ?: '—' ?></dd></div>
        <?php endif; ?>
      </dl>
      <?php include __DIR__ . '/_summary.php'; ?>
    </section>
    <?php if ($rec): ?>
    <section class="card stack-sm" aria-labelledby="cf-h">
      <h2 id="cf-h" class="card__title"><?= $e($t('recruit.files_title')) ?></h2>
      <ul class="plain-list">
        <?php foreach (array_keys(Lists::DOC_GROUPS) as $g): $f = $byGroup[$g] ?? null; ?>
          <li class="version-row"><span><strong><?= $e($t('recruit.group.' . $g)) ?></strong><?= in_array($g, $required, true) ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('recruit.required')]) : '' ?>
            <?php if ($f !== null): ?><span class="d-block text-sm"><a href="<?= $e($url($base . '/file/' . $f['id'])) ?>"><?= $e((string) $f['filename']) ?></a> · <?= $e($f['verified_at'] !== null ? $t('recruit.verified') : $t('recruit.not_verified')) ?></span><?php else: ?><span class="d-block text-sm text-muted"><?= $e($t('recruit.no_file')) ?></span><?php endif; ?></span>
            <?php if ($f !== null && $f['verified_at'] === null && $live): ?><form method="post" action="<?= $e($url($base . '/file/' . $f['id'] . '/verify')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('recruit.verify'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'circle-check']) ?></form><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($live): ?>
      <form method="post" action="<?= $e($url($base . '/file')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate>
        <?= $csrf ?>
        <div class="grid grid--2">
          <?= $ui('field', ['name' => 'group', 'id' => 'cf-group', 'type' => 'select', 'label' => $t('recruit.field.group'), 'options' => array_combine(array_keys(Lists::DOC_GROUPS), array_map(static fn (string $g): string => $t('recruit.group.' . $g), array_keys(Lists::DOC_GROUPS)))]) ?>
          <?= $ui('field', ['name' => 'file', 'id' => 'cf-file', 'type' => 'file', 'label' => $t('recruit.field.file'), 'accept' => '.pdf,.jpg,.jpeg,.png', 'error' => $errors['file'] ?? null]) ?>
        </div>
        <div class="row"><?= $ui('button', ['label' => $t('recruit.upload'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?></div>
        <p class="text-sm text-muted"><?= $e($t('recruit.verify_help')) ?></p>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="card stack-sm" aria-labelledby="ch-h">
      <details class="draw-more"><summary><h2 id="ch-h" class="card__title d-inline"><?= $e($t('recruit.history')) ?> (<?= count($events) ?>)</h2></summary>
        <ol class="plain-list">
          <?php foreach ($events as $ev): ?>
            <li><strong><?= $e($t('recruit.event.' . $ev['action'])) ?></strong><?= $ev['to_stage'] !== null ? ' · ' . $e($t('recruit.stage.' . $ev['to_stage'])) : '' ?><?= $ev['note'] !== null ? ' · ' . $e((string) $ev['note']) : '' ?>
              <span class="d-block text-sm text-muted"><?= $e((string) ($ev['actor'] ?? $t('recruit.by_applicant'))) ?> · <?= $e($date((string) $ev['occurred_at'])) ?></span></li>
          <?php endforeach; ?>
        </ol></details>
    </section>
  </div>
  <div class="stack">
    <?php if ($rec && $live): ?>
    <section class="card stack-sm" aria-labelledby="cs-h">
      <h2 id="cs-h" class="card__title"><?= $e($t('recruit.col.stage')) ?>: <?= $e($t('recruit.stage.' . $stage)) ?></h2>
      <?php if ((Lists::MOVES[$stage] ?? []) !== []): ?>
      <form method="post" action="<?= $e($url($base . '/move')) ?>" class="stack-sm" novalidate>
        <?= $csrf ?>
        <?= $ui('field', ['name' => 'to', 'id' => 'cs-to', 'type' => 'select', 'label' => $t('recruit.move_to'), 'options' => array_combine(Lists::MOVES[$stage], array_map(static fn (string $s): string => $t('recruit.stage.' . $s), Lists::MOVES[$stage]))]) ?>
        <?= $ui('field', ['name' => 'note', 'id' => 'cs-note', 'label' => $t('recruit.field.reason_note'), 'help' => $t('recruit.reason_help'), 'error' => $form === 'move' ? ($errors['note'] ?? null) : null]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('recruit.move'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'arrow-right']) ?></div>
      </form>
      <?php endif; ?>
      <form method="post" action="<?= $e($url($base . '/note')) ?>" class="row" novalidate>
        <?= $csrf ?>
        <?= $ui('field', ['name' => 'note', 'id' => 'cn-note', 'label' => $t('recruit.field.note'), 'error' => $form === 'note' ? ($errors['note'] ?? null) : null]) ?>
        <?= $ui('button', ['label' => $t('recruit.add_note'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'pencil']) ?>
      </form>
    </section>
    <?php endif; ?>
    <section class="card stack-sm" aria-labelledby="ci-h">
      <h2 id="ci-h" class="card__title"><?= $e($t('recruit.interviews')) ?></h2>
      <?php if ($interviews === []): ?><p class="text-sm text-muted"><?= $e($t('recruit.no_interviews')) ?></p><?php endif; ?>
      <?php foreach ($interviews as $i): ?>
        <div class="version-row"><div><strong><?= $e($t('recruit.kind.' . $i['kind'])) ?></strong> · <?= $e($when((string) $i['scheduled_at'])) ?><?= $i['location'] !== null ? ' · ' . $e((string) $i['location']) : '' ?>
          <ul class="plain-list text-sm">
            <?php foreach ($i['evaluations'] as $ev): ?>
              <li><?= $e((string) $ev['evaluator_name']) ?> — <?= $ev['submitted_at'] !== null ? $e((string) $ev['score'] . '/5 · ' . $t('recruit.rec.' . $ev['recommendation'])) . ($rec && $ev['comment'] !== null ? '<span class="d-block text-muted">' . $e((string) $ev['comment']) . '</span>' : '') : $e($t('recruit.waiting_eval')) ?></li>
            <?php endforeach; ?>
          </ul></div>
          <?php if ($rec && $live): ?>
          <form method="post" action="<?= $e($url($base . '/i/' . $i['public_id'] . '/evaluator')) ?>" class="row" novalidate>
            <?= $csrf ?>
            <?= $ui('field', ['name' => 'evaluator', 'id' => 'ev-' . $i['public_id'], 'label' => $t('recruit.field.evaluator'), 'error' => $form === 'evaluator' ? ($errors['evaluator'] ?? null) : null]) ?>
            <?= $ui('button', ['label' => $t('recruit.add_evaluator'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'send']) ?>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($rec && $live && in_array($stage, ['screening', 'interview'], true)): ?>
      <details class="draw-more"<?= $form === 'schedule' ? ' open' : '' ?>><summary><?= $e($t('recruit.schedule')) ?></summary>
        <form method="post" action="<?= $e($url($base . '/schedule')) ?>" class="stack-sm" novalidate>
          <?= $csrf ?>
          <div class="grid grid--2">
            <?= $ui('field', ['name' => 'kind', 'id' => 'sc-kind', 'type' => 'select', 'label' => $t('recruit.field.kind'), 'options' => ['interview' => $t('recruit.kind.interview'), 'practical' => $t('recruit.kind.practical'), 'external' => $t('recruit.kind.external')]]) ?>
            <?= $ui('field', ['name' => 'location', 'id' => 'sc-loc', 'label' => $t('recruit.field.location')]) ?>
            <?= $ui('field', ['name' => 'date', 'id' => 'sc-date', 'type' => 'date', 'label' => $t('recruit.field.date'), 'required' => true, 'value' => $today, 'error' => $form === 'schedule' ? ($errors['when'] ?? null) : null]) ?>
            <?= $ui('field', ['name' => 'time', 'id' => 'sc-time', 'type' => 'time', 'label' => $t('recruit.field.time'), 'required' => true, 'value' => '10:00']) ?>
          </div>
          <div class="row"><?= $ui('button', ['label' => $t('recruit.schedule'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'calendar-event']) ?></div>
        </form></details>
      <?php endif; ?>
    </section>
    <?php if ($rec): ?>
    <section class="card stack-sm" aria-labelledby="co-h">
      <h2 id="co-h" class="card__title"><?= $e($t('recruit.offer')) ?></h2>
      <?php foreach ($offers as $of): ?>
        <p class="text-sm"><?= $ui('badge', ['tone' => in_array($of['status'], ['approved', 'accepted'], true) ? 'success' : (in_array($of['status'], ['rejected', 'declined', 'cancelled'], true) ? 'neutral' : 'info'), 'label' => $t('recruit.offer_status.' . $of['status'])]) ?>
          <?= $e((string) $of['position_title'] . ' · ' . $money($of['salary']) . ' · ' . DateFormat::day((string) $of['start_date'], $loc)) ?></p>
      <?php endforeach; ?>
      <?php if ($live && $openOffer === null && in_array($stage, ['interview', 'offer'], true)): ?>
      <details class="draw-more"<?= $form === 'offer' ? ' open' : '' ?>><summary><?= $e($t('recruit.offer_new')) ?></summary>
        <form method="post" action="<?= $e($url($base . '/offer')) ?>" class="stack-sm" novalidate>
          <?= $csrf ?>
          <div class="grid grid--2">
            <?= $ui('field', ['name' => 'position_title', 'id' => 'of-title', 'label' => $t('recruit.field.position_title'), 'required' => true, 'value' => (string) $c['position_title'], 'error' => $form === 'offer' ? ($errors['position_title'] ?? null) : null]) ?>
            <?= $ui('field', ['name' => 'employment_type', 'id' => 'of-type', 'type' => 'select', 'label' => $t('recruit.field.employment_type'), 'value' => (string) $c['req_type'],
                'options' => array_combine(EmployeeService::TYPES, array_map(static fn (string $x): string => $t('employees.type.' . $x), EmployeeService::TYPES))]) ?>
            <?= $ui('field', ['name' => 'salary', 'id' => 'of-salary', 'type' => 'money', 'label' => $t('recruit.field.salary'), 'required' => true, 'error' => $form === 'offer' ? ($errors['salary'] ?? null) : null]) ?>
            <?= $ui('field', ['name' => 'start_date', 'id' => 'of-start', 'type' => 'date', 'label' => $t('recruit.field.start_date'), 'required' => true, 'value' => (string) ($c['available_from'] ?? ''), 'error' => $form === 'offer' ? ($errors['start_date'] ?? null) : null]) ?>
          </div>
          <?= $ui('field', ['name' => 'note', 'id' => 'of-note', 'label' => $t('recruit.field.note')]) ?>
          <p class="text-sm text-muted"><?= $e($t('recruit.offer_help')) ?></p>
          <div class="row"><?= $ui('button', ['label' => $t('recruit.offer_submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'send']) ?></div>
        </form></details>
      <?php elseif ($live && $openOffer !== null && $openOffer['status'] === 'approved'): ?>
        <form method="post" action="<?= $e($url($base . '/answer')) ?>" class="stack-sm" novalidate>
          <?= $csrf ?>
          <?= $ui('field', ['name' => 'answer', 'id' => 'oa-answer', 'type' => 'select', 'label' => $t('recruit.offer_answer'), 'options' => ['accepted' => $t('recruit.offer_status.accepted'), 'declined' => $t('recruit.offer_status.declined')]]) ?>
          <?= $ui('field', ['name' => 'note', 'id' => 'oa-note', 'label' => $t('recruit.field.note')]) ?>
          <div class="row"><?= $ui('button', ['label' => $t('recruit.save_answer'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check']) ?></div>
        </form>
      <?php endif; ?>
    </section>
    <section class="card stack-sm<?= !in_array(false, $ready, true) && $stage === 'offer' ? ' draw-start is-ready' : '' ?>" aria-labelledby="cr-h">
      <h2 id="cr-h" class="card__title"><?= $e($t('recruit.readiness')) ?></h2>
      <ul class="check-list">
        <?php foreach ($ready as $k => $ok): ?><li class="<?= $ok ? 'is-ok' : 'is-missing' ?>"><?= $ui('icon', ['name' => $ok ? 'circle-check' : 'circle-x', 'size' => 'sm']) ?> <span><?= $e($t('recruit.ready.' . $k)) ?></span></li><?php endforeach; ?>
      </ul>
      <?php if ($c['employee_id'] !== null): ?>
        <p><?= $ui('badge', ['tone' => 'success', 'label' => $t('recruit.stage.hired')]) ?> <?php if ($empPid !== null): ?><a href="<?= $e($url('/employees/' . $empPid)) ?>"><?= $e($t('recruit.open_employee')) ?></a><?php endif; ?></p>
      <?php elseif ($live && $stage === 'offer' && !in_array(false, $ready, true)): ?>
      <form method="post" action="<?= $e($url($base . '/hire')) ?>" class="stack-sm" novalidate>
        <?= $csrf ?>
        <div class="grid grid--2">
          <?= $ui('field', ['name' => 'employee_code', 'id' => 'hi-code', 'label' => $t('recruit.field.employee_code'), 'required' => true, 'error' => $form === 'hire' ? ($errors['employee_code'] ?? null) : null]) ?>
          <?= $ui('field', ['name' => 'employee_group', 'id' => 'hi-group', 'label' => $t('recruit.field.employee_group'), 'required' => true, 'value' => 'STAFF', 'error' => $form === 'hire' ? ($errors['employee_group'] ?? null) : null]) ?>
        </div>
        <?= $ui('field', ['name' => 'manager', 'id' => 'hi-mgr', 'type' => 'select', 'label' => $t('recruit.field.manager'), 'options' => ['' => '—'] + $managers]) ?>
        <p class="text-sm text-muted"><?= $e($t('recruit.hire_help')) ?></p>
        <div class="row"><?= $ui('button', ['label' => $t('recruit.hire'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'user']) ?></div>
      </form>
      <?php endif; ?>
    </section>
    <?php if ($talent && $live && (int) $c['talent_consent'] === 1): ?>
    <section class="card stack-sm" aria-labelledby="ct-h">
      <h2 id="ct-h" class="card__title"><?= $e($t('recruit.to_talent')) ?></h2>
      <p class="text-sm text-muted"><?= $e($t('recruit.to_talent_help')) ?></p>
      <form method="post" action="<?= $e($url('/talent/from/' . $c['public_id'])) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('recruit.to_talent'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'users']) ?></form>
    </section>
    <?php endif; ?>
    <?php if ($canErase && $live && $stage !== 'hired'): ?>
    <section class="card stack-sm" aria-labelledby="ce-h">
      <details class="draw-more"<?= $form === 'erase' ? ' open' : '' ?>><summary><h2 id="ce-h" class="card__title d-inline"><?= $e($t('recruit.erase_title')) ?></h2></summary>
        <p class="text-sm text-muted"><?= $e($t('recruit.erase_help')) ?></p>
        <form method="post" action="<?= $e($url($base . '/erase')) ?>" class="stack-sm" novalidate>
          <?= $csrf ?>
          <?= $ui('field', ['name' => 'reason', 'id' => 'er-reason', 'label' => $t('recruit.field.reason_note'), 'required' => true, 'error' => $form === 'erase' ? ($errors['reason'] ?? null) : null]) ?>
          <div class="row"><?= $ui('button', ['label' => $t('recruit.erase'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'trash']) ?></div>
        </form></details>
    </section>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
