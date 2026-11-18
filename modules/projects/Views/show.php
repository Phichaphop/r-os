<?php
/**
 * A project: charter and progress, then one of board · table · timeline · team (+ workload) · updates (+ closing, history).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $pj @var string $view
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var array<string, mixed>|null $tl
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $pj['public_id'];
$r = $pj['roles'];
$st = (string) $pj['status'];
$live = in_array($st, ['active', 'at_risk', 'on_hold'], true);
$open = in_array($st, ['planning', 'pending_approval', 'active', 'at_risk', 'on_hold'], true);
$act = static fn (string $a): string => $url('/projects/p/' . $pid . '/' . $a);
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$ov = static fn (string $f, string $k, string $def = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $def;
$tone = ['planning' => 'neutral', 'pending_approval' => 'warning', 'active' => 'info', 'at_risk' => 'danger', 'on_hold' => 'warning', 'completed' => 'success', 'closed' => 'neutral'];
$ttone = ['todo' => 'neutral', 'in_progress' => 'info', 'submitted' => 'warning', 'returned' => 'danger', 'accepted' => 'success', 'cancelled' => 'neutral'];
$views = ['board' => 'projects.view.board', 'table' => 'projects.view.table', 'timeline' => 'projects.view.timeline', 'team' => 'projects.view.team', 'updates' => 'projects.view.updates'];
$card = static function (array $k) use ($e, $t, $ui, $url): string {
    return '<li class="pj-task"><a href="' . $e($url('/projects/t/' . $k['public_id'])) . '">' . $e((string) $k['title']) . '</a>'
        . '<span class="text-sm text-muted">' . $e(($k['assignee'] ?? $t('projects.unassigned')) . ($k['due_on'] !== null ? ' · ' . $k['due_on'] : '')) . '</span>'
        . '<span class="row pj-flags">' . ($k['late'] ? $ui('badge', ['tone' => 'danger', 'label' => $t('projects.late')]) : '') . ($k['blocked'] ? $ui('badge', ['tone' => 'warning', 'label' => $t('projects.waiting_dep')]) : '')
        . ($k['parent_id'] !== null ? $ui('badge', ['tone' => 'neutral', 'label' => $t('projects.subtask')]) : '') . '</span></li>';
};
?>
<?= $ui('page-header', ['title' => (string) $pj['name'], 'subtitle' => $pj['ref'] . ' · ' . $pj['starts_on'] . ' – ' . $pj['ends_on'], 'crumbs' => [[$t('projects.title'), $url('/projects')], [(string) $pj['name'], null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ($r['approver'] && !$r['member']): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('projects.approver_view')]) ?><?php endif; ?>
<?php if (!empty($r['oversee'])): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('projects.oversee_view')]) ?><?php endif; ?>

<section class="card stack-sm" aria-labelledby="pj-sum">
  <div class="pj-card__head"><h2 id="pj-sum" class="card__title"><?= $e($t('projects.overview')) ?></h2> <?= $ui('badge', ['tone' => $tone[$st] ?? 'neutral', 'label' => $t('projects.status.' . $st)]) ?></div>
  <p class="pj-text"><?= $e((string) $pj['goal']) ?></p>
  <?php if (($pj['scope'] ?? '') !== ''): ?><details><summary><?= $e($t('projects.field.scope')) ?></summary><p class="pj-text"><?= $e((string) $pj['scope']) ?></p></details><?php endif; ?>
  <progress class="pj-progress" max="100" value="<?= (int) $pj['progress'] ?>" aria-label="<?= $e($t('projects.progress')) ?>"><?= (int) $pj['progress'] ?>%</progress>
  <ul class="plain-list pj-figs">
    <li><strong><?= (int) $pj['progress'] ?>%</strong> <span><?= $e($t('projects.progress')) ?></span></li>
    <li><strong><?= (int) $pj['counts']['accepted'] ?> / <?= count($pj['tasks']) - (int) $pj['counts']['cancelled'] ?></strong> <span><?= $e($t('projects.fig_accepted')) ?></span></li>
    <li><strong><?= (int) $pj['late'] ?></strong> <span><?= $e($t('projects.fig_late')) ?></span></li>
    <li><strong><?= (int) $pj['counts']['submitted'] ?></strong> <span><?= $e($t('projects.fig_to_check')) ?></span></li>
  </ul>
  <p class="text-sm text-muted"><?= $pj['budget'] !== null ? $e($t('projects.budget_is', ['amount' => number_format((float) $pj['budget'], 2)])) . ' · ' : '' ?><?= $pj['approver'] !== null ? $e($t('projects.approver_is', ['who' => (string) $pj['approver']])) : $e($t('projects.no_approver')) ?></p>
  <?php if ($r['lead'] && $st === 'planning'): ?>
    <form method="post" action="<?= $e($act('start')) ?>" class="row"><?= $csrf ?><?= $ui('button', ['label' => $pj['approver'] !== null ? $t('projects.request_start') : $t('projects.start'), 'type' => 'submit', 'variant' => 'primary']) ?>
      <span class="text-sm text-muted"><?= $e($pj['approver'] !== null ? $t('projects.request_start_help') : $t('projects.start_help')) ?></span></form>
  <?php elseif ($st === 'pending_approval'): ?><p class="text-sm"><?= $e($t('projects.waiting_approval')) ?></p><?php endif; ?>
  <?php if ($st === 'completed' || $st === 'closed'): ?>
    <?php if ($pj['summary'] !== null): ?><p class="pj-text"><strong><?= $e($t('projects.field.summary')) ?>:</strong> <?= $e((string) $pj['summary']) ?></p><p class="pj-text"><strong><?= $e($t('projects.field.lessons')) ?>:</strong> <?= $e((string) $pj['lessons']) ?></p><?php endif; ?>
    <?php if ($pj['close_reason'] !== null && $st === 'closed'): ?><p class="pj-text"><strong><?= $e($t('projects.field.close_reason')) ?>:</strong> <?= $e((string) $pj['close_reason']) ?></p><?php endif; ?>
  <?php endif; ?>
</section>

<nav class="segmented-links" aria-label="<?= $e($t('projects.views')) ?>">
  <?php foreach ($views as $k => $label): ?><a href="<?= $e($url('/projects/p/' . $pid . '?view=' . $k)) ?>"<?= $k === $view ? ' aria-current="page"' : '' ?>><?= $e($t($label)) ?></a><?php endforeach; ?>
</nav>

<?php if ($view === 'board'): ?>
<section class="pj-board" aria-label="<?= $e($t('projects.view.board')) ?>">
  <?php foreach (['todo', 'in_progress', 'submitted', 'returned', 'accepted'] as $col): $items = array_values(array_filter($pj['tasks'], static fn (array $k): bool => $k['status'] === $col)); ?>
  <div class="card pj-col" aria-labelledby="pj-c-<?= $e($col) ?>">
    <h2 id="pj-c-<?= $e($col) ?>" class="card__title"><?= $e($t('projects.task_status.' . $col)) ?> <span class="text-sm text-muted">(<?= count($items) ?>)</span></h2>
    <?php if ($items === []): ?><p class="text-sm text-muted"><?= $e($t('projects.col_empty')) ?></p><?php else: ?><ul class="plain-list stack-sm"><?= implode('', array_map($card, $items)) ?></ul><?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php if ($r['lead'] && $open): ?>
<details class="card"<?= $form === 'task' ? ' open' : '' ?>><summary><?= $e($t('projects.add_task')) ?></summary>
  <form method="post" action="<?= $e($act('task')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php
    $fill = $form === 'task' ? $old : [];
    $ferr = $form === 'task' ? $errors : [];
    $others = array_values(array_filter($pj['tasks'], static fn (array $k): bool => $k['status'] !== 'cancelled'));
    $tops = array_values(array_filter($pj['tasks'], static fn (array $k): bool => $k['parent_id'] === null && $k['status'] !== 'cancelled'));
    $members = $pj['active_members'];
    $tf = 'n';
    include __DIR__ . '/_taskfields.php';
    ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.add_task'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</details>
<?php endif; ?>
<?php elseif ($view === 'table'): ?>
<section class="card card--flush" aria-labelledby="pj-tbl">
  <div class="card__head"><h2 id="pj-tbl" class="card__title"><?= $e($t('projects.tasks_n', ['n' => (string) count($pj['tasks'])])) ?></h2></div>
  <?php if ($pj['tasks'] === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'list-check', 'title' => $t('projects.no_tasks'), 'text' => $t('projects.no_tasks_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="pj-tbl" tabindex="0"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('projects.field.task_title')) ?></th><th scope="col"><?= $e($t('projects.field.assignee')) ?></th><th scope="col"><?= $e($t('projects.field.starts_on')) ?></th><th scope="col"><?= $e($t('projects.field.due_on')) ?></th><th scope="col"><?= $e($t('projects.col.status')) ?></th><th scope="col" class="num"><?= $e($t('projects.progress')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($pj['tasks'] as $k): ?>
      <tr><td<?= $k['parent_id'] !== null ? ' class="pj-sub"' : '' ?>><a href="<?= $e($url('/projects/t/' . $k['public_id'])) ?>"><?= $e((string) $k['title']) ?></a><?= $k['deps'] !== [] ? '<br><span class="text-sm text-muted">' . $e($t('projects.after', ['tasks' => implode(', ', array_map(static fn (array $d): string => (string) $d['title'], $k['deps']))])) . '</span>' : '' ?></td>
        <td><?= $e((string) ($k['assignee'] ?? '—')) ?></td><td class="text-sm"><?= $e((string) ($k['starts_on'] ?? '—')) ?></td><td class="text-sm"><?= $e((string) ($k['due_on'] ?? '—')) ?><?= $k['late'] ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('projects.late')]) : '' ?></td>
        <td><?= $ui('badge', ['tone' => $ttone[$k['status']] ?? 'neutral', 'label' => $t('projects.task_status.' . $k['status'])]) ?></td><td class="num"><?= $k['status'] === 'accepted' ? 100 : (int) $k['progress'] ?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php elseif ($view === 'timeline' && $tl !== null): $rowH = 30; $label = 220; $px = max(6, min(40, (int) floor(820 / max(1, $tl['days'])))); $w = $label + $tl['days'] * $px + 10; $h = 34 + count($tl['bars']) * $rowH; ?>
<section class="card stack-sm" aria-labelledby="pj-tl">
  <h2 id="pj-tl" class="card__title"><?= $e($t('projects.view.timeline')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('projects.timeline_help', ['from' => (string) $tl['from'], 'days' => (string) $tl['days']])) ?></p>
  <?php if ($tl['bars'] === []): ?><p class="text-sm text-muted"><?= $e($t('projects.timeline_empty')) ?></p><?php else: ?>
  <div class="pj-gantt" role="region" aria-labelledby="pj-tl" tabindex="0">
    <svg width="<?= $w ?>" height="<?= $h ?>" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="<?= $e($t('projects.timeline_alt', ['n' => (string) count($tl['bars'])])) ?>" class="pj-gantt__svg">
      <?php for ($d = 0; $d < $tl['days']; $d += max(1, (int) ceil($tl['days'] / 12))): ?><line x1="<?= $label + $d * $px ?>" y1="20" x2="<?= $label + $d * $px ?>" y2="<?= $h ?>" class="pj-gantt__grid"/><text x="<?= $label + $d * $px + 2 ?>" y="14" class="pj-gantt__day">+<?= $d ?></text><?php endfor; ?>
      <?php if ($tl['today'] !== null): ?><line x1="<?= $label + $tl['today'] * $px ?>" y1="18" x2="<?= $label + $tl['today'] * $px ?>" y2="<?= $h ?>" class="pj-gantt__today"/><?php endif; ?>
      <?php foreach ($tl['bars'] as $i => $b): $y = 26 + $i * $rowH; $k = $b['task']; ?>
        <text x="4" y="<?= $y + 15 ?>" class="pj-gantt__label"><?= $e(mb_strimwidth(($k['parent_id'] !== null ? '· ' : '') . $k['title'], 0, 30, '…')) ?></text>
        <rect x="<?= $label + $b['x'] * $px ?>" y="<?= $y + 4 ?>" width="<?= $b['w'] * $px ?>" height="<?= $rowH - 10 ?>" rx="3" class="pj-bar pj-bar--<?= $e($k['late'] ? 'late' : (string) $k['status']) ?>"><title><?= $e($k['title'] . ' · ' . ($k['starts_on'] ?? '?') . ' – ' . ($k['due_on'] ?? '?') . ' · ' . $t('projects.task_status.' . $k['status'])) ?></title></rect>
      <?php endforeach; ?>
    </svg>
  </div>
  <ul class="plain-list row pj-legend text-sm"><?php foreach (['todo', 'in_progress', 'submitted', 'returned', 'accepted', 'late'] as $s): ?><li><span class="pj-swatch pj-bar--<?= $e($s) ?>" aria-hidden="true"></span> <?= $e($s === 'late' ? $t('projects.late') : $t('projects.task_status.' . $s)) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<?php elseif ($view === 'team'): ?>
<section class="card stack-sm" aria-labelledby="pj-team">
  <h2 id="pj-team" class="card__title"><?= $e($t('projects.team')) ?></h2>
  <ul class="plain-list stack-sm">
  <?php foreach ($pj['members'] as $m): ?>
    <li class="pj-member"><span><strong><?= $e((string) $m['name']) ?></strong> <span class="text-sm text-muted"><?= $e((string) $m['username']) ?></span></span>
      <span class="row"><?= $ui('badge', ['tone' => $m['role'] === 'lead' ? 'info' : 'neutral', 'label' => $t('projects.role.' . $m['role'])]) ?><?= $m['status'] === 'invited' ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('projects.invited')]) : '' ?>
      <?php if ($r['lead'] && $open): ?>
        <?php if ($m['status'] === 'active' && $m['role'] === 'member'): ?><form method="post" action="<?= $e($url('/projects/p/' . $pid . '/member/' . $m['user_id'] . '/lead')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('projects.make_lead'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'attrs' => ['aria-label' => $t('projects.make_lead_who', ['who' => (string) $m['name']])]]) ?></form><?php endif; ?>
        <?php if ($m['role'] === 'lead' && count(array_filter($pj['members'], static fn (array $x): bool => $x['role'] === 'lead' && $x['status'] === 'active')) > 1): ?><form method="post" action="<?= $e($url('/projects/p/' . $pid . '/member/' . $m['user_id'] . '/member')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('projects.make_member'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'attrs' => ['aria-label' => $t('projects.make_member_who', ['who' => (string) $m['name']])]]) ?></form><?php endif; ?>
        <?php if ($m['role'] === 'member'): ?><form method="post" action="<?= $e($url('/projects/p/' . $pid . '/member/' . $m['user_id'] . '/remove')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('projects.remove'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'attrs' => ['aria-label' => $t('projects.remove_who', ['who' => (string) $m['name']])]]) ?></form><?php endif; ?>
      <?php endif; ?></span></li>
  <?php endforeach; ?>
  </ul>
  <?php if ($r['lead'] && $open): ?>
  <form method="post" action="<?= $e($act('invite')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'who', 'id' => 'f-invite', 'label' => $t('projects.field.invite'), 'required' => true, 'value' => $ov('invite', 'who'), 'help' => $t('projects.person_help'), 'error' => $fe('invite', 'who')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.invite'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus']) ?></div>
  </form>
  <?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('projects.privacy_note')) ?></p>
</section>
<section class="card card--flush" aria-labelledby="pj-load">
  <div class="card__head"><h2 id="pj-load" class="card__title"><?= $e($t('projects.workload')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="pj-load" tabindex="0"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('projects.col.person')) ?></th><th scope="col" class="num"><?= $e($t('projects.col.open')) ?></th><th scope="col" class="num"><?= $e($t('projects.col.week')) ?></th><th scope="col" class="num"><?= $e($t('projects.col.late')) ?></th></tr></thead>
    <tbody><?php foreach ($pj['workload'] as $w): ?><tr><th scope="row"><?= $e((string) $w['name']) ?></th><td class="num"><?= (int) $w['open'] ?></td><td class="num"><?= (int) $w['week'] ?></td><td class="num"><?= (int) $w['late'] ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</section>
<?php else: ?>
<?php if ($r['lead'] && $live): ?>
<section class="card stack-sm" aria-labelledby="pj-up">
  <h2 id="pj-up" class="card__title"><?= $e($t('projects.status_update')) ?></h2>
  <form method="post" action="<?= $e($act('status')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'health', 'id' => 'f-health', 'type' => 'select', 'label' => $t('projects.field.health'), 'required' => true, 'value' => $ov('status', 'health', 'on_track'), 'options' => ['on_track' => $t('projects.health.on_track'), 'at_risk' => $t('projects.health.at_risk'), 'off_track' => $t('projects.health.off_track')], 'error' => $fe('status', 'health')]) ?>
      <?= $ui('field', ['name' => 'status', 'id' => 'f-status', 'type' => 'select', 'label' => $t('projects.field.status'), 'value' => $ov('status', 'status', $st), 'options' => ['active' => $t('projects.status.active'), 'at_risk' => $t('projects.status.at_risk'), 'on_hold' => $t('projects.status.on_hold')], 'error' => $fe('status', 'status')]) ?>
    </div>
    <?= $ui('field', ['name' => 'note', 'id' => 'f-up-note', 'type' => 'textarea', 'label' => $t('projects.field.update_note'), 'required' => true, 'value' => $ov('status', 'note'), 'error' => $fe('status', 'note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.post_update'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm']) ?></div>
  </form>
  <details<?= $form === 'complete' ? ' open' : '' ?>><summary><?= $e($t('projects.complete')) ?></summary>
    <form method="post" action="<?= $e($act('complete')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <p class="text-sm text-muted"><?= $e($t('projects.complete_help')) ?></p>
      <?= $ui('field', ['name' => 'summary', 'id' => 'f-summary', 'type' => 'textarea', 'label' => $t('projects.field.summary'), 'required' => true, 'value' => $ov('complete', 'summary'), 'error' => $fe('complete', 'summary')]) ?>
      <?= $ui('field', ['name' => 'lessons', 'id' => 'f-lessons', 'type' => 'textarea', 'label' => $t('projects.field.lessons'), 'required' => true, 'value' => $ov('complete', 'lessons'), 'error' => $fe('complete', 'lessons')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('projects.complete'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm']) ?></div>
    </form>
  </details>
</section>
<?php endif; ?>
<?php if ($r['lead'] && $open && $st !== 'pending_approval'): ?>
<details class="card"<?= $form === 'edit' ? ' open' : '' ?>><summary><?= $e($t('projects.edit_charter')) ?></summary>
  <form method="post" action="<?= $e($act('edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?php $fill = $form === 'edit' ? $old : $pj + ['approver' => (string) ($pj['approver_username'] ?? '')]; $ferr = $form === 'edit' ? $errors : []; include __DIR__ . '/_charter.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
  </form>
</details>
<details class="card"<?= $form === 'close' ? ' open' : '' ?>><summary><?= $e($t('projects.close')) ?></summary>
  <form method="post" action="<?= $e($act('close')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'f-close', 'type' => 'textarea', 'label' => $t('projects.field.close_reason'), 'required' => true, 'value' => $ov('close', 'reason'), 'error' => $fe('close', 'reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.close'), 'type' => 'submit', 'variant' => 'danger', 'size' => 'sm']) ?></div>
  </form>
</details>
<?php endif; ?>
<?php if ($r['lead'] && ($st === 'completed' || $st === 'closed')): ?>
<section class="card stack-sm" aria-labelledby="pj-reo">
  <h2 id="pj-reo" class="card__title"><?= $e($t('projects.reopen')) ?></h2>
  <form method="post" action="<?= $e($act('reopen')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'id' => 'f-reopen', 'label' => $t('projects.field.reason'), 'required' => true, 'value' => $ov('reopen', 'reason'), 'error' => $fe('reopen', 'reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('projects.reopen'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
  </form>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="pj-snaps">
  <h2 id="pj-snaps" class="card__title"><?= $e($t('projects.updates')) ?></h2>
  <?php if ($pj['updates'] === []): ?><p class="text-sm text-muted"><?= $e($t('projects.no_updates')) ?></p><?php endif; ?>
  <?php foreach ($pj['updates'] as $u): ?>
  <div class="pj-entry"><p class="text-sm"><?= $ui('badge', ['tone' => ['on_track' => 'success', 'at_risk' => 'warning', 'off_track' => 'danger'][$u['health']] ?? 'neutral', 'label' => $t('projects.health.' . $u['health'])]) ?> <?= $e($date((string) $u['created_at']) . ' · ' . ($u['username'] ?? '') . ' · ' . $t('projects.progress_n', ['n' => (string) $u['progress']])) ?><?= $u['status_from'] !== $u['status_to'] && $u['status_to'] !== null ? ' · ' . $e($t('projects.status.' . ($u['status_from'] ?? 'planning')) . ' → ' . $t('projects.status.' . $u['status_to'])) : '' ?></p><p class="pj-text"><?= $e((string) $u['note']) ?></p></div>
  <?php endforeach; ?>
</section>
<details class="card"><summary><?= $e($t('projects.history')) ?></summary>
  <ol class="plain-list stack-sm"><?php foreach ($pj['events'] as $ev): ?><li class="text-sm"><?= $e($date((string) $ev['created_at'])) ?> · <?= $e($t('projects.event.' . $ev['event'])) ?><?= $ev['task_title'] !== null ? ' · ' . $e((string) $ev['task_title']) : '' ?><?= $ev['username'] !== null ? ' · ' . $e((string) $ev['username']) : '' ?><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ol>
</details>
<?php endif; ?>
