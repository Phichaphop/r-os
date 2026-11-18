<?php
/**
 * One thread: text, files, likes; replies (hidden ones as a placeholder); reply; the author's edit; report; moderation.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $th
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var bool $canModerate @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $th['public_id'];
$mod = (bool) $th['is_mod'];
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
// a small form behind a <details>: action URL, label, needs a reason?, form key, variant
$mini = static function (string $action, string $label, bool $reason, string $key, string $variant = 'secondary', string $idp = '') use ($e, $ui, $t, $csrf, $form, $fe): string {
    $html = '<details' . ($form === $key ? ' open' : '') . '><summary>' . $e($label) . '</summary><form method="post" action="' . $e($action) . '" class="stack-sm" novalidate>' . $csrf;
    if ($reason) {
        $html .= $ui('field', ['name' => 'reason', 'id' => 'f-reason-' . $key . $idp, 'label' => $t('forum.field.reason'), 'required' => true, 'error' => $fe($key, 'reason')]);
    }
    return $html . '<div class="row">' . $ui('button', ['label' => $label, 'type' => 'submit', 'variant' => $variant, 'size' => 'sm']) . '</div></form></details>';
};
$quick = static fn (string $action, string $label, string $variant = 'secondary', array $attrs = []): string => '<form method="post" action="' . $e($action) . '">' . $csrf . $ui('button', ['label' => $label, 'type' => 'submit', 'variant' => $variant, 'size' => 'sm', 'attrs' => $attrs]) . '</form>';
$ta = static fn (string $x): string => $url('/forum/t/' . $pid . '/' . $x);
?>
<?= $ui('page-header', ['title' => (string) $th['title'], 'subtitle' => $th['category']['name'] . ' · ' . $th['author'], 'crumbs' => [[$t('forum.title'), $url('/forum')], [(string) $th['category']['name'], $url('/forum?category=' . rawurlencode((string) $th['category']['public_id']))]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ((int) $th['hidden'] === 1): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('forum.thread_hidden', ['reason' => (string) $th['hidden_reason']])]) ?><?php endif; ?>
<?php if ((int) $th['locked'] === 1): ?><?= $ui('alert', ['tone' => 'info', 'title' => $t('forum.thread_locked')]) ?><?php endif; ?>
<article class="card stack-sm" aria-labelledby="fm-t">
  <h2 id="fm-t" class="card__title"><?= $e((string) $th['title']) ?></h2>
  <p class="text-sm text-muted"><?= $e($th['author'] . ' · ' . $date((string) $th['created_at'])) ?><?= $th['edited_at'] !== null ? ' · ' . $e($t('forum.edited')) : '' ?></p>
  <div class="fm-body"><?= \Blue\Modules\Forum\ForumService::render((string) $th['body']) ?></div>
  <?php if ($th['files'] !== []): ?>
  <div class="fm-files">
    <?php foreach ($th['files'] as $f): $src = $url('/forum/t/' . $pid . '/f/' . $f['id']); ?>
      <?php if (str_starts_with((string) $f['mime'], 'image/')): ?><a href="<?= $e($src) ?>"><img src="<?= $e($src) ?>" alt="<?= $e((string) $f['filename']) ?>" loading="lazy" class="fm-img"></a>
      <?php else: ?><a href="<?= $e($src) ?>"><?= $ui('icon', ['name' => 'file-type-pdf', 'size' => 'sm']) ?> <?= $e((string) $f['filename']) ?></a><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="row fm-actions">
    <?php if (!$th['mine'] && (int) $th['hidden'] === 0): ?>
      <?= $quick($ta('like'), $t('forum.like_n', ['n' => (string) $th['likes']]), $th['liked'] ? 'primary' : 'secondary', ['aria-pressed' => $th['liked'] ? 'true' : 'false']) ?>
    <?php else: ?><span class="text-sm text-muted"><?= $e($t('forum.likes_n', ['n' => (string) $th['likes']])) ?></span><?php endif; ?>
  </div>
  <?php if (!$th['mine'] && !$th['reported']): ?><?= $mini($ta('report'), $t('forum.report'), true, 'thread_report') ?><?php elseif ($th['reported']): ?><p class="text-sm text-muted"><?= $e($t('forum.you_reported')) ?></p><?php endif; ?>
  <?php if ($th['mine'] && (int) $th['hidden'] === 0 && (int) $th['locked'] === 0): ?>
  <details<?= $form === 'thread_edit' ? ' open' : '' ?>><summary><?= $e($t('forum.edit')) ?></summary>
    <form method="post" action="<?= $e($ta('edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'title', 'id' => 'f-edit-title', 'label' => $t('forum.field.title'), 'required' => true, 'value' => $form === 'thread_edit' ? (string) ($old['title'] ?? '') : (string) $th['title'], 'error' => $fe('thread_edit', 'title')]) ?>
      <?= $ui('field', ['name' => 'body', 'id' => 'f-edit-body', 'type' => 'textarea', 'label' => $t('forum.field.body'), 'required' => true, 'value' => $form === 'thread_edit' ? (string) ($old['body'] ?? '') : (string) $th['body'], 'error' => $fe('thread_edit', 'body')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('forum.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
    </form>
  </details>
  <?php endif; ?>
  <?php if ($mod): ?>
  <div class="stack-sm fm-mod">
    <p class="text-sm"><strong><?= $e($t('forum.moderation')) ?></strong></p>
    <div class="row">
      <?= (int) $th['pinned'] === 1 ? $quick($ta('unpin'), $t('forum.unpin')) : $quick($ta('pin'), $t('forum.pin')) ?>
      <?= (int) $th['locked'] === 1 ? $quick($ta('unlock'), $t('forum.unlock')) : '' ?>
      <?= (int) $th['hidden'] === 1 ? $quick($ta('unhide'), $t('forum.unhide')) : '' ?>
    </div>
    <?= (int) $th['locked'] === 0 ? $mini($ta('lock'), $t('forum.lock'), true, 'thread_lock') : '' ?>
    <?= (int) $th['hidden'] === 0 ? $mini($ta('hide'), $t('forum.hide'), true, 'thread_hide', 'danger') : '' ?>
    <?php if ($th['edits'] !== []): ?><details><summary><?= $e($t('forum.earlier_versions', ['n' => (string) count($th['edits'])])) ?></summary><ul class="stack-sm"><?php foreach ($th['edits'] as $ed): ?><li class="text-sm"><?= $e($date((string) $ed['edited_at']) . ' · ' . $ed['old_title']) ?><br><?= $e(mb_substr((string) $ed['old_body'], 0, 300)) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  </div>
  <?php endif; ?>
</article>
<section class="card stack-sm" aria-labelledby="fm-r">
  <h2 id="fm-r" class="card__title"><?= $e($t('forum.replies_n', ['n' => (string) count($th['posts'])])) ?></h2>
  <?php if ($th['posts'] === []): ?><p class="text-sm text-muted"><?= $e($t('forum.no_replies')) ?></p><?php endif; ?>
  <ol class="plain-list stack fm-posts">
  <?php foreach ($th['posts'] as $p): $pp = (string) $p['public_id']; $pa = static fn (string $x): string => $url('/forum/p/' . $pp . '/' . $x); $key = static fn (string $a): string => 'post_' . $a . '_' . $pp; ?>
    <li id="p-<?= $e($pp) ?>" class="fm-post stack-sm">
      <p class="text-sm text-muted"><?= $e($p['author'] . ' · ' . $date((string) $p['created_at'])) ?><?= $p['edited_at'] !== null ? ' · ' . $e($t('forum.edited')) : '' ?></p>
      <?php if ((int) $p['hidden'] === 1): ?>
        <p class="text-sm fm-removed"><?= $e($p['hidden_reason'] === 'author' ? $t('forum.removed_by_author') : $t('forum.hidden_by_moderator')) ?><?= $p['visible'] && $p['hidden_reason'] !== 'author' ? ' — ' . $e((string) $p['hidden_reason']) : '' ?></p>
        <?php if ($p['visible']): ?><div class="fm-body fm-body--hidden"><?= \Blue\Modules\Forum\ForumService::render((string) $p['body']) ?></div><?php endif; ?>
        <?php if ($mod): ?><?= $quick($pa('unhide'), $t('forum.unhide')) ?><?php endif; ?>
      <?php else: ?>
        <div class="fm-body"><?= \Blue\Modules\Forum\ForumService::render((string) $p['body']) ?></div>
        <div class="row fm-actions">
          <?php if (!$p['mine']): ?><?= $quick($pa('like'), $t('forum.like_n', ['n' => (string) $p['likes']]), (int) $p['liked'] > 0 ? 'primary' : 'secondary', ['aria-pressed' => (int) $p['liked'] > 0 ? 'true' : 'false', 'aria-label' => $t('forum.like_reply', ['who' => (string) $p['author'], 'n' => (string) $p['likes']])]) ?>
          <?php else: ?><span class="text-sm text-muted"><?= $e($t('forum.likes_n', ['n' => (string) $p['likes']])) ?></span><?= $quick($pa('remove'), $t('forum.remove'), 'ghost') ?><?php endif; ?>
        </div>
        <?php if (!$p['mine'] && (int) $p['reported'] === 0): ?><?= $mini($pa('report'), $t('forum.report'), true, $key('report'), 'secondary', $pp) ?><?php endif; ?>
        <?php if ($p['mine'] && (int) $th['locked'] === 0): ?>
        <details<?= $form === $key('edit') ? ' open' : '' ?>><summary><?= $e($t('forum.edit')) ?></summary>
          <form method="post" action="<?= $e($pa('edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
            <?= $ui('field', ['name' => 'body', 'id' => 'f-body-' . $pp, 'type' => 'textarea', 'label' => $t('forum.field.reply'), 'required' => true, 'value' => $form === $key('edit') ? (string) ($old['body'] ?? '') : (string) $p['body'], 'error' => $fe($key('edit'), 'body')]) ?>
            <div class="row"><?= $ui('button', ['label' => $t('forum.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></div>
          </form>
        </details>
        <?php endif; ?>
        <?php if ($mod): ?><?= $mini($pa('hide'), $t('forum.hide'), true, $key('hide'), 'danger', $pp) ?><?php endif; ?>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ol>
  <span id="replies-end"></span>
  <?php if ($th['can_reply']): ?>
  <form method="post" action="<?= $e($ta('reply')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'body', 'id' => 'f-reply', 'type' => 'textarea', 'label' => $t('forum.field.reply'), 'required' => true, 'value' => $form === 'thread_reply' ? (string) ($old['body'] ?? '') : '', 'help' => $t('forum.mention_help'), 'error' => $fe('thread_reply', 'body')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('forum.send_reply'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
  <?php endif; ?>
</section>
