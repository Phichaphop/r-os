<?php
/** An anonymous report was sent: its tracking code, shown once. @var callable $e @var callable $t @var callable $ui @var callable $url @var string $ref @var string $code @var bool $canManage @var string $here */
?>
<?= $ui('page-header', ['title' => $t('safety.sent_title'), 'subtitle' => $t('safety.sent_subtitle', ['ref' => $ref])]) ?>
<section class="card stack-sm" aria-labelledby="sf-code">
  <h2 id="sf-code" class="card__title"><?= $e($t('safety.your_code')) ?></h2>
  <p class="sf-code"><?= $e($code) ?></p>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('safety.code_once')]) ?>
  <p><a href="<?= $e($url('/safety/track')) ?>"><?= $e($t('safety.track_link')) ?></a></p>
</section>
