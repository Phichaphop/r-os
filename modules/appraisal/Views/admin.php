<?php
/**
 * HR: appraisal cycles of a company + new cycle; link to forms.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array<string, string> $formOptions
 * @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var array<string, string> $errors @var array<string, mixed> $old
 */
$cv = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : ($old === [] ? $d : '');
$audIn = ['sites' => (array) ($old['sites'] ?? []), 'departments' => (array) ($old['departments'] ?? []), 'groups' => (string) ($old['groups'] ?? '')];
$actions = $ui('button', ['label' => $t('appraisal.forms_title'), 'href' => $url('/appraisal/admin/forms?le=' . rawurlencode($lePid)), 'variant' => 'secondary', 'icon' => 'list-check']);
?>
<?= $ui('page-header', ['title' => $t('appraisal.admin_title'), 'subtitle' => $t('appraisal.admin_subtitle'), 'actions' => $actions]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('appraisal.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/appraisal/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="aa-l">
  <h2 id="aa-l" class="card__title"><?= $e($t('appraisal.cycles')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('appraisal.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="aa-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('appraisal.field.name')) ?></th><th scope="col"><?= $e($t('appraisal.field.period')) ?></th><th scope="col"><?= $e($t('appraisal.field.manager_due')) ?></th><th scope="col"><?= $e($t('appraisal.col.progress')) ?></th><th scope="col"><?= $e($t('appraisal.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('appraisal.field.name')) ?>"><a href="<?= $e($url('/appraisal/admin/c/' . $x['public_id'])) ?>"><?= $e($x['code'] . ' · ' . $x['name']) ?></a></th>
          <td data-label="<?= $e($t('appraisal.field.period')) ?>"><?= $e($t('appraisal.period.' . $x['period'])) ?></td>
          <td data-label="<?= $e($t('appraisal.field.manager_due')) ?>"><?= $e($date((string) $x['manager_due'], false)) ?></td>
          <td data-label="<?= $e($t('appraisal.col.progress')) ?>"><?= $e($t('appraisal.done_of', ['done' => (string) $x['done'], 'n' => (string) $x['people']])) ?></td>
          <td data-label="<?= $e($t('appraisal.col.status')) ?>"><?= $ui('badge', ['tone' => $x['status'] === 'open' ? 'success' : ($x['status'] === 'closed' ? 'neutral' : 'info'), 'label' => $t('appraisal.cycle_status.' . $x['status'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="aa-n">
  <h2 id="aa-n" class="card__title"><?= $e($t('appraisal.new_cycle')) ?></h2>
  <?php if ($formOptions === []): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('appraisal.no_forms')]) ?><?php else: ?>
  <form method="post" action="<?= $e($url('/appraisal/admin/cycles')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?php include __DIR__ . '/_cycle_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('appraisal.create_cycle'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
  <?php endif; ?>
</section>
