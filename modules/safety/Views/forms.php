<?php
/**
 * Internal safety forms to print (0.45.0 · DEC-114) — drafted by the AI, to be checked before use; not government forms.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var bool $isTemplate @var bool $canManage @var string $here
 */
$forms = ['report' => 'file-text', 'near-miss' => 'alert-triangle', 'witness' => 'users', 'risk' => 'chart-bar'];
?>
<?= $ui('page-header', ['title' => $t('safety.forms.title'), 'subtitle' => $t('safety.forms.subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?= $ui('alert', ['tone' => 'warning', 'text' => $t('safety.forms.ai_label')]) ?>
<?php if ($isTemplate): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('safety.forms.no_policy')]) ?><?php endif; ?>
<ul class="sf-forms" role="list">
  <?php foreach ($forms as $f => $icon): ?>
  <li class="card stack-sm">
    <h2 class="card__title"><?= $ui('icon', ['name' => $icon]) ?> <?= $e($t('safety.forms.' . $f)) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('safety.forms.' . $f . '_help')) ?></p>
    <div class="row"><a class="btn btn--secondary" href="<?= $e($url('/safety/forms/' . $f)) ?>"><?= $ui('icon', ['name' => 'printer', 'size' => 'sm']) ?> <?= $e($t('safety.forms.open')) ?></a></div>
  </li>
  <?php endforeach; ?>
</ul>
<p class="text-sm text-muted"><?= $e($t('safety.forms.enter_note')) ?></p>
