<?php
/**
 * Ideas to handle (filter by state, search) for the managers of a company.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, string> $companies @var string $lePid
 * @var array<string, string> $f @var list<array<string, mixed>> $rows @var list<array<string, mixed>> $criteria @var string $here @var bool $canManage @var bool $canSurvey
 */
$opts = ['open' => $t('kaizen.filter_open'), 'all' => $t('kaizen.all')];
foreach (['submitted', 'screening', 'evaluation', 'accepted', 'implementing', 'verified', 'rejected', 'closed'] as $s) {
    $opts[$s] = $t('kaizen.status.' . $s);
}
?>
<?= $ui('page-header', ['title' => $t('kaizen.admin_title'), 'subtitle' => $t('kaizen.admin_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($criteria === []): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('kaizen.error.no_criteria'), 'html' => '<a href="' . $e($url('/kaizen/settings?le=' . rawurlencode($lePid))) . '">' . $e($t('kaizen.tab.settings')) . '</a>']) ?><?php endif; ?>
<form method="get" action="<?= $e($url('/kaizen/admin')) ?>" class="card row kz-filter">
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?= $ui('field', ['name' => 'q', 'label' => $t('kaizen.search'), 'value' => $f['q']]) ?>
  <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('kaizen.col.status'), 'value' => $f['status'], 'options' => $opts]) ?>
  <?= $ui('button', ['label' => $t('kaizen.show'), 'type' => 'submit', 'variant' => 'secondary']) ?>
</form>
<section class="card card--flush" aria-labelledby="kz-a">
  <div class="card__head"><h2 id="kz-a" class="card__title"><?= $e($t('kaizen.ideas_n', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'list-check', 'title' => $t('kaizen.admin_none'), 'text' => $t('kaizen.admin_none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="kz-a" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('kaizen.col.idea')) ?></th><th scope="col"><?= $e($t('kaizen.col.from')) ?></th><th scope="col"><?= $e($t('kaizen.col.status')) ?></th><th scope="col"><?= $e($t('kaizen.col.owner')) ?></th><th scope="col"><?= $e($t('kaizen.col.score')) ?></th><th scope="col"><?= $e($t('kaizen.col.votes')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $ist = (string) $row['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('kaizen.col.idea')) ?>"><a href="<?= $e($url('/kaizen/i/' . $row['public_id'])) ?>"><?= $e($row['ref'] . ' · ' . $row['title']) ?></a></th>
          <td data-label="<?= $e($t('kaizen.col.from')) ?>"><?= $e((int) $row['anonymous'] === 1 ? $t('kaizen.anonymous') : (string) $row['submitter_name']) ?> <span class="text-sm text-muted"><?= $e($date(substr((string) $row['created_at'], 0, 10), false)) ?></span></td>
          <td data-label="<?= $e($t('kaizen.col.status')) ?>"><?php include __DIR__ . '/_status.php'; ?><?= $row['reward_status'] === 'proposed' ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('kaizen.reward_status.proposed')]) : '' ?></td>
          <td data-label="<?= $e($t('kaizen.col.owner')) ?>"><?= $e((string) ($row['owner_name'] ?? '—')) ?><?= $row['due_date'] !== null && $ist === 'implementing' ? ' · ' . $e($t('kaizen.due_on', ['date' => $date((string) $row['due_date'], false)])) : '' ?></td>
          <td data-label="<?= $e($t('kaizen.col.score')) ?>"><?= $row['score'] === null ? '—' : $e(number_format((float) $row['score'], 2)) ?></td>
          <td data-label="<?= $e($t('kaizen.col.votes')) ?>"><?= (int) $row['votes'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
