<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array<string, mixed>> $defs @var list<string> $categories @var string $category @var string $q
 */
use Blue\Core\Registry\ConfigValue;

?>
<?= $ui('page-header', ['title' => $t('config.title'), 'subtitle' => $t('config.subtitle')]) ?>
<form method="get" action="<?= $e($url('/admin/config')) ?>" class="filter-bar" role="search">
  <?= $ui('field', ['name' => 'q', 'label' => $t('common.search'), 'value' => $q, 'placeholder' => $t('config.search_hint')]) ?>
  <?= $ui('field', ['name' => 'category', 'label' => $t('config.field.category'), 'type' => 'select', 'value' => $category,
      'options' => ['' => $t('common.all')] + array_combine($categories, array_map(static fn ($c) => $t('config.category.' . $c), $categories))]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>
<div class="table-wrap" role="region" aria-label="<?= $e($t('config.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('config.title')) ?></caption>
    <thead><tr>
      <th scope="col"><?= $e($t('config.field.key')) ?></th><th scope="col"><?= $e($t('config.field.current')) ?></th>
      <th scope="col"><?= $e($t('config.field.source')) ?></th><th scope="col"><?= $e($t('config.field.risk')) ?></th><th scope="col"><?= $e($t('config.field.pending')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($defs as $d): $cur = $d['current']; ?>
      <tr>
        <td data-label="<?= $e($t('config.field.key')) ?>"><a class="mono" href="<?= $e($url('/admin/config/key/' . $d['config_key'])) ?>"><?= $e($d['config_key']) ?></a>
          <?php if ((int) $d['is_demo'] === 1): ?> <?= $ui('badge', ['tone' => 'neutral', 'label' => 'DEMO']) ?><?php endif; ?>
          <div class="text-sm text-muted"><?= $e($t('config.desc.' . $d['config_key'])) ?></div></td>
        <td data-label="<?= $e($t('config.field.current')) ?>"><?php if ($cur === null): ?>
            <?= $ui('badge', ['tone' => (int) $d['is_required'] === 1 ? 'warning' : 'neutral', 'icon' => (int) $d['is_required'] === 1 ? 'alert-triangle' : 'info-circle', 'label' => $t((int) $d['is_required'] === 1 ? 'config.no_value_required' : 'config.no_value')]) ?>
          <?php else: ?><span class="mono"><?= $e(ConfigValue::display($cur->value)) ?></span> <?= $e((string) $d['unit']) ?><?php endif; ?></td>
        <td data-label="<?= $e($t('config.field.source')) ?>"><?= $e($t('config.source.' . $d['source_type'])) ?></td>
        <td data-label="<?= $e($t('config.field.risk')) ?>"><?= $d['risk_level'] === 'high' ? $ui('badge', ['tone' => 'warning', 'icon' => 'shield-check', 'label' => $t('config.risk.high')]) : $e($t('config.risk.normal')) ?></td>
        <td data-label="<?= $e($t('config.field.pending')) ?>"><?php if ((int) $d['pending'] + (int) $d['scheduled'] === 0): ?>—<?php else: ?>
          <?php if ((int) $d['pending'] > 0): ?><?= $ui('badge', ['tone' => 'info', 'icon' => 'hourglass', 'label' => $t('config.pending_n', ['n' => (int) $d['pending']])]) ?><?php endif; ?>
          <?php if ((int) $d['scheduled'] > 0): ?><?= $ui('badge', ['tone' => 'info', 'icon' => 'calendar-event', 'label' => $t('config.scheduled_n', ['n' => (int) $d['scheduled']])]) ?><?php endif; ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($defs === []): ?><tr><td colspan="5"><?= $ui('states', ['title' => $t('config.empty')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="text-sm text-muted"><?= $e($t('config.note_no_legal_values')) ?></p>
