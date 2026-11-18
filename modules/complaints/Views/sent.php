<?php
/**
 * Shown once after sending: the reference and the tracking code (not kept anywhere in readable form).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array{ref: string, code: string, remembered: bool} $res
 */
?>
<?= $ui('page-header', ['title' => $t('complaints.sent.title'), 'subtitle' => $t('complaints.sent.subtitle', ['ref' => $res['ref']])]) ?>
<section class="card stack" aria-labelledby="cp-code-h">
  <h2 id="cp-code-h" class="card__title"><?= $e($t('complaints.sent.code_title')) ?></h2>
  <p class="cp-code" id="cp-code-value"><?= $e($res['code']) ?></p>
  <label class="field__label" for="cp-code-copy"><?= $e($t('complaints.sent.copy_label')) ?></label>
  <input class="input" id="cp-code-copy" readonly value="<?= $e($res['code']) ?>">
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('complaints.sent.warning')]) ?>
  <?php if ($res['remembered']): ?><p class="text-sm"><?= $e($t('complaints.sent.remembered')) ?></p><?php endif; ?>
  <ul class="stack-sm text-sm">
    <li><?= $e($t('complaints.sent.next1')) ?></li>
    <li><?= $e($t('complaints.sent.next2')) ?></li>
  </ul>
  <div class="row"><a class="btn btn--secondary" href="<?= $e($url('/complaints')) ?>"><?= $e($t('complaints.sent.back')) ?></a></div>
</section>
