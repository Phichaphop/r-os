<?php
/**
 * The register: search and filter (site, type, cost centre, status), status badges, custodian, Excel export.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array<string, string> $f @var list<array<string, mixed>> $rows
 * @var list<array<string, mixed>> $sites @var list<array<string, mixed>> $types @var list<array<string, mixed>> $depts @var string $here @var bool $canView @var bool $canManage
 */
$statuses = ['' => $t('assets.all_active')];
foreach (['available', 'assigned', 'maintenance', 'lost', 'disposal_pending', 'disposed'] as $s) {
    $statuses[$s] = $t('assets.status.' . $s);
}
?>
<?= $ui('page-header', ['title' => $t('assets.title'), 'subtitle' => $t('assets.subtitle'),
    'actions' => $ui('button', ['label' => $t('assets.export'), 'href' => $url('/assets/export?le=' . rawurlencode($lePid)), 'variant' => 'secondary', 'icon' => 'download'])
        . ($canManage ? ' ' . $ui('button', ['label' => $t('assets.tab.new'), 'href' => $url('/assets/new?le=' . rawurlencode($lePid)), 'variant' => 'primary', 'icon' => 'plus']) : '')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<form method="get" action="<?= $e($url('/assets')) ?>" class="card row as-filter" role="search" aria-label="<?= $e($t('assets.search')) ?>">
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?= $ui('field', ['name' => 'q', 'type' => 'search', 'label' => $t('assets.search'), 'value' => $f['q'], 'placeholder' => $t('assets.search_placeholder')]) ?>
  <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('assets.field.site'), 'value' => $f['site'], 'options' => ['' => $t('assets.all')] + array_column($sites, 'code', 'public_id')]) ?>
  <?= $ui('field', ['name' => 'type', 'type' => 'select', 'label' => $t('assets.field.type'), 'value' => $f['type'], 'options' => ['' => $t('assets.all')] + array_column($types, 'name', 'public_id')]) ?>
  <?= $ui('field', ['name' => 'department', 'type' => 'select', 'label' => $t('assets.field.department'), 'value' => $f['department'], 'options' => ['' => $t('assets.all')] + array_column($depts, 'code', 'public_id')]) ?>
  <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('assets.field.status'), 'value' => $f['status'], 'options' => $statuses]) ?>
  <?= $ui('button', ['label' => $t('assets.show'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'search']) ?>
</form>
<section class="card card--flush" aria-labelledby="ai-h">
  <div class="card__head"><h2 id="ai-h" class="card__title"><?= $e($t('assets.count', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'database', 'title' => $t('assets.none'), 'text' => $t('assets.none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ai-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('assets.field.code')) ?></th><th scope="col"><?= $e($t('assets.field.name')) ?></th><th scope="col"><?= $e($t('assets.field.type')) ?></th><th scope="col"><?= $e($t('assets.field.site')) ?></th><th scope="col"><?= $e($t('assets.field.custodian')) ?></th><th scope="col"><?= $e($t('assets.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $st = (string) $row['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('assets.field.code')) ?>"><a href="<?= $e($url('/assets/a/' . $row['public_id'])) ?>"><?= $e((string) $row['code']) ?></a></th>
          <td data-label="<?= $e($t('assets.field.name')) ?>"><?= $e((string) $row['name']) ?><?= $row['serial_no'] !== null ? ' <span class="text-sm text-muted">' . $e('S/N ' . $row['serial_no']) . '</span>' : '' ?></td>
          <td data-label="<?= $e($t('assets.field.type')) ?>"><?= $e((string) $row['type_name']) ?></td>
          <td data-label="<?= $e($t('assets.field.site')) ?>"><?= $e($row['site_code'] . ($row['location'] !== null ? ' · ' . $row['location'] : '')) ?></td>
          <td data-label="<?= $e($t('assets.field.custodian')) ?>"><?= $e((string) ($row['custodian_name'] ?? '—')) ?><?= (int) ($row['unacknowledged'] ?? 0) === 1 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('assets.not_acknowledged')]) : '' ?></td>
          <td data-label="<?= $e($t('assets.field.status')) ?>"><?php include __DIR__ . '/_status.php'; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
