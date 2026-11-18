<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var string $next @var bool $useTotp @var array<string, string> $errors */
?>
<?= $ui('page-header', ['title' => $t('auth.reauth.title'), 'subtitle' => $t('auth.reauth.intro')]) ?>
<section class="card" aria-labelledby="ra-h">
  <h2 id="ra-h" class="sr-only"><?= $e($t('auth.reauth.title')) ?></h2>
  <form method="post" action="<?= $e($url('/reauth')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="next" value="<?= $e($next) ?>">
    <?php if ($useTotp): ?>
      <?= $ui('field', ['name' => 'code', 'label' => $t('auth.mfa.code'), 'required' => true, 'error' => $errors['code'] ?? null, 'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'autofocus' => true]) ?>
    <?php else: ?>
      <?= $ui('field', ['name' => 'password', 'label' => $t('auth.login.password'), 'type' => 'password', 'required' => true, 'error' => $errors['password'] ?? null, 'autocomplete' => 'current-password', 'autofocus' => true]) ?>
    <?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('auth.reauth.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'shield-check']) ?>
      <?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url($next), 'variant' => 'ghost']) ?></div>
  </form>
</section>
