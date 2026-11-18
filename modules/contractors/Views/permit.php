<?php
/**
 * One work permit: facts, what blocks it (documents, suspension, overlaps), the next step, workers in/out at the gate,
 * completion (area checked, equipment back, rating) and the history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $pm @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 * @var string $here @var bool $canView @var bool $canManage @var bool $canGate
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$p = $pm;
$pid = (string) $p['public_id'];
$pst = (string) $p['status'];
$dt = static fn (?string $x): string => $x === null ? '—' : $date(substr($x, 0, 10), false) . ' ' . substr($x, 11, 5);
$hazards = $p['hazards'] === null ? [] : explode(',', (string) $p['hazards']);
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
?>
<?= $ui('page-header', ['title' => $t('contractors.permit_title', ['ref' => (string) ($p['ref'] ?? $t('contractors.draft'))]), 'subtitle' => $p['company_name'] . ' · ' . $p['site_code'] . ' ' . $p['area'],
    'crumbs' => [[$t('contractors.permits_title'), $url('/contractors/permits')], [(string) ($p['ref'] ?? $t('contractors.draft')), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($p['problems'] !== []): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('contractors.problems_title'), 'html' => '<ul>' . implode('', array_map(static fn (string $x): string => '<li>' . $e($x) . '</li>', $p['problems'])) . '</ul>']) ?>
<?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="pm-f">
    <h2 id="pm-f" class="card__title"><?= $e($t('contractors.col.status')) ?>: <?php include __DIR__ . '/_pstatus.php'; ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('contractors.field.company')) ?></dt><dd><a href="<?= $e($url('/contractors/c/' . $p['company_pid'])) ?>"><?= $e((string) $p['company_name']) ?></a></dd></div>
      <div><dt><?= $e($t('contractors.field.work')) ?></dt><dd><?= $e((string) $p['work']) ?></dd></div>
      <div><dt><?= $e($t('contractors.col.when')) ?></dt><dd><?= $e($dt((string) $p['starts_at']) . ' – ' . $dt((string) $p['ends_at'])) ?></dd></div>
      <div><dt><?= $e($t('contractors.field.tools')) ?></dt><dd><?= $e((string) ($p['tools'] ?? '—')) ?></dd></div>
      <div><dt><?= $e($t('contractors.field.hazards')) ?></dt><dd><?= $hazards === [] ? '—' : implode(' ', array_map(static fn (string $h): string => $ui('badge', ['tone' => 'warning', 'label' => $t('contractors.hazard.' . $h)]), $hazards)) ?></dd></div>
      <div><dt><?= $e($t('contractors.field.controls')) ?></dt><dd><?= $e((string) ($p['controls'] ?? '—')) ?></dd></div>
      <div><dt><?= $e($t('contractors.field.supervisor')) ?></dt><dd><?= $e((string) $p['supervisor_name']) ?></dd></div>
      <div><dt><?= $e($t('contractors.col.requester')) ?></dt><dd><?= $e((string) $p['requester_name']) ?></dd></div>
      <?php if ($p['rating'] !== null): ?><div><dt><?= $e($t('contractors.field.rating')) ?></dt><dd><?= $e($t('contractors.rating_n', ['n' => (string) $p['rating']]) . ($p['review_note'] !== null ? ' — ' . $p['review_note'] : '')) ?></dd></div><?php endif; ?>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="pm-n">
    <h2 id="pm-n" class="card__title"><?= $e($t('contractors.next_step')) ?></h2>
    <?php if ($pst === 'draft' && $p['can_manage']): ?>
      <p class="row"><?= $ui('button', ['label' => $t('contractors.edit_permit'), 'href' => $url('/contractors/p/' . $pid . '/edit'), 'variant' => 'secondary', 'icon' => 'pencil']) ?></p>
      <form method="post" action="<?= $e($url('/contractors/p/' . $pid . '/submit')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('contractors.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'disabled' => $p['problems'] !== []]) ?></form>
    <?php elseif ($pst === 'review'): ?>
      <p class="text-sm"><?= $e($t('contractors.review_note')) ?></p>
    <?php elseif ($pst === 'approved'): ?>
      <p class="text-sm"><?= $e($t('contractors.approved_note')) ?></p>
    <?php elseif ($pst === 'on_site' && ($p['is_supervisor'] || $p['can_manage'])): ?>
      <form method="post" action="<?= $e($url('/contractors/p/' . $pid . '/complete')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <label class="check"><input type="checkbox" name="area_checked" value="1"> <?= $e($t('contractors.field.area_checked')) ?></label>
        <?php if ($fe('complete', 'area_checked') !== null): ?><div class="field__error"><?= $e((string) $fe('complete', 'area_checked')) ?></div><?php endif; ?>
        <label class="check"><input type="checkbox" name="equipment_returned" value="1"> <?= $e($t('contractors.field.equipment_returned')) ?></label>
        <?php if ($fe('complete', 'equipment_returned') !== null): ?><div class="field__error"><?= $e((string) $fe('complete', 'equipment_returned')) ?></div><?php endif; ?>
        <?= $ui('field', ['name' => 'rating', 'type' => 'select', 'label' => $t('contractors.field.rating'), 'required' => true, 'value' => (string) ($old['rating'] ?? ''), 'options' => ['' => $t('contractors.pick'), '5' => '5', '4' => '4', '3' => '3', '2' => '2', '1' => '1'], 'error' => $fe('complete', 'rating')]) ?>
        <?= $ui('field', ['name' => 'review_note', 'label' => $t('contractors.field.review_note'), 'value' => (string) ($old['review_note'] ?? ''), 'help' => $t('contractors.review_note_help'), 'error' => $fe('complete', 'review_note')]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('contractors.complete'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
      </form>
    <?php else: ?>
      <p class="text-sm text-muted"><?= $e($t('contractors.no_next')) ?></p>
    <?php endif; ?>
    <?php if (in_array($pst, ['draft', 'review', 'approved'], true) && $p['can_manage']): ?>
      <details<?= $form === 'cancel' ? ' open' : '' ?>><summary><?= $e($t('contractors.cancel')) ?></summary>
        <form method="post" action="<?= $e($url('/contractors/p/' . $pid . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $ui('field', ['name' => 'cancel_reason', 'label' => $t('contractors.field.reason')]) ?>
          <div class="row"><?= $ui('button', ['label' => $t('contractors.cancel'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
        </form>
      </details>
    <?php endif; ?>
  </section>
</div>
<section class="card card--flush" aria-labelledby="pm-w">
  <div class="card__head"><h2 id="pm-w" class="card__title"><?= $e($t('contractors.field.workers')) ?></h2></div>
  <ul class="plain-list card__pad stack-sm">
  <?php foreach ($p['workers'] as $w): ?>
    <li class="row"><span><?= $e((string) $w['name']) ?></span> <?= $w['inside'] !== null ? $ui('badge', ['tone' => 'warning', 'label' => $t('contractors.inside')]) : '' ?>
      <?php if ($p['can_gate'] && in_array($pst, ['approved', 'on_site'], true)): ?>
      <form method="post" action="<?= $e($url('/contractors/p/' . $pid . '/gate')) ?>"><?= $csrf ?><input type="hidden" name="worker" value="<?= $e((string) $w['public_id']) ?>"><input type="hidden" name="dir" value="<?= $w['inside'] !== null ? 'out' : 'in' ?>">
        <?= $ui('button', ['label' => $w['inside'] !== null ? $t('contractors.check_out') : $t('contractors.check_in'), 'type' => 'submit', 'variant' => $w['inside'] !== null ? 'secondary' : 'primary', 'size' => 'sm', 'attrs' => ['aria-label' => ($w['inside'] !== null ? $t('contractors.check_out') : $t('contractors.check_in')) . ' ' . $w['name']]]) ?></form>
      <?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php if ($p['checkins'] !== []): ?>
<section class="card stack-sm" aria-labelledby="pm-c">
  <h2 id="pm-c" class="card__title"><?= $e($t('contractors.checkins')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($p['checkins'] as $k): ?><li><?= $e($k['worker_name'] . ' · ' . $t('contractors.in_at', ['at' => $dt((string) $k['in_at'])]) . ($k['out_at'] !== null ? ' · ' . $t('contractors.out_at', ['at' => $dt((string) $k['out_at'])]) : '')) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="pm-h">
  <h2 id="pm-h" class="card__title"><?= $e($t('contractors.history')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($p['events'] as $ev): ?><li><?= $e($t('contractors.event.' . $ev['event'])) ?> · <?= $e((string) ($ev['actor'] ?? $t('contractors.system'))) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
