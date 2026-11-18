<?php
/**
 * Lockers of a company (workplace admin): what needs doing (keys to hand over, returns pending), the zones with their
 * counts, a new zone.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $zones @var list<array<string, mixed>> $todo
 * @var list<array<string, mixed>> $sites @var array<string, string> $errors @var array<string, mixed> $old
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
?>
<?= $ui('page-header', ['title' => $t('lockers.admin_title'), 'subtitle' => $t('lockers.admin_subtitle'), 'crumbs' => [[$t('lockers.title'), $url('/lockers')], [$t('lockers.admin_title'), null]],
    'actions' => $ui('button', ['label' => $t('lockers.export'), 'href' => $url('/lockers/admin/export?le=' . rawurlencode($lePid)), 'variant' => 'secondary', 'icon' => 'download'])]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('lockers.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/lockers/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card card--flush" aria-labelledby="la-t">
  <div class="card__head"><h2 id="la-t" class="card__title"><?= $e($t('lockers.todo', ['n' => (string) count($todo)])) ?></h2></div>
  <?php if ($todo === []): ?><div class="card__pad"><p class="text-sm text-muted"><?= $e($t('lockers.todo_none')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="la-t" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('lockers.col.locker')) ?></th><th scope="col"><?= $e($t('lockers.holder')) ?></th><th scope="col"><?= $e($t('lockers.col.state')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($todo as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('lockers.col.locker')) ?>"><a href="<?= $e($url('/lockers/l/' . $x['locker_pid'])) ?>"><?= $e((string) $x['code']) ?></a> <span class="text-sm text-muted"><?= $e((string) $x['zone_name']) ?></span></th>
          <td data-label="<?= $e($t('lockers.holder')) ?>"><?= $e($x['employee_code'] . ' · ' . $x['name_th']) ?></td>
          <td data-label="<?= $e($t('lockers.col.state')) ?>"><?= $ui('badge', ['tone' => $x['status'] === 'reserved' ? 'info' : 'warning', 'label' => $t('lockers.astatus.' . $x['status'])]) ?>
            <span class="text-sm text-muted"><?= $x['status'] === 'reserved' ? $e($t('lockers.pick_key_by', ['d' => $date((string) $x['hold_until'], false)])) : ($x['return_reason'] !== null ? $e($t('lockers.reason.' . $x['return_reason'])) : '') ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card card--flush" aria-labelledby="la-z">
  <div class="card__head"><h2 id="la-z" class="card__title"><?= $e($t('lockers.zones')) ?></h2></div>
  <?php if ($zones === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'key', 'title' => $t('lockers.no_zones')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="la-z" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('lockers.col.zone')) ?></th><th scope="col"><?= $e($t('lockers.field.site')) ?></th><th scope="col" class="num"><?= $e($t('lockers.col.lockers')) ?></th><th scope="col" class="num"><?= $e($t('lockers.col.free')) ?></th><th scope="col" class="num"><?= $e($t('lockers.col.used')) ?></th><th scope="col" class="num"><?= $e($t('lockers.col.out_of_use')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($zones as $z): ?>
        <tr><th scope="row" data-label="<?= $e($t('lockers.col.zone')) ?>"><a href="<?= $e($url('/lockers/z/' . $z['public_id'])) ?>"><?= $e($z['code'] . ' · ' . $z['name']) ?></a></th>
          <td data-label="<?= $e($t('lockers.field.site')) ?>"><?= $e((string) ($z['site_code'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('lockers.col.lockers')) ?>" class="num"><?= (int) $z['lockers'] ?></td>
          <td data-label="<?= $e($t('lockers.col.free')) ?>" class="num"><?= (int) $z['free'] ?></td>
          <td data-label="<?= $e($t('lockers.col.used')) ?>" class="num"><?= (int) $z['used'] ?></td>
          <td data-label="<?= $e($t('lockers.col.out_of_use')) ?>" class="num"><?= (int) $z['out_of_use'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="la-n">
  <h2 id="la-n" class="card__title"><?= $e($t('lockers.new_zone')) ?></h2>
  <form method="post" action="<?= $e($url('/lockers/admin/zones')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'label' => $t('lockers.field.code'), 'required' => true, 'value' => $o('code'), 'help' => $t('lockers.code_help'), 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name', 'label' => $t('lockers.field.name'), 'required' => true, 'value' => $o('name'), 'error' => $errors['name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('lockers.field.site'), 'value' => $o('site'), 'options' => ['' => '—'] + array_column($sites, 'code', 'public_id'), 'error' => $errors['site'] ?? null]) ?>
    </div>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'grid_rows', 'type' => 'number', 'label' => $t('lockers.field.grid_rows'), 'required' => true, 'value' => $o('grid_rows'), 'error' => $errors['grid_rows'] ?? null]) ?>
      <?= $ui('field', ['name' => 'grid_cols', 'type' => 'number', 'label' => $t('lockers.field.grid_cols'), 'required' => true, 'value' => $o('grid_cols'), 'error' => $errors['grid_cols'] ?? null]) ?>
      <?= $ui('field', ['name' => 'hold_days', 'type' => 'number', 'label' => $t('lockers.field.hold_days'), 'required' => true, 'value' => $o('hold_days'), 'help' => $t('lockers.hold_help'), 'error' => $errors['hold_days'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'note', 'label' => $t('lockers.field.note'), 'value' => $o('note'), 'error' => $errors['note'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('lockers.create_zone'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
