<?php
/**
 * A session: course details, place, seats — enrol / my status.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $s @var array<string, mixed>|null $mineEnr @var bool $canManage @var string $today
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$free = (int) $s['seats'] - (int) $s['taken'];
$x = $s;
$open = $s['status'] === 'open' && ($s['enroll_until'] === null || $today <= $s['enroll_until']) && $today <= $s['start_date'];
?>
<?= $ui('page-header', ['title' => (string) $s['title'], 'subtitle' => $s['code'] . ' · ' . $t('training.session_status.' . $s['status']),
    'crumbs' => [[$t('training.title'), $url('/training')], [(string) $s['code'], null]],
    'actions' => $canManage ? $ui('button', ['label' => $t('training.manage'), 'href' => $url('/training/admin/s/' . $s['public_id']), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="ts-d">
  <h2 id="ts-d" class="card__title"><?= $e($t('training.details')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('training.col.when')) ?></dt><dd><?php include __DIR__ . '/_when.php'; ?></dd></div>
    <div><dt><?= $e($t('training.field.location')) ?></dt><dd><?= $e((string) $s['location']) ?></dd></div>
    <div><dt><?= $e($t('training.field.trainer')) ?></dt><dd><?= $e((string) ($s['trainer'] ?? '—')) ?></dd></div>
    <div><dt><?= $e($t('training.field.hours')) ?></dt><dd><?= $e(rtrim(rtrim((string) $s['hours'], '0'), '.')) ?></dd></div>
    <div><dt><?= $e($t('training.field.pass_score')) ?></dt><dd><?= $e($s['pass_score'] !== null ? (string) $s['pass_score'] : $t('training.attendance_only')) ?></dd></div>
    <div><dt><?= $e($t('training.col.seats')) ?></dt><dd><?= $e($t('training.seats_of', ['taken' => (string) $s['taken'], 'seats' => (string) $s['seats']])) ?><?= (int) $s['waiting'] > 0 ? ' · ' . $e($t('training.waiting_n', ['n' => (string) $s['waiting']])) : '' ?></dd></div>
    <?php if ($s['enroll_until'] !== null): ?><div><dt><?= $e($t('training.field.enroll_until')) ?></dt><dd><?= $e($date((string) $s['enroll_until'], false)) ?></dd></div><?php endif; ?>
  </dl>
  <?php if ((string) ($s['description'] ?? '') !== ''): ?><p class="pre-line"><?= $e((string) $s['description']) ?></p><?php endif; ?>
  <?php if ((string) ($s['objectives'] ?? '') !== ''): ?><h3 class="text-sm"><?= $e($t('training.field.objectives')) ?></h3><p class="pre-line"><?= $e((string) $s['objectives']) ?></p><?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="ts-e">
  <h2 id="ts-e" class="card__title"><?= $e($t('training.my_place')) ?></h2>
  <?php if ($mineEnr !== null && !in_array($mineEnr['status'], ['cancelled', 'rejected'], true)): $st = (string) $mineEnr['status']; ?>
    <p><?php include __DIR__ . '/_enr.php'; ?></p>
    <p class="text-sm text-muted"><?= $e($t('training.enr_help.' . $st)) ?></p>
    <?php if (in_array($st, ['pending', 'enrolled', 'waitlisted'], true) && $today < $s['start_date']): ?>
      <form method="post" action="<?= $e($url('/training/e/' . $mineEnr['public_id'] . '/cancel')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('training.cancel_mine'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'x']) ?></form>
    <?php endif; ?>
  <?php elseif ($open): ?>
    <p class="text-sm"><?= $e($free > 0 ? $t('training.seats_left', ['n' => (string) $free]) : $t('training.full_waitlist')) ?></p>
    <?php if ((int) $s['needs_approval'] === 1): ?><p class="text-sm text-muted"><?= $e($t('training.approval_help')) ?></p><?php endif; ?>
    <form method="post" action="<?= $e($url('/training/s/' . $s['public_id'] . '/enroll')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('training.enroll'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></form>
  <?php else: ?>
    <p class="text-sm text-muted"><?= $e($t('training.not_open')) ?></p>
  <?php endif; ?>
</section>
</div>
