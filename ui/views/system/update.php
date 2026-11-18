<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array{version:string, module:string, file:string, checksum:string, state:string}> $migrations
 * @var bool $needsBackup @var array<string, mixed>|null $result
 */
$pending = array_values(array_filter($migrations, static fn ($m) => $m['state'] !== 'applied'));
?>
<?= $ui('page-header', ['title' => $t('update.title'), 'subtitle' => $t('update.subtitle')]) ?>
<?php if (is_array($result) && !$result['ok']): ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('update.failed'), 'text' => $t('update.failed_text', ['ref' => (string) $result['ref']]) . ' ' . (string) $result['error']]) ?>
<?php endif; ?>
<section class="card stack" aria-labelledby="mig-h">
  <div class="card__head"><h2 id="mig-h" class="card__title"><?= $e($t('update.migrations')) ?></h2>
    <?= $pending === [] ? $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('update.up_to_date')]) : $ui('badge', ['tone' => 'warning', 'icon' => 'alert-triangle', 'label' => $t('update.pending_n', ['n' => count($pending)])]) ?></div>
  <div class="table-wrap"><table class="table table--stack">
    <caption class="sr-only"><?= $e($t('update.migrations')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('update.field.version')) ?></th><th scope="col"><?= $e($t('update.field.module')) ?></th><th scope="col"><?= $e($t('update.field.state')) ?></th></tr></thead>
    <tbody><?php foreach ($migrations as $m): ?>
      <tr><td data-label="<?= $e($t('update.field.version')) ?>" class="mono"><?= $e($m['version']) ?></td><td data-label="<?= $e($t('update.field.module')) ?>"><?= $e($m['module']) ?></td>
        <td data-label="<?= $e($t('update.field.state')) ?>"><?= $ui('badge', ['tone' => $m['state'] === 'applied' ? 'success' : ($m['state'] === 'DRIFT' ? 'danger' : 'warning'), 'label' => $t('update.state.' . strtolower($m['state']))]) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if ($pending !== []): ?>
  <form method="post" action="<?= $e($url('/system/update')) ?>" class="stack"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="_back" value="/system/update">
    <?php if ($needsBackup): ?><label class="choice"><input type="checkbox" name="backup_confirmed" value="1" required> <?= $e($t('update.backup_confirm')) ?></label><?php endif; ?>
    <p class="text-sm text-muted"><?= $e($t('update.note')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('update.run'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'database']) ?></div>
  </form>
  <?php endif; ?>
</section>
