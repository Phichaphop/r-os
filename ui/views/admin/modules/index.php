<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var list<array<string, mixed>> $rows */
?>
<?= $ui('page-header', ['title' => $t('module.title'), 'subtitle' => $t('module.subtitle')]) ?>
<?php if ($rows === []): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('module.none')]) ?>
<?php endif; ?>
<div class="grid grid--2">
<?php foreach ($rows as $m): $id = 'mod-' . $m['code']; ?>
  <section class="card stack-sm module-card" aria-labelledby="<?= $e($id) ?>">
    <div class="module-card__head">
      <span class="module-card__icon" aria-hidden="true"><?= $ui('icon', ['name' => $m['icon']]) ?></span>
      <div class="module-card__title">
        <h2 id="<?= $e($id) ?>" class="card__title"><?= $e($m['name']) ?></h2>
        <div class="text-sm text-muted mono"><?= $e($m['code']) ?> · v<?= $e($m['version']) ?> · <?= $e($t('module.pack.' . $m['pack'])) ?><?= $m['spec'] !== '' ? ' · ' . $e($m['spec']) : '' ?></div>
      </div>
      <?= $m['enabled']
        ? $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('module.on')])
        : $ui('badge', ['tone' => 'neutral', 'icon' => 'circle-x', 'label' => $m['switched_on'] ? $t('module.off_dependency') : $t('module.off')]) ?>
    </div>
    <p class="text-sm text-muted"><?= $e($t('module.counts', ['p' => $m['permissions'], 'c' => $m['config']])) ?><?= $m['depends_on'] !== [] ? ' · ' . $e($t('module.depends', ['list' => implode(', ', $m['depends_on'])])) : '' ?></p>
    <form method="post" action="<?= $e($url('/admin/modules/' . $m['code'] . '/toggle')) ?>" class="row row--end">
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <input type="hidden" name="enabled" value="<?= $m['switched_on'] ? '0' : '1' ?>">
      <label class="sr-only" for="<?= $e($id) ?>-reason"><?= $e($t('module.reason')) ?></label>
      <input class="input module-card__reason" id="<?= $e($id) ?>-reason" name="reason" required minlength="5" maxlength="200" placeholder="<?= $e($t('module.reason')) ?>">
      <?= $ui('button', ['label' => $m['switched_on'] ? $t('module.turn_off') : $t('module.turn_on'), 'type' => 'submit', 'variant' => $m['switched_on'] ? 'secondary' : 'primary']) ?>
    </form>
  </section>
<?php endforeach; ?>
</div>
<p class="text-sm text-muted"><?= $e($t('module.help')) ?></p>
