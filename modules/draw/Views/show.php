<?php
/**
 * The draw screen (0.11.1: opens on the wheel / start line itself; click the wheel or press Space to draw).
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $ev @var list<array<string, mixed>> $prizes @var list<array<string, mixed>> $winners @var list<string> $names
 */
$base = '/draw/' . $ev['public_id'];
$open = in_array($ev['status'], ['ready', 'drawing'], true);
$active = array_values(array_filter($winners, static fn (array $w): bool => $w['status'] === 'active'));
$seqMode = ($ev['prize_mode'] ?? 'prizes') === 'sequence';
$removeDrawn = (int) $ev['no_repeat'] === 1;
$left = array_sum(array_map(static fn (array $z): int => max(0, (int) $z['quantity'] - (int) $z['won']), $prizes));
$tr = ['drawing' => $t('draw.show.drawing'), 'winner' => $t('draw.show.winner'), 'done' => $t('draw.show.prize_done'), 'error' => $t('draw.show.error'),
    'remaining' => $t('draw.show.remaining'), 'countdown' => $t('draw.show.countdown'), 'seq' => $t('draw.seq.place', ['n' => '{n}']), 'next' => $t('draw.show.next'),
    'all_done' => $t('draw.show.all_done'), 'hint' => $t('draw.show.hint'), 'spin' => $t('draw.quick.spin_hub'), 'photo' => $t('draw.race.photo'), 'sec' => $t('draw.race.sec')];
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<div class="draw-stage stack" data-draw data-endpoint="<?= $e($url($base . '/round')) ?>" data-csrf="<?= $e($v['csrf'] ?? '') ?>" data-mode="<?= $e($ev['mode']) ?>"
  data-remove="<?= $removeDrawn ? '1' : '0' ?>" data-names="<?= $e((string) json_encode($names, JSON_UNESCAPED_UNICODE)) ?>" data-t="<?= $e((string) json_encode($tr, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="draw-stage__head">
    <div>
      <h1 class="draw-stage__title"><?= $e($ev['name']) ?></h1>
      <p class="text-sm text-muted"><?= $e($t('draw.show.fair', ['hash' => substr((string) $ev['commitment'], 0, 16)])) ?></p>
    </div>
    <div class="row">
      <?php if ($open): ?>
      <form method="post" action="<?= $e($url($base . '/mode')) ?>" class="seg" aria-label="<?= $e($t('draw.field.mode')) ?>">
        <?= $csrf ?>
        <?php foreach (['wheel' => 'refresh', 'race' => 'arrow-right'] as $m => $icon): ?>
          <button type="submit" name="mode" value="<?= $m ?>" class="seg__btn<?= $ev['mode'] === $m ? ' is-on' : '' ?>" aria-pressed="<?= $ev['mode'] === $m ? 'true' : 'false' ?>"><?= $ui('icon', ['name' => $icon, 'size' => 'sm']) ?><?= $e($t('draw.mode.' . $m)) ?></button>
        <?php endforeach; ?>
      </form>
      <?php endif; ?>
      <button type="button" class="btn btn--ghost" data-draw-fullscreen><?= $ui('icon', ['name' => 'layout-dashboard', 'size' => 'sm']) ?><?= $e($t('draw.show.fullscreen')) ?></button>
      <a class="btn btn--ghost" href="<?= $e($url($base)) ?>"><?= $ui('icon', ['name' => 'chevron-left', 'size' => 'sm']) ?><?= $e($t('draw.show.exit')) ?></a>
    </div>
  </div>
  <?php if (!$open): ?>
    <?= $ui('alert', ['tone' => 'success', 'text' => $t('draw.show.confirmed')]) ?>
  <?php endif; ?>
  <div class="draw-canvas-wrap">
    <canvas class="draw-canvas<?= $open ? ' is-clickable' : '' ?>" width="960" height="560" data-draw-canvas role="img" aria-label="<?= $e($t($ev['mode'] === 'race' ? 'draw.mode.race' : 'draw.mode.wheel')) ?>"></canvas>
    <div class="draw-overlay" data-draw-overlay hidden></div>
  </div>
  <?php if ($open): ?>
  <div class="draw-controls">
    <?php if (!$seqMode): ?>
    <div class="field">
      <label class="field__label" for="dw-prize"><?= $e($t('draw.col.prize')) ?></label>
      <select class="input" id="dw-prize" data-draw-prize>
        <?php foreach ($prizes as $z): $l = (int) $z['quantity'] - (int) $z['won']; ?>
          <option value="<?= (int) $z['id'] ?>" data-left="<?= $l ?>"<?= $l === 0 ? ' disabled' : '' ?>><?= $e($z['name']) ?> (<?= $e($t('draw.show.left', ['n' => (string) $l])) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php else: ?>
      <?php foreach ($prizes as $z): $l = (int) $z['quantity'] - (int) $z['won']; ?>
        <input type="hidden" value="<?= (int) $z['id'] ?>" data-left="<?= $l ?>" data-draw-prize>
        <p class="draw-left" data-draw-left><?= $e($t('draw.show.places_left', ['n' => (string) $l])) ?></p>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="field draw-controls__count">
      <label class="field__label" for="dw-count"><?= $e($t('draw.show.count')) ?></label>
      <input class="input" id="dw-count" type="number" min="1" value="1" inputmode="numeric" data-draw-count>
    </div>
    <button type="button" class="btn btn--primary draw-go" data-draw-go><?= $ui('icon', ['name' => 'refresh']) ?><?= $e($t('draw.show.go')) ?></button>
    <details class="draw-more draw-controls__opts">
      <summary><?= $e($t('draw.show.options')) ?></summary>
      <div class="row">
        <label class="check"><input type="checkbox" checked data-draw-sound> <?= $e($t('draw.show.sound')) ?></label>
        <label class="check"><input type="checkbox" data-draw-countdown> <?= $e($t('draw.show.countdown_on')) ?></label>
      </div>
    </details>
  </div>
  <p class="text-sm text-muted"><?= $e($t('draw.show.hint')) ?></p>
  <?php endif; ?>
  <div class="draw-announce" aria-live="assertive" data-draw-announce></div>
  <section class="draw-board" aria-labelledby="dw-board">
    <h2 id="dw-board" class="card__title"><?= $e($t('draw.show.board')) ?></h2>
    <ol class="draw-board__list" data-draw-board>
      <?php foreach ($active as $w): ?><li><span class="draw-board__prize"><?= $e($w['seq_no'] !== null ? $t('draw.seq.place', ['n' => (string) $w['seq_no']]) : $w['prize_name']) ?></span> <strong><?= $e($w['name']) ?></strong><?= $w['grp'] ? ' <span class="text-muted">' . $e($w['grp']) . '</span>' : '' ?></li><?php endforeach; ?>
    </ol>
  </section>
  <?php if ($open): ?>
  <section class="card stack-sm draw-finish<?= $left === 0 ? ' is-ready' : '' ?>" aria-labelledby="dw-fin" data-draw-finish<?= $ev['status'] === 'drawing' ? '' : ' hidden' ?>>
    <h2 id="dw-fin" class="card__title"><?= $ui('icon', ['name' => 'circle-check', 'size' => 'sm']) ?> <?= $e($t('draw.finish_title')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('draw.confirm_help')) ?></p>
    <form method="post" action="<?= $e($url($base . '/confirm')) ?>" class="row" novalidate>
      <?= $csrf ?>
      <?= $ui('field', ['name' => 'witness', 'id' => 'dw-witness', 'label' => $t('draw.field.witness'), 'help' => $t('draw.witness_help')]) ?>
      <?= $ui('button', ['label' => $t('draw.confirm'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check']) ?>
    </form>
  </section>
  <?php endif; ?>
</div>
<script src="<?= $e($asset('ui/js/race-plan.js')) ?>" defer></script>
<script src="<?= $e($asset('ui/js/draw.js')) ?>" defer></script>
