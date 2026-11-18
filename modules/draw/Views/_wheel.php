<?php
/**
 * The wheel screen shared by the quick draw and the saved lucky draw (0.11.2): wheel or race first, names beside it,
 * click to spin, winner dialog. $wq: saved (bool — spins go to the server and are kept), event (public id or ''),
 * title, names (text), count, take_out (bool), errors, history (list of lines), note.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array{saved: bool, event: string, title: string, names: string, count: string, take_out: bool, errors: array<string, string>, history: list<string>, note: string} $wq
 */
$tr = ['photo' => $t('draw.race.photo'), 'sec' => $t('draw.race.sec'), 'spin' => $t('draw.quick.spin_hub'), 'empty' => $t('draw.quick.empty'), 'count' => $t('draw.quick.tally'), 'more' => $t('draw.quick.more'), 'lanes' => $t('draw.quick.lanes'),
    'drawing' => $t('draw.show.drawing'), 'winner' => $t('draw.show.winner'), 'round' => $t('draw.quick.round'), 'need_names' => $t('draw.quick.error.names'),
    'copied' => $t('draw.quick.copied'), 'wheel' => $t('draw.mode.wheel'), 'race' => $t('draw.mode.race'), 'error' => $t('draw.show.error'), 'place' => $t('draw.simple.place')];
