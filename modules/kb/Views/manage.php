<?php
/**
 * Authors: articles by state, and questions from employees (answer by writing an article, or decline).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var string $view @var array<string, string> $f @var list<array<string, mixed>> $rows @var list<array<string, mixed>> $questions @var list<array<string, mixed>> $categories
 * @var string $here @var bool $canAuthor @var bool $canReview
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$opts = ['' => $t('kb.all')];
foreach (['draft', 'review', 'published', 'due', 'archived'] as $s) {
    $opts[$s] = $t('kb.filter.' . $s);
}
$state = static function (array $r) use ($t): array {
    if ($r['status'] === 'archived') {
        return ['neutral', $t('kb.status.archived')];
    }
    if ($r['working_status'] === 'review') {
        return ['info', $t('kb.status.review')];
    }
    if ($r['status'] === 'published') {
        return ['success', $t('kb.status.published')];
    }
    return ['neutral', $t($r['working_status'] === 'returned' ? 'kb.status.returned' : 'kb.status.draft')];
};
?>
<?= $ui('page-header', ['title' => $t('kb.manage_title'), 'subtitle' => $t('kb.manage_subtitle'),
    'actions' => $ui('button', ['label' => $t('kb.new_article'), 'href' => $url('/kb/manage/new?le=' . rawurlencode($lePid)), 'variant' => 'primary', 'icon' => 'plus'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<nav class="segmented-links" aria-label="<?= $e($t('kb.manage_views')) ?>">
  <a href="<?= $e($url('/kb/manage?le=' . rawurlencode($lePid))) ?>"<?= $view === 'articles' ? ' aria-current="page"' : '' ?>><?= $e($t('kb.articles')) ?></a>
  <a href="<?= $e($url('/kb/manage?view=questions&le=' . rawurlencode($lePid))) ?>"<?= $view === 'questions' ? ' aria-current="page"' : '' ?>><?= $e($t('kb.questions_n', ['n' => (string) count($questions)])) ?></a>
</nav>
<?php if ($view === 'articles'): ?>
<form method="get" action="<?= $e($url('/kb/manage')) ?>" class="card row kb-search">
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?= $ui('field', ['name' => 'q', 'label' => $t('kb.search'), 'value' => $f['q']]) ?>
  <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('kb.col.status'), 'value' => $f['status'], 'options' => $opts]) ?>
  <?= $ui('button', ['label' => $t('kb.show'), 'type' => 'submit', 'variant' => 'secondary']) ?>
</form>
<section class="card card--flush" aria-labelledby="kb-m">
  <div class="card__head"><h2 id="kb-m" class="card__title"><?= $e($t('kb.articles_n', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'help-circle', 'title' => $t('kb.no_articles'), 'text' => $t('kb.no_articles_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="kb-m" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('kb.col.question')) ?></th><th scope="col"><?= $e($t('kb.field.category')) ?></th><th scope="col"><?= $e($t('kb.col.status')) ?></th><th scope="col"><?= $e($t('kb.field.owner')) ?></th><th scope="col"><?= $e($t('kb.field.review_due')) ?></th><th scope="col"><?= $e($t('kb.col.reads')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): [$tone, $label] = $state($row); ?>
        <tr><th scope="row" data-label="<?= $e($t('kb.col.question')) ?>"><a href="<?= $e($url('/kb/manage/a/' . $row['public_id'])) ?>"><?= $e((string) $row['question_th']) ?></a></th>
          <td data-label="<?= $e($t('kb.field.category')) ?>"><?= $e((string) $row['category_th']) ?></td>
          <td data-label="<?= $e($t('kb.col.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'label' => $label]) ?><?= (int) $row['not_helpful'] > 0 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('kb.not_helpful_n', ['n' => (string) $row['not_helpful']])]) : '' ?></td>
          <td data-label="<?= $e($t('kb.field.owner')) ?>"><?= $e((string) $row['owner_name']) ?></td>
          <td data-label="<?= $e($t('kb.field.review_due')) ?>"><?= $row['review_due'] === null ? '—' : $e($date((string) $row['review_due'], false)) ?></td>
          <td data-label="<?= $e($t('kb.col.reads')) ?>"><?= (int) $row['views'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="kb-qs">
  <h2 id="kb-qs" class="card__title"><?= $e($t('kb.open_questions')) ?></h2>
  <?php if ($questions === []): ?><p class="text-sm text-muted"><?= $e($t('kb.no_questions')) ?></p><?php else: ?>
  <ul class="plain-list stack">
  <?php foreach ($questions as $q): $qp = (string) $q['public_id']; ?>
    <li class="stack-sm kb-qitem">
      <p class="kb-q"><?= $e((string) $q['text']) ?></p>
      <p class="text-sm text-muted"><?= $e($q['asker_name'] . ' · ' . ($q['category_th'] ?? $t('kb.not_sure')) . ' · ' . $date((string) $q['created_at'])) ?></p>
      <?php if ($q['article_pid'] !== null): ?>
        <p class="text-sm"><?= $e($t('kb.being_answered')) ?> <a href="<?= $e($url('/kb/manage/a/' . $q['article_pid'])) ?>"><?= $e($t('kb.open_draft')) ?></a></p>
      <?php else: ?>
      <div class="row">
        <?= $ui('button', ['label' => $t('kb.answer_with_article'), 'href' => $url('/kb/manage/new?le=' . rawurlencode($lePid) . '&question=' . rawurlencode($qp)), 'variant' => 'primary', 'size' => 'sm', 'icon' => 'pencil']) ?>
      </div>
      <details><summary><?= $e($t('kb.decline')) ?></summary>
        <form method="post" action="<?= $e($url('/kb/manage/q/' . $qp . '/decline')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $ui('field', ['name' => 'reply', 'id' => 'f-reply-' . $qp, 'label' => $t('kb.field.reply'), 'required' => true, 'help' => $t('kb.reply_help')]) ?>
          <div class="row"><?= $ui('button', ['label' => $t('kb.decline'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
        </form>
      </details>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
