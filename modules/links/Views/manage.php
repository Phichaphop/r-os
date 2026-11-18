<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var list<array<string, mixed>> $rows */
$st = ['draft' => ['neutral', 'pencil'], 'published' => ['success', 'circle-check'], 'disabled' => ['warning', 'eye-off'], 'archived' => ['neutral', 'history']];
?>
<?= $ui('page-header', ['title' => $t('links.manage'), 'subtitle' => $t('links.manage_subtitle'),
    'actions' => $ui('button', ['label' => $t('links.new'), 'href' => $url('/admin/links/new'), 'variant' => 'primary', 'icon' => 'plus'])
        . $ui('button', ['label' => $t('links.view_page'), 'href' => $url('/links'), 'variant' => 'secondary', 'icon' => 'eye'])]) ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('links.manage')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('links.manage')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('links.field.name_th')) ?></th><th scope="col"><?= $e($t('links.field.category')) ?></th>
      <th scope="col"><?= $e($t('links.field.audience')) ?></th><th scope="col"><?= $e($t('links.field.status')) ?></th><th scope="col" class="num"><?= $e($t('links.clicks_30d')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $l): [$tone, $icon] = $st[$l['status']] ?? ['neutral', 'info-circle']; ?>
      <tr>
        <td data-label="<?= $e($t('links.field.name_th')) ?>"><a href="<?= $e($url('/admin/links/' . $l['public_id'])) ?>"><?= $e($l['name_th']) ?></a>
          <div class="text-sm text-muted mono break-all"><?= $e($l['host']) ?></div></td>
        <td data-label="<?= $e($t('links.field.category')) ?>"><?= $e($t('links.cat.' . $l['category'])) ?></td>
        <td data-label="<?= $e($t('links.field.audience')) ?>"><?= $e($l['audience_roles'] === null ? $t('links.audience_all') : str_replace(',', ', ', (string) $l['audience_roles'])) ?></td>
        <td data-label="<?= $e($t('links.field.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('links.status.' . $l['status'])]) ?>
          <?php if ($l['draft_json'] !== null): ?><div class="text-sm text-warning"><?= $e($t('links.has_pending')) ?></div><?php endif; ?>
          <?php if ($l['reviewer_name'] !== null): ?><div class="text-sm text-muted"><?= $e($t('links.reviewed_by', ['name' => $l['reviewer_name'], 'at' => $date((string) $l['reviewed_at'], false)])) ?></div><?php endif; ?></td>
        <td data-label="<?= $e($t('links.clicks_30d')) ?>" class="num"><?= (int) $l['clicks_30d'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
