<?php
/**
 * Checklists and inspection points (inspection admin).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $checklists @var list<array<string, mixed>> $points
 * @var list<array<string, mixed>> $sites @var list<array<string, mixed>> $categories @var string $today @var bool $canManage
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$here = '/inspection/admin';
$fo = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
?>
<?= $ui('page-header', ['title' => $t('inspection.admin_title'), 'subtitle' => $t('inspection.admin_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('inspection.pick_company')) ?>">
  <?php foreach ($companies as $cp => $code): ?><a href="<?= $e($url('/inspection/admin?le=' . rawurlencode($cp))) ?>"<?= $cp === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card card--flush" aria-labelledby="ia-points">
  <div class="card__head"><h2 id="ia-points" class="card__title"><?= $e($t('inspection.points')) ?></h2></div>
  <?php if ($points === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('inspection.no_points')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ia-points" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('inspection.col.point')) ?></th><th scope="col"><?= $e($t('inspection.field.checklist')) ?></th><th scope="col"><?= $e($t('inspection.field.frequency')) ?></th><th scope="col"><?= $e($t('inspection.field.inspector')) ?></th><th scope="col"><?= $e($t('inspection.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($points as $p): ?>
        <tr><th scope="row" data-label="<?= $e($t('inspection.col.point')) ?>"><a href="<?= $e($url('/inspection/p/' . $p['public_id'])) ?>"><?= $e($p['code'] . ' · ' . $p['name']) ?></a></th>
          <td data-label="<?= $e($t('inspection.field.checklist')) ?>"><?= $e((string) $p['checklist_name']) ?></td>
          <td data-label="<?= $e($t('inspection.field.frequency')) ?>"><?= $e($t('inspection.frequency.' . $p['frequency'])) ?></td>
          <td data-label="<?= $e($t('inspection.field.inspector')) ?>"><?= $e((string) $p['inspector_name']) ?></td>
          <td data-label="<?= $e($t('inspection.col.status')) ?>"><?= $ui('badge', ['tone' => (int) $p['active'] === 1 ? 'success' : 'neutral', 'label' => $t((int) $p['active'] === 1 ? 'inspection.active' : 'inspection.inactive')]) ?><?= $p['ticket_category_pid'] !== null ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('inspection.to_helpdesk')]) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="card__pad stack-sm">
    <h3 class="text-md"><?= $e($t('inspection.add_point')) ?></h3>
    <?php if ($checklists === []): ?><p class="text-sm text-muted"><?= $e($t('inspection.need_checklist')) ?></p><?php else: ?>
    <?php $pv = $form === 'point' ? $old : ['start_date' => $today]; $pErr = $form === 'point' ? $errors : []; $pAction = '/inspection/admin/point'; $pf = 'np'; $pLe = $lePid; $pEdit = false; include __DIR__ . '/_point_form.php'; ?>
    <?php endif; ?>
  </div>
</section>
<section class="card card--flush" aria-labelledby="ia-checklists">
  <div class="card__head"><h2 id="ia-checklists" class="card__title"><?= $e($t('inspection.checklists')) ?></h2></div>
  <?php if ($checklists === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('inspection.no_checklists')) ?></p><?php else: ?>
  <ul class="plain-list card__pad stack-sm">
    <?php foreach ($checklists as $c): ?><li><a href="<?= $e($url('/inspection/c/' . $c['public_id'])) ?>"><?= $e((string) $c['name']) ?></a> · <?= $e($t('inspection.items_n', ['n' => (string) count($c['items'])])) ?> · <?= $e($t('inspection.version_n', ['n' => (string) ($c['versions'][0]['version_no'] ?? 1)])) ?></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <div class="card__pad stack-sm">
    <h3 class="text-md"><?= $e($t('inspection.add_checklist')) ?></h3>
    <form method="post" action="<?= $e($url('/inspection/admin/checklist')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'name', 'id' => 'f-nc-name', 'label' => $t('inspection.field.checklist_name'), 'required' => true, 'value' => $fo('checklist', 'name'), 'error' => $fe('checklist', 'name')]) ?>
        <?= $ui('field', ['name' => 'effective_from', 'id' => 'f-nc-from', 'type' => 'date', 'label' => $t('inspection.field.effective_from'), 'required' => true, 'value' => $fo('checklist', 'effective_from', $today), 'min' => $today, 'error' => $fe('checklist', 'effective_from')]) ?>
      </div>
      <?= $ui('field', ['name' => 'items', 'id' => 'f-nc-items', 'type' => 'textarea', 'label' => $t('inspection.field.items'), 'required' => true, 'value' => $fo('checklist', 'items'), 'help' => $t('inspection.items_help'), 'error' => $fe('checklist', 'items')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('inspection.add_checklist'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
    </form>
  </div>
</section>
