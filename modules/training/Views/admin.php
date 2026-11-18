<?php
/**
 * HR: sessions of a company (upcoming / past year) + new session; links to courses and the matrix.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array<string, string> $courseOptions @var bool $past
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$actions = $ui('button', ['label' => $t('training.courses_title'), 'href' => $url('/training/admin/courses?le=' . rawurlencode($lePid)), 'variant' => 'secondary', 'icon' => 'list-check'])
    . $ui('button', ['label' => $t('training.matrix_title'), 'href' => $url('/training/admin/matrix?le=' . rawurlencode($lePid)), 'variant' => 'ghost', 'icon' => 'layout-dashboard']);
?>
<?= $ui('page-header', ['title' => $t('training.admin_title'), 'subtitle' => $t('training.admin_subtitle'), 'actions' => $actions]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('training.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/training/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<nav class="segmented-links" aria-label="<?= $e($t('training.period')) ?>">
  <a href="<?= $e($url('/training/admin?le=' . rawurlencode($lePid))) ?>"<?= !$past ? ' aria-current="page"' : '' ?>><?= $e($t('training.upcoming')) ?></a>
  <a href="<?= $e($url('/training/admin?le=' . rawurlencode($lePid) . '&past=1')) ?>"<?= $past ? ' aria-current="page"' : '' ?>><?= $e($t('training.past_year')) ?></a>
</nav>
<section class="card stack-sm" aria-labelledby="ta-l">
  <h2 id="ta-l" class="card__title"><?= $e($t('training.sessions')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('training.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ta-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('training.field.course')) ?></th><th scope="col"><?= $e($t('training.col.when')) ?></th><th scope="col"><?= $e($t('training.col.seats')) ?></th><th scope="col"><?= $e($t('training.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('training.field.course')) ?>"><a href="<?= $e($url('/training/admin/s/' . $x['public_id'])) ?>"><?= $e($x['code'] . ' · ' . $x['title']) ?></a></th>
          <td data-label="<?= $e($t('training.col.when')) ?>"><?php include __DIR__ . '/_when.php'; ?></td>
          <td data-label="<?= $e($t('training.col.seats')) ?>"><?= $e($t('training.seats_of', ['taken' => (string) $x['taken'], 'seats' => (string) $x['seats']])) ?><?= (int) $x['waiting'] > 0 ? ' · ' . $e($t('training.waiting_n', ['n' => (string) $x['waiting']])) : '' ?></td>
          <td data-label="<?= $e($t('training.col.status')) ?>"><?= $ui('badge', ['tone' => $x['status'] === 'open' ? 'success' : ($x['status'] === 'cancelled' ? 'neutral' : 'info'), 'label' => $t('training.session_status.' . $x['status'])]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="ta-n">
  <h2 id="ta-n" class="card__title"><?= $e($t('training.new_session')) ?></h2>
  <?php if ($courseOptions === []): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('training.no_courses')]) ?><?php else: ?>
  <form method="post" action="<?= $e($url('/training/admin/sessions')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?php include __DIR__ . '/_session_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('training.create_session'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
  <?php endif; ?>
</section>
