<?php
/**
 * HR: one employee's training history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $emp @var list<array<string, mixed>> $rows
 */
$total = 0.0;
foreach ($rows as $x) {
    if ($x['status'] === 'passed') {
        $total += (float) $x['hours'];
    }
}
?>
<?= $ui('page-header', ['title' => (string) $emp['name_th'], 'subtitle' => $emp['employee_code'] . ' · ' . $t('training.hours_total', ['h' => rtrim(rtrim(number_format($total, 1), '0'), '.')]), 'crumbs' => [[$t('training.matrix_title'), $url('/training/admin/matrix')], [(string) $emp['employee_code'], null]]]) ?>
<section class="card stack-sm" aria-labelledby="tp-h">
  <h2 id="tp-h" class="card__title"><?= $e($t('training.history')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('training.none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($rows as $x): $st = (string) $x['status']; ?>
      <li><a href="<?= $e($url('/training/admin/s/' . $x['session_pid'])) ?>"><?= $e($x['code'] . ' · ' . $x['title']) ?></a> <?php include __DIR__ . '/_enr.php'; ?>
        <span class="d-block text-sm text-muted"><?php include __DIR__ . '/_when.php'; ?><?= $x['score'] !== null ? ' · ' . $e($t('training.score_n', ['s' => (string) (float) $x['score']])) : '' ?><?= $x['cert_no'] !== null ? ' · ' . $e((string) $x['cert_no']) : '' ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
