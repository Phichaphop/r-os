<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $user @var list<array<string, mixed>> $assignments @var list<string> $roles
 * @var array{legal_entity:array<int,string>, site:array<int,string>, department:array<int,string>}|null $scopeOptions
 * @var int $orgId @var bool $canManage @var bool $canGrant @var bool $isSelf @var array<string, string>|null $link
 * @var array<string, string> $errors @var string $today
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$back = '<input type="hidden" name="_back" value="' . $e('/admin/users/' . $user['public_id']) . '">';
$statusActions = ['active' => ['suspend', 'offboard', 'deactivate'], 'suspended' => ['reactivate', 'deactivate'], 'offboarding' => ['deactivate'], 'invited' => ['deactivate'], 'locked' => ['deactivate']][$user['status']] ?? [];
$scopeLabel = function (string $s) use ($scopeOptions, $orgId, $t): string {
    [$level, $id] = explode(':', $s);
    if ($level === 'organization') {
        return $t('iam.scope.organization');
    }
    return $t('iam.scope.' . $level) . ': ' . ($scopeOptions[$level][(int) $id] ?? '#' . $id);
};
$assignmentTone = ['active' => ['success', 'circle-check'], 'pending_approval' => ['warning', 'hourglass'], 'revoked' => ['neutral', 'x'], 'rejected' => ['danger', 'circle-x']];
?>
<?= $ui('page-header', ['title' => (string) $user['display_name'], 'subtitle' => (string) $user['username'],
    'crumbs' => [[$t('iam.users.title'), $url('/admin/users')], [(string) $user['display_name'], null]]]) ?>

<?php if ($link !== null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('iam.link.' . $link['kind'] . '_title'), 'html' => '<p>' . $e($t('iam.link.once')) . '</p><div class="code-box">' . $e($link['url']) . '</div>']) ?>
<?php endif; ?>

<div class="grid grid--2">
  <section class="card" aria-labelledby="u-h">
    <div class="card__head"><h2 id="u-h" class="card__title"><?= $e($t('iam.user.details')) ?></h2></div>
    <dl class="dl">
      <dt><?= $e($t('iam.field.status')) ?></dt><dd><?= $e($t('iam.status.' . $user['status'])) ?><?php if ($user['locked_until'] !== null && $user['locked_until'] > ($v['now_utc'] ?? '')): ?> · <?= $e($t('iam.status.temp_locked')) ?><?php endif; ?></dd>
      <dt><?= $e($t('iam.field.email')) ?></dt><dd><?= $e($user['email'] ?? '—') ?></dd>
      <dt>MFA</dt><dd><?= $e((int) $user['mfa_enabled'] === 1 ? $t('profile.mfa.on') : $t('profile.mfa.off')) ?></dd>
      <dt><?= $e($t('iam.field.last_login')) ?></dt><dd><?= $e($date($user['last_login_at'])) ?></dd>
      <dt><?= $e($t('iam.field.created')) ?></dt><dd><?= $e($date($user['created_at'])) ?></dd>
    </dl>
  </section>
  <?php if ($canManage && !$isSelf): ?>
  <section class="card stack-sm" aria-labelledby="act-h">
    <div class="card__head"><h2 id="act-h" class="card__title"><?= $e($t('iam.user.actions')) ?></h2></div>
    <form method="post" action="<?= $e($url('/admin/users/' . $user['public_id'] . '/reset-link')) ?>" class="inline-form"><?= $csrf . $back ?>
      <?= $ui('button', ['label' => $t($user['status'] === 'invited' ? 'iam.user.new_activation' : 'iam.user.reset_link'), 'type' => 'submit', 'icon' => 'key']) ?>
      <span class="text-muted text-sm"><?= $e($t('iam.user.reauth_note')) ?></span></form>
    <?php if ($user['locked_until'] !== null): ?>
      <form method="post" action="<?= $e($url('/admin/users/' . $user['public_id'] . '/status/unlock')) ?>" class="inline-form"><?= $csrf ?>
        <?= $ui('button', ['label' => $t('iam.action.unlock'), 'type' => 'submit', 'icon' => 'lock-open']) ?></form>
    <?php endif; ?>
    <?php if ((int) $user['mfa_enabled'] === 1): ?>
      <form method="post" action="<?= $e($url('/admin/users/' . $user['public_id'] . '/mfa-reset')) ?>" class="inline-form"><?= $csrf . $back ?>
        <label class="sr-only" for="mfa-reason"><?= $e($t('iam.field.reason')) ?></label>
        <input class="input" id="mfa-reason" name="reason" placeholder="<?= $e($t('iam.field.reason')) ?>" required>
        <?= $ui('button', ['label' => $t('iam.action.mfa_reset'), 'type' => 'submit', 'icon' => 'shield-check']) ?></form>
    <?php endif; ?>
    <?php foreach ($statusActions as $act): ?>
      <form method="post" action="<?= $e($url('/admin/users/' . $user['public_id'] . '/status/' . $act)) ?>" class="inline-form"><?= $csrf ?>
        <label class="sr-only" for="r-<?= $e($act) ?>"><?= $e($t('iam.field.reason')) ?></label>
        <input class="input" id="r-<?= $e($act) ?>" name="reason" placeholder="<?= $e($t('iam.field.reason')) ?>"<?= $act === 'reactivate' ? '' : ' required' ?>>
        <?= $ui('button', ['label' => $t('iam.action.' . $act), 'type' => 'submit', 'variant' => $act === 'deactivate' ? 'danger' : 'secondary']) ?></form>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</div>

