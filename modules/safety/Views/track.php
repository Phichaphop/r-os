<?php
/**
 * Follow an anonymous report with its code (POST, so the code is not in the address or logs).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var bool $asked @var array<string, mixed>|null $found @var bool $canManage @var string $here
 */
?>
<?= $ui('page-header', ['title' => $t('safety.track_title'), 'subtitle' => $t('safety.track_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<form method="post" action="<?= $e($url('/safety/track')) ?>" class="card stack-sm" novalidate><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('field', ['name' => 'code', 'label' => $t('safety.field.code'), 'required' => true, 'autocomplete' => 'off', 'error' => $asked && $found === null ? $t('safety.error.code') : null]) ?>
  <div class="row"><?= $ui('button', ['label' => $t('safety.track'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
</form>
<?php if ($found !== null): ?>
<section class="card stack-sm" aria-labelledby="sf-tr" role="status">
  <h2 id="sf-tr" class="card__title"><?= $e((string) $found['ref']) ?> · <?= $e($t('safety.kind.' . $found['kind'])) ?></h2>
  <p><?= $ui('badge', ['tone' => $found['status'] === 'closed' ? 'neutral' : 'info', 'label' => $found['status'] === 'closed' && $found['closed_reason'] !== 'done' && $found['closed_reason'] !== null ? $t('safety.closed_reason.' . $found['closed_reason']) : $t('safety.status.' . $found['status'])]) ?></p>
  <?php if (($found['outcome'] ?? '') !== ''): ?><p class="sf-text"><?= $e((string) $found['outcome']) ?></p><?php endif; ?>
</section>
<?php endif; ?>
