<?php
/**
 * New event (draft).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var array<string, mixed> $audIn
 * @var array<string, string> $errors @var array<string, mixed> $old @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('events.new_event'), 'subtitle' => $t('events.new_subtitle'), 'crumbs' => [[$t('events.manage_title'), $url('/events/manage')], [$t('events.new_event'), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/events/manage/new')) ?>" class="card stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?php $fill = $old; $ferr = $errors; include __DIR__ . '/_fields.php'; ?>
  <div class="row"><?= $ui('button', ['label' => $t('events.save_draft'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
</form>
