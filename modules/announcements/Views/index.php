<?php
/**
 * Announcement board. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var list<string> $categories @var string $q @var string $cat @var string $view @var bool $canPublish @var array<string, mixed>|null $me
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$en = $loc === 'en';
$tab = static fn (string $key, string $label) => '<a href="' . $e($url('/announcements?view=' . $key)) . '"' . ($view === $key ? ' aria-current="page"' : '') . '>' . $e($label) . '</a>';
?>
<?= $ui('page-header', ['title' => $t('announcements.title'), 'subtitle' => $t('announcements.subtitle'),
    'actions' => $canPublish ? $ui('button', ['label' => $t('announcements.nav_admin'), 'href' => $url('/announcements/admin'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<?php if ($me === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('announcements.no_employee_title'), 'text' => $t('announcements.no_employee_text')]) ?>
<?php else: ?>
<form method="get" action="<?= $e($url('/announcements')) ?>" class="row row--tight" role="search">
  <input type="hidden" name="view" value="<?= $e($view) ?>">
  <label class="sr-only" for="aq"><?= $e($t('common.search')) ?></label><input class="input" type="search" id="aq" name="q" value="<?= $e($q) ?>" placeholder="<?= $e($t('announcements.search_hint')) ?>">
  <label class="sr-only" for="ac"><?= $e($t('announcements.field.category')) ?></label>
  <select class="select" id="ac" name="cat"><option value=""><?= $e($t('announcements.all_categories')) ?></option><?php foreach ($categories as $c): ?><option value="<?= $e($c) ?>"<?= $c === $cat ? ' selected' : '' ?>><?= $e($c) ?></option><?php endforeach; ?></select>
  <?= $ui('button', ['label' => $t('common.search'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'search']) ?>
</form>
<nav class="segmented-links" aria-label="<?= $e($t('announcements.views')) ?>"><?= $tab('all', $t('announcements.view_all')) ?><?= $tab('unread', $t('announcements.view_unread')) ?><?= $tab('archive', $t('announcements.view_archive')) ?></nav>
<?php if ($rows === []): ?><section class="card"><p class="text-sm text-muted"><?= $e($t('announcements.none')) ?></p></section><?php endif; ?>
<ul class="ann-list" role="list">
  <?php foreach ($rows as $p): $unread = (int) ($p['views'] ?? 0) === 0; $needAck = (int) $p['requires_ack'] === 1 && (int) ($p['acked_revision'] ?? 0) !== (int) $p['revision']; ?>
  <li class="card ann-item<?= $unread ? ' is-unread' : '' ?> ann-item--<?= $e((string) $p['priority']) ?>">
    <div class="row row--tight">
      <?php if ((int) $p['pinned'] === 1): ?><span class="badge badge--info"><?= $e($t('announcements.pinned')) ?></span><?php endif; ?>
      <?php if ($p['priority'] !== 'normal'): ?><span class="badge badge--<?= $p['priority'] === 'critical' ? 'danger' : 'warning' ?>"><?= $e($t('announcements.priority.' . $p['priority'])) ?></span><?php endif; ?>
      <?php if ($unread): ?><span class="badge badge--success"><?= $e($t('announcements.new')) ?></span><?php endif; ?>
      <?php if ($needAck): ?><span class="badge badge--warning"><?= $e($t('announcements.need_ack')) ?></span><?php endif; ?>
      <span class="text-sm text-muted"><?= $e($p['category'] . ' · ' . DateFormat::day(substr((string) $p['starts_at'], 0, 10), $loc)) ?></span>
    </div>
    <h2 class="ann-item__title"><a href="<?= $e($url('/announcements/p/' . $p['public_id'])) ?>"><?= $e($en && $p['title_en'] !== null ? (string) $p['title_en'] : (string) $p['title']) ?></a></h2>
    <p class="ann-item__summary text-sm"><?= $e(mb_substr(preg_replace('/\s+/', ' ', $en && $p['body_en'] !== null ? (string) $p['body_en'] : (string) $p['body']) ?? '', 0, 220)) ?></p>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
