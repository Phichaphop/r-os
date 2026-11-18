<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $partial @var array<string, mixed> $v
 * @var list<array<string, mixed>> $tree @var array<string, mixed>|null $current @var string $siteFilter @var string $orgName @var string $view tree|list @var bool $canCreateCompany @var \Blue\Core\Authz\ScopeSet $manage @var bool $crossCompany
 */
$canManageLe = static fn (array $le): bool => $manage->all || in_array((int) $le['id'], $manage->legalEntityIds, true);
$status = static fn (array $row) => $row['status'] === 'active' ? '' : ' ' . $ui('badge', ['tone' => 'neutral', 'icon' => 'x', 'label' => $t('org.status.inactive')]);
?>
<?= $ui('page-header', ['title' => $t('org.title'), 'subtitle' => $orgName !== '' ? $t('org.group') . ': ' . $orgName : null,
    'actions' => $canCreateCompany ? $ui('button', ['label' => $t('org.form.legal-entity.new'), 'href' => $url('/admin/org/legal-entity/new'), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<nav class="segmented-links" aria-label="<?= $e($t('org.view_label')) ?>">
  <a href="<?= $e($url('/admin/org')) ?>"<?= $view === 'tree' ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'components', 'size' => 'sm']) ?> <?= $e($t('org.view.tree')) ?></a>
  <a href="<?= $e($url('/admin/org?view=list')) ?>"<?= $view === 'list' ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'list-check', 'size' => 'sm']) ?> <?= $e($t('org.view.list')) ?></a>
</nav>
<?php if ($tree !== [] && $view === 'tree'): ?>
  <?= $partial('admin/org/_chart', ['tree' => $tree, 'le' => $current, 'manage' => $manage, 'siteFilter' => $siteFilter]) ?>
<?php elseif ($tree === []): ?>
  <div class="card"><?= $ui('states', ['icon' => 'building', 'title' => $t('org.empty'), 'text' => $t($canCreateCompany ? 'org.empty_admin' : 'org.empty_text')]) ?></div>
<?php else: ?>
<ul class="org-tree" role="list">
  <?php foreach ($tree as $le): ?>
  <li class="card">
    <div class="org-node">
      <?= $ui('icon', ['name' => 'building']) ?>
      <span class="org-node__name"><?= $e($le['name']) ?></span>
      <span class="badge badge--neutral mono"><?= $e($le['code']) ?></span>
      <?php if (!empty($le['registration_no'])): ?><span class="text-muted text-sm"><?= $e($t('org.field.registration_no')) ?> <?= $e($le['registration_no']) ?></span><?php endif; ?>
      <?= $status($le) ?>
      <?php if (!empty($le['partial'])): ?><span class="text-muted text-sm"><?= $e($t('org.partial')) ?></span><?php endif; ?>
      <span class="spacer"></span>
      <?php if (empty($le['partial']) && $canManageLe($le)): ?>
        <?= $ui('button', ['label' => $t('common.edit'), 'href' => $url('/admin/org/legal-entity/' . $le['public_id']), 'size' => 'sm', 'variant' => 'ghost', 'icon' => 'pencil']) ?>
        <?= $ui('button', ['label' => $t('org.form.site.new'), 'href' => $url('/admin/org/site/' . $le['public_id'] . '/new'), 'size' => 'sm', 'icon' => 'plus']) ?>
        <?= $ui('button', ['label' => $t('org.form.department.new'), 'href' => $url('/admin/org/department/' . $le['public_id'] . '/new'), 'size' => 'sm', 'icon' => 'plus']) ?>
      <?php endif; ?>
    </div>
    <div class="grid grid--2">
      <div>
        <h3 class="text-sm text-muted"><?= $e($t('org.sites')) ?> (<?= count($le['sites']) ?>)</h3>
        <ul class="org-sub" role="list">
          <?php foreach ($le['sites'] as $s): ?>
            <li class="org-node"><?= $ui('icon', ['name' => 'map-pin', 'size' => 'sm']) ?><span><?= $e($s['name']) ?></span><span class="mono text-sm text-muted"><?= $e($s['code']) ?></span>
              <span class="text-sm text-muted"><?= $e($t('org.site_type.' . $s['site_type'])) ?><?= $s['branch_no'] !== null ? ' · ' . $e($t('org.field.branch_no')) . ' ' . $e($s['branch_no']) : '' ?></span><?= $status($s) ?>
              <span class="spacer"></span><?php if ($canManageLe($le)): ?><?= $ui('button', ['label' => $t('common.edit') . ' ' . $s['code'], 'iconOnly' => true, 'icon' => 'pencil', 'href' => $url('/admin/org/site/' . $s['public_id']), 'size' => 'sm', 'variant' => 'ghost']) ?><?php endif; ?></li>
          <?php endforeach; ?>
          <?php if ($le['sites'] === []): ?><li class="text-muted text-sm"><?= $e($t('org.none')) ?></li><?php endif; ?>
        </ul>
      </div>
      <div>
        <h3 class="text-sm text-muted"><?= $e($t('org.departments')) ?> (<?= count($le['departments']) ?>)</h3>
        <ul class="org-sub" role="list">
          <?php
          // structure order (parent before children) with depth — DEC-68 levels
          $ids = array_map('intval', array_column($le['departments'], 'id'));
          $ordered = [];
          $walk = static function (?int $parent, int $depth) use (&$walk, &$ordered, $le, $ids): void {
              foreach ($le['departments'] as $d) {
                  $p = $d['parent_id'] !== null && in_array((int) $d['parent_id'], $ids, true) ? (int) $d['parent_id'] : null;
                  if ($p === $parent && $depth < 8) {
                      $ordered[] = [$d, $depth];
                      $walk((int) $d['id'], $depth + 1);
                  }
              }
          };
          $walk(null, 0);
          $siteCode = [];
          foreach ($le['sites'] as $s0) {
              $siteCode[(int) $s0['id']] = (string) $s0['code'];
          }
          ?>
          <?php foreach ($ordered as [$d, $depth]): ?>
            <li class="org-node org-node--d<?= (int) $depth ?>"><?= $ui('icon', ['name' => 'users', 'size' => 'sm']) ?><span><?= $e($d['name']) ?></span>
              <?= $ui('badge', ['tone' => 'neutral', 'label' => $t('org.level.' . ($d['unit_level'] ?? 'department'))]) ?><span class="mono text-sm text-muted"><?= $e($d['code']) ?></span>
              <span class="oc-tag<?= $d['site_id'] === null ? ' oc-tag--all' : '' ?>"><?= $ui('icon', ['name' => 'map-pin', 'size' => 'sm']) ?><span><?= $e($d['site_id'] !== null ? (string) ($siteCode[(int) $d['site_id']] ?? '') : $t('org.site_all_short')) ?></span></span><?= $status($d) ?>
              <span class="spacer"></span><?php if ($canManageLe($le)): ?><?= $ui('button', ['label' => $t('common.edit') . ' ' . $d['code'], 'iconOnly' => true, 'icon' => 'pencil', 'href' => $url('/admin/org/department/' . $d['public_id']), 'size' => 'sm', 'variant' => 'ghost']) ?><?php endif; ?></li>
          <?php endforeach; ?>
          <?php if ($le['departments'] === []): ?><li class="text-muted text-sm"><?= $e($t('org.none')) ?></li><?php endif; ?>
        </ul>
      </div>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
