<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var int $total @var int $page @var int $per @var array<string, string> $filters @var bool $canExport @var string $guard
 */
$tone = ['success' => ['success', 'circle-check'], 'denied' => ['danger', 'lock'], 'failure' => ['warning', 'alert-triangle']];
$q = static fn (array $extra = []) => http_build_query($filters + $extra);
?>
<?= $ui('page-header', ['title' => $t('audit.title'), 'subtitle' => $t('audit.subtitle'),
    'actions' => $canExport ? $ui('button', ['label' => $t('audit.export'), 'href' => $url('/admin/audit/export') . '?' . $q(), 'icon' => 'download']) : '']) ?>
<?php if ($guard !== 'triggers'): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('audit.guard_code_only')]) ?><?php endif; ?>
<form method="get" action="<?= $e($url('/admin/audit')) ?>" class="filter-bar" role="search">
  <?= $ui('field', ['name' => 'action', 'label' => $t('audit.field.action'), 'value' => $filters['action'] ?? '', 'placeholder' => 'auth. / iam. / org.']) ?>
  <?= $ui('field', ['name' => 'actor', 'label' => $t('audit.field.actor'), 'value' => $filters['actor'] ?? '']) ?>
  <?= $ui('field', ['name' => 'outcome', 'label' => $t('audit.field.outcome'), 'type' => 'select', 'value' => $filters['outcome'] ?? '',
      'options' => ['' => $t('common.all'), 'success' => $t('audit.outcome.success'), 'denied' => $t('audit.outcome.denied'), 'failure' => $t('audit.outcome.failure')]]) ?>
  <?= $ui('field', ['name' => 'from', 'label' => $t('audit.field.from'), 'type' => 'date', 'value' => $filters['from'] ?? '']) ?>
  <?= $ui('field', ['name' => 'to', 'label' => $t('audit.field.to'), 'type' => 'date', 'value' => $filters['to'] ?? '']) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<div class="table-wrap" role="region" aria-label="<?= $e($t('audit.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <thead><tr><th scope="col"><?= $e($t('audit.field.time')) ?></th><th scope="col"><?= $e($t('audit.field.actor')) ?></th><th scope="col"><?= $e($t('audit.field.action')) ?></th>
      <th scope="col"><?= $e($t('audit.field.target')) ?></th><th scope="col"><?= $e($t('audit.field.outcome')) ?></th><th scope="col"><?= $e($t('audit.field.reason')) ?></th><th scope="col"><?= $e($t('error.reference')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$tn, $ic] = $tone[$r['outcome']] ?? ['neutral', 'info-circle']; ?>
      <tr>
        <td data-label="<?= $e($t('audit.field.time')) ?>" class="num"><?= $e($date($r['occurred_at'])) ?></td>
        <td data-label="<?= $e($t('audit.field.actor')) ?>"><?= $e($r['username'] ?? $t('audit.actor.' . $r['actor_type'])) ?></td>
        <td data-label="<?= $e($t('audit.field.action')) ?>" class="mono text-sm"><?= $e($r['action']) ?></td>
        <td data-label="<?= $e($t('audit.field.target')) ?>" class="text-sm"><?= $e(trim(($r['target_type'] ?? '') . ' ' . ($r['target_id'] ?? '')) ?: '—') ?></td>
        <td data-label="<?= $e($t('audit.field.outcome')) ?>"><?= $ui('badge', ['tone' => $tn, 'icon' => $ic, 'label' => $t('audit.outcome.' . $r['outcome'])]) ?></td>
        <td data-label="<?= $e($t('audit.field.reason')) ?>" class="text-sm"><?= $e($r['reason'] ?? '') ?></td>
        <td data-label="<?= $e($t('error.reference')) ?>" class="mono text-sm"><?= $e(substr((string) $r['correlation_id'], -8)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="7"><?= $ui('states', ['icon' => 'history', 'title' => $t('audit.empty')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= $ui('pagination', ['path' => '/admin/audit', 'query' => $filters, 'page' => $page, 'per' => $per, 'total' => $total]) ?>
