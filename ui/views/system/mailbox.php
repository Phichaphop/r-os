<?php
/** @var callable $e @var callable $t @var callable $ui @var callable $date @var list<array<string, mixed>> $rows */
?>
<?= $ui('page-header', ['title' => $t('mailbox.title'), 'subtitle' => $t('mailbox.subtitle')]) ?>
<?= $ui('alert', ['tone' => 'warning', 'text' => $t('mailbox.warning')]) ?>
<?php if ($rows === []): ?>
  <div class="card"><?= $ui('states', ['icon' => 'inbox', 'title' => $t('mailbox.empty')]) ?></div>
<?php else: ?>
  <?php foreach ($rows as $m): ?>
    <article class="card stack-sm">
      <div class="row"><strong><?= $e($m['subject']) ?></strong><span class="spacer"></span><span class="text-muted text-sm"><?= $e($date($m['created_at'])) ?></span></div>
      <div class="text-sm text-muted"><?= $e($t('mailbox.to')) ?>: <?= $e($m['to_address']) ?></div>
      <pre class="debug-trace"><?= $e($m['body']) ?></pre>
    </article>
  <?php endforeach; ?>
<?php endif; ?>
