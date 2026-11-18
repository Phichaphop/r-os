<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var int $status @var string $title @var string $hint @var string $reference @var ?string $debugDetail */
?>
<?= $ui('states', [
    'kind' => $status === 403 ? 'forbidden' : ($status >= 500 ? 'error' : 'empty'),
    'icon' => $status === 404 ? 'search' : null,
    'title' => $title,
    'text' => $hint,
    'reference' => $reference,
    'action' => $ui('button', ['label' => $t('common.back_home'), 'href' => $url('/'), 'variant' => 'primary', 'icon' => 'home']),
]) ?>
<?php if ($debugDetail !== null): ?>
  <details><summary><?= $e($t('error.debug_detail')) ?></summary><pre class="debug-trace"><?= $e($debugDetail) ?></pre></details>
<?php endif; ?>
