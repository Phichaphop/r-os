<?php
/**
 * My projects, invitations, projects I approve, my open tasks.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var string $today
 * @var array{projects: list<array<string, mixed>>, invites: list<array<string, mixed>>, approving: list<array<string, mixed>>, overseeing: list<array<string, mixed>>} $mine @var list<array<string, mixed>> $tasks @var bool $canCreate
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$tone = ['planning' => 'neutral', 'pending_approval' => 'warning', 'active' => 'info', 'at_risk' => 'danger', 'on_hold' => 'warning', 'completed' => 'success', 'closed' => 'neutral'];
?>
<?= $ui('page-header', ['title' => $t('projects.title'), 'subtitle' => $t('projects.subtitle'),
    'actions' => $canCreate ? $ui('button', ['label' => $t('projects.new'), 'href' => $url('/projects/new'), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<?php if (!$canCreate): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('projects.employees_only')]) ?><?php endif; ?>
<?php if ($mine['invites'] !== []): ?>
<section class="card stack-sm" aria-labelledby="pj-inv">
  <h2 id="pj-inv" class="card__title"><?= $e($t('projects.invitations')) ?></h2>
  <?php foreach ($mine['invites'] as $i): ?>
  <div class="pj-invite">
    <div><strong><?= $e((string) $i['name']) ?></strong> <span class="text-sm text-muted"><?= $e((string) $i['ref']) ?></span><p class="text-sm pj-text"><?= $e(mb_substr((string) $i['goal'], 0, 200)) ?></p></div>
    <div class="row">
      <form method="post" action="<?= $e($url('/projects/p/' . $i['public_id'] . '/accept')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('projects.accept_invite'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm']) ?></form>
      <form method="post" action="<?= $e($url('/projects/p/' . $i['public_id'] . '/decline')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('projects.decline_invite'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></form>
    </div>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($tasks !== []): ?>
<section class="card card--flush" aria-labelledby="pj-mt">
  <div class="card__head"><h2 id="pj-mt" class="card__title"><?= $e($t('projects.my_tasks')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="pj-mt" tabindex="0"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('projects.field.task_title')) ?></th><th scope="col"><?= $e($t('projects.col.project')) ?></th><th scope="col"><?= $e($t('projects.col.status')) ?></th><th scope="col"><?= $e($t('projects.field.due_on')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $k): $late = $k['due_on'] !== null && (string) $k['due_on'] < $today && $k['status'] !== 'submitted'; ?>
      <tr><td><a href="<?= $e($url('/projects/t/' . $k['public_id'])) ?>"><?= $e((string) $k['title']) ?></a></td><td><?= $e((string) $k['project']) ?></td>
        <td><?= $ui('badge', ['tone' => $k['status'] === 'returned' ? 'warning' : 'info', 'label' => $t('projects.task_status.' . $k['status'])]) ?> <span class="text-sm"><?= (int) $k['progress'] ?>%</span></td>
        <td class="text-sm"><?= $e((string) ($k['due_on'] ?? '—')) ?><?= $late ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('projects.late')]) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="pj-my">
  <h2 id="pj-my" class="card__title"><?= $e($t('projects.my_projects')) ?></h2>
  <?php if ($mine['projects'] === []): ?><?= $ui('states', ['icon' => 'list-check', 'title' => $t('projects.none'), 'text' => $t('projects.none_help')]) ?><?php else: ?>
  <ul class="plain-list pj-cards">
  <?php foreach ($mine['projects'] as $p): ?>
    <li class="pj-card stack-sm">
      <div class="pj-card__head"><a href="<?= $e($url('/projects/p/' . $p['public_id'])) ?>"><strong><?= $e((string) $p['name']) ?></strong></a> <?= $ui('badge', ['tone' => $tone[$p['status']] ?? 'neutral', 'label' => $t('projects.status.' . $p['status'])]) ?></div>
      <progress class="pj-progress" max="100" value="<?= (int) $p['progress'] ?>" aria-label="<?= $e($t('projects.progress_of', ['name' => (string) $p['name']])) ?>"><?= (int) $p['progress'] ?>%</progress>
      <p class="text-sm text-muted"><?= $e($t('projects.card_line', ['progress' => (string) $p['progress'], 'open' => (string) $p['open_tasks'], 'mine' => (string) $p['my_tasks'], 'end' => (string) $p['ends_on']])) ?><?= (int) $p['late_tasks'] > 0 ? ' · ' . $e($t('projects.late_n', ['n' => (string) $p['late_tasks']])) : '' ?> · <?= $e($t('projects.role.' . $p['role'])) ?></p>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if ($mine['approving'] !== []): ?>
<section class="card stack-sm" aria-labelledby="pj-ap">
  <h2 id="pj-ap" class="card__title"><?= $e($t('projects.approving')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($mine['approving'] as $p): ?><li><a href="<?= $e($url('/projects/p/' . $p['public_id'])) ?>"><?= $e($p['ref'] . ' · ' . $p['name']) ?></a> <?= $ui('badge', ['tone' => $tone[$p['status']] ?? 'neutral', 'label' => $t('projects.status.' . $p['status'])]) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php if ($mine['overseeing'] !== []): ?>
<section class="card stack-sm" aria-labelledby="pj-ov">
  <h2 id="pj-ov" class="card__title"><?= $e($t('projects.overseeing')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('projects.overseeing_help')) ?></p>
  <ul class="plain-list stack-sm"><?php foreach ($mine['overseeing'] as $p): ?><li><a href="<?= $e($url('/projects/p/' . $p['public_id'])) ?>"><?= $e($p['ref'] . ' · ' . $p['name']) ?></a> <?= $ui('badge', ['tone' => $tone[$p['status']] ?? 'neutral', 'label' => $t('projects.status.' . $p['status'])]) ?> <span class="text-sm text-muted"><?= $e((string) $p['starts_on'] . ' – ' . (string) $p['ends_on']) ?></span></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
