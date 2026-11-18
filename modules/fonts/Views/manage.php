<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var array<string, int> $counts @var string $status @var array<string, string> $errors @var array<string, string> $old @var bool $openCreate
 */
$tone = ['draft' => ['neutral', 'pencil'], 'published' => ['success', 'circle-check'], 'deprecated' => ['warning', 'alert-triangle'], 'archived' => ['neutral', 'history']];
?>
<?= $ui('page-header', ['title' => $t('fonts.manage.title'), 'subtitle' => $t('fonts.manage.subtitle'),
    'actions' => $ui('button', ['label' => $t('fonts.title'), 'href' => $url('/fonts'), 'variant' => 'ghost', 'icon' => 'typography'])]) ?>
<?= $ui('alert', ['tone' => 'info', 'title' => $t('fonts.manage.rules_title'), 'text' => $t('fonts.manage.rules_text')]) ?>
<nav class="tabs__list subnav" aria-label="<?= $e($t('fonts.field.status')) ?>">
  <a class="tabs__tab" href="<?= $e($url('/fonts/manage')) ?>"<?= $status === '' ? ' aria-current="page"' : '' ?>><?= $e($t('fonts.status.all')) ?> (<?= array_sum($counts) ?>)</a>
  <?php foreach ($counts as $k => $n): ?>
    <a class="tabs__tab" href="<?= $e($url('/fonts/manage?status=' . $k)) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= $e($t('fonts.status.' . $k)) ?> (<?= $n ?>)</a>
  <?php endforeach; ?>
</nav>
<section class="card stack" aria-labelledby="fm-new">
  <details<?= $openCreate ? ' open' : '' ?>>
    <summary><h2 id="fm-new" class="card__title d-inline"><?= $e($t('fonts.manage.create')) ?></h2></summary>
    <form method="post" action="<?= $e($url('/fonts/manage')) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <?php include __DIR__ . '/_form.php'; ?>
      <div class="row"><?= $ui('button', ['label' => $t('fonts.manage.create_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
    </form>
  </details>
</section>
<section class="card stack-sm" aria-labelledby="fm-list">
  <h2 id="fm-list" class="card__title"><?= $e($t('fonts.manage.list')) ?></h2>
  <?php if ($rows === []): ?>
    <p class="text-sm text-muted"><?= $e($t('fonts.manage.none')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="fm-list" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('fonts.field.name')) ?></th><th scope="col"><?= $e($t('fonts.field.category')) ?></th><th scope="col"><?= $e($t('fonts.field.status')) ?></th>
        <th scope="col"><?= $e($t('fonts.field.files')) ?></th><th scope="col"><?= $e($t('fonts.field.license_name')) ?></th><th scope="col"><?= $e($t('fonts.field.downloads')) ?></th><th scope="col"><?= $e($t('fonts.field.updated')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): [$tn, $ic] = $tone[$r['status']]; ?>
        <tr>
          <td data-label="<?= $e($t('fonts.field.name')) ?>"><a href="<?= $e($url('/fonts/manage/' . $r['public_id'])) ?>"><?= $e($r['name']) ?></a><?= (int) $r['restricted'] === 1 ? ' ' . $ui('badge', ['tone' => 'info', 'icon' => 'lock', 'label' => $t('fonts.restricted')]) : '' ?></td>
          <td data-label="<?= $e($t('fonts.field.category')) ?>"><?= $e($t('fonts.category.' . $r['category'])) ?></td>
          <td data-label="<?= $e($t('fonts.field.status')) ?>"><?= $ui('badge', ['tone' => $tn, 'icon' => $ic, 'label' => $t('fonts.status.' . $r['status'])]) ?></td>
          <td data-label="<?= $e($t('fonts.field.files')) ?>"><?= (int) $r['file_count'] ?></td>
          <td data-label="<?= $e($t('fonts.field.license_name')) ?>"><?= $e((string) ($r['license_name'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('fonts.field.downloads')) ?>"><?= number_format((int) $r['download_count']) ?></td>
          <td data-label="<?= $e($t('fonts.field.updated')) ?>"><?= $e($date((string) $r['updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
