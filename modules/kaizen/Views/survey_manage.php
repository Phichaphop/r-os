<?php
/**
 * HR: one survey — details, questions (draft only), open / close / write the summary.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, mixed> $s
 * @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var array<string, mixed> $audIn
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $here @var bool $canManage @var bool $canSurvey
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $s['public_id'];
$sst = (string) $s['status'];
$draft = $sst === 'draft';
$act = static fn (string $w): string => $url('/kaizen/surveys/m/' . $pid . '/' . $w);
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$kinds = [];
foreach (\Blue\Modules\Kaizen\SurveyService::KINDS as $k) {
    $kinds[$k] = $t('kaizen.kind.' . $k);
}
?>
<?= $ui('page-header', ['title' => (string) $s['title'], 'subtitle' => $t('kaizen.window', ['from' => $date((string) $s['opens_on'], false), 'to' => $date((string) $s['closes_on'], false)]),
    'crumbs' => [[$t('kaizen.survey_admin_title'), $url('/kaizen/surveys/admin')], [(string) $s['title'], null]],
    'actions' => $draft ? '' : $ui('button', ['label' => $t('kaizen.results'), 'href' => $url('/kaizen/surveys/m/' . $pid . '/results'), 'variant' => 'primary', 'icon' => 'layout-dashboard'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="kz-sf">
    <h2 id="kz-sf" class="card__title"><?= $e($t('kaizen.col.status')) ?>: <?php include __DIR__ . '/_sstatus.php'; ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('kaizen.audience')) ?></dt><dd><?= $e((string) $s['audience_text']) ?> · <?= $e($t('kaizen.people_n', ['n' => (string) $s['audience_size']])) ?></dd></div>
      <div><dt><?= $e($t('kaizen.field.survey_anonymous')) ?></dt><dd><?= $e((int) $s['anonymous'] === 1 ? $t('kaizen.anonymous_survey') : $t('kaizen.named_survey')) ?></dd></div>
      <div><dt><?= $e($t('kaizen.field.min_results')) ?></dt><dd><?= (int) $s['min_results'] ?></dd></div>
      <div><dt><?= $e($t('kaizen.col.answers')) ?></dt><dd><?= (int) $s['answers'] ?></dd></div>
      <?php if ($s['intro'] !== null): ?><div><dt><?= $e($t('kaizen.field.intro')) ?></dt><dd class="kz-text"><?= $e((string) $s['intro']) ?></dd></div><?php endif; ?>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="kz-sx">
    <h2 id="kz-sx" class="card__title"><?= $e($t('kaizen.next_step')) ?></h2>
    <?php if ($draft): ?>
      <p class="text-sm"><?= $e($t('kaizen.open_help')) ?></p>
      <form method="post" action="<?= $e($act('open')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('kaizen.open_survey'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'disabled' => $s['questions'] === []]) ?></form>
    <?php elseif ($sst === 'open'): ?>
      <p class="text-sm"><?= $e($t('kaizen.close_help')) ?></p>
      <form method="post" action="<?= $e($act('close')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('kaizen.close_survey'), 'type' => 'submit', 'variant' => 'secondary']) ?></form>
    <?php elseif ($sst === 'closed'): ?>
      <form method="post" action="<?= $e($act('analyze')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'summary', 'type' => 'textarea', 'label' => $t('kaizen.field.summary'), 'required' => true, 'value' => $form === 'analyze' ? (string) ($old['summary'] ?? '') : '', 'help' => $t('kaizen.summary_help'), 'error' => $fe('analyze', 'summary')]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('kaizen.analyze'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
      </form>
    <?php else: ?>
      <p class="kz-text"><?= $e((string) $s['summary']) ?></p>
    <?php endif; ?>
  </section>
</div>
<section class="card stack-sm" aria-labelledby="kz-sq">
  <h2 id="kz-sq" class="card__title"><?= $e($t('kaizen.questions_n', ['n' => (string) count($s['questions'])])) ?></h2>
  <?php if (!$draft): ?><p class="text-sm text-muted"><?= $e($t('kaizen.questions_fixed')) ?></p><?php endif; ?>
  <ol class="stack-sm">
  <?php foreach ($s['questions'] as $q): ?>
    <li class="row"><span><?= $e((string) $q['text']) ?> <span class="text-sm text-muted">(<?= $e($t('kaizen.kind.' . $q['kind'])) ?><?= $q['choices'] !== [] ? ': ' . $e(implode(' / ', $q['choices'])) : '' ?><?= (int) $q['required'] === 1 ? '' : ' · ' . $e($t('form.optional_tag')) ?>)</span></span>
      <?php if ($draft): ?><form method="post" action="<?= $e($act('question')) ?>"><?= $csrf ?><input type="hidden" name="remove" value="<?= (int) $q['id'] ?>"><?= $ui('button', ['label' => $t('kaizen.remove'), 'type' => 'submit', 'size' => 'sm', 'variant' => 'ghost', 'icon' => 'trash', 'attrs' => ['aria-label' => $t('kaizen.remove_question', ['q' => (string) $q['text']])]]) ?></form><?php endif; ?></li>
  <?php endforeach; ?>
  </ol>
  <?php if ($draft): ?>
  <form method="post" action="<?= $e($act('question')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <h3 class="text-md"><?= $e($t('kaizen.add_question')) ?></h3>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'kind', 'type' => 'select', 'label' => $t('kaizen.field.kind'), 'required' => true, 'value' => $form === 'question' ? (string) ($old['kind'] ?? '') : '', 'options' => ['' => $t('kaizen.pick')] + $kinds, 'error' => $fe('question', 'kind')]) ?>
      <?= $ui('field', ['name' => 'text', 'label' => $t('kaizen.field.question'), 'required' => true, 'value' => $form === 'question' ? (string) ($old['text'] ?? '') : '', 'error' => $fe('question', 'text')]) ?>
    </div>
    <?= $ui('field', ['name' => 'options', 'type' => 'textarea', 'label' => $t('kaizen.field.options'), 'value' => $form === 'question' ? (string) ($old['options'] ?? '') : '', 'help' => $t('kaizen.options_help'), 'error' => $fe('question', 'options')]) ?>
    <label class="check"><input type="checkbox" name="required" value="1" checked> <?= $e($t('kaizen.field.required')) ?></label>
    <div class="row"><?= $ui('button', ['label' => $t('kaizen.add_question'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
  </form>
  <details<?= $form === 'edit' ? ' open' : '' ?>><summary><?= $e($t('kaizen.edit_survey')) ?></summary>
    <form method="post" action="<?= $e($act('edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?php $sv = $form === 'edit' ? $old : $s; $serr = $form === 'edit' ? $errors : []; include __DIR__ . '/_survey_fields.php'; ?>
      <div class="row"><?= $ui('button', ['label' => $t('kaizen.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
