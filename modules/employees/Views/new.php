<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, array<int, string>> $org @var array<int, string> $managers @var array<string, string> $errors @var array<string, mixed> $old
 */
?>
<?= $ui('page-header', ['title' => $t('employees.new'), 'subtitle' => $t('employees.new_subtitle'),
    'actions' => $ui('button', ['label' => $t('employees.title'), 'href' => $url('/employees'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<form method="post" action="<?= $e($url('/employees')) ?>" class="stack" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <section class="card stack" aria-labelledby="en-p">
    <h2 id="en-p" class="card__title"><?= $e($t('employees.section.person')) ?></h2>
    <?php include __DIR__ . '/_person.php'; ?>
  </section>
  <section class="card stack" aria-labelledby="en-j">
    <h2 id="en-j" class="card__title"><?= $e($t('employees.section.job')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('employees.first_job_help')) ?></p>
    <?php include __DIR__ . '/_job.php'; ?>
  </section>
  <div class="row"><?= $ui('button', ['label' => $t('employees.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
</form>
