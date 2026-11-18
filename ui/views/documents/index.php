<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var int $total @var int $page @var int $per @var array<string, mixed> $f
 * @var list<string> $classes @var bool $canUpload @var array<int, string> $companies
 */
$query = array_filter(['q' => $f['q'], 'class' => $f['class'], 'status' => $f['status'], 'legal_entity' => $f['legal_entity'] ?: ''], static fn ($x) => $x !== '');
$actions = ($canUpload ? $ui('button', ['label' => $t('document.upload'), 'href' => $url('/documents/new'), 'variant' => 'primary', 'icon' => 'upload']) : '')
    . $ui('button', ['label' => $t('common.export_excel'), 'href' => $url('/documents/export') . '?' . http_build_query($query + ['format' => 'xlsx']), 'variant' => 'secondary', 'icon' => 'download']);
?>
<?= $ui('page-header', ['title' => $t('document.title'), 'subtitle' => $t('document.subtitle'), 'actions' => $actions]) ?>
<form method="get" action="<?= $e($url('/documents')) ?>" class="filter-bar" role="search">
  <?= $ui('field', ['name' => 'q', 'label' => $t('common.search'), 'value' => $f['q'], 'placeholder' => $t('document.search_hint')]) ?>
  <?= $ui('field', ['name' => 'class', 'label' => $t('document.field.class'), 'type' => 'select', 'value' => $f['class'],
      'options' => ['' => $t('common.all')] + array_combine($classes, array_map(static fn ($c) => $t('document.class.' . $c), $classes))]) ?>
  <?= $ui('field', ['name' => 'status', 'label' => $t('document.field.status'), 'type' => 'select', 'value' => $f['status'],
      'options' => ['' => $t('common.all')] + array_combine(['draft', 'final', 'superseded', 'disposed'], array_map(static fn ($s) => $t('document.status.' . $s), ['draft', 'final', 'superseded', 'disposed']))]) ?>
  <?php if (count($companies) > 1): ?>
    <?= $ui('field', ['name' => 'legal_entity', 'label' => $t('document.field.company'), 'type' => 'select', 'value' => (string) ($f['legal_entity'] ?: ''), 'options' => ['' => $t('common.all')] + $companies]) ?>
  <?php endif; ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<div class="table-wrap" role="region" aria-label="<?= $e($t('document.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('document.title')) ?></caption>
    <thead><tr>
      <th scope="col"><?= $e($t('document.field.title')) ?></th><th scope="col"><?= $e($t('document.field.class')) ?></th>
      <th scope="col"><?= $e($t('document.field.company')) ?></th><th scope="col"><?= $e($t('document.field.status')) ?></th>
      <th scope="col"><?= $e($t('document.field.version')) ?></th><th scope="col"><?= $e($t('document.field.updated')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $d): ?>
      <tr>
        <td data-label="<?= $e($t('document.field.title')) ?>"><a href="<?= $e($url('/documents/' . $d['public_id'])) ?>"><?= $e($d['title']) ?></a>
          <?php if ($d['document_number'] !== null): ?><div class="text-sm text-muted mono"><?= $e($d['document_number']) ?></div><?php endif; ?>
          <?php if ((int) $d['is_demo'] === 1): ?> <?= $ui('badge', ['tone' => 'neutral', 'label' => 'DEMO']) ?><?php endif; ?></td>
        <td data-label="<?= $e($t('document.field.class')) ?>"><?= $e($t('document.class.' . $d['document_class'])) ?> <span class="text-sm text-muted">(<?= $e($t('document.classification.' . $d['classification'])) ?>)</span></td>
        <td data-label="<?= $e($t('document.field.company')) ?>"><?= $e($d['le_code'] ?? $t('config.scope.organization')) ?></td>
        <td data-label="<?= $e($t('document.field.status')) ?>"><?php $st = (string) $d['lifecycle_status']; include __DIR__ . '/_status.php'; ?></td>
        <td data-label="<?= $e($t('document.field.version')) ?>" class="num"><?= (int) $d['current_version_no'] ?></td>
        <td data-label="<?= $e($t('document.field.updated')) ?>"><?= $e($date($d['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'file-text', 'title' => $t('document.empty'), 'text' => $t($canUpload ? 'document.empty_upload' : 'document.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= $ui('pagination', ['path' => '/documents', 'query' => $query, 'page' => $page, 'per' => $per, 'total' => $total]) ?>
