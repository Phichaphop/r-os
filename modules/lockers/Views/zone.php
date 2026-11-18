<?php
/**
 * A zone as a grid of lockers coloured by status. Each locker opens its page (reserve, details).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, mixed> $zone
 */
?>
<?= $ui('page-header', ['title' => $zone['code'] . ' · ' . $zone['name'], 'subtitle' => $t('lockers.zone_subtitle', ['d' => (string) $zone['hold_days']]),
    'crumbs' => [[$t('lockers.title'), $url('/lockers')], [(string) $zone['code'], null]]]) ?>
<?php if ($zone['status'] !== 'active'): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('lockers.zone_closed')]) ?><?php endif; ?>
<?php if ($zone['has_one']): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('lockers.have_one')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="lz-g">
  <h2 id="lz-g" class="card__title"><?= $e($t('lockers.layout')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="lz-g" tabindex="0">
    <table class="locker-grid">
      <caption class="sr-only"><?= $e($t('lockers.layout')) ?></caption>
      <tbody>
      <?php for ($r = 1; $r <= (int) $zone['grid_rows']; $r++): ?>
        <tr>
        <?php for ($c = 1; $c <= (int) $zone['grid_cols']; $c++): $l = $zone['grid'][$r][$c] ?? null; ?>
          <?php if ($l === null): ?><td class="locker locker--none"></td><?php else: ?>
          <td class="locker locker--<?= $e((string) $l['status']) ?><?= $l['mine'] ? ' locker--mine' : '' ?>">
            <a href="<?= $e($url('/lockers/l/' . $l['public_id'])) ?>"><span class="locker__code"><?= $e(substr((string) $l['code'], strlen((string) $zone['code']) + 1)) ?></span>
              <span class="locker__state"><?= $e($l['mine'] ? $t('lockers.yours') : $t('lockers.status.' . $l['status'])) ?></span>
              <?php if ($zone['can_manage'] && $l['employee_code'] !== null): ?><span class="locker__who"><?= $e((string) $l['employee_code']) ?></span><?php endif; ?></a></td>
          <?php endif; ?>
        <?php endfor; ?>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
  </div>
  <p class="text-sm text-muted room-legend">
    <?php foreach (['available', 'reserved', 'assigned', 'return_pending', 'maintenance', 'blocked'] as $s): ?><span class="locker-key locker--<?= $e($s) ?>"></span> <?= $e($t('lockers.status.' . $s)) ?> · <?php endforeach; ?>
    <span class="locker-key locker--mine"></span> <?= $e($t('lockers.yours')) ?>
  </p>
</section>
