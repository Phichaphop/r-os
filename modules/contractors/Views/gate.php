<?php
/**
 * Gate: today's approved / on-site permits; check each worker in (only inside the window, documents re-checked) and out.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $permits @var string $now @var string $here @var bool $canView @var bool $canManage @var bool $canGate
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$hm = static fn (string $x): string => substr($x, 11, 5);
?>
<?= $ui('page-header', ['title' => $t('contractors.gate_title'), 'subtitle' => $t('contractors.gate_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($permits === []): ?>
  <?= $ui('states', ['icon' => 'shield-check', 'title' => $t('contractors.gate_none'), 'text' => $t('contractors.gate_none_help')]) ?>
<?php endif; ?>
<?php foreach ($permits as $p): $pid = (string) $p['public_id']; $pst = (string) $p['status']; $open = $now >= (string) $p['starts_at'] && $now < (string) $p['ends_at']; ?>
<section class="card stack-sm" aria-labelledby="g-<?= $e($pid) ?>">
  <h2 id="g-<?= $e($pid) ?>" class="card__title"><a href="<?= $e($url('/contractors/p/' . $pid)) ?>"><?= $e((string) $p['ref']) ?></a> · <?= $e((string) $p['company_name']) ?> <?php include __DIR__ . '/_pstatus.php'; ?></h2>
  <p class="text-sm"><?= $e($p['site_code'] . ' ' . $p['area'] . ' · ' . $hm((string) $p['starts_at']) . '–' . $hm((string) $p['ends_at']) . ' · ' . $p['work']) ?></p>
  <?php if (!$open): ?><p class="text-sm text-muted"><?= $e($t('contractors.window_closed')) ?></p><?php endif; ?>
  <?php if ((int) $p['suspended'] === 1): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $t('contractors.suspended')]) ?><?php endif; ?>
  <ul class="plain-list stack-sm">
  <?php foreach ($p['workers'] as $w): ?>
    <li class="row"><span><?= $e((string) $w['name']) ?></span> <?= $w['inside'] !== null ? $ui('badge', ['tone' => 'warning', 'label' => $t('contractors.inside')]) : '' ?>
      <form method="post" action="<?= $e($url('/contractors/p/' . $pid . '/gate')) ?>"><?= $csrf ?><input type="hidden" name="back" value="gate"><input type="hidden" name="worker" value="<?= $e((string) $w['public_id']) ?>"><input type="hidden" name="dir" value="<?= $w['inside'] !== null ? 'out' : 'in' ?>">
        <?= $ui('button', ['label' => $w['inside'] !== null ? $t('contractors.check_out') : $t('contractors.check_in'), 'type' => 'submit', 'variant' => $w['inside'] !== null ? 'secondary' : 'primary', 'size' => 'sm', 'attrs' => ['aria-label' => ($w['inside'] !== null ? $t('contractors.check_out') : $t('contractors.check_in')) . ' ' . $w['name']]]) ?></form></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
