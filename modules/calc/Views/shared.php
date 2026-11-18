<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $row @var bool $mine */
$res = ['steps' => (array) ($row['result']['steps'] ?? [])];
?>
<?= $ui('page-header', ['title' => $t('calc.tab.' . $row['calculator']), 'subtitle' => $t('calc.shared_by', ['name' => (string) $row['owner_name'], 'at' => $date((string) $row['created_at'])])]) ?>
<p><a href="<?= $e($url('/calc?tab=' . $row['calculator'])) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('calc.back')) ?></a></p>
<section class="card stack-sm" aria-labelledby="r-h">
  <h2 id="r-h" class="card__title"><?= $e($t('calc.result')) ?></h2>
  <div class="calc-outputs">
    <?php foreach ((array) ($row['result']['outputs'] ?? []) as $k => $val): ?>
      <div class="stat"><div class="stat__label"><?= $e($t('calc.out.' . $row['calculator'] . '.' . $k)) ?></div><div class="stat__value stat__value--sm mono"><?= $e((string) $val) ?></div></div>
    <?php endforeach; ?>
  </div>
  <?php include __DIR__ . '/_steps.php'; ?>
  <dl class="facts">
    <div><dt><?= $e($t('calc.field.reference_date')) ?></dt><dd><?= $e((string) ($row['result']['at'] ?? '')) ?></dd></div>
    <div><dt><?= $e($t('calc.rule.source')) ?></dt><dd class="break-all"><?= $e((string) ($row['result']['source'] ?? '—')) ?></dd></div>
    <div><dt><?= $e($t('calc.share_expires')) ?></dt><dd><?= $e($date((string) $row['expires_at'])) ?></dd></div>
  </dl>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('calc.estimate_only')]) ?>
  <?php if ($mine): ?>
  <form method="post" action="<?= $e($url('/calc/s/' . $row['token'] . '/delete')) ?>" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('button', ['label' => $t('calc.unshare'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?>
  </form>
  <?php endif; ?>
</section>
