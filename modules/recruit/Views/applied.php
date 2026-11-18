<?php
/** Application received — the code for the status check. @var callable $e @var callable $t @var callable $ui @var callable $url @var string $code @var array<string, mixed> $job */
?>
<?= $ui('page-header', ['title' => $t('recruit.applied_title'), 'subtitle' => (string) $job['title']]) ?>
<section class="card stack-sm applied-card" aria-labelledby="ap-h">
  <h2 id="ap-h" class="card__title"><?= $ui('icon', ['name' => 'circle-check']) ?> <?= $e($t('recruit.applied_thanks')) ?></h2>
  <p><?= $e($t('recruit.applied_code')) ?></p>
  <p class="applied-code mono" aria-label="<?= $e($t('recruit.field.app_code')) ?>"><?= $e($code) ?></p>
  <p class="text-sm text-muted"><?= $e($t('recruit.applied_keep')) ?></p>
  <div class="row"><?= $ui('button', ['label' => $t('recruit.status_title'), 'href' => $url('/recruit/status'), 'variant' => 'secondary', 'icon' => 'search']) ?>
    <a href="<?= $e($url('/recruit/jobs')) ?>"><?= $e($t('recruit.jobs_back')) ?></a></div>
</section>
