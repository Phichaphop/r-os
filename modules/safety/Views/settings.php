<?php
/**
 * Company settings: what each level means (no defaults), who is alerted at once for levels 3–4, anonymous reports.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var array<string, mixed> $set @var array<string, string> $errors @var array<string, mixed> $old @var bool $canManage @var string $here
 */
$fv = static fn (string $k, string $def): string => $old !== [] ? (is_scalar($old[$k] ?? null) ? (string) $old[$k] : '') : $def;
?>
<?= $ui('page-header', ['title' => $t('safety.settings_title'), 'subtitle' => $t('safety.settings_subtitle')]) ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url('/safety/settings')) ?>" class="card stack-sm" novalidate><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('safety.level_notes')) ?></legend>
    <p class="text-sm text-muted"><?= $e($t('safety.level_notes_help')) ?></p>
    <?php foreach ([1, 2, 3, 4] as $l): ?>
      <?= $ui('field', ['name' => 'note_' . $l, 'label' => $t('safety.level.' . $l), 'value' => $fv('note_' . $l, (string) $set['notes'][$l]), 'error' => $errors['note_' . $l] ?? null]) ?>
    <?php endforeach; ?>
  </fieldset>
  <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('safety.escalation')) ?></legend>
    <p class="text-sm text-muted"><?= $e($t('safety.escalation_help')) ?></p>
    <?php foreach ([3, 4] as $l): ?>
      <?= $ui('field', ['name' => 'escalate_' . $l, 'label' => $t('safety.escalate_level', ['level' => $t('safety.level.' . $l)]), 'value' => $fv('escalate_' . $l, (string) $set['escalate_names'][$l]), 'help' => $t('safety.usernames_help'), 'error' => $errors['escalate_' . $l] ?? null]) ?>
    <?php endforeach; ?>
  </fieldset>
  <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('safety.anonymous')) ?></legend>
    <label class="check"><input type="checkbox" name="anonymous_on" value="1"<?= $fv('anonymous_on', $set['anonymous_on'] ? '1' : '') === '1' ? ' checked' : '' ?>> <?= $e($t('safety.field.anonymous_on')) ?></label>
    <p class="text-sm text-muted"><?= $e($t('safety.anonymous_on_help')) ?></p>
  </fieldset>
  <div class="row"><?= $ui('button', ['label' => $t('safety.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
</form>
