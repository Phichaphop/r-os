<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $errors @var array<string, string> $old */
?>
<?= $ui('page-header', ['title' => $t('iam.users.invite'), 'crumbs' => [[$t('iam.users.title'), $url('/admin/users')], [$t('iam.users.invite'), null]]]) ?>
<section class="card" aria-label="<?= $e($t('iam.users.invite')) ?>">
  <p class="text-muted"><?= $e($t('iam.users.invite_help')) ?></p>
  <form method="post" action="<?= $e($url('/admin/users/new')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <div class="form-grid">
      <?= $ui('field', ['name' => 'username', 'label' => $t('iam.field.username'), 'required' => true, 'value' => $old['username'] ?? '', 'error' => $errors['username'] ?? null, 'help' => $t('iam.field.username_help'), 'autocomplete' => 'off']) ?>
      <?= $ui('field', ['name' => 'display_name', 'label' => $t('iam.field.display_name'), 'required' => true, 'value' => $old['display_name'] ?? '', 'error' => $errors['display_name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'email', 'label' => $t('iam.field.email'), 'type' => 'email', 'value' => $old['email'] ?? '', 'error' => $errors['email'] ?? null, 'help' => $t('iam.field.email_help')]) ?>
      <?= $ui('field', ['name' => 'locale', 'label' => $t('iam.field.locale'), 'type' => 'select', 'value' => $old['locale'] ?? 'th', 'options' => ['th' => 'ไทย', 'en' => 'English']]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('iam.users.invite_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?>
      <?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url('/admin/users'), 'variant' => 'ghost']) ?></div>
  </form>
</section>
