<?php
/**
 * HR: courses of a company + add/edit.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array<string, mixed>|null $edit
 * @var array<string, string> $prereqs @var array<string, string> $errors @var array<string, mixed> $old
 */
$val = static fn (string $k, string $d = ''): string => $old !== [] ? (is_scalar($old[$k] ?? null) ? (string) $old[$k] : '') : (string) ($edit[$k] ?? $d);
$num = static fn (mixed $n): string => $n === null ? '' : rtrim(rtrim((string) $n, '0'), '.');
?>
<?= $ui('page-header', ['title' => $t('training.courses_title'), 'subtitle' => $t('training.courses_subtitle'), 'crumbs' => [[$t('training.admin_title'), $url('/training/admin?le=' . rawurlencode($lePid))], [$t('training.courses_title'), null]]]) ?>
<section class="card stack-sm" aria-labelledby="tc-l">
  <h2 id="tc-l" class="card__title"><?= $e($t('training.courses')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('training.none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($rows as $x): ?>
      <li><a href="<?= $e($url('/training/admin/courses/' . $x['public_id'])) ?>"><?= $e($x['code'] . ' · ' . $x['title']) ?></a> <?= $x['status'] === 'archived' ? $ui('badge', ['tone' => 'neutral', 'label' => $t('training.archived')]) : '' ?>
        <span class="d-block text-sm text-muted"><?= $e($t('training.course_line', ['h' => $num($x['hours']), 'pass' => $x['pass_score'] !== null ? (string) $x['pass_score'] : $t('training.attendance_only'), 'n' => (string) $x['sessions']])) ?>
          <?= (int) $x['needs_approval'] === 1 ? ' · ' . $e($t('training.needs_approval')) : '' ?><?= $x['valid_months'] !== null ? ' · ' . $e($t('training.valid_n', ['n' => (string) $x['valid_months']])) : '' ?><?= $x['prereq_code'] !== null ? ' · ' . $e($t('training.prereq_short', ['code' => (string) $x['prereq_code']])) : '' ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="tc-f">
  <h2 id="tc-f" class="card__title"><?= $e($edit !== null ? $t('training.course_edit', ['code' => (string) $edit['code']]) : $t('training.course_new')) ?></h2>
  <form method="post" action="<?= $e($url($edit !== null ? '/training/admin/courses/' . $edit['public_id'] : '/training/admin/courses')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'label' => $t('training.field.code'), 'required' => true, 'value' => $val('code'), 'placeholder' => 'SAFETY-01', 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'title', 'label' => $t('training.field.title'), 'required' => true, 'value' => $val('title'), 'error' => $errors['title'] ?? null]) ?>
      <?= $ui('field', ['name' => 'category', 'label' => $t('training.field.category'), 'value' => $val('category')]) ?>
      <?= $ui('field', ['name' => 'hours', 'label' => $t('training.field.hours'), 'required' => true, 'inputmode' => 'decimal', 'value' => $old !== [] ? $val('hours') : $num($edit['hours'] ?? null), 'error' => $errors['hours'] ?? null]) ?>
      <?= $ui('field', ['name' => 'pass_score', 'label' => $t('training.field.pass_score'), 'inputmode' => 'numeric', 'value' => $val('pass_score'), 'help' => $t('training.pass_help'), 'error' => $errors['pass_score'] ?? null]) ?>
      <?= $ui('field', ['name' => 'valid_months', 'label' => $t('training.field.valid_months'), 'inputmode' => 'numeric', 'value' => $val('valid_months'), 'help' => $t('training.valid_help'), 'error' => $errors['valid_months'] ?? null]) ?>
      <?= $ui('field', ['name' => 'prerequisite', 'type' => 'select', 'label' => $t('training.field.prerequisite'), 'value' => $val('prerequisite'), 'options' => ['' => '—'] + $prereqs, 'error' => $errors['prerequisite'] ?? null]) ?>
      <?php if ($edit !== null): ?><?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('training.col.status'), 'value' => $val('status', 'active'), 'options' => ['active' => $t('training.active'), 'archived' => $t('training.archived')]]) ?><?php endif; ?>
    </div>
    <label class="check"><input type="checkbox" name="needs_approval" value="1"<?= $val('needs_approval') === '1' ? ' checked' : '' ?>> <?= $e($t('training.field.needs_approval')) ?></label>
    <?= $ui('field', ['name' => 'required_titles', 'label' => $t('training.field.required_titles'), 'value' => $val('required_titles'), 'help' => $t('training.required_help')]) ?>
    <?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('training.field.description'), 'value' => $val('description')]) ?>
    <?= $ui('field', ['name' => 'objectives', 'type' => 'textarea', 'label' => $t('training.field.objectives'), 'value' => $val('objectives')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('training.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
