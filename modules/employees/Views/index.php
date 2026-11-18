<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var int $total @var int $page @var int $per @var array<string, mixed> $f
 * @var array<string, array<int, string>> $org @var bool $canManage @var bool $canReport
 */
include __DIR__ . '/_helpers.php';
$statusOpts = ['' => $t('employees.all')];
foreach (\Blue\Modules\Employees\EmployeeService::STATUSES as $s) {
    $statusOpts[$s] = $t('employees.status.' . $s);
}
$actions = ($canReport ? $ui('button', ['label' => $t('employees.nav_report'), 'href' => $url('/employees/report'), 'variant' => 'secondary', 'icon' => 'clipboard-check']) : '')
    . ($canManage ? $ui('button', ['label' => $t('employees.new'), 'href' => $url('/employees/new'), 'variant' => 'primary', 'icon' => 'plus']) : '');
?>
<?= $ui('page-header', ['title' => $t('employees.title'), 'subtitle' => $t('employees.subtitle'),
    'actions' => $actions]) ?>
<form method="get" action="<?= $e($url('/employees')) ?>" class="filter-bar" role="search" aria-label="<?= $e($t('employees.search')) ?>">
  <?= $ui('field', ['name' => 'q', 'label' => $t('common.search'), 'value' => (string) $f['q'], 'placeholder' => $t('employees.search_placeholder')]) ?>
  <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('employees.field.company'), 'value' => (string) ($f['le'] ?: ''), 'options' => ['' => $t('employees.all')] + $org['legal_entity']]) ?>
  <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('employees.field.status'), 'value' => (string) $f['status'],
      'options' => $statusOpts]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<section class="card stack-sm" aria-labelledby="el-h">
  <h2 id="el-h" class="card__title"><?= $e($t('employees.list', ['n' => (string) $total])) ?></h2>
  <?php if ($rows === []): ?>
    <p class="text-sm text-muted"><?= $e($t('employees.none')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="el-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('employees.field.code')) ?></th><th scope="col"><?= $e($t('employees.field.name')) ?></th><th scope="col"><?= $e($t('employees.field.company')) ?></th>
        <th scope="col"><?= $e($t('employees.field.position')) ?></th><th scope="col"><?= $e($t('employees.field.manager')) ?></th><th scope="col"><?= $e($t('employees.field.group')) ?></th>
        <th scope="col"><?= $e($t('employees.field.account')) ?></th><th scope="col"><?= $e($t('employees.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td data-label="<?= $e($t('employees.field.code')) ?>"><a href="<?= $e($url('/employees/' . $r['public_id'])) ?>"><?= $e($r['employee_code']) ?></a></td>
          <td data-label="<?= $e($t('employees.field.name')) ?>"><?= $e($r['name_th']) ?><?= ($r['preferred_name'] ?? '') !== '' ? ' (' . $e($r['preferred_name']) . ')' : '' ?></td>
          <td data-label="<?= $e($t('employees.field.company')) ?>"><?= $e($org['site'][(int) $r['site_id']] ?? ($org['legal_entity'][(int) $r['legal_entity_id']] ?? '—')) ?></td>
          <td data-label="<?= $e($t('employees.field.position')) ?>"><?= $e((string) ($r['position_title'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('employees.field.manager')) ?>"><?= $e((string) ($r['manager_name'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('employees.field.group')) ?>"><?= $e($r['employee_group']) ?></td>
          <td data-label="<?= $e($t('employees.field.account')) ?>"><?= $r['username'] !== null ? $e($r['username']) : $e($t('employees.no_account')) ?></td>
          <td data-label="<?= $e($t('employees.field.status')) ?>"><?= $statusBadge((string) $r['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= $ui('pagination', ['path' => '/employees', 'query' => ['q' => $f['q'], 'le' => $f['le'] ?: '', 'status' => $f['status']], 'page' => $page, 'per' => $per, 'total' => $total]) ?>
  <?php endif; ?>
</section>
