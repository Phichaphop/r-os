<?php
/**
 * Safety team: incidents of a company (open first, urgent first), filters, the board.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, string> $companies @var string $lePid @var array<string, string> $f
 * @var list<array<string, mixed>> $rows @var array<string, string> $sites @var array<string, mixed> $board @var bool $canManage @var string $here
 */
$statuses = ['' => $t('safety.filter_open'), 'all' => $t('safety.filter_all')];
foreach (['reported', 'triage', 'investigating', 'action_assigned', 'verification', 'closed', 'reopened'] as $s) {
    $statuses[$s] = $t('safety.status.' . $s);
}
$tone = [1 => 'neutral', 2 => 'info', 3 => 'warning', 4 => 'danger'];
?>
<?= $ui('page-header', ['title' => $t('safety.manage_title'), 'subtitle' => $t('safety.manage_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php include __DIR__ . '/_board.php'; ?>
<form method="get" action="<?= $e($url('/safety/manage')) ?>" class="card stack-sm" role="search"><input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <div class="sf-filter">
    <?= $ui('field', ['name' => 'q', 'label' => $t('safety.search'), 'value' => $f['q']]) ?>
    <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('safety.col.status'), 'value' => $f['status'], 'options' => $statuses]) ?>
    <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('safety.field.kind'), 'value' => $f['kind'], 'options' => ['' => $t('safety.all'), 'injury' => $t('safety.kind.injury'), 'damage' => $t('safety.kind.damage'), 'near_miss' => $t('safety.kind.near_miss'), 'hazard' => $t('safety.kind.hazard')]]) ?>
    <?= $ui('field', ['name' => 'severity', 'type' => 'select', 'label' => $t('safety.field.severity'), 'value' => $f['severity'], 'options' => ['' => $t('safety.all'), '1' => $t('safety.level.1'), '2' => $t('safety.level.2'), '3' => $t('safety.level.3'), '4' => $t('safety.level.4')]]) ?>
    <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('safety.field.site'), 'value' => $f['site'], 'options' => ['' => $t('safety.all')] + $sites]) ?>
  </div>
  <div class="row"><?= $ui('button', ['label' => $t('safety.show'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
</form>
<section class="card card--flush" aria-labelledby="sf-list">
  <div class="card__head"><h2 id="sf-list" class="card__title"><?= $e($t('safety.incidents_n', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'shield-check', 'title' => $t('safety.none'), 'text' => $t('safety.none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="sf-list" tabindex="0"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('safety.col.ref')) ?></th><th scope="col"><?= $e($t('safety.field.kind')) ?></th><th scope="col"><?= $e($t('safety.field.severity')) ?></th><th scope="col"><?= $e($t('safety.field.occurred_at')) ?></th><th scope="col"><?= $e($t('safety.field.location')) ?></th><th scope="col"><?= $e($t('safety.col.status')) ?></th><th scope="col"><?= $e($t('safety.col.actions')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="<?= $e($url('/safety/i/' . $r['public_id'])) ?>"><?= $e((string) $r['ref']) ?></a></td>
        <td><?= $e($t('safety.kind.' . $r['kind'])) ?></td>
        <td><?= $ui('badge', ['tone' => $tone[(int) $r['severity']] ?? 'neutral', 'label' => $t('safety.level.' . $r['severity'])]) ?></td>
        <td class="text-sm"><?= $e(substr((string) $r['occurred_at'], 0, 16)) ?></td>
        <td><?= $e((string) $r['location']) ?></td>
        <td><?= $e($r['status'] === 'closed' && $r['closed_reason'] !== null && $r['closed_reason'] !== 'done' ? $t('safety.closed_reason.' . $r['closed_reason']) : $t('safety.status.' . $r['status'])) ?><?= $r['investigator'] !== null ? '<br><span class="text-sm text-muted">' . $e((string) $r['investigator']) . '</span>' : '' ?></td>
        <td class="text-sm"><?= (int) $r['open_actions'] > 0 ? $e($t('safety.open_actions_n', ['n' => (string) $r['open_actions']])) : '—' ?><?= (int) $r['late_actions'] > 0 ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('safety.late_n', ['n' => (string) $r['late_actions']])]) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
