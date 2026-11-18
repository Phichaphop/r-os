<?php
/**
 * Online signing inbox: waiting for me, what I signed or declined, and what I sent.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{todo: list<array<string, mixed>>, mine: list<array<string, mixed>>, sent: list<array<string, mixed>>} $box @var bool $canSend @var bool $canManage @var string $today
 */
include __DIR__ . '/_status.php';
$actions = ($canSend ? $ui('button', ['label' => $t('esign.new_title'), 'href' => $url('/esign/new'), 'variant' => 'primary', 'icon' => 'plus']) : '')
    . $ui('button', ['label' => $t('esign.verify_title'), 'href' => $url('/esign/verify'), 'variant' => 'secondary', 'icon' => 'shield-check'])
    . ($canManage ? $ui('button', ['label' => $t('esign.admin_title'), 'href' => $url('/esign/admin'), 'variant' => 'ghost', 'icon' => 'list-check']) : '');
?>
<?= $ui('page-header', ['title' => $t('esign.title'), 'subtitle' => $t('esign.subtitle'), 'actions' => $actions]) ?>
<?php foreach (['todo' => 'esign.to_sign', 'mine' => 'esign.signed_by_me', 'sent' => 'esign.sent_by_me'] as $g => $label): if ($g === 'sent' && !$canSend && $box['sent'] === []) { continue; } ?>
<section class="card stack-sm" aria-labelledby="ei-<?= $e($g) ?>">
  <h2 id="ei-<?= $e($g) ?>" class="card__title"><?= $e($t($label)) ?><?= $g === 'todo' && $box['todo'] !== [] ? ' (' . count($box['todo']) . ')' : '' ?></h2>
  <?php if ($box[$g] === []): ?><p class="text-sm text-muted"><?= $e($t($g === 'todo' ? 'esign.nothing_to_sign' : 'esign.none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($box[$g] as $x): ?>
      <li><a href="<?= $e($url('/esign/r/' . $x['public_id'])) ?>"><?= $e((string) $x['title']) ?></a> <?= $stBadge((string) $x['status']) ?>
        <span class="d-block text-sm text-muted"><?= $e(($x['request_no'] ?? '') !== '' ? $x['request_no'] . ' · ' : '') ?><?= $e($t('esign.signed_of', ['n' => (string) $x['n_signed'], 'total' => (string) $x['n_signers']])) ?><?php if (in_array($x['status'], ['sent', 'partial'], true)): ?> · <?= $e($t('esign.until', ['d' => $date((string) $x['expires_on'], false)])) ?><?php endif; ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endforeach; ?>
