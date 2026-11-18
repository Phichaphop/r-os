<?php
/**
 * Answer a survey (once) — or read the summary HR wrote after analysing it.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $s @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canManage @var bool $canSurvey
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $s['public_id'];
?>
<?= $ui('page-header', ['title' => (string) $s['title'], 'subtitle' => $t('kaizen.window', ['from' => $date((string) $s['opens_on'], false), 'to' => $date((string) $s['closes_on'], false)]),
    'crumbs' => [[$t('kaizen.surveys_title'), $url('/kaizen/surveys')], [(string) $s['title'], null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($s['intro'] !== null): ?><p class="kz-text"><?= $e((string) $s['intro']) ?></p><?php endif; ?>
<?= $ui('alert', ['tone' => 'info', 'title' => (int) $s['anonymous'] === 1 ? $t('kaizen.anonymous_promise') : $t('kaizen.named_notice')]) ?>
<?php if ($s['status'] === 'analyzed'): ?>
<section class="card stack-sm" aria-labelledby="kz-sum"><h2 id="kz-sum" class="card__title"><?= $e($t('kaizen.summary')) ?></h2><p class="kz-text"><?= $e((string) $s['summary']) ?></p></section>
<?php elseif ($s['answered']): ?>
  <?= $ui('alert', ['tone' => 'success', 'title' => $t('kaizen.thanks')]) ?>
<?php elseif (!$s['answerable']): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('kaizen.error.survey_closed')]) ?>
<?php else: ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/kaizen/surveys/s/' . $pid)) ?>" class="card stack" novalidate><?= $csrf ?>
  <?php foreach ($s['questions'] as $n => $q): $k = 'q' . $q['id']; $err = $errors[$k] ?? null; $req = (int) $q['required'] === 1; $label = ($n + 1) . '. ' . $q['text']; ?>
    <?php if (in_array($q['kind'], ['scale', 'enps', 'choice', 'multi'], true)):
        $choices = $q['kind'] === 'scale' ? array_map('strval', range(1, 5)) : ($q['kind'] === 'enps' ? array_map('strval', range(0, 10)) : $q['choices']);
        $picked = $q['kind'] === 'multi' ? array_map('strval', (array) ($old[$k] ?? [])) : [(string) ($old[$k] ?? '')]; ?>
    <fieldset class="stack-sm kz-q"<?= $err !== null ? ' aria-invalid="true" aria-describedby="' . $e($k) . '-err"' : '' ?>>
      <legend class="field__label"><?= $e($label) ?> <span class="field__req"><?= $e($t($req ? 'form.required_tag' : 'form.optional_tag')) ?></span></legend>
      <?php if ($q['kind'] === 'scale'): ?><p class="field__help"><?= $e($t('kaizen.scale_help')) ?></p><?php elseif ($q['kind'] === 'enps'): ?><p class="field__help"><?= $e($t('kaizen.enps_help')) ?></p><?php endif; ?>
      <div class="<?= in_array($q['kind'], ['scale', 'enps'], true) ? 'kz-scale' : 'check-list' ?>">
        <?php foreach ($choices as $c): ?><label class="check"><input type="<?= $q['kind'] === 'multi' ? 'checkbox' : 'radio' ?>" name="<?= $e($k . ($q['kind'] === 'multi' ? '[]' : '')) ?>" value="<?= $e($c) ?>"<?= in_array($c, $picked, true) ? ' checked' : '' ?>> <?= $e($c) ?></label><?php endforeach; ?>
      </div>
      <?php if ($err !== null): ?><p class="field__error" id="<?= $e($k) ?>-err"><?= $e($err) ?></p><?php endif; ?>
    </fieldset>
    <?php else: ?>
      <?= $ui('field', ['name' => $k, 'type' => 'textarea', 'label' => $label, 'required' => $req, 'value' => (string) ($old[$k] ?? ''), 'error' => $err]) ?>
    <?php endif; ?>
  <?php endforeach; ?>
  <div class="row"><?= $ui('button', ['label' => $t('kaizen.send_answers'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
</form>
<?php endif; ?>
