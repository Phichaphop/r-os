<?php
/**
 * My privacy notices: the published notices of my employer, those I must acknowledge first.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var list<array<string, mixed>> $rows
 */
?>
<?= $ui('page-header', ['title' => $t('privacy.mine.title'), 'subtitle' => $t('privacy.mine.subtitle'),
    'actions' => $ui('button', ['label' => $t('privacy.mine.my_data'), 'href' => $url('/me/data'), 'variant' => 'secondary', 'icon' => 'database'])]) ?>
<?php if ($rows === []): ?>
  <?= $ui('states', ['kind' => 'empty', 'icon' => 'shield-check', 'title' => $t('privacy.mine.none')]) ?>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="pm-h">
  <h2 id="pm-h" class="card__title"><?= $e($t('privacy.mine.list')) ?></h2>
  <ul class="plain-list stack-sm">
  <?php foreach ($rows as $n): ?>
    <li class="row">
      <a href="<?= $e($url('/privacy/notices/' . $n['public_id'])) ?>"><?= $e((string) $n['title']) ?></a>
      <span class="text-sm text-muted"><?= $e($t('privacy.version_n', ['n' => (string) $n['version']])) ?> · <?= $e($t('privacy.effective', ['d' => (string) $n['effective_from']])) ?></span>
      <?php if ((int) $n['requires_ack'] === 1): ?>
        <?= $n['acked_at'] === null ? $ui('badge', ['tone' => 'warning', 'label' => $t('privacy.mine.to_ack')]) : $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('privacy.mine.acked')]) ?>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
