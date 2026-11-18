<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array{key:string, ok:bool, detail:string}> $requirements @var array<string, string> $errors @var array<string, mixed> $old @var string $codePath @var int $min
 */
$err = static fn (string $k): ?string => $errors[$k] ?? null;
?>
<div class="stack-sm">
  <h1><?= $e($t('install.title')) ?></h1>
  <p class="text-muted"><?= $e($t('install.intro')) ?></p>
</div>

<?php if (!empty($errors['_form'])): ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('install.error.title'), 'text' => $errors['_form']]) ?>
<?php endif; ?>

<section aria-labelledby="req-h" class="stack-sm">
  <h2 id="req-h"><?= $e($t('install.step.requirements')) ?></h2>
  <ul role="list" class="stack-sm">
    <?php foreach ($requirements as $r): ?>
      <li class="row"><?= $ui('badge', $r['ok'] ? ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('install.req_ok')] : ['tone' => $r['key'] === 'req.config_writable' ? 'warning' : 'danger', 'icon' => 'alert-triangle', 'label' => $t('install.req_fail')]) ?>
        <span><?= $e($t('install.' . $r['key'])) ?></span> <span class="text-muted text-sm"><?= $e($r['detail']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>

<form method="post" action="<?= $e($url('/install')) ?>" class="stack" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <h2><?= $e($t('install.step.database')) ?></h2>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('install.db_note')]) ?>
  <div class="form-grid">
    <?= $ui('field', ['name' => 'setup_code', 'label' => $t('install.setup_code'), 'required' => true, 'help' => $t('install.setup_code_help', ['path' => $codePath]), 'error' => $err('setup_code'), 'autocomplete' => 'off', 'full' => true]) ?>
    <?= $ui('field', ['name' => 'db_host', 'label' => 'DB_HOST', 'required' => true, 'value' => $old['db_host'] ?? 'localhost', 'error' => $err('db_host')]) ?>
    <?= $ui('field', ['name' => 'db_port', 'label' => 'DB_PORT', 'type' => 'number', 'required' => true, 'value' => $old['db_port'] ?? '3306', 'error' => $err('db_port')]) ?>
    <?= $ui('field', ['name' => 'db_name', 'label' => 'DB_NAME', 'required' => true, 'value' => $old['db_name'] ?? '', 'error' => $err('db_name')]) ?>
    <?= $ui('field', ['name' => 'db_user', 'label' => 'DB_USER', 'required' => true, 'value' => $old['db_user'] ?? '', 'error' => $err('db_user'), 'autocomplete' => 'off']) ?>
    <?= $ui('field', ['name' => 'db_pass', 'label' => 'DB_PASS', 'type' => 'password', 'help' => $t('install.db_pass_help'), 'autocomplete' => 'new-password']) ?>
    <?= $ui('field', ['name' => 'app_env', 'label' => $t('install.app_env'), 'type' => 'select', 'required' => true, 'value' => $old['app_env'] ?? 'prototype', 'error' => $err('app_env'),
        'options' => ['prototype' => $t('install.env.prototype'), 'local' => $t('install.env.local'), 'staging' => $t('install.env.staging'), 'production' => $t('install.env.production')]]) ?>
  </div>
  <h2><?= $e($t('install.step.admin')) ?></h2>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('install.admin_note', ['min' => (string) ($min ?? 12)])]) ?>
  <div class="form-grid">
    <?= $ui('field', ['name' => 'org_name_th', 'label' => $t('install.org_name_th'), 'required' => true, 'value' => $old['org_name_th'] ?? '', 'error' => $err('org_name_th'), 'help' => $t('install.org_name_help')]) ?>
    <?= $ui('field', ['name' => 'org_name_en', 'label' => $t('install.org_name_en'), 'value' => $old['org_name_en'] ?? '']) ?>
    <?= $ui('field', ['name' => 'admin_username', 'label' => $t('iam.field.username'), 'required' => true, 'value' => $old['admin_username'] ?? '', 'error' => $err('admin_username'), 'autocomplete' => 'username']) ?>
    <?= $ui('field', ['name' => 'admin_name', 'label' => $t('iam.field.display_name'), 'required' => true, 'value' => $old['admin_name'] ?? '', 'error' => $err('admin_name')]) ?>
    <?= $ui('field', ['name' => 'admin_email', 'label' => $t('iam.field.email'), 'type' => 'email', 'value' => $old['admin_email'] ?? '', 'error' => $err('admin_email')]) ?>
    <div></div>
    <?= $ui('field', ['name' => 'admin_password', 'label' => $t('auth.pw.new'), 'type' => 'password', 'required' => true, 'error' => $err('admin_password'), 'autocomplete' => 'new-password']) ?>
    <?= $ui('field', ['name' => 'admin_password_confirm', 'label' => $t('auth.pw.confirm'), 'type' => 'password', 'required' => true, 'error' => $err('admin_password_confirm'), 'autocomplete' => 'new-password']) ?>
  </div>
  <label class="check"><input type="checkbox" name="demo_data" value="1"<?= ($old['demo_data'] ?? '') === '1' ? ' checked' : '' ?>> <?= $e($t('install.demo_data')) ?></label>
  <p class="text-muted text-sm"><?= $e($t('install.demo_help')) ?></p>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('install.lock_note')]) ?>
  <div class="row"><?= $ui('button', ['label' => $t('install.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'database']) ?></div>
</form>
