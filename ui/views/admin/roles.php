<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, array{0:string, 1:bool, 2:bool, 3:list<string>}> $roles @var array<string, string> $permissions @var list<array{0:string,1:string}> $conflicts
 */
?>
<?= $ui('page-header', ['title' => $t('iam.roles.title'), 'subtitle' => $t('iam.roles.subtitle')]) ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('iam.roles.matrix')) ?>" tabindex="0">
  <table class="table matrix">
    <caption class="sr-only"><?= $e($t('iam.roles.matrix')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('iam.roles.permission')) ?></th>
      <?php foreach ($roles as $code => $_): ?><th scope="col" title="<?= $e($t('role.' . $code)) ?>"><?= $e($code) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <tr><th scope="row"><?= $e($t('iam.roles.default_scope')) ?></th>
        <?php foreach ($roles as [$scope]): ?><td class="text-sm"><?= $e($t('iam.scope.' . $scope)) ?></td><?php endforeach; ?></tr>
      <tr><th scope="row"><?= $e($t('iam.roles.high_risk')) ?></th>
        <?php foreach ($roles as [, $high]): ?><td><?= $high ? $ui('icon', ['name' => 'alert-triangle', 'label' => $t('common.yes')]) : '<span class="sr-only">' . $e($t('common.no')) . '</span>—' ?></td><?php endforeach; ?></tr>
      <tr><th scope="row"><?= $e($t('iam.roles.mfa')) ?></th>
        <?php foreach ($roles as [, , $mfa]): ?><td><?= $mfa ? $ui('icon', ['name' => 'shield-check', 'label' => $t('common.yes')]) : '<span class="sr-only">' . $e($t('common.no')) . '</span>—' ?></td><?php endforeach; ?></tr>
      <?php foreach ($permissions as $perm => $risk): ?>
        <tr><th scope="row"><span class="mono text-sm"><?= $e($perm) ?></span><br><span class="text-sm text-muted"><?= $e($t('perm.' . $perm)) ?></span></th>
          <?php foreach ($roles as [, , , $perms]): ?><td><?= in_array($perm, $perms, true) ? $ui('icon', ['name' => 'circle-check', 'label' => $t('common.yes')]) : '<span class="sr-only">' . $e($t('common.no')) . '</span>—' ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<section class="card" aria-labelledby="sod-h">
  <div class="card__head"><h2 id="sod-h" class="card__title"><?= $e($t('iam.sod.rules')) ?></h2></div>
  <ul class="stack-sm">
    <li><?= $e($t('iam.sod.rule_self')) ?></li>
    <li><?= $e($t('iam.sod.rule_second_admin')) ?></li>
    <?php foreach ($conflicts as [$a, $b]): ?><li><?= $e($t('iam.sod.rule_pair', ['a' => $a . ' (' . $t('role.' . $a) . ')', 'b' => $b . ' (' . $t('role.' . $b) . ')'])) ?></li><?php endforeach; ?>
  </ul>
  <p class="text-muted text-sm"><?= $e($t('iam.roles.note')) ?></p>
</section>
