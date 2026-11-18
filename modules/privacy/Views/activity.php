<?php
/**
 * One processing activity (record of processing): edit; activate / retire by another officer (privacy.approve).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed>|null $a @var int $le @var array<string, string> $errors @var array<string, mixed> $old @var bool $canApprove
 */
use Blue\Modules\Privacy\Templates;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$val = static fn (string $k): string => (string) ($old[$k] ?? ($a[$k] ?? ''));
$bases = ['' => $t('privacy.not_set')] + array_combine(Templates::BASES, array_map(static fn (string $b): string => $t('privacy.basis.' . $b), Templates::BASES));
$action = $a === null ? '/privacy/center/activities/new' : '/privacy/center/a/' . $a['public_id'];
?>
<?= $ui('page-header', ['title' => $a === null ? $t('privacy.activity.new') : (string) $a['name'], 'subtitle' => $a === null ? '' : (string) $a['code'],
    'crumbs' => [[$t('privacy.center.title'), $url('/privacy/center?tab=activities&le=' . $le)], [$a === null ? $t('privacy.activity.new') : (string) $a['code'], null]]]) ?>
<?php if ($a !== null): ?>
<div class="row">
  <?= $ui('badge', ['tone' => $a['status'] === 'active' ? 'success' : 'neutral', 'label' => $t('privacy.status.' . $a['status'])]) ?>
  <?php if ((int) $a['ai_draft'] === 1): ?><?= $ui('badge', ['tone' => 'warning', 'label' => $t('privacy.ai_label')]) ?><?php endif; ?>
</div>
<?php if ((int) $a['ai_draft'] === 1): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('privacy.activity.ai_help')]) ?><?php endif; ?>
<?php endif; ?>
<section class="card stack" aria-labelledby="ac-h">
  <h2 id="ac-h" class="card__title"><?= $e($t('privacy.activity.details')) ?></h2>
  <form method="post" action="<?= $e($url($action)) ?>" class="stack" novalidate>
    <?= $csrf ?><input type="hidden" name="le" value="<?= $le ?>">
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'code', 'id' => 'ac-code', 'label' => $t('privacy.field.code'), 'required' => true, 'value' => $val('code'), 'placeholder' => 'HR-ATTEND', 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name', 'id' => 'ac-name', 'label' => $t('privacy.field.name'), 'required' => true, 'value' => $val('name'), 'error' => $errors['name'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'purpose', 'id' => 'ac-purpose', 'type' => 'textarea', 'label' => $t('privacy.field.purpose'), 'required' => true, 'value' => $val('purpose'), 'error' => $errors['purpose'] ?? null]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'legal_basis', 'id' => 'ac-basis', 'type' => 'select', 'label' => $t('privacy.field.legal_basis'), 'value' => $val('legal_basis'), 'options' => $bases, 'help' => $t('privacy.basis_help'), 'error' => $errors['legal_basis'] ?? null]) ?>
      <?= $ui('field', ['name' => 'basis_note', 'id' => 'ac-basis-note', 'label' => $t('privacy.field.basis_note'), 'value' => $val('basis_note')]) ?>
      <?= $ui('field', ['name' => 'subjects', 'id' => 'ac-subjects', 'label' => $t('privacy.field.subjects'), 'required' => true, 'value' => $val('subjects'), 'error' => $errors['subjects'] ?? null]) ?>
      <?= $ui('field', ['name' => 'data_categories', 'id' => 'ac-data', 'label' => $t('privacy.field.data_categories'), 'required' => true, 'value' => $val('data_categories'), 'error' => $errors['data_categories'] ?? null]) ?>
    </div>
    <label class="check"><input type="checkbox" name="sensitive" value="1"<?= (string) ($old['sensitive'] ?? ($a['is_sensitive'] ?? '')) === '1' ? ' checked' : '' ?>> <?= $e($t('privacy.field.sensitive')) ?></label>
    <?= $ui('field', ['name' => 'sensitive_note', 'id' => 'ac-sens', 'label' => $t('privacy.field.sensitive_note'), 'value' => $val('sensitive_note'), 'help' => $t('privacy.sensitive_help')]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'recipients', 'id' => 'ac-rec', 'label' => $t('privacy.field.recipients'), 'value' => $val('recipients')]) ?>
      <?= $ui('field', ['name' => 'transfer_abroad', 'id' => 'ac-abroad', 'label' => $t('privacy.field.transfer_abroad'), 'value' => $val('transfer_abroad'), 'help' => $t('privacy.abroad_help')]) ?>
      <?= $ui('field', ['name' => 'retention', 'id' => 'ac-ret', 'label' => $t('privacy.field.retention'), 'value' => $val('retention'), 'help' => $t('privacy.retention_help')]) ?>
      <?= $ui('field', ['name' => 'owner', 'id' => 'ac-owner', 'label' => $t('privacy.field.owner'), 'value' => $val('owner')]) ?>
    </div>
    <?= $ui('field', ['name' => 'security', 'id' => 'ac-sec', 'type' => 'textarea', 'label' => $t('privacy.field.security'), 'value' => $val('security')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'pencil']) ?></div>
    <?php if ($a !== null && $a['status'] === 'active'): ?><p class="text-sm text-muted"><?= $e($t('privacy.activity.edit_active')) ?></p><?php endif; ?>
  </form>
</section>
<?php if ($a !== null): ?>
<section class="card stack-sm" aria-labelledby="aa-h">
  <h2 id="aa-h" class="card__title"><?= $e($t('privacy.activity.approval')) ?></h2>
  <?php if ($a['status'] === 'draft'): ?>
    <?php if ($canApprove): ?>
      <form method="post" action="<?= $e($url('/privacy/center/a/' . $a['public_id'] . '/activate')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('privacy.activity.activate'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
      <p class="text-sm text-muted"><?= $e($t('privacy.activity.activate_help')) ?></p>
    <?php else: ?><p class="text-sm text-muted"><?= $e($t('privacy.activity.other_officer')) ?></p><?php endif; ?>
  <?php elseif ($a['status'] === 'active'): ?>
    <p class="text-sm"><?= $e($t('privacy.activity.active_since', ['at' => $date((string) $a['activated_at'])])) ?></p>
    <form method="post" action="<?= $e($url('/privacy/center/a/' . $a['public_id'] . '/retire')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('privacy.activity.retire'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'x']) ?></form>
  <?php endif; ?>
  <ul class="plain-list">
    <?php foreach ($a['history'] as $h): ?><li><?= $e($date((string) $h['created_at'])) ?> · <?= $e($t('privacy.event.' . $h['action'])) ?> · <?= $e((string) ($h['actor'] ?? '—')) ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
