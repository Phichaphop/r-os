<?php
/**
 * Publishers: list + new announcement. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array{sites: array<string, string>, departments: array<string, string>} $audOptions
 * @var array<string, string> $errors @var array<string, mixed> $old @var \Blue\Modules\Announcements\AnnouncementService $svc
 */
$in = $old;
$fid = 'na';
$tone = ['draft' => 'warning', 'review' => 'info', 'scheduled' => 'info', 'published' => 'success', 'expired' => 'neutral', 'archived' => 'neutral', 'rejected' => 'danger', 'withdrawn' => 'neutral'];
?>
<?= $ui('page-header', ['title' => $t('announcements.admin_title'), 'subtitle' => $t('announcements.admin_subtitle'), 'crumbs' => [[$t('announcements.title'), $url('/announcements')], [$t('announcements.admin_title'), null]]]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('announcements.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/announcements/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="al-h">
  <h2 id="al-h" class="card__title"><?= $e($t('announcements.all')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('announcements.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="al-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('announcements.field.title')) ?></th><th scope="col"><?= $e($t('announcements.field.status')) ?></th><th scope="col"><?= $e($t('announcements.field.priority')) ?></th>
        <th scope="col"><?= $e($t('announcements.field.starts_at')) ?></th><th scope="col"><?= $e($t('announcements.field.readers')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr><th scope="row" data-label="<?= $e($t('announcements.field.title')) ?>"><a href="<?= $e($url('/announcements/admin/p/' . $p['public_id'])) ?>"><?= $e((string) $p['title']) ?></a></th>
          <td data-label="<?= $e($t('announcements.field.status')) ?>"><?= $ui('badge', ['tone' => $tone[$p['status']] ?? 'neutral', 'label' => $t('announcements.status.' . $p['status'])]) ?></td>
          <td data-label="<?= $e($t('announcements.field.priority')) ?>"><?= $e($t('announcements.priority.' . $p['priority'])) ?></td>
          <td data-label="<?= $e($t('announcements.field.starts_at')) ?>"><?= $e(str_replace('T', ' ', $svc->local((string) $p['starts_at']))) ?></td>
          <td data-label="<?= $e($t('announcements.field.readers')) ?>" class="num"><?= (int) $p['readers'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="an-h">
  <h2 id="an-h" class="card__title"><?= $e($t('announcements.new')) ?></h2>
  <form method="post" action="<?= $e($url('/announcements/admin')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
    <?php include __DIR__ . '/_form.php'; ?>
    <p class="text-sm text-muted"><?= $e($t('announcements.new_help')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('announcements.save_draft'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