?>
<div class="qd" data-quick-draw data-t="<?= $e((string) json_encode($tr, JSON_UNESCAPED_UNICODE)) ?>"<?php if ($wq['saved']): ?> data-endpoint="<?= $e($url('/draw/spin')) ?>" data-csrf="<?= $e($v['csrf'] ?? '') ?>" data-event="<?= $e($wq['event']) ?>"<?php endif; ?>>
  <section class="card qd__stage" data-qd-stage data-qd-js hidden aria-label="<?= $e($t('draw.quick.stage')) ?>">
    <div class="qd__bar" data-qd-js hidden>
      <div class="seg" role="group" aria-label="<?= $e($t('draw.field.mode')) ?>">
        <button type="button" class="seg__btn is-on" data-qd-mode="wheel" aria-pressed="true"><?= $ui('icon', ['name' => 'refresh', 'size' => 'sm']) ?><?= $e($t('draw.mode.wheel')) ?></button>
        <button type="button" class="seg__btn" data-qd-mode="race" aria-pressed="false"><?= $ui('icon', ['name' => 'arrow-right', 'size' => 'sm']) ?><?= $e($t('draw.mode.race')) ?></button>
      </div>
      <div class="row">
        <label class="check"><input type="checkbox" checked data-qd-sound> <?= $e($t('draw.show.sound')) ?></label>
        <button type="button" class="btn btn--ghost btn--sm" data-qd-fullscreen><?= $ui('icon', ['name' => 'layout-dashboard', 'size' => 'sm']) ?><?= $e($t('draw.show.fullscreen')) ?></button>
      </div>
    </div>
    <canvas class="draw-canvas is-clickable" width="960" height="560" data-qd-canvas role="img" aria-label="<?= $e($t('draw.mode.wheel')) ?>"></canvas>
    <div class="qd__go" data-qd-js hidden>
      <button type="button" class="btn btn--primary draw-go" data-qd-spin><?= $ui('icon', ['name' => 'refresh']) ?><?= $e($t('draw.quick.spin')) ?></button>
      <p class="text-sm text-muted"><?= $e($t('draw.quick.hint')) ?></p>
    </div>
    <div class="draw-announce" aria-live="assertive" data-qd-announce></div>
  </section>
  <section class="card stack qd__panel" aria-labelledby="qd-h">
    <div class="qd__panel-head">
      <h2 id="qd-h" class="card__title"><?= $e($t('draw.quick.form')) ?></h2>
      <span class="badge badge--neutral" data-qd-tally data-qd-js hidden></span>
    </div>
    <form method="post" action="<?= $e($url('/draw/quick')) ?>" class="stack" novalidate data-qd-form>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <?php if ($wq['saved']): ?>
        <?= $ui('field', ['name' => 'title', 'id' => 'qd-title', 'label' => $t('draw.simple.title_field'), 'value' => $wq['title'], 'placeholder' => $t('draw.name_example'), 'help' => $t('draw.simple.title_help')]) ?>
      <?php endif; ?>
      <?= $ui('field', ['name' => 'names', 'type' => 'textarea', 'label' => $t('draw.quick.names'), 'required' => true, 'value' => $wq['names'], 'error' => $wq['errors']['names'] ?? null,
          'help' => $t('draw.quick.names_help'), 'placeholder' => $t('draw.quick.names_example')]) ?>
      <div class="row qd__tools" data-qd-js hidden>
        <button type="button" class="btn btn--ghost btn--sm" data-qd-tool="shuffle"><?= $ui('icon', ['name' => 'arrows-sort', 'size' => 'sm']) ?><?= $e($t('draw.quick.shuffle')) ?></button>
        <button type="button" class="btn btn--ghost btn--sm" data-qd-tool="sort"><?= $ui('icon', ['name' => 'sort-ascending', 'size' => 'sm']) ?><?= $e($t('draw.quick.sort')) ?></button>
        <button type="button" class="btn btn--ghost btn--sm" data-qd-tool="clear"><?= $ui('icon', ['name' => 'trash', 'size' => 'sm']) ?><?= $e($t('draw.quick.clear')) ?></button>
      </div>
      <?php if ($wq['saved']): ?>
        <?= $ui('field', ['name' => 'prize', 'id' => 'qd-prize', 'label' => $t('draw.simple.prize_field'), 'placeholder' => $t('draw.simple.prize_example'), 'help' => $t('draw.simple.prize_help')]) ?>
      <?php endif; ?>
      <?= $ui('field', ['name' => 'count', 'type' => 'number', 'label' => $t('draw.quick.count_each'), 'required' => true, 'value' => $wq['count'], 'error' => $wq['errors']['count'] ?? null,
          'min' => '1', 'max' => '200', 'inputmode' => 'numeric']) ?>
      <label class="check" data-qd-js hidden><input type="checkbox" data-qd-remove<?= $wq['take_out'] ? ' checked' : '' ?>> <?= $e($t('draw.quick.auto_remove')) ?></label>
      <?php if (!$wq['saved']): ?><div class="row" data-qd-nojs><?= $ui('button', ['label' => $t('draw.quick.submit'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'refresh']) ?></div><?php endif; ?>
    </form>
    <div class="stack-sm" data-qd-history-box<?= $wq['history'] === [] ? ' hidden' : '' ?>>
      <div class="qd__panel-head">
        <h3 class="text-sm"><?= $e($t($wq['saved'] ? 'draw.simple.history' : 'draw.quick.history')) ?></h3>
        <div class="row">
          <button type="button" class="btn btn--ghost btn--sm" data-qd-tool="copy"><?= $ui('icon', ['name' => 'copy', 'size' => 'sm']) ?><?= $e($t('draw.quick.copy')) ?></button>
          <?php if (!$wq['saved']): ?><button type="button" class="btn btn--ghost btn--sm" data-qd-tool="reset"><?= $ui('icon', ['name' => 'x', 'size' => 'sm']) ?><?= $e($t('draw.quick.reset')) ?></button><?php endif; ?>
        </div>
      </div>
      <ol class="qd__history" data-qd-history><?php foreach ($wq['history'] as $h): ?><li><?= $e($h) ?></li><?php endforeach; ?></ol>
    </div>
    <p class="text-sm text-muted"><?= $ui('icon', ['name' => $wq['saved'] ? 'shield-check' : 'info-circle', 'size' => 'sm']) ?> <?= $e($wq['note']) ?></p>
  </section>
  <dialog class="qd-dialog" data-qd-dialog aria-labelledby="qd-d-h">
    <p class="qd-dialog__eyebrow">🎉</p>
    <h2 id="qd-d-h" class="qd-dialog__title"><?= $e($t('draw.quick.we_have')) ?></h2>
    <ol class="qd-dialog__list" data-qd-winners></ol>
    <div class="row qd-dialog__actions">
      <button type="button" class="btn btn--secondary" data-qd-take-out><?= $ui('icon', ['name' => 'trash', 'size' => 'sm']) ?><?= $e($t('draw.quick.take_out')) ?></button>
      <button type="button" class="btn btn--primary" data-qd-again><?= $ui('icon', ['name' => 'refresh', 'size' => 'sm']) ?><?= $e($t('draw.quick.again')) ?></button>
      <button type="button" class="btn btn--ghost" data-qd-close><?= $e($t('draw.quick.close')) ?></button>
    </div>
  </dialog>
</div>
