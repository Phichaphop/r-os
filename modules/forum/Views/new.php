<?php
/**
 * Start a thread: category, title, text, up to three images / PDFs.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var list<array<string, mixed>> $categories
 * @var array<string, string> $errors @var array<string, mixed> $old @var bool $canModerate @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$copts = ['' => $t('forum.pick')];
foreach ($categories as $c) {
    $copts[(string) $c['public_id']] = (string) $c['name'];
}
?>
<?= $ui('page-header', ['title' => $t('forum.new_thread'), 'subtitle' => $t('forum.new_subtitle'), 'crumbs' => [[$t('forum.title'), $url('/forum')], [$t('forum.new_thread'), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/forum/new')) ?>" enctype="multipart/form-data" class="card stack-sm" novalidate><?= $csrf ?>
  <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('forum.field.category'), 'required' => true, 'value' => (string) ($old['category'] ?? ''), 'options' => $copts, 'error' => $errors['category'] ?? null]) ?>
  <?= $ui('field', ['name' => 'title', 'label' => $t('forum.field.title'), 'required' => true, 'value' => (string) ($old['title'] ?? ''), 'full' => true, 'error' => $errors['title'] ?? null]) ?>
  <?= $ui('field', ['name' => 'body', 'type' => 'textarea', 'label' => $t('forum.field.body'), 'required' => true, 'value' => (string) ($old['body'] ?? ''), 'help' => $t('forum.body_help'), 'error' => $errors['body'] ?? null]) ?>
  <div class="field">
    <label class="field__label" for="f-files"><?= $e($t('forum.field.files')) ?> <span class="field__req"><?= $e($t('form.optional_tag')) ?></span></label>
    <input class="input" type="file" id="f-files" name="files[]" multiple accept=".png,.jpg,.jpeg,.webp,.pdf" aria-describedby="f-files-help<?= isset($errors['files']) ? ' f-files-err' : '' ?>">
    <p class="field__help" id="f-files-help"><?= $e($t('forum.files_help')) ?></p>
    <?php if (isset($errors['files'])): ?><p class="field__error" id="f-files-err"><?= $e($errors['files']) ?></p><?php endif; ?>
  </div>
  <p class="text-sm text-muted"><?= $e($t('forum.rules_note')) ?></p>
  <div class="row"><?= $ui('button', ['label' => $t('forum.post'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
</form>
