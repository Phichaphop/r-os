<?php
/**
 * Complaint settings (complaints.manage): opening the intake (notice + legal confirmation + two handlers), SLAs, retention,
 * minimum group for figures, daily limit and the company's own policy text.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var array<string, mixed> $v
 * @var list<array{id: int, code: string, name: string}> $companies @var int $le @var array<string, mixed> $settings @var list<string> $missing
 * @var string|null $notice @var bool $alternate @var array<string, string> $errors @var array<string, mixed> $old @var string $today
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '"><input type="hidden" name="le" value="' . $le . '">';
$s = $settings;
$val = static fn (string $k) => is_scalar($old[$k] ?? null) ? (string) $old[$k] : (string) ($s[$k] ?? '');
?>
<?= $ui('page-header', ['title' => $t('complaints.settings.title'), 'subtitle' => $t('complaints.settings.subtitle')]) ?>
<?= $partial('@complaints/_companies', ['companies' => $companies, 'le' => $le, 'path' => '/complaints/settings']) ?>
<section class="card stack-sm" aria-labelledby="st-in">
  <h2 id="st-in" class="card__title"><?= $e($t('complaints.settings.intake')) ?></h2>
  <?php if ($s['open']): ?>
    <?= $ui('alert', ['tone' => 'success', 'text' => $t('complaints.settings.is_open', ['who' => (string) $s['legal_reviewer'], 'd' => $date((string) $s['legal_reviewed_on'], false)])]) ?>
    <form method="post" action="<?= $e($url('/complaints/settings/close')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'close_reason', 'id' => 'st-close', 'label' => $t('complaints.field.close_intake_reason'), 'required' => true, 'error' => $errors['close_reason'] ?? null]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('complaints.settings.close_intake'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
    </form>
  <?php else: ?>
    <p class="text-sm"><?= $e($t('complaints.settings.closed_help')) ?></p>
    <ul class="stack-sm text-sm">
      <li><?= $notice !== null ? '✓ ' . $e($t('complaints.ready.notice_ok')) . ' — <a href="' . $e($url($notice)) . '">' . $e($t('complaints.notice_link')) . '</a>' : '✗ ' . $e($t('complaints.ready.notice')) ?></li>
      <li><?= in_array('complaints.ready.handlers', $missing, true) ? '✗ ' . $e($t('complaints.ready.handlers')) : '✓ ' . $e($t('complaints.ready.handlers_ok')) ?></li>
      <li><?= $alternate ? '✓ ' . $e($t('complaints.ready.alternate_ok')) : 'ⓘ ' . $e($t('complaints.ready.alternate')) ?></li>
    </ul>
    <form method="post" action="<?= $e($url('/complaints/settings/open')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <label class="check"><input type="checkbox" name="legal_checked" value="1"<?= isset($errors['legal_checked']) ? ' aria-describedby="st-lc-err"' : '' ?>> <?= $e($t('complaints.field.legal_checked')) ?></label>
      <?php if (isset($errors['legal_checked'])): ?><p class="field__error" id="st-lc-err"><?= $e($errors['legal_checked']) ?></p><?php endif; ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'legal_reviewer', 'id' => 'st-lr', 'label' => $t('complaints.field.legal_reviewer'), 'required' => true, 'value' => $val('legal_reviewer'), 'error' => $errors['legal_reviewer'] ?? null]) ?>
        <?= $ui('field', ['name' => 'legal_reviewed_on', 'id' => 'st-ld', 'type' => 'date', 'label' => $t('complaints.field.legal_reviewed_on'), 'required' => true, 'value' => $val('legal_reviewed_on'), 'error' => $errors['legal_reviewed_on'] ?? null]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('complaints.settings.open_intake'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
    </form>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="st-set">
  <h2 id="st-set" class="card__title"><?= $e($t('complaints.settings.rules')) ?></h2>
  <form method="post" action="<?= $e($url('/complaints/settings/save')) ?>" class="stack" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'first_response_days', 'id' => 'st-fr', 'type' => 'number', 'label' => $t('complaints.field.first_response_days'), 'value' => $val('first_response_days'), 'help' => $t('complaints.no_default'), 'error' => $errors['first_response_days'] ?? null]) ?>
      <?= $ui('field', ['name' => 'resolve_days', 'id' => 'st-rd', 'type' => 'number', 'label' => $t('complaints.field.resolve_days'), 'value' => $val('resolve_days'), 'help' => $t('complaints.no_default'), 'error' => $errors['resolve_days'] ?? null]) ?>
      <?= $ui('field', ['name' => 'retention_days', 'id' => 'st-ret', 'type' => 'number', 'label' => $t('complaints.field.retention_days'), 'value' => $val('retention_days'), 'help' => $t('complaints.retention_help'), 'error' => $errors['retention_days'] ?? null]) ?>
    </div>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'min_group', 'id' => 'st-min', 'type' => 'number', 'label' => $t('complaints.field.min_group'), 'value' => $val('min_group'), 'help' => $t('complaints.min_group_help'), 'error' => $errors['min_group'] ?? null]) ?>
      <?= $ui('field', ['name' => 'daily_limit', 'id' => 'st-lim', 'type' => 'number', 'label' => $t('complaints.field.daily_limit'), 'value' => $val('daily_limit'), 'help' => $t('complaints.daily_limit_help'), 'error' => $errors['daily_limit'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'policy_text', 'id' => 'st-pol', 'type' => 'textarea', 'label' => $t('complaints.field.policy_text'), 'value' => $val('policy_text'), 'help' => $t('complaints.policy_help'), 'error' => $errors['policy_text'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('complaints.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
