<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array{env_written:bool, env_php:string, migrations:list<string>, demo_password:?string} $result @var string $admin */
?>
<?= $ui('states', ['icon' => 'circle-check', 'title' => $t('install.done.title'), 'text' => $t('install.done.text', ['n' => count($result['migrations'])])]) ?>
<?php if (!$result['env_written']): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('install.done.manual_title'), 'text' => $t('install.done.manual_text')]) ?>
  <pre class="debug-trace"><?= $e($result['env_php']) ?></pre>
<?php endif; ?>
<?= $ui('alert', ['tone' => 'info', 'text' => $t('install.done.admin', ['username' => $admin])]) ?>
<?php if (!empty($result['demo_password'])): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('install.done.demo_title'),
      'html' => '<p>' . $e($t('install.done.demo_text')) . '</p><div class="code-box">' . $e($result['demo_password']) . '</div>'
        . '<ul class="plain-list text-sm demo-accounts">' . implode('', array_map(static fn (string $u, string $role): string => '<li><span class="mono">' . $e($u) . '</span> — ' . $e($t('role.' . $role)) . '</li>',
            array_keys(\Blue\Core\Install\CompanyDemo::SIGN_INS), \Blue\Core\Install\CompanyDemo::SIGN_INS)) . '</ul>'
        . '<p class="text-sm">' . $e($t('install.done.demo_guide')) . '</p>']) ?>
<?php endif; ?>
<div class="row"><?= $ui('button', ['label' => $t('install.done.login'), 'href' => $url('/login'), 'variant' => 'primary', 'icon' => 'arrow-right']) ?></div>
