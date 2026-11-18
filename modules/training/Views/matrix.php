<?php
/**
 * Training matrix: people whose position needs a course × status.
 * @var callable $e @var callable $t @var callable $ui @var callable $url
 * @var array<string, string> $companies @var string $lePid @var array{courses: list<array<string, mixed>>, rows: list<array{employee: array<string, mixed>, cells: array<int, string>}>} $m
 */
$tone = ['valid' => 'success', 'expiring' => 'warning', 'booked' => 'info', 'expired' => 'danger', 'missing' => 'danger'];
?>
<?= $ui('page-header', ['title' => $t('training.matrix_title'), 'subtitle' => $t('training.matrix_subtitle'), 'crumbs' => [[$t('training.admin_title'), $url('/training/admin?le=' . rawurlencode($lePid))], [$t('training.matrix_title'), null]]]) ?>
<section class="card stack-sm" aria-labelledby="tm-h">
  <h2 id="tm-h" class="card__title"><?= $e($t('training.matrix_people', ['n' => (string) count($m['rows'])])) ?></h2>
  <?php if ($m['courses'] === []): ?><p class="text-sm text-muted"><?= $e($t('training.matrix_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="tm-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('training.col.employee')) ?></th><?php foreach ($m['courses'] as $c): ?><th scope="col"><?= $e((string) $c['code']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($m['rows'] as $row): ?>
        <tr><th scope="row" data-label="<?= $e($t('training.col.employee')) ?>"><a href="<?= $e($url('/training/admin/e/' . $row['employee']['public_id'])) ?>"><?= $e($row['employee']['employee_code'] . ' ' . $row['employee']['name_th']) ?></a>
          <span class="d-block text-sm text-muted"><?= $e(trim(($row['employee']['dept_code'] ?? '') . ' · ' . $row['employee']['position_title'], ' ·')) ?></span></th>
          <?php foreach ($m['courses'] as $c): $cell = $row['cells'][(int) $c['id']] ?? ''; ?>
            <td data-label="<?= $e((string) $c['code']) ?>"><?= $cell === '' ? '<span class="text-muted">—</span>' : $ui('badge', ['tone' => $tone[$cell], 'label' => $t('training.matrix.' . $cell)]) ?></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
