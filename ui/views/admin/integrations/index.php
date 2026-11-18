<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var list<array<string, mixed>> $rows @var bool $canManage */
$st = ['planned' => ['neutral', 'hourglass'], 'active' => ['success', 'circle-check'], 'suspended' => ['warning', 'alert-triangle'], 'retired' => ['neutral', 'x']];
?>
<?= $ui('page-header', ['title' => $t('integration.title'), 'subtitle' => $t('integration.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('integration.new'), 'href' => $url('/admin/integrations/new'), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('integration.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('integration.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('integration.field.name')) ?></th><th scope="col"><?= $e($t('integration.field.direction')) ?></th><th scope="col"><?= $e($t('integration.field.auth_method')) ?></th>
      <th scope="col"><?= $e($t('integration.field.data_classification')) ?></th><th scope="col"><?= $e($t('integration.field.status')) ?></th><th scope="col"><?= $e($t('integration.health')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i): [$tone, $icon] = $st[$i['status']] ?? ['neutral', 'info-circle']; ?>
      <tr>
        <td data-label="<?= $e($t('integration.field.name')) ?>"><a href="<?= $e($url('/admin/integrations/' . $i['public_id'])) ?>"><?= $e($i['name']) ?></a><div class="text-sm text-muted mono"><?= $e($i['code']) ?></div></td>
        <td data-label="<?= $e($t('integration.field.direction')) ?>"><?= $e($t('integration.direction.' . $i['direction'])) ?></td>
        <td data-label="<?= $e($t('integration.field.auth_method')) ?>"><?= $e($t('integration.auth.' . $i['auth_method'])) ?><?= (int) $i['active_keys'] > 0 ? ' · ' . $e($t('integration.keys_n', ['n' => (int) $i['active_keys']])) : '' ?></td>
        <td data-label="<?= $e($t('integration.field.data_classification')) ?>"><?= $e($t('document.classification.' . $i['data_classification'])) ?></td>
        <td data-label="<?= $e($t('integration.field.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('integration.status.' . $i['status'])]) ?>
          <div class="text-sm text-muted"><?= $e($t('integration.privacy.' . $i['privacy_review'])) ?></div></td>
        <td data-label="<?= $e($t('integration.health')) ?>"><?= $i['last_success'] !== null ? $e($t('integration.last_success', ['at' => $date($i['last_success'])])) : '—' ?>
          <?php if ((int) $i['failures_24h'] > 0): ?><div class="text-sm text-danger"><?= $e($t('integration.failures', ['n' => (int) $i['failures_24h']])) ?></div><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
