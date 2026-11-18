<?php
/**
 * A correction request: what was asked, its decisions, and what the employee can still do.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $c @var list<array{0: string, 1: string}> $summary @var list<array<string, mixed>> $history @var bool $canEvidence
 */
include __DIR__ . '/_helpers.php';
$own = $c['access'] === 'own';
$st = (string) $c['status'];
$back = $own ? '/attendance/day/' . $c['work_date'] : '/attendance/e/' . $c['employee_pid'] . '/day/' . $c['work_date'];
?>
<?= $ui('page-header', ['title' => $t('attendance.correction_title'), 'subtitle' => (string) ($summary[0][1] ?? ''),
    'actions' => $ui('button', ['label' => $t('attendance.back_day'), 'href' => $url($back), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>

<section class="card stack-sm" aria-labelledby="cs-h">
  <h2 id="cs-h" class="card__title"><?= $e($t('attendance.details')) ?> <?= $corrStatus($st) ?></h2>
  <dl class="dl"><?php foreach ($summary as [$k, $val]): ?><dt><?= $e($k) ?></dt><dd><?= $e($val) ?></dd><?php endforeach; ?></dl>
  <div class="row">
    <?php if ($canEvidence): ?><?= $ui('button', ['label' => $t('attendance.download_evidence'), 'href' => $url('/attendance/c/' . $c['public_id'] . '/evidence'), 'variant' => 'secondary', 'icon' => 'download']) ?><?php endif; ?>
    <?php if ($own && $st === 'returned'): ?><?= $ui('button', ['label' => $t('attendance.edit_resend'), 'href' => $url('/attendance/c/' . $c['public_id'] . '/edit#ask'), 'variant' => 'primary', 'icon' => 'pencil']) ?><?php endif; ?>
    <?php if (!$own && $st === 'pending' && $c['instance_pid'] !== null): ?><?= $ui('button', ['label' => $t('attendance.open_approval'), 'href' => $url('/approvals/' . $c['instance_pid']), 'variant' => 'primary', 'icon' => 'checks']) ?><?php endif; ?>
  </div>
</section>

<?php if ($history !== []): ?>
<section class="card stack-sm" aria-labelledby="ch-h">
  <h2 id="ch-h" class="card__title"><?= $e($t('attendance.history')) ?></h2>
  <ol class="timeline">
    <?php foreach ($history as $h): ?>
      <li class="timeline__item"><span class="timeline__dot" aria-hidden="true"><?= $ui('icon', ['name' => match ((string) $h['decision']) { 'approve' => 'circle-check', 'reject' => 'circle-x', 'return' => 'arrow-back-up', 'cancel' => 'x', default => 'send' }, 'size' => 'sm']) ?></span>
        <div><strong><?= $e($t('workflow.decision.' . $h['decision'])) ?></strong> · <?= $e((string) ($h['display_name'] ?? '')) ?> · <span class="text-muted"><?= $e($date((string) $h['decided_at'])) ?></span>
          <?php if (($h['comment'] ?? '') !== ''): ?><div class="text-sm">“<?= $e((string) $h['comment']) ?>”</div><?php endif; ?></div></li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<?php if ($own && in_array($st, ['pending', 'returned'], true)): ?>
<section class="card stack-sm" id="withdraw" aria-labelledby="cw-h">
  <h2 id="cw-h" class="card__title"><?= $e($t('attendance.withdraw')) ?></h2>
  <form method="post" action="<?= $e($url('/attendance/c/' . $c['public_id'] . '/withdraw')) ?>" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'reason', 'label' => $t('attendance.field.withdraw_reason'), 'required' => true]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('attendance.withdraw'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?></div>
  </form>
</section>
<?php endif; ?>
