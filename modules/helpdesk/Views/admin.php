<?php
/**
 * Work categories (SLA and team) and the company policy (helpdesk admin).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $categories @var array<string, mixed> $policy
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$here = '/helpdesk/admin';
$staff = true;
$canManage = true;
$fo = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$hours = static fn (mixed $h): string => $h === null ? '—' : (string) $h;
?>
<?= $ui('page-header', ['title' => $t('helpdesk.admin_title'), 'subtitle' => $t('helpdesk.admin_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('helpdesk.pick_company')) ?>">
  <?php foreach ($companies as $cp => $code): ?><a href="<?= $e($url('/helpdesk/admin?le=' . rawurlencode($cp))) ?>"<?= $cp === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($categories === []): ?><?= $ui('states', ['icon' => 'tool', 'title' => $t('helpdesk.no_categories'), 'text' => $t('helpdesk.no_defaults')]) ?><?php endif; ?>
<?php foreach ($categories as $c): $cpid = (string) $c['public_id']; $mine = ($old['cat'] ?? '') === $cpid; ?>
<section class="card stack-sm" id="hc-<?= $e($cpid) ?>" aria-labelledby="hc-h-<?= $e($cpid) ?>">
  <h2 id="hc-h-<?= $e($cpid) ?>" class="card__title"><?= $e((string) $c['name']) ?> <?= (int) $c['active'] === 1 ? '' : $ui('badge', ['tone' => 'neutral', 'label' => $t('helpdesk.inactive')]) ?></h2>
  <p class="text-sm"><?= $e($t('helpdesk.sla_line', ['r' => $hours($c['response_hours']), 'low' => $hours($c['hours_low']), 'normal' => $hours($c['hours_normal']), 'high' => $hours($c['hours_high']), 'urgent' => $hours($c['hours_urgent'])])) ?></p>
  <h3 class="text-md"><?= $e($t('helpdesk.team')) ?></h3>
  <?php if ($c['members'] === []): ?><p class="text-sm text-muted"><?= $e($t('helpdesk.no_team')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($c['members'] as $m): ?>
    <li><form method="post" action="<?= $e($url('/helpdesk/c/' . $cpid . '/remove')) ?>" class="row"><?= $csrf ?><input type="hidden" name="user" value="<?= (int) $m['user_id'] ?>">
      <span><?= $e($m['name'] . ' (' . $m['username'] . ')') ?> · <?= $ui('badge', ['tone' => $m['role'] === 'lead' ? 'info' : 'neutral', 'label' => $t('helpdesk.role.' . $m['role'])]) ?></span>
      <?= $ui('button', ['label' => $t('helpdesk.remove'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'x']) ?></form></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/helpdesk/c/' . $cpid . '/member')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'member', 'id' => 'f-m-' . $cpid, 'label' => $t('helpdesk.field.member'), 'required' => true, 'value' => $mine ? $fo('cat-member', 'member') : '', 'help' => $t('helpdesk.member_help'), 'error' => $mine ? $fe('cat-member', 'member') : null]) ?>
      <?= $ui('field', ['name' => 'role', 'id' => 'f-r-' . $cpid, 'type' => 'select', 'label' => $t('helpdesk.field.role'), 'value' => $mine ? $fo('cat-member', 'role', 'agent') : 'agent', 'options' => ['agent' => $t('helpdesk.role.agent'), 'lead' => $t('helpdesk.role.lead')], 'error' => $mine ? $fe('cat-member', 'role') : null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.add_member'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
  </form>
  <details<?= $mine && $form === 'cat-edit' ? ' open' : '' ?>><summary><?= $e($t('helpdesk.edit_category')) ?></summary>
    <?php $cv = $mine && $form === 'cat-edit' ? $old : $c; $cErr = $mine && $form === 'cat-edit' ? $errors : []; $cAction = '/helpdesk/c/' . $cpid . '/edit'; $cf = 'e' . substr($cpid, -6); $cLe = ''; $cEdit = true; include __DIR__ . '/_cat_form.php'; ?>
  </details>
</section>
<?php endforeach; ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="ha-n">
  <h2 id="ha-n" class="card__title"><?= $e($t('helpdesk.add_category')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('helpdesk.no_defaults')) ?></p>
  <?php $cv = $form === 'category' ? $old : []; $cErr = $form === 'category' ? $errors : []; $cAction = '/helpdesk/admin/category'; $cf = 'new'; $cLe = $lePid; $cEdit = false; include __DIR__ . '/_cat_form.php'; ?>
</section>
<section class="card stack-sm" aria-labelledby="ha-p">
  <h2 id="ha-p" class="card__title"><?= $e($t('helpdesk.policy')) ?></h2>
  <form method="post" action="<?= $e($url('/helpdesk/admin/policy')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?= $ui('field', ['name' => 'reopen_days', 'type' => 'number', 'label' => $t('helpdesk.field.reopen_days'), 'value' => $fo('policy', 'reopen_days', $policy['reopen_days'] !== null ? (string) $policy['reopen_days'] : ''), 'help' => $t('helpdesk.reopen_days_help'), 'error' => $fe('policy', 'reopen_days')]) ?>
    <?= $ui('field', ['name' => 'auto_close_days', 'type' => 'number', 'label' => $t('helpdesk.field.auto_close_days'), 'value' => $fo('policy', 'auto_close_days', $policy['auto_close_days'] !== null ? (string) $policy['auto_close_days'] : ''), 'help' => $t('helpdesk.auto_close_help'), 'error' => $fe('policy', 'auto_close_days')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('helpdesk.save'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
</div>
