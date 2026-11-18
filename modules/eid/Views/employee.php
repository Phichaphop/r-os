<?php
/**
 * HR: one employee's card. @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed> $emp @var array<string, mixed>|null $card @var string|null $status @var array<string, mixed>|null $face
 * @var list<array<string, mixed>> $events @var list<array<string, mixed>> $history @var array<string, string> $errors
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$current = $card !== null && in_array($card['status'], ['active', 'suspended', 'lost'], true);
$actions = $card === null ? [] : array_values(array_filter([
    $card['status'] === 'active' ? 'suspend' : null, $card['status'] === 'suspended' ? 'resume' : null, in_array($card['status'], ['active', 'suspended'], true) ? 'lost' : null,
    $current ? 'revoke' : null, $current ? 'reissue' : null,
]));
?>
<?= $ui('page-header', ['title' => $t('eid.employee_title'), 'subtitle' => $emp['employee_code'] . ' · ' . $emp['name_th'], 'crumbs' => [[$t('eid.admin_title'), $url('/eid/admin')], [(string) $emp['employee_code'], null]],
    'actions' => $eidStatus($status)]) ?>
<?php if (isset($errors['action'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['action']]) ?><?php endif; ?>
<?php if ($card === null || !$current): ?>
<section class="card stack-sm" aria-labelledby="is-h">
  <h2 id="is-h" class="card__title"><?= $e($t('eid.issue')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('eid.issue_help')) ?></p>
  <form method="post" action="<?= $e($url('/eid/admin/e/' . $emp['public_id'] . '/issue')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('button', ['label' => $t('eid.issue'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'id-badge-2', 'disabled' => $emp['status'] === 'former', 'disabledReason' => $t('eid.error.former')]) ?>
  </form>
</section>
<?php endif; ?>
<?php if ($card !== null && $face !== null): ?>
<div class="grid grid--2">
<section class="card stack" aria-labelledby="cf-h">
  <h2 id="cf-h" class="card__title"><?= $e($t('eid.preview')) ?></h2>
  <?= $cardFace($face, null) ?>
  <p class="text-sm text-muted"><?= $e($t('eid.preview_note')) ?></p>
  <?php if ($current): ?>
  <form method="post" action="<?= $e($url('/eid/admin/c/' . $card['public_id'])) ?>" enctype="multipart/form-data" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="action" value="photo">
    <?= $ui('field', ['name' => 'photo', 'id' => 'ph-file', 'type' => 'file', 'label' => $t('eid.field.photo'), 'accept' => 'image/png,image/jpeg,image/webp', 'help' => $t('eid.photo_help'), 'error' => $errors['photo'] ?? null]) ?>
    <?= $ui('button', ['label' => $t('eid.photo_upload'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?>
  </form>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="ac-h">
  <h2 id="ac-h" class="card__title"><?= $e($t('eid.actions')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('eid.field.card_no')) ?></dt><dd><?= $e((string) $card['card_no']) ?></dd></div>
    <div><dt><?= $e($t('eid.field.issued')) ?></dt><dd><?= $e(DateFormat::day(substr((string) $card['issued_at'], 0, 10), $loc)) ?></dd></div>
    <div><dt><?= $e($t('eid.field.expires')) ?></dt><dd><?= $e($card['expires_on'] !== null ? DateFormat::day((string) $card['expires_on'], $loc) : $t('eid.no_expiry')) ?></dd></div>
    <?php if ($card['status_reason'] !== null): ?><div><dt><?= $e($t('eid.field.reason')) ?></dt><dd><?= $e((string) $card['status_reason']) ?></dd></div><?php endif; ?>
  </dl>
  <?php if ($actions !== []): ?>
  <form method="post" action="<?= $e($url('/eid/admin/c/' . $card['public_id'])) ?>" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'action', 'id' => 'ac-action', 'type' => 'select', 'label' => $t('eid.field.action'), 'options' => array_combine($actions, array_map(static fn (string $a): string => $t('eid.action.' . $a), $actions))]) ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'ac-reason', 'label' => $t('eid.field.reason'), 'required' => true, 'error' => $errors['reason'] ?? null]) ?>
    <?= $ui('button', ['label' => $t('eid.action_go'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?>
  </form>
  <p class="text-sm text-muted"><?= $e($t('eid.actions_help')) ?></p>
  <?php endif; ?>
</section>
</div>
<?php endif; ?>
<?php if ($events !== [] || count($history) > 1): ?>
<section class="card stack-sm" aria-labelledby="hi-h">
  <h2 id="hi-h" class="card__title"><?= $e($t('eid.history_card')) ?></h2>
  <ul class="stack-sm text-sm">
    <?php foreach ($events as $ev): ?><li><?= $e(DateFormat::day(substr((string) $ev['created_at'], 0, 10), $loc) . ' ' . substr((string) $ev['created_at'], 11, 5) . ' · ' . $t('eid.event.' . $ev['event']) . ' · ' . ($ev['actor_name'] ?? $t('eid.system')) . ($ev['reason'] !== null ? ' — ' . $ev['reason'] : '')) ?></li><?php endforeach; ?>
    <?php foreach ($history as $h): if ($card !== null && (int) $h['id'] === (int) $card['id']) { continue; } ?><li><?= $e($h['card_no'] . ' · ' . $t('eid.status.' . $h['status'])) ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
