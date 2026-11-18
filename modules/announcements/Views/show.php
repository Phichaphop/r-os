<?php
/** One announcement. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, mixed> $post */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$p = $post;
$en = $loc === 'en' && $p['title_en'] !== null;
$mine = $p['mine'] ?? null;
$acked = $mine !== null && (int) ($mine['acked_revision'] ?? 0) === (int) $p['revision'];
$react = (string) ($mine['reaction'] ?? '');
$images = array_values(array_filter($p['attachments'], static fn (array $a): bool => str_starts_with((string) $a['mime'], 'image/')));
$files = array_values(array_filter($p['attachments'], static fn (array $a): bool => !str_starts_with((string) $a['mime'], 'image/')));
$labels = ['like' => '👍 ' . $t('announcements.reaction.like'), 'thanks' => '🙏 ' . $t('announcements.reaction.thanks'), 'celebrate' => '🎉 ' . $t('announcements.reaction.celebrate')];
?>
<?= $ui('page-header', ['title' => $en ? (string) $p['title_en'] : (string) $p['title'], 'subtitle' => $p['category'] . ' · ' . DateFormat::day(substr((string) $p['starts_at'], 0, 10), $loc) . ' · ' . ($p['created_name'] ?? ''),
    'crumbs' => [[$t('announcements.title'), $url('/announcements')], [$t('announcements.read'), null]],
    'actions' => $p['can_manage'] ? $ui('button', ['label' => $t('announcements.manage'), 'href' => $url('/announcements/admin/p/' . $p['public_id']), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<?php if ($p['status'] !== 'published'): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('announcements.status_note', ['status' => $t('announcements.status.' . $p['status'])])]) ?><?php endif; ?>
<article class="card stack ann-body">
  <?php if ($p['priority'] !== 'normal'): ?><span class="badge badge--<?= $p['priority'] === 'critical' ? 'danger' : 'warning' ?>"><?= $e($t('announcements.priority.' . $p['priority'])) ?></span><?php endif; ?>
  <div class="ann-text"><?php foreach (preg_split('/\R{2,}/', trim($en ? (string) $p['body_en'] : (string) $p['body'])) ?: [] as $para): ?><p><?= nl2br($e($para)) ?></p><?php endforeach; ?></div>
  <?php foreach ($images as $img): ?><img class="ann-image" src="<?= $e($url('/announcements/p/' . $p['public_id'] . '/file/' . $img['document_pid'])) ?>" alt="<?= $e((string) $img['file_name']) ?>" loading="lazy"><?php endforeach; ?>
  <?php if ($files !== [] || $p['video_url'] !== null || $p['link_url'] !== null): ?>
  <div class="row">
    <?php foreach ($files as $f): ?><a class="btn btn--secondary btn--sm" href="<?= $e($url('/announcements/p/' . $p['public_id'] . '/file/' . $f['document_pid'])) ?>"><?= $ui('icon', ['name' => 'download', 'size' => 'sm']) ?><span><?= $e((string) $f['file_name']) ?></span></a><?php endforeach; ?>
    <?php if ($p['video_url'] !== null): ?><a class="btn btn--secondary btn--sm" href="<?= $e($url('/announcements/p/' . $p['public_id'] . '/go/video')) ?>" target="_blank" rel="noopener noreferrer"><?= $ui('icon', ['name' => 'external-link', 'size' => 'sm']) ?><span><?= $e($t('announcements.watch_video')) ?></span><span class="sr-only"> (<?= $e($t('home.new_tab')) ?>)</span></a><?php endif; ?>
    <?php if ($p['link_url'] !== null): ?><a class="btn btn--secondary btn--sm" href="<?= $e($url('/announcements/p/' . $p['public_id'] . '/go/link')) ?>" target="_blank" rel="noopener noreferrer"><?= $ui('icon', ['name' => 'external-link', 'size' => 'sm']) ?><span><?= $e($t('announcements.open_link')) ?></span><span class="sr-only"> (<?= $e($t('home.new_tab')) ?>)</span></a><?php endif; ?>
  </div>
  <?php endif; ?>
</article>
<?php if ($p['status'] === 'published'): ?>
<section class="card stack-sm" id="reactions" aria-labelledby="rx-h">
  <h2 id="rx-h" class="card__title"><?= $e($t('announcements.your_response')) ?></h2>
  <?php if ((int) $p['requires_ack'] === 1): ?>
    <?php if ($acked): ?>
      <p><?= $ui('badge', ['tone' => 'success', 'icon' => 'circle-check', 'label' => $t('announcements.acked_at', ['at' => DateFormat::day(substr((string) $mine['acked_at'], 0, 10), $loc) . ' ' . substr((string) $mine['acked_at'], 11, 5)])]) ?></p>
    <?php else: ?>
      <form method="post" action="<?= $e($url('/announcements/p/' . $p['public_id'] . '/ack')) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
        <?= $ui('button', ['label' => $t('announcements.acknowledge'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
      </form>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/announcements/p/' . $p['public_id'] . '/react')) ?>" class="row row--tight">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?php foreach ($labels as $k => $label): ?>
      <button type="submit" name="reaction" value="<?= $e($k) ?>" class="btn btn--sm <?= $react === $k ? 'btn--primary' : 'btn--secondary' ?>" aria-pressed="<?= $react === $k ? 'true' : 'false' ?>"><span><?= $e($label) ?></span><span class="text-muted">&nbsp;<?= (int) ($p['reactions'][$k] ?? 0) ?></span></button>
    <?php endforeach; ?>
  </form>
</section>
<?php endif; ?>
