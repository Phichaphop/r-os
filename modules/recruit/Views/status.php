<?php
/** Status check with the application code + phone number. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string>|null $result @var bool $tried @var string $code */
?>
<?= $ui('page-header', ['title' => $t('recruit.status_title'), 'subtitle' => $t('recruit.status_subtitle'), 'crumbs' => [[$t('recruit.jobs_title'), $url('/recruit/jobs')], [$t('recruit.status_title'), null]]]) ?>
<?php if ($result !== null): ?>
  <?= $ui('alert', ['tone' => in_array($result['stage'], ['closed', 'withdrawn'], true) ? 'neutral' : 'info', 'title' => $result['title'], 'text' => $t('recruit.public_stage.' . $result['stage'])]) ?>
<?php elseif ($tried): ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('recruit.status_not_found')]) ?>
<?php endif; ?>
<section class="card stack" aria-labelledby="st-h">
  <h2 id="st-h" class="card__title"><?= $e($t('recruit.status_check')) ?></h2>
  <form method="post" action="<?= $e($url('/recruit/status')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'code', 'label' => $t('recruit.field.app_code'), 'required' => true, 'value' => $code, 'autocomplete' => 'off']) ?>
      <?= $ui('field', ['name' => 'phone', 'type' => 'tel', 'label' => $t('recruit.field.phone'), 'required' => true, 'inputmode' => 'tel', 'autocomplete' => 'tel']) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('recruit.status_check'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'search']) ?></div>
  </form>
</section>
