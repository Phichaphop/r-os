<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var bool $mfaOn @var array<string, string> $errors */
?>
<?= $ui('page-header', ['title' => $t('security.title'), 'subtitle' => $t('security.subtitle')]) ?>
<section class="card" aria-labelledby="mfa-h">
  <div class="card__head">
    <h2 id="mfa-h" class="card__title"><?= $e($t('security.mfa.title')) ?></h2>
    <?= $ui('badge', ['tone' => $mfaOn ? 'success' : 'neutral', 'icon' => 'shield-check', 'label' => $t($mfaOn ? 'security.mfa.state_on' : 'security.mfa.state_off')]) ?>
  </div>
  <p><?= $e($t('security.mfa.desc')) ?></p>
  <ul class="stack-sm text-sm text-muted">
    <li><?= $e($t('security.mfa.who')) ?></li>
    <li><?= $e($t('security.mfa.kept')) ?></li>
  </ul>
  <?php if (!$mfaOn): ?>
    <?= $ui('alert', ['tone' => 'warning', 'title' => $t('security.mfa.off_risk_title'), 'text' => $t('security.mfa.off_risk')]) ?>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/admin/security')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="mfa_enforced" value="<?= $mfaOn ? '0' : '1' ?>">
    <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'error' => $errors['reason'] ?? null, 'help' => $t($mfaOn ? 'security.mfa.help_off' : 'security.mfa.help_on')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t($mfaOn ? 'security.mfa.turn_off' : 'security.mfa.turn_on'), 'type' => 'submit', 'variant' => $mfaOn ? 'secondary' : 'primary', 'icon' => 'shield-check']) ?></div>
  </form>
</section>
