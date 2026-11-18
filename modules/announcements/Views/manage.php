<?php
/**
 * Manage one announcement: status, engagement, edit, attachments. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $post @var array<string, mixed> $in @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var string $audText
 * @var list<array<string, mixed>> $attachments @var array<string, mixed>|null $stats @var string|null $instancePid @var array<string, string> $errors @var bool $mail
 */
$p = $post;
$fid = 'ea';
$tone = ['draft' => 'warning', 'review' => 'info', 'scheduled' => 'info', 'published' => 'success', 'expired' => 'neutral', 'archived' => 'neutral', 'rejected' => 'danger', 'withdrawn' => 'neutral'];
$editable = in_array($p['status'], ['draft', 'rejected', 'scheduled', 'published'], true);
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => (string) $p['title'], 'subtitle' => $p['category'] . ' · ' . $audText, 'crumbs' => [[$t('announcements.admin_title'), $url('/announcements/admin')], [$t('announcements.manage'), null]],
    'actions' => $ui('badge', ['tone' => $tone[$p['status']] ?? 'neutral', 'label' => $t('announcements.status.' . $p['status'])])]) ?>
<section class="card stack-sm" aria-labelledby="ms-h">
  <h2 id="ms-h" class="card__title"><?= $e($t('announcements.next_step')) ?></h2>
  <p class="text-sm"><?= $e($t('announcements.state_help.' . $p['status'])) ?></p>
  <div class="row">
    <?php if ($p['status'] === 'draft'): ?>
      <form method="post" action="<?= $e($url('/announcements/admin/p/' . $p['public_id'] . '/action')) ?>"><?= $csrf ?><input type="hidden" name="action" value="submit">
        <?= $ui('button', ['label' => $t($p['priority'] === 'normal' ? 'announcements.publish_now' : 'announcements.send_approval'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
    <?php endif; ?>
    <?php if ($instancePid !== null && $p['status'] === 'review'): ?><?= $ui('button', ['label' => $t('announcements.approval_link'), 'href' => $url('/approvals/' . $instancePid), 'variant' => 'secondary']) ?><?php endif; ?>
    <?php if (in_array($p['status'], ['published', 'expired'], true)): ?><?= $ui('button', ['label' => $t('announcements.view_as_reader'), 'href' => $url('/announcements/p/' . $p['public_id']), 'variant' => 'secondary', 'icon' => 'eye']) ?><?php endif; ?>
  </div>
  <?php if ((int) $p['send_email'] === 1 && !$mail): ?><p class="text-sm text-muted"><?= $ui('icon', ['name' => 'info-circle', 'size' => 'sm']) ?> <?= $e($t('announcements.mail_off')) ?></p><?php endif; ?>
</section>

<?php if ($stats !== null): ?>
<section class="card stack-sm" aria-labelledby="eg-h">
  <h2 id="eg-h" class="card__title"><?= $e($t('announcements.engagement')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('announcements.stat.audience')) ?></dt><dd><?= (int) $stats['audience'] ?></dd></div>
    <div><dt><?= $e($t('announcements.stat.read')) ?></dt><dd><?= (int) $stats['read'] ?> (<?= $e((string) $stats['read_pct']) ?>%)</dd></div>
    <?php if ($stats['acked_pct'] !== null): ?><div><dt><?= $e($t('announcements.stat.acked')) ?></dt><dd><?= (int) $stats['acked'] ?> (<?= $e((string) $stats['acked_pct']) ?>%)</dd></div><?php endif; ?>
    <div><dt><?= $e($t('announcements.stat.views')) ?></dt><dd><?= (int) $stats['views'] ?></dd></div>
    <div><dt><?= $e($t('announcements.stat.clicks')) ?></dt><dd><?= (int) $stats['clicks'] ?></dd></div>
    <div><dt><?= $e($t('announcements.stat.reactions')) ?></dt><dd>👍 <?= (int) $stats['reactions']['like'] ?> · 🙏 <?= (int) $stats['reactions']['thanks'] ?> · 🎉 <?= (int) $stats['reactions']['celebrate'] ?></dd></div>
  </dl>
  <?php if ($stats['not_read'] !== []): ?>
  <details><summary><?= $e($t('announcements.not_read', ['n' => (string) $stats['not_read_count']])) ?></summary><ul class="text-sm"><?php foreach ($stats['not_read'] as $n): ?><li><?= $e($n) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
  <?php if ($stats['not_acked'] !== []): ?>
  <details><summary><?= $e($t('announcements.not_acked', ['n' => (string) $stats['not_acked_count']])) ?></summary><ul class="text-sm"><?php foreach ($stats['not_acked'] as $n): ?><li><?= $e($n) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
  <?php if ($p['status'] === 'published' && ($stats['not_read_count'] > 0 || $stats['not_acked_count'] > 0)): ?>
  <form method="post" action="<?= $e($url('/announcements/admin/p/' . $p['public_id'] . '/action')) ?>"><?= $csrf ?><input type="hidden" name="action" value="remind">
    <?= $ui('button', ['label' => $t('announcements.remind'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'bell']) ?></form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card stack" aria-labelledby="at-h">
  <h2 id="at-h" class="card__title"><?= $e($t('announcements.attachments')) ?></h2>
  <?php if ($attachments !== []): ?>
  <ul class="stack-sm" role="list">
    <?php foreach ($attachments as $a): ?>
    <li class="row row--between"><span><?= $ui('icon', ['name' => str_starts_with((string) $a['mime'], 'image/') ? 'eye' : 'file-text', 'size' => 'sm']) ?> <?= $e((string) $a['file_name']) ?></span>
      <form method="post" action="<?= $e($url('/announcements/admin/p/' . $p['public_id'] . '/action')) ?>"><?= $csrf ?><input type="hidden" name="action" value="detach"><input type="hidden" name="doc" value="<?= $e((string) $a['document_pid']) ?>">
        <?= $ui('button', ['label' => $t('announcements.remove'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'icon' => 'trash']) ?></form></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/announcements/admin/p/' . $p['public_id'] . '/attach')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate>
    <?= $csrf ?>
    <?= $ui('field', ['name' => 'attachment', 'id' => 'at-file', 'type' => 'file', 'label' => $t('announcements.field.attachment'), 'accept' => '.pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx', 'help' => $t('announcements.attachment_help'), 'error' => $errors['attachment'] ?? null]) ?>
    <?= $ui('button', ['label' => $t('announcements.attach'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?>
  </form>
</section>

<?php if ($editable): ?>
<section class="card stack" aria-labelledby="ed-h">
  <h2 id="ed-h" class="card__title"><?= $e($t('announcements.edit')) ?></h2>
  <?php if (in_array($p['status'], ['published', 'scheduled'], true)): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('announcements.edit_published_note')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/announcements/admin/p/' . $p['public_id'])) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <?php include __DIR__ . '/_form.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('announcements.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<?php endif; ?>

<?php if (in_array($p['status'], ['scheduled', 'published', 'review'], true)): ?>
<section class="card stack-sm" aria-labelledby="wd-h">
  <h2 id="wd-h" class="card__title"><?= $e($t('announcements.withdraw')) ?></h2>
  <form method="post" action="<?= $e($url('/announcements/admin/p/' . $p['public_id'] . '/action')) ?>" class="row row--tight" novalidate>
    <?= $csrf ?><input type="hidden" name="action" value="withdraw">
    <?= $ui('field', ['name' => 'reason', 'id' => 'wd-reason', 'label' => $t('announcements.field.reason'), 'required' => true, 'error' => $errors['reason'] ?? null]) ?>
    <?= $ui('button', ['label' => $t('announcements.withdraw'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?>
  </form>
</section>
<?php endif; ?>
