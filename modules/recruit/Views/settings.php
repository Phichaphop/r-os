<?php
/**
 * Recruitment settings of a company: the applicant privacy notice (written by the company / DPO — the system has no
 * default text) and the files an application must have before hiring.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var array{notice_text: string, notice_version: int, required_docs: list<string>} $s @var array<string, mixed>|null $retention @var array<string, string> $errors @var array<string, mixed>|null $old
 */
use Blue\Modules\Recruit\Lists;

$notice = is_array($old) ? (string) ($old['notice_text'] ?? '') : $s['notice_text'];
$req = is_array($old) ? array_map('strval', (array) ($old['required'] ?? [])) : $s['required_docs'];
?>
<?= $ui('page-header', ['title' => $t('recruit.settings_title'), 'subtitle' => $t('recruit.settings_subtitle')]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('recruit.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/recruit/settings?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack" aria-labelledby="rs-h">
  <h2 id="rs-h" class="card__title"><?= $e($t('recruit.notice_title')) ?></h2>
  <form method="post" action="<?= $e($url('/recruit/settings')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('recruit.notice_help')]) ?>
    <?= $ui('field', ['name' => 'notice_text', 'type' => 'textarea', 'label' => $t('recruit.notice_title'), 'value' => $notice, 'error' => $errors['notice_text'] ?? null,
        'help' => $s['notice_version'] > 0 ? $t('recruit.notice_version', ['n' => (string) $s['notice_version']]) : $t('recruit.notice_none')]) ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('recruit.required_docs')) ?></legend>
      <div class="grid grid--3">
        <?php foreach (array_keys(Lists::DOC_GROUPS) as $g): ?><label class="check"><input type="checkbox" name="required[]" value="<?= $e($g) ?>"<?= in_array($g, $req, true) ? ' checked' : '' ?>> <?= $e($t('recruit.group.' . $g)) ?></label><?php endforeach; ?>
      </div>
      <p class="text-sm text-muted"><?= $e($t('recruit.required_help')) ?></p>
    </fieldset>
    <div class="row"><?= $ui('button', ['label' => $t('common.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="rs-r">
  <h2 id="rs-r" class="card__title"><?= $e($t('recruit.retention_title')) ?></h2>
  <p class="text-sm"><?= $e($retention !== null ? $t('recruit.retention_rule', ['m' => (string) $retention['duration_months'], 'a' => (string) $retention['disposition_action']]) : $t('recruit.retention_none')) ?></p>
  <p class="text-sm"><a href="<?= $e($url('/admin/records/rules/applicant_unsuccessful')) ?>"><?= $e($t('recruit.retention_link')) ?></a></p>
</section>
