<?php
/**
 * Delegate my approvals to someone else for a period (6.30.3) — own page under the approval menu.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string,mixed>> $delegations @var list<array<string,mixed>> $users @var array<string,string> $errors @var string $today
 */
?>
<?= $ui('page-header', ['title' => $t('workflow.delegate.title'), 'subtitle' => $t('workflow.delegate.help'),
    'actions' => $ui('button', ['label' => $t('workflow.inbox.title'), 'href' => $url('/approvals'), 'variant' => 'ghost', 'icon' => 'checks'])]) ?>
<section class="card" id="delegate" aria-labelledby="del-h">
  <h2 id="del-h" class="card__title"><?= $e($t('workflow.delegate.title')) ?></h2>
    <?php if ($delegations !== []): ?>
  <ul class="stack-sm">
    <?php foreach ($delegations as $d): ?>
      <li class="row"><span><?= $e($t('workflow.delegate.item', ['who' => (string) $d['to_name'], 'from' => $date($d['valid_from'], false), 'to' => $date($d['valid_to'], false)])) ?> · <?= $e((string) $d['reason']) ?></span>
        <?php if ($d['status'] === 'active'): ?>
        <form method="post" action="<?= $e($url('/approvals/delegations/' . $d['public_id'] . '/revoke')) ?>"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
          <?= $ui('button', ['label' => $t('workflow.delegate.revoke'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'x']) ?></form>
        <?php else: ?><?= $ui('badge', ['tone' => 'neutral', 'label' => $t('workflow.delegate.revoked')]) ?><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/approvals/delegations')) ?>" class="form-grid" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?= $ui('field', ['name' => 'to_user', 'label' => $t('workflow.delegate.to'), 'type' => 'select', 'required' => true, 'error' => $errors['to_user'] ?? null,
        'options' => ['' => '—'] + array_combine(array_map(static fn ($u) => (string) $u['id'], $users), array_map(static fn ($u) => $u['display_name'] . ' (' . $u['username'] . ')', $users))]) ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'error' => $errors['reason'] ?? null]) ?>
    <?= $ui('field', ['name' => 'valid_from', 'label' => $t('iam.field.valid_from'), 'type' => 'date', 'required' => true, 'value' => $today]) ?>
    <?= $ui('field', ['name' => 'valid_to', 'label' => $t('iam.field.valid_to'), 'type' => 'date', 'required' => true, 'error' => $errors['valid_to'] ?? null]) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('workflow.delegate.save'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'users']) ?></div>
  </form>
</section>
