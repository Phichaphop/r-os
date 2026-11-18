<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var bool $manage
 */
?>
<?= $ui('page-header', ['title' => $t('template.title'), 'subtitle' => $t('template.subtitle'),
    'actions' => $manage ? $ui('button', ['label' => $t('template.new.title'), 'href' => $url('/templates/new'), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('template.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('template.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('template.field.name')) ?></th><th scope="col"><?= $e($t('template.field.code')) ?></th>
      <th scope="col"><?= $e($t('template.field.published')) ?></th><th scope="col"><?= $e($t('template.field.issued')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $tp): ?>
      <tr>
        <td data-label="<?= $e($t('template.field.name')) ?>"><a href="<?= $e($url('/templates/' . $tp['public_id'])) ?>"><?= $e($tp['name']) ?></a>
          <?php if ((int) $tp['is_demo'] === 1): ?> <?= $ui('badge', ['tone' => 'neutral', 'label' => 'DEMO']) ?><?php endif; ?>
          <div class="text-sm text-muted"><?= $e($t('document.class.' . $tp['document_class'])) ?><?= $tp['le_code'] !== null ? ' · ' . $e($tp['le_code']) : '' ?></div></td>
        <td data-label="<?= $e($t('template.field.code')) ?>" class="mono"><?= $e($tp['code']) ?></td>
        <td data-label="<?= $e($t('template.field.published')) ?>"><?= $tp['published_no'] !== null ? $e('v' . $tp['published_no']) : $ui('badge', ['tone' => 'neutral', 'icon' => 'pencil', 'label' => $t('template.not_published')]) ?>
          <?php if ($manage && (int) $tp['has_draft'] > 0): ?> <?= $ui('badge', ['tone' => 'info', 'icon' => 'pencil', 'label' => $t('template.has_draft')]) ?><?php endif; ?></td>
        <td data-label="<?= $e($t('template.field.issued')) ?>" class="num"><?= (int) $tp['issued'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="4"><?= $ui('states', ['icon' => 'clipboard-check', 'title' => $t('template.empty')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
