<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $ev @var list<array<string, mixed>> $prizes @var list<array<string, mixed>> $winners @var string|null $seed @var array<string, mixed>|null $verify @var int $count
 */
use Blue\Modules\Draw\DrawEngine;

$simple = ($ev['style'] ?? 'classic') === 'simple';
$byPrize = [];
foreach ($winners as $w) {
    $byPrize[(int) $w['prize_id']][] = $w;
}
$inOrder = $winners; // simple draws: one list in the order drawn (0.11.2)
usort($inOrder, static fn (array $a, array $b): int => (int) $a['seq_no'] <=> (int) $b['seq_no']);
?>
<?= $ui('page-header', ['title' => (string) $ev['name'], 'subtitle' => $t('draw.results_subtitle', ['at' => $date((string) ($ev['confirmed_at'] ?? $ev['updated_at']))])]) ?>
<p><a href="<?= $e($url($simple ? '/draw' : '/draw/' . $ev['public_id'])) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('draw.back')) ?></a></p>
<?php if ($ev['status'] !== 'confirmed' && $ev['status'] !== 'archived'): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('draw.not_confirmed')]) ?><?php endif; ?>
<?php if ((string) ($ev['description'] ?? '') !== ''): ?><p class="pre-line"><?= $e($ev['description']) ?></p><?php endif; ?>
<div class="settings-grid">
  <section class="card stack-sm" aria-labelledby="rs-w">
    <h2 id="rs-w" class="card__title"><?= $e($t('draw.winners')) ?></h2>
    <?php if ($simple): ?>
      <ol class="plain-list draw-winners">
        <?php foreach ($inOrder as $w): ?>
          <li><span><span class="draw-seq" aria-label="<?= $e($t('draw.simple.place', ['n' => (string) $w['seq_no']])) ?>"><?= (int) $w['seq_no'] ?></span><strong><?= $e($w['name']) ?></strong> · <?= $e($w['prize_name']) ?></span>
            <span class="text-sm text-muted"><?= $e($t('draw.round_n', ['n' => (string) $w['round_no']])) ?> · <?= $e($date((string) $w['drawn_at'])) ?></span></li>
        <?php endforeach; ?>
      </ol>
      <div class="row">
        <?= $ui('button', ['label' => $t('draw.export_xlsx'), 'href' => $url('/draw/' . $ev['public_id'] . '/export'), 'variant' => 'secondary', 'icon' => 'download']) ?>
        <?= $ui('button', ['label' => 'CSV', 'href' => $url('/draw/' . $ev['public_id'] . '/export?format=csv'), 'variant' => 'ghost', 'icon' => 'download']) ?>
      </div>
    <?php endif; ?>
    <?php foreach ($simple ? [] : $prizes as $z): ?>
      <h3 class="text-sm"><?= $ui('icon', ['name' => 'gift', 'size' => 'sm']) ?> <?= $e($z['name']) ?></h3>
      <?php if (($byPrize[(int) $z['id']] ?? []) === []): ?><p class="text-sm text-muted"><?= $e($t('draw.not_drawn')) ?></p><?php endif; ?>
      <ol class="plain-list draw-winners">
        <?php foreach ($byPrize[(int) $z['id']] ?? [] as $w): ?>
          <li class="<?= $w['status'] === 'void' ? 'is-void' : '' ?>"><span><?php if ($w['seq_no'] !== null): ?><span class="draw-seq" aria-label="<?= $e($t('draw.seq.place', ['n' => (string) $w['seq_no']])) ?>"><?= (int) $w['seq_no'] ?></span><?php endif; ?><strong><?= $e($w['name']) ?></strong><?= $w['grp'] ? ' · ' . $e($w['grp']) : '' ?>
            <?php if ($w['status'] === 'void'): ?><span class="d-block text-sm text-muted"><?= $e($t('draw.winner.void')) ?> — <?= $e($w['void_reason']) ?></span><?php endif; ?>
            <?php if ($w['round_reason']): ?><span class="d-block text-sm text-muted"><?= $e($t('draw.redraw_because', ['reason' => (string) $w['round_reason']])) ?></span><?php endif; ?></span></li>
        <?php endforeach; ?>
      </ol>
    <?php endforeach; ?>
  </section>
  <section class="card stack-sm" aria-labelledby="rs-v">
    <h2 id="rs-v" class="card__title"><?= $e($t('draw.fairness')) ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('draw.algorithm')) ?></dt><dd><?= $e(DrawEngine::ALGORITHM) ?></dd></div>
      <div><dt><?= $e($t('draw.col.participants')) ?></dt><dd><?= number_format($count) ?></dd></div>
      <div><dt><?= $e($t('draw.commitment')) ?></dt><dd class="mono break-all text-sm"><?= $e($ev['commitment']) ?></dd></div>
      <?php if (!$simple): ?><div><dt><?= $e($t('draw.snapshot')) ?></dt><dd class="mono break-all text-sm"><?= $e($ev['snapshot_hash']) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('draw.seed')) ?></dt><dd class="mono break-all text-sm"><?= $e($seed ?? $t('draw.seed_hidden')) ?></dd></div>
      <?php if ($verify !== null): ?><div><dt><?= $e($t('draw.verify')) ?></dt><dd><?= $verify['ok'] ? $ui('badge', ['tone' => 'success', 'icon' => 'shield-check', 'label' => $t('draw.verify_ok')]) : $ui('badge', ['tone' => 'danger', 'icon' => 'alert-triangle', 'label' => $t('draw.verify_bad')]) ?></dd></div><?php endif; ?>
      <?php if ($ev['witness']): ?><div><dt><?= $e($t('draw.field.witness')) ?></dt><dd><?= $e($ev['witness']) ?></dd></div><?php endif; ?>
    </dl>
    <p class="text-sm text-muted"><?= $e($t($simple ? 'draw.simple.how_verify' : 'draw.how_verify')) ?></p>
  </section>
</div>
