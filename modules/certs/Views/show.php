<?php
/**
 * My request: status steps, information HR asked for, pickup code when ready, rating after receiving, my document.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $req @var list<array<string, mixed>> $events @var array<string, string> $errors
 */
$st = (string) $req['status'];
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/certs/r/' . $req['public_id'];
$steps = ['submitted', 'preparing', 'ready', 'received'];
$at = array_search($st === 'need_info' ? 'submitted' : $st, $steps, true);
?>
<?= $ui('page-header', ['title' => (string) $req['request_no'], 'subtitle' => (string) $req['name_th'], 'crumbs' => [[$t('certs.title'), $url('/certs')], [(string) $req['request_no'], null]]]) ?>
<?php if ($st !== 'cancelled'): ?>
<ol class="steps" aria-label="<?= $e($t('certs.progress')) ?>">
  <?php foreach ($steps as $i => $s): ?><li class="steps__item<?= $at !== false && $i < $at ? ' is-done' : '' ?><?= $i === $at ? ' is-current' : '' ?>"<?= $i === $at ? ' aria-current="step"' : '' ?>><?= $e($t('certs.status.' . $s)) ?></li><?php endforeach; ?>
</ol>
<?php endif; ?>
<?php if ($st === 'need_info'): ?>
<section class="card stack-sm" aria-labelledby="cs-i">
  <h2 id="cs-i" class="card__title"><?= $e($t('certs.need_info_title')) ?></h2>
  <p class="pre-line"><?= $e((string) $req['info_note']) ?></p>
  <form method="post" action="<?= $e($url($base . '/answer')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'answer', 'type' => 'textarea', 'label' => $t('certs.answer'), 'required' => true, 'error' => $errors['answer'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('certs.send_answer'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php elseif ($st === 'ready'): ?>
<section class="card stack-sm" aria-labelledby="cs-r">
  <h2 id="cs-r" class="card__title"><?= $e($t('certs.ready_title')) ?></h2>
  <p><?= $e($t('certs.ready_help')) ?></p>
  <p class="applied-code" aria-label="<?= $e($t('certs.pickup_code')) ?>"><?= $e((string) $req['pickup_code']) ?></p>
  <p class="text-sm text-muted"><?= $e($t('certs.proxy_help')) ?></p>
</section>
<?php elseif ($st === 'received'): ?>
<section class="card stack-sm" aria-labelledby="cs-d">
  <h2 id="cs-d" class="card__title"><?= $e($t('certs.received_title')) ?></h2>
  <p><?= $e($t('certs.received_by', ['name' => (string) $req['receiver_name'], 'at' => $date((string) $req['received_at'])])) ?></p>
  <p><?= $ui('button', ['label' => $t('certs.view_copy'), 'href' => $url($base . '/doc'), 'variant' => 'secondary', 'icon' => 'file-text']) ?></p>
  <?php if ($req['rating'] === null && $req['rating_waived_by'] === null): ?>
    <form method="post" action="<?= $e($url($base . '/rate')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('certs.rate_title')) ?> <span class="field__req"><?= $e($t('form.required_tag')) ?></span></legend>
        <div class="rating-row">
          <?php for ($i = 1; $i <= 5; $i++): ?><label class="rating-row__item"><input type="radio" name="rating" value="<?= $i ?>"> <span><?= $i ?></span><span class="sr-only"> <?= $e($t('certs.rating.' . $i)) ?></span></label><?php endfor; ?>
        </div>
        <p class="text-sm text-muted"><?= $e($t('certs.rating_scale')) ?></p>
        <?php if (isset($errors['rating'])): ?><p class="field__error"><?= $e($errors['rating']) ?></p><?php endif; ?>
      </fieldset>
      <?= $ui('field', ['name' => 'comment', 'type' => 'textarea', 'label' => $t('certs.rating_comment')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('certs.rate_send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
    </form>
  <?php elseif ($req['rating'] !== null): ?>
    <p class="text-sm text-muted"><?= $e($t('certs.rated', ['n' => (string) $req['rating']])) ?></p>
  <?php endif; ?>
</section>
<?php elseif ($st === 'cancelled'): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('certs.cancelled_note', ['reason' => (string) ($req['cancel_reason'] ?? '—')])]) ?>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="cs-x">
  <h2 id="cs-x" class="card__title"><?= $e($t('certs.details')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('certs.field.type')) ?></dt><dd><?= $e((string) $req['name_th']) ?></dd></div>
    <div><dt><?= $e($t('certs.field.language')) ?></dt><dd><?= $e($t('certs.lang.' . $req['language'])) ?></dd></div>
    <div><dt><?= $e($t('certs.field.copies')) ?></dt><dd><?= (int) $req['copies'] ?></dd></div>
    <div><dt><?= $e($t('certs.field.purpose')) ?></dt><dd><?= $e((string) $req['purpose']) ?></dd></div>
  </dl>
  <?php if (in_array($st, ['submitted', 'need_info', 'preparing'], true)): ?>
    <form method="post" action="<?= $e($url($base . '/cancel')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('certs.cancel_mine'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'x']) ?></form>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="cs-h">
  <h2 id="cs-h" class="card__title"><?= $e($t('certs.history')) ?></h2>
  <ol class="plain-list stack-sm">
    <?php foreach ($events as $ev): ?>
      <li><strong><?= $e($t('certs.event.' . $ev['action'])) ?></strong><?= $ev['note'] !== null && $ev['action'] !== 'rated' ? ' — ' . $e((string) $ev['note']) : '' ?>
        <span class="d-block text-sm text-muted"><?= $e((string) ($ev['actor'] ?? '—')) ?> · <?= $e($date((string) $ev['occurred_at'])) ?></span></li>
    <?php endforeach; ?>
  </ol>
</section>
