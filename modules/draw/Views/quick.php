<?php
/**
 * Quick draw (0.8.1 → 0.11.1): the wheel (or race) first, names beside it; click to spin; nothing stored.
 * Without JavaScript the form still draws on the server and lists the winners.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset
 * @var array<string, mixed> $v @var array<string, string> $old @var array<string, string> $errors @var list<string>|null $winners @var int $total @var bool $guest
 */
$full = !$guest ? $ui('button', ['label' => $t('draw.quick.full'), 'href' => $url('/draw'), 'variant' => 'secondary', 'icon' => 'gift']) : '';
?>
<?= $ui('page-header', ['title' => $t('draw.quick.title'), 'subtitle' => $t('draw.quick.subtitle'), 'actions' => $full]) ?>
<?php if ($winners !== null): ?>
<section class="card stack-sm quick-draw__result" aria-labelledby="qd-r" aria-live="polite">
  <h2 id="qd-r" class="card__title"><?= $ui('icon', ['name' => 'gift']) ?> <?= $e($t('draw.quick.result', ['k' => count($winners), 'n' => number_format($total)])) ?></h2>
  <ol class="quick-draw__list">
    <?php foreach ($winners as $w): ?><li><?= $e($w) ?></li><?php endforeach; ?>
  </ol>
  <p class="text-sm text-muted"><?= $e($t('draw.quick.not_saved')) ?></p>
</section>
<?php endif; ?>
<?php $wq = ['saved' => false, 'event' => '', 'title' => '', 'names' => (string) ($old['names'] ?? ''), 'count' => (string) ($old['count'] ?? '1'), 'take_out' => false, 'errors' => $errors, 'history' => [],
    'note' => $t($guest ? 'draw.quick.note_guest' : 'draw.quick.note_user')];
include __DIR__ . '/_wheel.php'; ?>
<script src="<?= $e($asset('ui/js/race-plan.js')) ?>" defer></script>
<script src="<?= $e($asset('ui/js/draw.js')) ?>" defer></script>
