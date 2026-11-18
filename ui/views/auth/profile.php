<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $user @var list<array<string, mixed>> $sessions @var int $currentSessionId
 * @var list<array<string, mixed>> $assignments @var bool $mfaRequired @var bool $mfaOn
 */
?>
<?= $ui('page-header', ['title' => $t('profile.title'), 'subtitle' => (string) $user['display_name'] . ' · ' . (string) $user['username'],
    'actions' => $ui('button', ['label' => $t('profile.password.title'), 'href' => $url('/me/password'), 'icon' => 'key'])
        . $ui('button', ['label' => $t('privacy.my_title'), 'href' => $url('/me/data'), 'icon' => 'shield-check', 'variant' => 'secondary'])]) ?>
<div class="grid grid--2">
  <section class="card" aria-labelledby="acc-h">
    <div class="card__head"><h2 id="acc-h" class="card__title"><?= $e($t('profile.account')) ?></h2></div>
    <dl class="dl">
      <dt><?= $e($t('iam.field.username')) ?></dt><dd class="mono"><?= $e($user['username']) ?></dd>
      <dt><?= $e($t('iam.field.email')) ?></dt><dd><?= $e($user['email'] ?? '—') ?></dd>
      <dt><?= $e($t('iam.field.last_login')) ?></dt><dd><?= $e($date($user['last_login_at'])) ?></dd>
      <dt><?= $e($t('profile.password_changed')) ?></dt><dd><?= $e($date($user['password_changed_at'])) ?></dd>
      <dt>MFA</dt><dd><?php if (!$mfaOn): ?><?= $ui('badge', ['tone' => 'neutral', 'icon' => 'shield-check', 'label' => $t('profile.mfa.system_off')]) ?>
        <?php else: ?><?= (int) $user['mfa_enabled'] === 1
            ? $ui('badge', ['tone' => 'success', 'icon' => 'shield-check', 'label' => $t('profile.mfa.on')])
            : $ui('badge', ['tone' => $mfaRequired ? 'warning' : 'neutral', 'icon' => 'shield-check', 'label' => $t('profile.mfa.off')]) ?>
        <?php if ((int) $user['mfa_enabled'] !== 1): ?> <a href="<?= $e($url('/mfa/setup')) ?>"><?= $e($t('profile.mfa.enable')) ?></a><?php endif; ?><?php endif; ?></dd>
    </dl>
  </section>
  <section class="card" aria-labelledby="roles-h">
    <div class="card__head"><h2 id="roles-h" class="card__title"><?= $e($t('profile.roles')) ?></h2></div>
    <?php $active = array_filter($assignments, static fn ($a) => $a['status'] === 'active'); ?>
    <?php if ($active === []): ?>
      <?= $ui('states', ['icon' => 'shield-check', 'title' => $t('profile.roles.none'), 'text' => $t('profile.roles.none_text')]) ?>
    <?php else: ?>
      <ul role="list" class="stack-sm">
        <?php foreach ($active as $a): ?>
          <li class="row"><span class="badge badge--info"><?= $e($a['role_code']) ?></span><span><?= $e($t('role.' . $a['role_code'])) ?></span>
            <span class="text-muted text-sm"><?= $e($t('iam.valid_until', ['date' => $a['valid_to'] ?? $t('iam.no_end')])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
<section class="card card--flush" aria-labelledby="sess-h">
  <div class="card__head"><h2 id="sess-h" class="card__title"><?= $e($t('profile.sessions')) ?></h2><span class="spacer"></span>
    <form method="post" action="<?= $e($url('/me/sessions/revoke-others')) ?>" class="inline-form"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <?= $ui('button', ['label' => $t('profile.sessions.revoke_others'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'logout']) ?></form>
  </div>
  <div class="table-wrap" role="region" aria-labelledby="sess-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('profile.sessions.device')) ?></th><th scope="col"><?= $e($t('profile.sessions.started')) ?></th><th scope="col"><?= $e($t('profile.sessions.last_seen')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('common.actions')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($sessions as $s): ?>
        <tr><td data-label="<?= $e($t('profile.sessions.device')) ?>"><?= $e(mb_substr((string) $s['user_agent'], 0, 70)) ?><?php if ((int) $s['id'] === $currentSessionId): ?> <?= $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('profile.sessions.this')]) ?><?php endif; ?></td>
          <td data-label="<?= $e($t('profile.sessions.started')) ?>"><?= $e($date($s['created_at'])) ?></td>
          <td data-label="<?= $e($t('profile.sessions.last_seen')) ?>"><?= $e($date($s['last_seen_at'])) ?></td>
          <td><?php if ((int) $s['id'] !== $currentSessionId): ?>
            <form method="post" action="<?= $e($url('/me/sessions/' . $s['public_id'] . '/revoke')) ?>"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
              <?= $ui('button', ['label' => $t('profile.sessions.revoke'), 'type' => 'submit', 'size' => 'sm', 'variant' => 'ghost']) ?></form><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
