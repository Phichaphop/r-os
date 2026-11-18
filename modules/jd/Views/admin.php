<?php
/**
 * JD positions of a company + add one.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array<string, string> $depts @var list<string> $titles
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
?>
<?= $ui('page-header', ['title' => $t('jd.admin_title'), 'subtitle' => $t('jd.admin_subtitle')]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('jd.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/jd/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="ja-l">
  <h2 id="ja-l" class="card__title"><?= $e($t('jd.positions')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('jd.no_positions')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ja-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('jd.field.code')) ?></th><th scope="col"><?= $e($t('jd.field.position_title')) ?></th><th scope="col"><?= $e($t('jd.field.department')) ?></th>
        <th scope="col"><?= $e($t('jd.col.current')) ?></th><th scope="col"><?= $e($t('jd.col.acks')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('jd.field.code')) ?>"><a href="<?= $e($url('/jd/admin/p/' . $x['public_id'])) ?>"><?= $e((string) $x['code']) ?></a></th>
          <td data-label="<?= $e($t('jd.field.position_title')) ?>"><?= $e((string) $x['position_title']) ?></td>
          <td data-label="<?= $e($t('jd.field.department')) ?>"><?= $e($x['dept_code'] !== null ? (string) $x['dept_code'] : $t('jd.all_units')) ?></td>
          <td data-label="<?= $e($t('jd.col.current')) ?>"><?= $x['version_no'] !== null ? $e($t('jd.version_n', ['n' => (string) $x['version_no']])) : $ui('badge', ['tone' => 'warning', 'label' => $t('jd.no_effective')]) ?><?= (int) $x['open_versions'] > 0 ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('jd.has_open')]) : '' ?></td>
          <td data-label="<?= $e($t('jd.col.acks')) ?>"><?= $e($t('jd.ack_counts', ['done' => (string) ((int) $x['assigned'] - (int) $x['pending']), 'all' => (string) (int) $x['assigned']])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="ja-n">
  <h2 id="ja-n" class="card__title"><?= $e($t('jd.new_position')) ?></h2>
  <form method="post" action="<?= $e($url('/jd/admin')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'label' => $t('jd.field.code'), 'required' => true, 'value' => $o('code'), 'placeholder' => 'JD-HRIS', 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'position_title', 'label' => $t('jd.field.position_title'), 'required' => true, 'value' => $o('position_title'), 'error' => $errors['position_title'] ?? null, 'help' => $t('jd.title_help')]) ?>
      <?= $ui('field', ['name' => 'department', 'type' => 'select', 'label' => $t('jd.field.department'), 'value' => $o('department'), 'options' => ['' => $t('jd.all_units')] + $depts, 'error' => $errors['department'] ?? null]) ?>
    </div>
    <?php if ($titles !== []): ?><p class="text-sm text-muted"><?= $e($t('jd.titles_in_use')) ?> <?= $e(implode(' · ', array_slice($titles, 0, 30))) ?></p><?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('jd.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
