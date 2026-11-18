<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{rows:list<array<string,mixed>>, total:int} $list @var string $q @var string $status @var int $page @var int $per
 * @var bool $canManage @var list<array<string,mixed>> $pending @var list<array<string,mixed>> $sod
 */
$statusTone = ['invited' => ['info', 'send'], 'active' => ['success', 'circle-check'], 'suspended' => ['warning', 'lock'], 'locked' => ['danger', 'lock'],
    'offboarding' => ['warning', 'logout'], 'deactivated' => ['neutral', 'x']];
?>
<?= $ui('page-header', ['title' => $t('iam.users.title'), 'subtitle' => $t('iam.users.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('iam.users.invite'), 'href' => $url('/admin/users/new'), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>

<?php if ($pending !== []): ?>
<section class="card" aria-labelledby="pend-h">
  <div class="card__head"><h2 id="pend-h" class="card__title"><?= $e($t('iam.pending.title')) ?></h2><?= $ui('badge', ['status' => 'pending', 'label' => (string) count($pending)]) ?></div>
  <p class="text-muted text-sm"><?= $e($t('iam.pending.help')) ?></p>
  <ul role="list" class="stack-sm">
    <?php foreach ($pending as $p): ?>
      <li class="row"><span class="badge badge--warning"><?= $e($p['role_code']) ?></span>
        <a href="<?= $e($url('/admin/users/' . $p['user_public_id'])) ?>"><?= $e($p['display_name']) ?></a>
        <span class="text-muted text-sm"><?= $e($t('iam.pending.by', ['who' => (string) ($p['requested_by_name'] ?? '?'), 'when' => $date($p['created_at'])])) ?> · <?= $e($p['reason']) ?></span>
        <span class="spacer"></span>
        <form method="post" action="<?= $e($url('/admin/assignments/' . $p['public_id'] . '/approve')) ?>" class="inline-form"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="_back" value="/admin/users">
          <?= $ui('button', ['label' => $t('iam.pending.approve'), 'type' => 'submit', 'size' => 'sm', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
        <form method="post" action="<?= $e($url('/admin/assignments/' . $p['public_id'] . '/reject')) ?>" class="inline-form"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="_back" value="/admin/users">
          <?= $ui('button', ['label' => $t('iam.pending.reject'), 'type' => 'submit', 'size' => 'sm', 'variant' => 'ghost']) ?></form></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($sod !== []): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('iam.sod.title'), 'text' => implode(' · ', array_map(static fn ($s) => $s['username'] . ': ' . $s['roles'], $sod))]) ?>
<?php endif; ?>

<form method="get" action="<?= $e($url('/admin/users')) ?>" class="filter-bar" role="search">
  <?= $ui('field', ['name' => 'q', 'label' => $t('common.search'), 'value' => $q, 'placeholder' => $t('iam.users.search_ph')]) ?>
  <?= $ui('field', ['name' => 'status', 'label' => $t('iam.field.status'), 'type' => 'select', 'value' => $status,
      'options' => ['' => $t('common.all')] + array_combine(array_keys($statusTone), array_map(static fn ($s) => $t('iam.status.' . $s), array_keys($statusTone)))]) ?>
  <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
</form>

<div class="table-wrap" role="region" aria-label="<?= $e($t('iam.users.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <thead><tr><th scope="col"><?= $e($t('iam.field.username')) ?></th><th scope="col"><?= $e($t('iam.field.display_name')) ?></th><th scope="col"><?= $e($t('iam.field.roles')) ?></th>
      <th scope="col"><?= $e($t('iam.field.status')) ?></th><th scope="col">MFA</th><th scope="col"><?= $e($t('iam.field.last_login')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($list['rows'] as $u): [$tone, $icon] = $statusTone[$u['status']] ?? ['neutral', 'info-circle']; ?>
      <tr>
        <td data-label="<?= $e($t('iam.field.username')) ?>" class="mono"><a href="<?= $e($url('/admin/users/' . $u['public_id'])) ?>"><?= $e($u['username']) ?></a><?php if ((int) $u['is_demo'] === 1): ?> <span class="badge badge--neutral">DEMO</span><?php endif; ?></td>
        <td data-label="<?= $e($t('iam.field.display_name')) ?>"><?= $e($u['display_name']) ?></td>
        <td data-label="<?= $e($t('iam.field.roles')) ?>"><?= $e($u['roles'] ?? '—') ?></td>
        <td data-label="<?= $e($t('iam.field.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('iam.status.' . $u['status'])]) ?>
          <?php if ($u['locked_until'] !== null && $u['locked_until'] > ($v['now_utc'] ?? '')): ?> <?= $ui('badge', ['tone' => 'danger', 'icon' => 'lock', 'label' => $t('iam.status.temp_locked')]) ?><?php endif; ?></td>
        <td data-label="MFA"><?= (int) $u['mfa_enabled'] === 1 ? $ui('icon', ['name' => 'shield-check', 'label' => $t('profile.mfa.on')]) : '—' ?></td>
        <td data-label="<?= $e($t('iam.field.last_login')) ?>"><?= $e($date($u['last_login_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($list['rows'] === []): ?><tr><td colspan="6"><?= $ui('states', ['title' => $t('iam.users.empty'), 'text' => $t('iam.users.empty_text')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?= $ui('pagination', ['path' => '/admin/users', 'query' => ['q' => $q, 'status' => $status], 'page' => $page, 'per' => $per, 'total' => $list['total']]) ?>
