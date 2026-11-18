<?php
/**
 * A posting: its public text (recruiters edit / publish / close) and the applications by stage.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $p @var list<array<string, mixed>> $rows @var string $stage @var bool $recruiter @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Modules\Recruit\Lists;

$o = static fn (string $k): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : (string) ($p[$k] ?? '');
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$actions = '';
if ($recruiter && $p['status'] !== 'published' && $p['req_status'] === 'approved') {
    $actions = '<form method="post" action="' . $e($url('/recruit/p/' . $p['public_id'] . '/status')) . '">' . $csrf . '<input type="hidden" name="to" value="published">' . $ui('button', ['label' => $t('recruit.publish'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'send']) . '</form>';
} elseif ($recruiter && $p['status'] === 'published') {
    $actions = $ui('button', ['label' => $t('recruit.view_public'), 'href' => $url('/recruit/jobs/' . $p['public_id']), 'variant' => 'ghost', 'icon' => 'external-link'])
        . '<form method="post" action="' . $e($url('/recruit/p/' . $p['public_id'] . '/status')) . '">' . $csrf . '<input type="hidden" name="to" value="closed">' . $ui('button', ['label' => $t('recruit.close_posting'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'lock']) . '</form>';
}
?>
<?= $ui('page-header', ['title' => (string) $p['title'], 'subtitle' => $t('recruit.post_status.' . $p['status']) . ' · ' . $t('recruit.applicants_n', ['n' => (string) count($rows)]),
    'crumbs' => [[$t('recruit.req_title'), $url('/recruit/requisitions')], [(string) $p['position_title'], $url('/recruit/r/' . $p['req_pid'])], [(string) $p['title'], null]], 'actions' => $actions]) ?>
<section class="card stack-sm" aria-labelledby="pp-h">
  <h2 id="pp-h" class="card__title"><?= $e($t('recruit.applications')) ?></h2>
  <nav class="stage-tabs" aria-label="<?= $e($t('recruit.col.stage')) ?>">
    <a class="btn btn--sm btn--<?= $stage === '' ? 'secondary' : 'ghost' ?>" href="<?= $e($url('/recruit/p/' . $p['public_id'])) ?>"<?= $stage === '' ? ' aria-current="page"' : '' ?>><?= $e($t('recruit.all')) ?></a>
    <?php foreach (Lists::STAGES as $s): ?><a class="btn btn--sm btn--<?= $stage === $s ? 'secondary' : 'ghost' ?>" href="<?= $e($url('/recruit/p/' . $p['public_id'] . '?stage=' . $s)) ?>"<?= $stage === $s ? ' aria-current="page"' : '' ?>><?= $e($t('recruit.stage.' . $s)) ?></a><?php endforeach; ?>
  </nav>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('recruit.no_applications')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="pp-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('recruit.field.candidate')) ?></th><th scope="col"><?= $e($t('recruit.col.stage')) ?></th><th scope="col"><?= $e($t('recruit.field.expected_salary')) ?></th>
        <th scope="col"><?= $e($t('recruit.col.files')) ?></th><th scope="col"><?= $e($t('recruit.col.score')) ?></th><th scope="col"><?= $e($t('recruit.col.applied')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr><th scope="row" data-label="<?= $e($t('recruit.field.candidate')) ?>"><a href="<?= $e($url('/recruit/c/' . $c['public_id'])) ?>"><?= $e((string) $c['name']) ?></a>
            <?= (int) $c['other_applications'] > 0 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('recruit.applied_before', ['n' => (string) $c['other_applications']])]) : '' ?>
            <?= $c['source'] !== 'web' ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('recruit.source.' . $c['source'])]) : '' ?></th>
          <td data-label="<?= $e($t('recruit.col.stage')) ?>"><?= $e($t('recruit.stage.' . $c['stage'])) ?></td>
          <td data-label="<?= $e($t('recruit.field.expected_salary')) ?>"><?= $e($c['expected_salary'] !== null ? number_format((float) $c['expected_salary'], 2) : '—') ?></td>
          <td data-label="<?= $e($t('recruit.col.files')) ?>"><?= (int) $c['files'] ?></td>
          <td data-label="<?= $e($t('recruit.col.score')) ?>"><?= $e($c['score'] !== null ? (string) $c['score'] . '/5' : '—') ?></td>
          <td data-label="<?= $e($t('recruit.col.applied')) ?>"><?= $e($date((string) $c['created_at'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($recruiter): ?>
<section class="card stack" aria-labelledby="pe-h">
  <details class="draw-more"<?= $errors !== [] ? ' open' : '' ?>>
    <summary><h2 id="pe-h" class="card__title d-inline"><?= $e($t('recruit.posting_edit')) ?></h2></summary>
    <form method="post" action="<?= $e($url('/recruit/p/' . $p['public_id'])) ?>" class="stack" novalidate>
      <?= $csrf ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'title', 'label' => $t('recruit.field.post_title'), 'required' => true, 'value' => $o('title'), 'error' => $errors['title'] ?? null]) ?>
        <?= $ui('field', ['name' => 'location', 'label' => $t('recruit.field.location'), 'value' => $o('location')]) ?>
        <?= $ui('field', ['name' => 'closes_on', 'type' => 'date', 'label' => $t('recruit.field.closes_on'), 'value' => $o('closes_on'), 'error' => $errors['closes_on'] ?? null]) ?>
      </div>
      <?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('recruit.field.description'), 'required' => true, 'value' => $o('description'), 'error' => $errors['description'] ?? null]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check']) ?></div>
    </form>
  </details>
</section>
<?php endif; ?>
