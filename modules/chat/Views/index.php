<?php
/**
 * Chat home: conversations on the side, search results or a short note in the main area.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var array<string, mixed> $v
 * @var bool $canChat @var list<array<string, mixed>> $list @var string $q @var list<array<string, mixed>> $found @var bool $canModerate @var string $current
 * @var array<string, int> $settings
 */
?>
<?= $ui('page-header', ['title' => $t('chat.title'), 'subtitle' => $t('chat.subtitle'),
    'actions' => $canModerate ? $ui('button', ['label' => $t('chat.nav_moderate'), 'href' => $url('/chat/moderate'), 'variant' => 'secondary', 'icon' => 'shield-check']) : '']) ?>
<?php if (!$canChat): ?>
  <?= $ui('states', ['kind' => 'forbidden', 'title' => $t('chat.no_employee_title'), 'text' => $t('chat.error.no_employee')]) ?>
<?php else: ?>
<div class="chat">
  <?= $partial('@chat/_list', ['list' => $list, 'current' => $current]) ?>
  <section class="chat__main card stack" aria-labelledby="chat-main-h">
    <?php if ($q !== ''): ?>
      <h2 id="chat-main-h" class="card__title"><?= $e($t('chat.results', ['q' => $q])) ?></h2>
      <?php if ($found === []): ?><p class="text-sm text-muted"><?= $e($t('chat.no_results')) ?></p><?php else: ?>
        <ul class="plain-list stack-sm">
          <?php foreach ($found as $f): ?>
            <li><a href="<?= $e($url('/chat/c/' . $f['conversation'] . '#m-' . $f['public_id'])) ?>"><strong><?= $e($f['kind'] === 'direct' ? (string) $f['other_name'] : (string) $f['title']) ?></strong></a>
              <span class="text-xs text-muted"> · <?= $e((string) $f['sender']) ?> · <?= $e($date((string) $f['created_at'])) ?></span>
              <div class="text-sm"><?= $e(mb_substr((string) $f['body'], 0, 160)) ?></div></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php else: ?>
      <h2 id="chat-main-h" class="card__title"><?= $e($t('chat.pick')) ?></h2>
      <p class="text-sm text-muted"><?= $e($t('chat.privacy', ['years' => (string) round($settings['retention_days'] / 365, 1), 'days' => (string) $settings['retention_days']])) ?></p>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>
