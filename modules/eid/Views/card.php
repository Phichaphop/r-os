<?php
/**
 * The card on screen (pressed "show employee card"). @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $face @var array{url: string, svg: string, refresh: int}|null $qr @var bool $print
 */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
?>
<?= $ui('page-header', ['title' => $t('eid.card_title'), 'subtitle' => $t($face['status'] === 'active' ? 'eid.card_subtitle' : 'eid.card_void'), 'crumbs' => [[$t('eid.title'), $url('/eid')], [$t('eid.card_title'), null]],
    'actions' => $ui('button', ['label' => $t('eid.close'), 'href' => $url('/eid'), 'variant' => 'secondary', 'icon' => 'x'])]) ?>
<?php if ($face['status'] !== 'active'): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('eid.status.' . $face['status']), 'text' => $t('eid.void_text')]) ?><?php endif; ?>
<div class="eid-stage" data-eid-stage data-qr-url="<?= $e($url('/eid/card/qr')) ?>" data-msg-full="<?= $e($t('eid.fullscreen')) ?>" data-msg-exit="<?= $e($t('eid.fullscreen_exit')) ?>">
  <?= $cardFace($face, $qr, true) ?>
  <div class="row eid-stage__actions">
    <button type="button" class="btn btn--primary" data-eid-full hidden><?= $ui('icon', ['name' => 'eye']) ?><span><?= $e($t('eid.fullscreen')) ?></span></button>
    <?php if ($face['allow_print'] && $face['status'] === 'active' && !$print): ?><?= $ui('button', ['label' => $t('eid.print'), 'href' => $url('/eid/card?print=1'), 'variant' => 'secondary', 'icon' => 'download']) ?><?php endif; ?>
    <?= $ui('button', ['label' => $t('eid.close'), 'href' => $url('/eid'), 'variant' => 'ghost', 'icon' => 'x']) ?>
  </div>
  <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'shield-check', 'size' => 'sm']) ?> <?= $e($t($print ? 'eid.print_note' : 'eid.qr_note')) ?></p>
</div>
<?php if ($qr !== null && !$print): ?><script src="<?= $e($asset('ui/js/eid.js')) ?>" defer></script><?php endif; ?>
