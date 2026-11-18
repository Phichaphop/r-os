<?php
/**
 * Evaluator link (one candidate, expires): a summary without contact details or ID, then score + recommendation.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $ev @var array<string, string> $errors @var bool $done @var string $token
 */
$sum = (array) $ev['summary'];
$closed = $done || $ev['submitted_at'] !== null || $ev['expired'];
?>
<?= $ui('page-header', ['title' => $t('recruit.eval_title'), 'subtitle' => (string) $ev['title'] . ' · ' . $t('recruit.kind.' . $ev['kind'])]) ?>
<?php if ($done): ?><?= $ui('alert', ['tone' => 'success', 'text' => $t('recruit.eval_thanks')]) ?>
<?php elseif ($ev['submitted_at'] !== null): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('recruit.eval_already')]) ?>
<?php elseif ($ev['expired']): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('recruit.eval_expired')]) ?><?php endif; ?>
<?php if (isset($errors['form'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['form']]) ?><?php endif; ?>
<div class="settings-grid">
  <section class="card stack-sm" aria-labelledby="ev-c">
    <h2 id="ev-c" class="card__title"><?= $e((string) $ev['name']) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('recruit.eval_for', ['name' => (string) $ev['evaluator_name']])) ?></p>
    <?php include __DIR__ . '/_summary.php'; ?>
  </section>
  <?php if (!$closed): ?>
  <section class="card stack" aria-labelledby="ev-f">
    <h2 id="ev-f" class="card__title"><?= $e($t('recruit.eval_form')) ?></h2>
    <form method="post" action="<?= $e($url('/recruit/eval/' . $token)) ?>" class="stack" novalidate>
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('recruit.field.score')) ?></legend>
        <div class="row"><?php foreach (['1', '2', '3', '4', '5'] as $s): ?><label class="choice"><input type="radio" name="score" value="<?= $s ?>"> <?= $s ?></label><?php endforeach; ?></div>
        <p class="text-sm text-muted"><?= $e($t('recruit.score_help')) ?></p>
        <?php if (isset($errors['score'])): ?><p class="field__error" role="alert"><?= $e($errors['score']) ?></p><?php endif; ?>
      </fieldset>
      <?= $ui('field', ['name' => 'recommendation', 'type' => 'select', 'label' => $t('recruit.field.recommendation'), 'required' => true, 'error' => $errors['recommendation'] ?? null,
          'options' => ['' => '—', 'hire' => $t('recruit.rec.hire'), 'maybe' => $t('recruit.rec.maybe'), 'no' => $t('recruit.rec.no')]]) ?>
      <?= $ui('field', ['name' => 'comment', 'type' => 'textarea', 'label' => $t('recruit.field.comment')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('recruit.eval_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
  </section>
  <?php endif; ?>
</div>
