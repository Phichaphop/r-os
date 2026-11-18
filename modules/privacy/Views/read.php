<?php
/**
 * Read a published notice of my employer and acknowledge it when required.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $n @var array<string, string> $errors
 */
use Blue\Modules\Privacy\PrivacyCenterService;

?>
<?= $ui('page-header', ['title' => (string) $n['title'], 'subtitle' => $t('privacy.version_n', ['n' => (string) $n['version']]) . ' · ' . $t('privacy.effective', ['d' => (string) $n['effective_from']]),
    'crumbs' => [[$t('privacy.mine.title'), $url('/privacy/notices')], [$t('privacy.version_n', ['n' => (string) $n['version']]), null]]]) ?>
<article class="card stack-sm privacy-text" aria-labelledby="pr-h">
  <h2 id="pr-h" class="card__title"><?= $e((string) $n['title']) ?></h2>
  <?= PrivacyCenterService::render((string) $n['body']) ?>
  <?php if (!empty($n['body_en'])): ?><details><summary><?= $e($t('privacy.field.body_en')) ?></summary><div lang="en"><?= PrivacyCenterService::render((string) $n['body_en']) ?></div></details><?php endif; ?>
</article>
<?php if ((int) $n['requires_ack'] === 1): ?>
<section class="card stack-sm" aria-labelledby="pa-h">
  <h2 id="pa-h" class="card__title"><?= $e($t('privacy.ack_title')) ?></h2>
  <?php if ($n['acked_at'] !== null): ?>
    <p><?= $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('privacy.acked_on', ['at' => $date((string) $n['acked_at'])])]) ?></p>
  <?php else: ?>
  <form method="post" action="<?= $e($url('/privacy/notices/' . $n['public_id'] . '/ack')) ?>" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <label class="check"><input type="checkbox" name="confirm" value="1"<?= isset($errors['confirm']) ? ' aria-invalid="true" aria-describedby="ack-err"' : '' ?>> <?= $e($t('privacy.ack_statement', ['v' => (string) $n['version']])) ?></label>
    <?php if (isset($errors['confirm'])): ?><p class="field__error" id="ack-err"><?= $e($errors['confirm']) ?></p><?php endif; ?>
    <?= $ui('button', ['label' => $t('privacy.acknowledge'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
  </form>
  <p class="text-sm text-muted"><?= $e($t('privacy.ack_evidence')) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>