<section class="card card--flush" aria-labelledby="as-h">
  <div class="card__head"><h2 id="as-h" class="card__title"><?= $e($t('iam.assignments.title')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="as-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('iam.field.role')) ?></th><th scope="col"><?= $e($t('iam.field.scope')) ?></th><th scope="col"><?= $e($t('iam.field.period')) ?></th>
        <th scope="col"><?= $e($t('iam.field.status')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('common.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($assignments as $a): [$tone, $icon] = $assignmentTone[$a['status']] ?? ['neutral', 'info-circle']; ?>
        <tr>
          <td data-label="<?= $e($t('iam.field.role')) ?>"><strong><?= $e($a['role_code']) ?></strong> · <?= $e($t('role.' . $a['role_code'])) ?></td>
          <td data-label="<?= $e($t('iam.field.scope')) ?>"><?= $e(implode(', ', array_map($scopeLabel, array_filter(explode(',', (string) ($a['scopes'] ?? '')))))) ?: '—' ?></td>
          <td data-label="<?= $e($t('iam.field.period')) ?>"><?= $e($date($a['valid_from'], false)) ?> – <?= $e($a['valid_to'] ? $date($a['valid_to'], false) : $t('iam.no_end')) ?></td>
          <td data-label="<?= $e($t('iam.field.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('iam.assignment.' . $a['status'])]) ?></td>
          <td><?php if ($canGrant && in_array($a['status'], ['active', 'pending_approval'], true)): ?>
            <form method="post" action="<?= $e($url('/admin/assignments/' . $a['public_id'] . '/revoke')) ?>" class="inline-form"><?= $csrf . $back ?>
              <label class="sr-only" for="rv-<?= $e($a['public_id']) ?>"><?= $e($t('iam.field.reason')) ?></label>
              <input class="input" id="rv-<?= $e($a['public_id']) ?>" name="reason" placeholder="<?= $e($t('iam.field.reason')) ?>" required>
              <?= $ui('button', ['label' => $t('iam.action.revoke'), 'type' => 'submit', 'size' => 'sm']) ?></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($assignments === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'shield-check', 'title' => $t('iam.assignments.empty'), 'text' => $t('iam.assignments.empty_text')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($canGrant && !$isSelf && $scopeOptions !== null && !in_array($user['status'], ['deactivated', 'offboarding'], true)): ?>
<section class="card" id="grant" aria-labelledby="g-h">
  <div class="card__head"><h2 id="g-h" class="card__title"><?= $e($t('iam.grant.title')) ?></h2></div>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('iam.grant.help')]) ?>
  <?php if (!empty($errors['scopes'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['scopes']]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/admin/users/' . $user['public_id'] . '/grant')) ?>" class="stack" novalidate><?= $csrf ?>
    <div class="form-grid">
      <?= $ui('field', ['name' => 'role', 'label' => $t('iam.field.role'), 'type' => 'select', 'required' => true, 'error' => $errors['role'] ?? null,
          'options' => array_combine($roles, array_map(static fn ($r) => $r . ' · ' . $t('role.' . $r), $roles))]) ?>
      <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'error' => $errors['reason'] ?? null]) ?>
      <?= $ui('field', ['name' => 'valid_from', 'label' => $t('iam.field.valid_from'), 'type' => 'date', 'required' => true, 'value' => $today, 'error' => $errors['valid_from'] ?? null]) ?>
      <?= $ui('field', ['name' => 'valid_to', 'label' => $t('iam.field.valid_to'), 'type' => 'date', 'error' => $errors['valid_to'] ?? null, 'help' => $t('iam.field.valid_to_help')]) ?>
    </div>
    <fieldset class="field">
      <legend class="field__label"><?= $e($t('iam.field.scope')) ?> <span class="field__req"><?= $e($t('form.required_tag')) ?></span></legend>
      <div class="checks">
        <?php if ($orgId > 0): ?><label class="check"><input type="checkbox" name="scopes[]" value="organization:<?= $orgId ?>"> <?= $e($t('iam.scope.organization')) ?></label><?php endif; ?>
        <?php foreach (['legal_entity', 'site', 'department'] as $lvl): foreach ($scopeOptions[$lvl] as $id => $label): ?>
          <label class="check"><input type="checkbox" name="scopes[]" value="<?= $e($lvl . ':' . $id) ?>"> <?= $e($t('iam.scope.' . $lvl)) ?>: <?= $e($label) ?></label>
        <?php endforeach; endforeach; ?>
      </div>
    </fieldset>
    <div class="row"><?= $ui('button', ['label' => $t('iam.grant.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'shield-check']) ?></div>
  </form>
</section>
<?php endif; ?>
