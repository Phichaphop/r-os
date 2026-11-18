<?php
/** Working shown step by step (7B item 3). @var callable $e @var callable $t @var array{steps: list<array{key: string, p: array<string, string>, v: string}>} $res */
?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('calc.steps')) ?>" tabindex="0">
  <table class="table">
    <caption class="sr-only"><?= $e($t('calc.steps')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('calc.steps')) ?></th><th scope="col" class="num"><?= $e($t('calc.value')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($res['steps'] as $s): ?><tr><td><?= $e($t($s['key'], $s['p'])) ?></td><td class="num mono"><?= $e($s['v']) ?></td></tr><?php endforeach; ?>
    </tbody>
  </table>
</div>
