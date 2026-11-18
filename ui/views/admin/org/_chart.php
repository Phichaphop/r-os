<?php
/**
 * Org chart (0.8.2, DEC-69): ONE structure per company — company → Top Management → Division → Department → Section →
 * Sub Section (levels may be skipped). Branches are not a separate tree: every unit carries the branch it belongs to as
 * a tag (none = the unit serves every branch). Several units with the same job in different branches simply sit under the
 * same parent, each with its own branch tag (e.g. HR Section HQ / HR Section BR1 under the HR Division).
 * Pure HTML/CSS (nested lists = a real tree for screen readers); a wide chart scrolls sideways inside its own box.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url
 * @var array<string, mixed> $le the company shown @var list<array<string, mixed>> $tree all companies (switcher)
 * @var \Blue\Core\Authz\ScopeSet $manage @var string $siteFilter site public id ('' = all branches)
 */
$can = empty($le['partial']) && ($manage->all || in_array((int) $le['id'], $manage->legalEntityIds, true));
$sites = [];
foreach ($le['sites'] as $s) {
    $sites[(int) $s['id']] = $s;
}
$filterId = null;
foreach ($le['sites'] as $s) {
    if ((string) $s['public_id'] === $siteFilter) {
        $filterId = (int) $s['id'];
    }
}
$node = static function (string $kind, string $icon, array $row, string $meta, ?string $editHref, string $tag = '', bool $dim = false) use ($e, $t, $ui, $url): string {
    $inactive = ($row['status'] ?? 'active') !== 'active';
    $name = (string) ($row['name'] ?? '') !== '' ? (string) $row['name'] : (string) ($row['code'] ?? '');
    $h = '<div class="oc-node oc-node--' . $e($kind) . ($inactive ? ' oc-node--inactive' : '') . ($dim ? ' oc-node--dim' : '') . '">'
        . '<span class="oc-node__icon" aria-hidden="true">' . $ui('icon', ['name' => $icon, 'size' => 'sm']) . '</span>'
        . '<span class="oc-node__body"><span class="oc-node__name">' . $e($name) . '</span>'
        . '<span class="oc-node__meta">' . (isset($row['code']) ? '<span class="mono">' . $e($row['code']) . '</span>' : '') . ($meta !== '' ? ' · ' . $e($meta) : '')
        . ($inactive ? ' · ' . $e($t('org.status.inactive')) : '') . '</span>' . $tag . '</span>';
    if ($editHref !== null) {
        $h .= '<a class="oc-node__edit" href="' . $e($url($editHref)) . '" aria-label="' . $e($t('common.edit') . ' ' . $name) . '">' . $ui('icon', ['name' => 'pencil', 'size' => 'sm']) . '</a>';
    }
    return $h . '</div>';
};
$units = static function (array $all, ?int $parentId, int $depth = 0) use (&$units, $node, $t, $e, $ui, $can, $sites, $filterId): string {
    $ids = array_map('intval', array_column($all, 'id'));
    $kids = array_values(array_filter($all, static fn (array $d): bool => $parentId === null
        ? ($d['parent_id'] === null || !in_array((int) $d['parent_id'], $ids, true))
        : (int) ($d['parent_id'] ?? 0) === $parentId));
    if ($kids === [] || $depth > 8) {
        return '';
    }
    $h = '<ul role="group">';
    foreach ($kids as $d) {
        $site = $d['site_id'] !== null ? ($sites[(int) $d['site_id']] ?? null) : null;
        $tag = '<span class="oc-tag' . ($site === null ? ' oc-tag--all' : '') . '">' . $ui('icon', ['name' => 'map-pin', 'size' => 'sm'])
            . '<span>' . $e($site !== null ? (string) $site['code'] : $t('org.site_all_short')) . '</span></span>';
        $dim = $filterId !== null && $site !== null && (int) $site['id'] !== $filterId;
        $h .= '<li role="treeitem">' . $node('dept oc-node--' . str_replace('_', '-', (string) ($d['unit_level'] ?? 'department')), 'users', $d,
            $t('org.level.' . ($d['unit_level'] ?? 'department')), $can ? '/admin/org/department/' . $d['public_id'] : null, $tag, $dim)
            . $units($all, (int) $d['id'], $depth + 1) . '</li>';
    }
    return $h . '</ul>';
};
?>
<section class="card oc-wrap" aria-labelledby="oc-h">
  <div class="oc-head">
    <h2 id="oc-h" class="card__title"><?= $e($t('org.chart.title_company', ['name' => (string) $le['name']])) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('org.chart.help')) ?></p>
  </div>
  <?php if (count($tree) > 1): ?>
  <nav class="segmented-links" aria-label="<?= $e($t('org.chart.pick_company')) ?>">
    <?php foreach ($tree as $c): ?>
      <a href="<?= $e($url('/admin/org?le=' . rawurlencode((string) $c['public_id']))) ?>"<?= (string) $c['public_id'] === (string) $le['public_id'] ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e((string) $c['code']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <div class="oc-branches">
    <span class="text-sm text-muted"><?= $e($t('org.chart.branches')) ?></span>
    <a class="oc-tag oc-tag--all<?= $filterId === null ? ' is-current' : '' ?>" href="<?= $e($url('/admin/org?le=' . rawurlencode((string) $le['public_id']))) ?>"<?= $filterId === null ? ' aria-current="true"' : '' ?>><?= $ui('icon', ['name' => 'map-pin', 'size' => 'sm']) ?><span><?= $e($t('org.chart.all_branches')) ?></span></a>
    <?php foreach ($le['sites'] as $s): $cur = $filterId === (int) $s['id']; ?>
      <a class="oc-tag<?= $cur ? ' is-current' : '' ?>" href="<?= $e($url('/admin/org?le=' . rawurlencode((string) $le['public_id']) . '&site=' . rawurlencode((string) $s['public_id']))) ?>"<?= $cur ? ' aria-current="true"' : '' ?> title="<?= $e((string) $s['name']) ?>"><?= $ui('icon', ['name' => 'map-pin', 'size' => 'sm']) ?><span><?= $e((string) $s['code']) ?> · <?= $e((string) $s['name']) ?></span></a>
      <?php if ($can): ?><a class="oc-tag__edit" href="<?= $e($url('/admin/org/site/' . $s['public_id'])) ?>" aria-label="<?= $e($t('common.edit') . ' ' . $s['name']) ?>"><?= $ui('icon', ['name' => 'pencil', 'size' => 'sm']) ?></a><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($can): ?>
      <a class="btn btn--ghost btn--sm" href="<?= $e($url('/admin/org/site/' . $le['public_id'] . '/new')) ?>"><?= $ui('icon', ['name' => 'plus', 'size' => 'sm']) ?><?= $e($t('org.form.site.new')) ?></a>
      <a class="btn btn--secondary btn--sm" href="<?= $e($url('/admin/org/department/' . $le['public_id'] . '/new')) ?>"><?= $ui('icon', ['name' => 'plus', 'size' => 'sm']) ?><?= $e($t('org.form.department.new')) ?></a>
    <?php endif; ?>
  </div>
  <?php if ($filterId !== null): ?><p class="text-sm text-muted" role="status"><?= $e($t('org.chart.filter_note')) ?></p><?php endif; ?>
  <div class="oc-scroll" tabindex="0" role="region" aria-labelledby="oc-h" data-oc-scroll>
    <ul class="oc" role="tree" aria-labelledby="oc-h">
      <li role="treeitem" aria-expanded="true">
        <?= $node('company oc-node--root', 'building', $le, $t('org.chart.company'), $can ? '/admin/org/legal-entity/' . $le['public_id'] : null) ?>
        <?= $le['departments'] !== [] ? $units($le['departments'], null) : '' ?>
      </li>
    </ul>
  </div>
  <?php if ($le['departments'] === []): ?><p class="text-sm text-muted"><?= $e($t('org.none')) ?></p><?php endif; ?>
  <p class="text-sm text-muted oc-legend"><?= $e($t('org.chart.levels_note')) ?></p>
</section>
