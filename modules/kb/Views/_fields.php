<?php
/**
 * Article fields (new / edit). Needs $fill (values), $ferr (errors), $categories, $needReason.
 * @var callable $e @var callable $t @var callable $ui @var array<string, mixed> $fill @var array<string, string> $ferr @var list<array<string, mixed>> $categories @var bool $needReason
 */
$fv = static fn (string $k): string => is_scalar($fill[$k] ?? null) ? (string) $fill[$k] : '';
$copts = ['' => $t('kb.pick')];
foreach ($categories as $c) {
    $copts[(string) $c['public_id']] = (string) $c['name_th'];
}
?>
<?= $ui('field', ['name' => 'category', 'type' => 'select', 'label' => $t('kb.field.category'), 'required' => true, 'value' => $fv('category'), 'options' => $copts, 'error' => $ferr['category'] ?? null]) ?>
<?= $ui('field', ['name' => 'question_th', 'label' => $t('kb.field.question_th'), 'required' => true, 'value' => $fv('question_th'), 'full' => true, 'error' => $ferr['question_th'] ?? null]) ?>
<?= $ui('field', ['name' => 'answer_th', 'type' => 'textarea', 'label' => $t('kb.field.answer_th'), 'required' => true, 'value' => $fv('answer_th'), 'help' => $t('kb.answer_help'), 'error' => $ferr['answer_th'] ?? null]) ?>
<details<?= $fv('question_en') !== '' || isset($ferr['question_en']) ? ' open' : '' ?>><summary><?= $e($t('kb.english_version')) ?></summary>
  <div class="stack-sm">
    <?= $ui('field', ['name' => 'question_en', 'label' => $t('kb.field.question_en'), 'value' => $fv('question_en'), 'full' => true, 'error' => $ferr['question_en'] ?? null]) ?>
    <?= $ui('field', ['name' => 'answer_en', 'type' => 'textarea', 'label' => $t('kb.field.answer_en'), 'value' => $fv('answer_en'), 'error' => $ferr['answer_en'] ?? null]) ?>
  </div>
</details>
<div class="grid grid--2">
  <?= $ui('field', ['name' => 'tags', 'label' => $t('kb.field.tags'), 'value' => $fv('tags'), 'help' => $t('kb.tags_help'), 'error' => $ferr['tags'] ?? null]) ?>
  <?= $ui('field', ['name' => 'source', 'label' => $t('kb.field.source'), 'value' => $fv('source'), 'help' => $t('kb.source_help'), 'error' => $ferr['source'] ?? null]) ?>
</div>
<?= $ui('field', ['name' => 'change_reason', 'label' => $t('kb.field.change_reason'), 'required' => $needReason, 'value' => $fv('change_reason'), 'error' => $ferr['change_reason'] ?? null]) ?>
