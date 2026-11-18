<?php
/**
 * Read: search (words and synonyms), categories, most read, ask a new question, my questions.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var int|null $le @var string $q @var string $cat @var bool $searching @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $results
 * @var list<array<string, mixed>> $popular @var list<array<string, mixed>> $mine @var array<string, string> $errors @var array<string, mixed> $old @var string $here
 * @var bool $canAuthor @var bool $canReview
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$loc = (string) ($v['locale'] ?? 'th');
$cname = static fn (array $c): string => $loc === 'en' && $c['name_en'] !== null ? (string) $c['name_en'] : (string) $c['name_th'];
$copts = ['' => $t('kb.all_categories')];
foreach ($categories as $c) {
    $copts[(string) $c['public_id']] = $cname($c);
}
?>
<?= $ui('page-header', ['title' => $t('kb.title'), 'subtitle' => $t('kb.subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($le === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('kb.employees_only')]) ?>
<?php else: ?>
<form method="get" action="<?= $e($url('/kb')) ?>" class="card row kb-search" role="search">
  <?= $ui('field', ['name' => 'q', 'label' => $t('kb.search'), 'value' => $q, 'placeholder' => $t('kb.search_example')]) ?>
  <?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('kb.field.category'), 'value' => $cat, 'options' => $copts]) ?>
  <?= $ui('button', ['label' => $t('kb.find'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'search']) ?>
</form>
<?php if ($searching): ?>
<section class="card stack-sm" aria-labelledby="kb-res">
  <h2 id="kb-res" class="card__title"><?= $e($t('kb.results_n', ['n' => (string) count($results)])) ?></h2>
  <?php if ($results === []): ?><?= $ui('states', ['icon' => 'search', 'title' => $t('kb.no_results'), 'text' => $t('kb.no_results_help')]) ?><?php else: ?><?php $items = $results; include __DIR__ . '/_list.php'; ?><?php endif; ?>
</section>
<?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="kb-cat">
    <h2 id="kb-cat" class="card__title"><?= $e($t('kb.categories')) ?></h2>
    <?php if ($categories === []): ?><p class="text-sm text-muted"><?= $e($t('kb.no_categories')) ?></p><?php else: ?>
    <ul class="plain-list stack-sm"><?php foreach ($categories as $c): ?><li><a href="<?= $e($url('/kb?category=' . rawurlencode((string) $c['public_id']))) ?>"><?= $e($cname($c)) ?></a> <span class="text-sm text-muted">(<?= (int) $c['published'] ?>)</span></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
  <section class="card stack-sm" aria-labelledby="kb-pop">
    <h2 id="kb-pop" class="card__title"><?= $e($t('kb.popular')) ?></h2>
    <?php if ($popular === []): ?><p class="text-sm text-muted"><?= $e($t('kb.none_yet')) ?></p><?php else: ?><?php $items = $popular; include __DIR__ . '/_list.php'; ?><?php endif; ?>
  </section>
</div>
<section class="card stack-sm" aria-labelledby="kb-ask">
  <h2 id="kb-ask" class="card__title"><?= $e($t('kb.ask_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('kb.ask_help')) ?></p>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/kb/ask')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'text', 'type' => 'textarea', 'label' => $t('kb.field.your_question'), 'required' => true, 'value' => (string) ($old['text'] ?? ''), 'error' => $errors['text'] ?? null]) ?>
    <?= $ui('field', ['name' => 'category', 'id' => 'f-ask-category', 'type' => 'select', 'label' => $t('kb.field.category'), 'value' => (string) ($old['category'] ?? ''), 'options' => ['' => $t('kb.not_sure')] + array_slice($copts, 1, null, true), 'error' => $errors['category'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('kb.ask'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php endif; ?>
<?php if ($mine !== []): ?>
<section class="card stack-sm" aria-labelledby="my-questions-h" id="my-questions">
  <h2 id="my-questions-h" class="card__title"><?= $e($t('kb.my_questions')) ?></h2>
  <ul class="plain-list stack-sm">
  <?php foreach ($mine as $m): ?>
    <li><span class="kb-q"><?= $e((string) $m['text']) ?></span> <?= $ui('badge', ['tone' => ['open' => 'info', 'answered' => 'success', 'declined' => 'neutral'][$m['status']] ?? 'neutral', 'label' => $t('kb.question_status.' . $m['status'])]) ?>
      <?php if ($m['status'] === 'answered' && $m['article_pid'] !== null): ?> <a href="<?= $e($url('/kb/a/' . $m['article_pid'])) ?>"><?= $e($t('kb.read_answer')) ?></a><?php endif; ?>
      <?php if ($m['reply'] !== null): ?><br><span class="text-sm"><?= $e((string) $m['reply']) ?></span><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
