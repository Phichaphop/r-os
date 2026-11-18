<?php
/**
 * My appraisals (own results once released) and the ones I evaluate or review, with deadlines.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{self: list<array<string, mixed>>, manager: list<array<string, mixed>>, reviewer: list<array<string, mixed>>} $groups @var string $today @var bool $canManage
 */
$due = static fn (array $x): ?string => match ((string) $x['status']) { 'self_review' => (string) $x['self_due'], 'manager_review' => (string) $x['manager_due'], 'final_review' => $x['final_due'] !== null ? (string) $x['final_due'] : null, default => null };
$tone = static fn (string $s): string => match ($s) { 'finalized', 'acknowledged' => 'success', 'final_review' => 'info', default => 'warning' };
?>
<?= $ui('page-header', ['title' => $t('appraisal.title'), 'subtitle' => $t('appraisal.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('appraisal.nav_admin'), 'href' => $url('/appraisal/admin'), 'variant' => 'secondary', 'icon' => 'list-check']) : '']) ?>
<?php foreach (['self' => 'appraisal.mine', 'manager' => 'appraisal.to_evaluate', 'reviewer' => 'appraisal.to_review'] as $g => $label): ?>
<?php if ($g !== 'self' && $groups[$g] === []) { continue; } ?>
<section class="card stack-sm" aria-labelledby="ai-<?= $e($g) ?>">
  <h2 id="ai-<?= $e($g) ?>" class="card__title"><?= $e($t($label)) ?></h2>
  <?php if ($groups[$g] === []): ?><p class="text-sm text-muted"><?= $e($t('appraisal.none_mine')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($groups[$g] as $x): $d = $due($x); $st = (string) $x['status'];
        $label2 = $g === 'self' && in_array($st, ['manager_review', 'final_review'], true) ? 'appraisal.status_self.waiting' : 'appraisal.status.' . $st; ?>
      <li><a href="<?= $e($url('/appraisal/a/' . $x['public_id'])) ?>"><?= $e($g === 'self' ? (string) $x['cycle_name'] : $x['employee_code'] . ' · ' . $x['name_th']) ?></a>
        <?= $ui('badge', ['tone' => $tone($st), 'label' => $t($label2)]) ?>
        <span class="d-block text-sm text-muted"><?= $g !== 'self' ? $e((string) $x['cycle_name']) . ' · ' : '' ?><?php if ($d !== null): ?><?= $e($t($d < $today ? 'appraisal.overdue_on' : 'appraisal.due_on', ['d' => $date($d, false)])) ?><?php elseif ($g === 'self' && in_array($st, ['finalized', 'acknowledged'], true)): ?><?= $e((string) $x['results_on'] > $today ? $t('appraisal.results_on', ['d' => $date((string) $x['results_on'], false)]) : $t('appraisal.results_ready')) ?><?php endif; ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endforeach; ?>
