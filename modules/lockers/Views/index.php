<?php
/**
 * Lockers: mine (status, key, what to do next) and the zones of the company to reserve a free one.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $mine @var list<array<string, mixed>> $zones @var bool $canManage
 */
$atone = ['reserved' => 'info', 'assigned' => 'success', 'return_pending' => 'warning'];
?>
<?= $ui('page-header', ['title' => $t('lockers.title'), 'subtitle' => $t('lockers.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('lockers.admin_title'), 'href' => $url('/lockers/admin?le=' . rawurlencode($lePid)), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('lockers.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/lockers?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="lk-m">
  <h2 id="lk-m" class="card__title"><?= $e($t('lockers.mine')) ?></h2>
  <?php if ($mine === []): ?><p class="text-sm text-muted"><?= $e($t('lockers.mine_none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($mine as $x): ?>
    <li class="row"><a href="<?= $e($url('/lockers/l/' . $x['locker_pid'])) ?>"><strong><?= $e((string) $x['code']) ?></strong></a> <?= $e($x['le_code'] . ' · ' . $x['zone_name'] . ($x['site_code'] !== null ? ' · ' . $x['site_code'] : '')) ?>
      <?= $ui('badge', ['tone' => $atone[$x['status']] ?? 'neutral', 'label' => $t('lockers.astatus.' . $x['status'])]) ?>
      <span class="text-sm text-muted"><?= $x['status'] === 'reserved' ? $e($t('lockers.pick_key_by', ['d' => $date((string) $x['hold_until'], false)])) : ($x['key_no'] !== null ? $e($t('lockers.key_no_is', ['k' => (string) $x['key_no']])) : '') ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="stack-sm" aria-labelledby="lk-z">
  <h2 id="lk-z" class="section-title"><?= $e($t('lockers.zones')) ?></h2>
  <?php if ($zones === []): ?><?= $ui('states', ['icon' => 'key', 'title' => $t('lockers.no_zones')]) ?><?php else: ?>
  <div class="grid grid--3">
    <?php foreach ($zones as $z): ?>
    <article class="card stat-card stack-sm">
      <h3 class="stat__label"><a href="<?= $e($url('/lockers/z/' . $z['public_id'])) ?>"><?= $e($z['code'] . ' · ' . $z['name']) ?></a></h3>
      <p class="stat__value"><?= (int) $z['free'] ?></p>
      <p class="stat__meta"><?= $e($t('lockers.free_of', ['n' => (string) $z['lockers']])) ?><?= $z['site_code'] !== null ? ' · ' . $e((string) $z['site_code']) : '' ?></p>
      <div class="row"><?= $ui('button', ['label' => $t('lockers.open_zone'), 'href' => $url('/lockers/z/' . $z['public_id']), 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'key']) ?></div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
