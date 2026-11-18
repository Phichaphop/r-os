<?php
/**
 * Follow an anonymous idea with its tracking code (sent by POST — the code never appears in a URL).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed>|null $found @var bool $missed @var string $here @var bool $canManage @var bool $canSurvey
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('kaizen.track_title'), 'subtitle' => $t('kaizen.track_subtitle'), 'crumbs' => [[$t('kaizen.title'), $url('/kaizen')], [$t('kaizen.track'), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<form method="post" action="<?= $e($url('/kaizen/track')) ?>" class="card row kz-filter" autocomplete="off"><?= $csrf ?>
  <?= $ui('field', ['name' => 'code', 'label' => $t('kaizen.field.code'), 'required' => true, 'autocomplete' => 'off', 'error' => $missed ? $t('kaizen.error.code') : null]) ?>
  <?= $ui('button', ['label' => $t('kaizen.show'), 'type' => 'submit', 'variant' => 'primary']) ?>
</form>
<?php if ($found !== null): $ist = (string) $found['status']; ?>
<section class="card stack-sm" aria-labelledby="kz-tr">
  <h2 id="kz-tr" class="card__title"><?= $e($found['ref'] . ' · ' . $found['title']) ?></h2>
  <p><?php include __DIR__ . '/_status.php'; ?></p>
  <?php if ($found['decision_note'] !== null): ?><p><?= $e((string) $found['decision_note']) ?></p><?php endif; ?>
  <ul class="plain-list stack-sm"><?php foreach ($found['events'] as $ev): ?><li><?= $e($t('kaizen.event.' . $ev['event'])) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
