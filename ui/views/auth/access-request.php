<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<string> $roles @var array<string, array<int, string>> $scopeOptions @var int $orgId
 * @var array<string, string> $errors @var array<string, string> $old @var array<string, mixed>|null $appInfo
 */
$scopes = ['organization:' . $orgId => $t('iam.scope.organization')];
foreach (['legal_entity', 'site', 'department'] as $lv) {
    foreach ($scopeOptions[$lv] ?? [] as $id => $label) {
        $scopes[$lv . ':' . $id] = $t('iam.scope.' . $lv) . ' ' . $label;
    }
}
?>
<?= $ui('page-header', ['title' => $t('access.request.title'), 'subtitle' => $t('access.request.subtitle')]) ?>
<?php if (($appInfo ?? null) !== null): ?>
<?= $ui('alert', ['tone' => 'info', 'title' => $t('access.request.for_app', ['app' => (string) $appInfo['label']]), 'text' => $t('access.request.for_app_text')]) ?>
<?php endif; ?>
<section class="card">
  <form method="post" action="<?= $e($url('/me/access')) ?>" class="form-grid" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?php if (($appInfo ?? null) !== null): ?><input type="hidden" name="app" value="<?= $e((string) $appInfo['key']) ?>"><?php endif; ?>
    <?= $ui('field', ['name' => 'role', 'label' => $t('access.field.role'), 'type' => 'select', 'required' => true, 'value' => $old['role'] ?? '', 'error' => $errors['role'] ?? null,
        'options' => ['' => '—'] + array_combine($roles, array_map(static fn ($r) => $r . ' — ' . $t('role.' . $r), $roles))]) ?>
    <?= $ui('field', ['name' => 'scope', 'label' => $t('access.field.scope'), 'type' => 'select', 'required' => true, 'value' => $old['scope'] ?? '', 'error' => $errors['scope'] ?? null, 'options' => ['' => '—'] + $scopes]) ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'type' => 'textarea', 'required' => true, 'value' => $old['reason'] ?? '', 'error' => $errors['reason'] ?? null, 'full' => true, 'help' => $t('access.request.reason_help')]) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('access.request.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
