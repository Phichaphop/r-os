<?php
/**
 * Public privacy notice (no sign-in): /privacy-notice/<company code>/<audience>.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, mixed>|null $n
 */
use Blue\Modules\Privacy\PrivacyCenterService;

?>
<div class="stack privacy-public">
<?php if ($n === null): ?>
  <section class="card stack-sm"><h1 class="card__title"><?= $e($t('privacy.public.none_title')) ?></h1><p><?= $e($t('privacy.public.none_text')) ?></p></section>
<?php else: ?>
  <article class="card stack-sm privacy-text" aria-labelledby="pp-h">
    <h1 id="pp-h"><?= $e((string) $n['title']) ?></h1>
    <p class="text-sm text-muted"><?= $e((string) $n['company']) ?> · <?= $e($t('privacy.version_n', ['n' => (string) $n['version']])) ?> · <?= $e($t('privacy.effective', ['d' => (string) $n['effective_from']])) ?></p>
    <?= PrivacyCenterService::render((string) $n['body']) ?>
    <?php if (!empty($n['body_en'])): ?><details><summary>English</summary><div lang="en"><?= PrivacyCenterService::render((string) $n['body_en']) ?></div></details><?php endif; ?>
  </article>
<?php endif; ?>
</div>
