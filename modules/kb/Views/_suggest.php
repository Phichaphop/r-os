<?php
/**
 * Matching answers for the help desk form (fragment, no layout).
 * @var callable $e @var callable $t @var callable $url @var callable $ui @var list<array<string, mixed>> $rows @var string $q
 */
?>
<div data-kb-results>
<?php if ($rows !== []): ?>
  <div class="alert alert--info kb-suggest">
    <p><strong><?= $e($t('kb.suggest_title')) ?></strong></p>
    <?php $items = $rows; include __DIR__ . '/_list.php'; ?>
  </div>
<?php endif; ?>
</div>
