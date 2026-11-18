<?php
/**
 * Finance sub-navigation + company switcher + DEC-50 activation banner.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var string $tab @var int $le
 * @var array<int, string> $leOptions @var string $mode
 */
$tabs = ['overview' => '/finance', 'journals' => '/finance/journals', 'requests' => '/finance/requests', 'accounts' => '/finance/accounts',
    'rules' => '/finance/rules', 'periods' => '/finance/periods', 'trial' => '/finance/trial-balance'];
$here = $tabs[$tab] ?? '/finance';
?>
<div class="row row--between finance-bar">
  <nav class="tabs__list subnav" aria-label="<?= $e($t('finance.title')) ?>">
    <?php foreach ($tabs as $k => $href): ?>
      <a class="tabs__tab" href="<?= $e($url($href . '?le=' . $le)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $e($t('finance.tab.' . $k)) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (count($leOptions) > 1): ?>
  <form method="get" action="<?= $e($url($here)) ?>" class="row row--tight">
    <label class="sr-only" for="fin-le"><?= $e($t('finance.field.company')) ?></label>
    <select id="fin-le" name="le" class="select select--sm"><?php foreach ($leOptions as $id => $label): ?><option value="<?= (int) $id ?>"<?= (int) $id === $le ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
    <?= $ui('button', ['label' => $t('finance.switch'), 'type' => 'submit', 'size' => 'sm']) ?>
  </form>
  <?php endif; ?>
</div>
<?php if ($mode !== 'live'): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('finance.demo_only.title'), 'text' => $t('finance.demo_only.text')]) ?>
<?php endif; ?>
