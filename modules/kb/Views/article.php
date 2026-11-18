<?php
/**
 * One published answer: question, answer, files, source, owner and review date, "was this helpful?".
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $a @var bool $en @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canAuthor @var bool $canReview
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $a['public_id'];
$rev = $a['rev'];
$q = $en ? (string) $rev['question_en'] : (string) $rev['question_th'];
$ans = $en ? (string) $rev['answer_en'] : (string) $rev['answer_th'];
$cat = $en && $a['category']['name_en'] !== null ? (string) $a['category']['name_en'] : (string) $a['category']['name_th'];
?>
<?= $ui('page-header', ['title' => $q, 'subtitle' => $cat, 'crumbs' => [[$t('kb.title'), $url('/kb')], [$cat, $url('/kb?category=' . rawurlencode((string) $a['category']['public_id']))], [$t('kb.answer'), null]],
    'actions' => $a['can_author'] ? $ui('button', ['label' => $t('kb.edit_article'), 'href' => $url('/kb/manage/a/' . $pid), 'variant' => 'secondary', 'icon' => 'pencil']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($a['status'] === 'archived'): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('kb.archived_note', ['reason' => (string) $a['archived_reason']])]) ?><?php endif; ?>
<?php if ($a['review_overdue']): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('kb.review_overdue_note')]) ?><?php endif; ?>
<article class="card stack kb-answer" aria-labelledby="kb-q">
  <h2 id="kb-q" class="card__title"><?= $e($q) ?></h2>
  <div class="kb-body"><?= \Blue\Modules\Kb\KbService::render($ans) ?></div>
  <?php if ($a['files'] !== []): ?>
  <div class="stack-sm"><h3 class="text-md"><?= $e($t('kb.files')) ?></h3><ul class="plain-list stack-sm"><?php foreach ($a['files'] as $f): ?><li><a href="<?= $e($url('/kb/a/' . $pid . '/f/' . $f['id'])) ?>"><?= $ui('icon', ['name' => 'download', 'size' => 'sm']) ?> <?= $e((string) $f['filename']) ?></a></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <dl class="facts">
    <?php if ($rev['source'] !== null): ?><div><dt><?= $e($t('kb.field.source')) ?></dt><dd><?= $e((string) $rev['source']) ?></dd></div><?php endif; ?>
    <div><dt><?= $e($t('kb.field.owner')) ?></dt><dd><?= $e((string) $a['owner_name']) ?></dd></div>
    <div><dt><?= $e($t('kb.version')) ?></dt><dd><?= $e($t('kb.version_n', ['n' => (string) $rev['version'], 'date' => $date((string) $rev['decided_at'])])) ?></dd></div>
    <?php if ($a['review_due'] !== null): ?><div><dt><?= $e($t('kb.field.review_due')) ?></dt><dd><?= $e($date((string) $a['review_due'], false)) ?></dd></div><?php endif; ?>
    <?php if ($rev['tags'] !== null): ?><div><dt><?= $e($t('kb.field.tags')) ?></dt><dd><?= implode(' ', array_map(static fn (string $tg): string => $ui('badge', ['tone' => 'neutral', 'label' => trim($tg)]), explode(',', (string) $rev['tags']))) ?></dd></div><?php endif; ?>
  </dl>
</article>
<?php if ($a['status'] === 'published'): ?>
<section class="card stack-sm" aria-labelledby="kb-fb">
  <h2 id="kb-fb" class="card__title"><?= $e($t('kb.helpful_q')) ?></h2>
  <?php if ($a['mine'] !== null): ?><p class="text-sm text-muted"><?= $e((int) $a['mine']['helpful'] === 1 ? $t('kb.you_said_yes') : $t('kb.you_said_no')) ?></p><?php endif; ?>
  <form method="post" action="<?= $e($url('/kb/a/' . $pid . '/feedback')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="row">
      <?= $ui('button', ['label' => $t('kb.helpful_yes'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-check', 'attrs' => ['name' => 'helpful', 'value' => '1']]) ?>
      <?= $ui('button', ['label' => $t('kb.helpful_no'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'circle-x', 'attrs' => ['name' => 'helpful', 'value' => '0']]) ?>
    </div>
    <?= $ui('field', ['name' => 'comment', 'label' => $t('kb.field.comment'), 'value' => (string) ($old['comment'] ?? ''), 'help' => $t('kb.comment_help'), 'error' => $errors['comment'] ?? null]) ?>
  </form>
  <p class="text-sm text-muted"><?= $e($t('kb.helpful_counts', ['yes' => (string) $a['helpful_yes'], 'no' => (string) $a['helpful_no'], 'views' => (string) $a['views']])) ?></p>
</section>
<?php endif; ?>
