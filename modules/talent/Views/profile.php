<?php
/**
 * One person in the pool: details (editable), CV, status, star, put forward for a job, applications, contact history, erase.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $p @var list<array<string, mixed>> $contacts @var list<array<string, mixed>> $apps @var list<array<string, mixed>> $matches
 * @var list<array<string, mixed>> $postings @var bool $canErase @var array<string, string> $errors @var string $form @var array<string, mixed> $old
 */
use Blue\Modules\Recruit\Lists;
use Blue\Modules\Talent\TalentService;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/talent/p/' . $p['public_id'];
$live = $p['anonymized_at'] === null;
$o = static fn (string $k, string $d = ''): string => $form === 'edit' && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$actions = '';
if ($live) {
    $on = (int) $p['starred'] === 1;
    $actions = '<form method="post" action="' . $e($url($base . '/star')) . '">' . $csrf . '<input type="hidden" name="on" value="' . ($on ? '0' : '1') . '">'
        . $ui('button', ['label' => $t($on ? 'talent.unstar' : 'talent.star'), 'type' => 'submit', 'variant' => $on ? 'secondary' : 'ghost', 'icon' => 'star']) . '</form>';
}
?>
<?= $ui('page-header', ['title' => $live ? (string) $p['name'] : $t('talent.anonymized'), 'subtitle' => $t('talent.status.' . $p['status']) . ' · ' . $t('talent.source.' . $p['source']),
    'crumbs' => [[$t('talent.title'), $url('/talent')], [$live ? (string) $p['name'] : '—', null]], 'actions' => $actions]) ?>
<?php if (!$live): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('talent.anonymized_help', ['d' => $date((string) $p['anonymized_at'], false)])]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="tp-d">
    <h2 id="tp-d" class="card__title"><?= $e($t('talent.details')) ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('talent.field.email')) ?></dt><dd><?= $e((string) ($p['email'] ?? '—')) ?></dd></div>
      <div><dt><?= $e($t('talent.field.phone')) ?></dt><dd><?= $e((string) ($p['phone'] ?? '—')) ?></dd></div>
      <div><dt><?= $e($t('talent.field.positions')) ?></dt><dd><?= $e((string) $p['positions'] !== '' ? (string) $p['positions'] : '—') ?></dd></div>
      <div><dt><?= $e($t('talent.field.skills')) ?></dt><dd><?= $e((string) $p['skills'] !== '' ? (string) $p['skills'] : '—') ?></dd></div>
      <div><dt><?= $e($t('talent.field.experience_years')) ?></dt><dd><?= $e($p['experience_years'] !== null ? (string) (float) $p['experience_years'] : '—') ?></dd></div>
      <div><dt><?= $e($t('talent.field.education_level')) ?></dt><dd><?= $e($p['education_level'] !== null ? $t('recruit.edu.' . $p['education_level']) : '—') ?></dd></div>
      <?php if ($p['referred_by'] !== null): ?><div><dt><?= $e($t('talent.field.referred_by')) ?></dt><dd><?= $e((string) $p['referred_by']) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('talent.field.consent_at')) ?></dt><dd><?= $e($date((string) $p['consent_at'], false)) ?> · <?= $e((string) $p['consent_basis']) ?></dd></div>
      <div><dt><?= $e($t('talent.field.retention_until')) ?></dt><dd><?= $e($p['retention_until'] !== null ? $date((string) $p['retention_until'], false) : $t('talent.no_rule_short')) ?></dd></div>
      <?php if ((string) ($p['notes'] ?? '') !== ''): ?><div><dt><?= $e($t('talent.field.notes')) ?></dt><dd><?= $e((string) $p['notes']) ?></dd></div><?php endif; ?>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="tp-cv">
    <h2 id="tp-cv" class="card__title"><?= $e($t('talent.field.cv')) ?></h2>
    <?php if ($p['cv_document_pid'] !== null): ?>
      <p><?= $ui('button', ['label' => $t('talent.cv_open', ['name' => (string) $p['cv_filename']]), 'href' => $url($base . '/cv'), 'variant' => 'secondary', 'icon' => 'download']) ?></p>
    <?php else: ?><p class="text-sm text-muted"><?= $e($t('talent.no_cv')) ?></p><?php endif; ?>
    <?php if ($live): ?>
    <form method="post" action="<?= $e($url($base . '/cv')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'cv', 'type' => 'file', 'label' => $t('talent.cv_new'), 'accept' => '.pdf,.docx,.jpg,.jpeg,.png', 'help' => $t('talent.cv_help'), 'error' => $err('cv', 'cv')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('talent.cv_upload'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'upload']) ?></div>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php if ($live): ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="tp-l">
    <h2 id="tp-l" class="card__title"><?= $e($t('talent.put_forward')) ?></h2>
    <?php if ($matches !== []): ?>
      <p class="text-sm"><?= $e($t('talent.matches')) ?></p>
      <ul class="plain-list"><?php foreach ($matches as $m): ?><li><?= $ui('icon', ['name' => 'briefcase', 'size' => 'sm']) ?> <?= $e((string) $m['title']) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($postings === []): ?><p class="text-sm text-muted"><?= $e($t('talent.no_postings')) ?></p><?php else: ?>
    <form method="post" action="<?= $e($url($base . '/link')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'posting', 'type' => 'select', 'label' => $t('talent.posting'), 'options' => array_column(array_map(static fn (array $x): array => ['k' => (string) $x['public_id'], 'v' => (string) $x['title'] . ' · ' . $t('recruit.post_status.' . $x['status'])], $postings), 'v', 'k')]) ?>
      <p class="text-sm text-muted"><?= $e($t('talent.put_forward_help')) ?></p>
      <div class="row"><?= $ui('button', ['label' => $t('talent.put_forward'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
    </form>
    <?php endif; ?>
  </section>
  <section class="card stack-sm" aria-labelledby="tp-s">
    <h2 id="tp-s" class="card__title"><?= $e($t('talent.col.status')) ?></h2>
    <form method="post" action="<?= $e($url($base . '/status')) ?>" class="row" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('talent.col.status'), 'value' => (string) $p['status'],
          'options' => array_combine(TalentService::STATUSES, array_map(static fn (string $x): string => $t('talent.status.' . $x), TalentService::STATUSES))]) ?>
      <?= $ui('button', ['label' => $t('talent.save'), 'type' => 'submit', 'variant' => 'secondary']) ?>
    </form>
  </section>
</div>
<?php endif; ?>
<?php if ($apps !== []): ?>
<section class="card stack-sm" aria-labelledby="tp-a">
  <h2 id="tp-a" class="card__title"><?= $e($t('talent.applications')) ?></h2>
  <ul class="plain-list"><?php foreach ($apps as $a): ?><li><a href="<?= $e($url('/recruit/c/' . $a['public_id'])) ?>"><?= $e((string) $a['title']) ?></a> · <?= $e($t('recruit.stage.' . $a['stage'])) ?> · <?= $e($date((string) $a['created_at'])) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="tp-c">
  <h2 id="tp-c" class="card__title"><?= $e($t('talent.contacts')) ?></h2>
  <?php if ($live): ?>
  <form method="post" action="<?= $e($url($base . '/contact')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'channel', 'type' => 'select', 'label' => $t('talent.channel'), 'value' => 'phone', 'options' => array_combine(['phone', 'email', 'meeting', 'message', 'other'], array_map(static fn (string $x): string => $t('talent.kind.' . $x), ['phone', 'email', 'meeting', 'message', 'other']))]) ?>
    </div>
    <?= $ui('field', ['name' => 'note', 'type' => 'textarea', 'label' => $t('talent.contact_note'), 'required' => true, 'error' => $err('contact', 'note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('talent.contact_add'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></div>
  </form>
  <?php endif; ?>
  <ol class="plain-list stack-sm">
    <?php foreach ($contacts as $x): $k = (string) $x['kind']; ?>
      <li>
        <strong><?= $e($t('talent.kind.' . $k)) ?></strong><?php if ($x['note'] !== null && $x['note'] !== ''): ?> — <?= $e(match ($k) { 'status' => $t('talent.status.' . $x['note']), 'created' => $t('talent.source.' . $x['note']), default => (string) $x['note'] }) ?><?php endif; ?>
        <span class="d-block text-sm text-muted"><?= $e((string) ($x['actor'] ?? $t('talent.system'))) ?> · <?= $e($date((string) $x['occurred_at'])) ?></span></li>
    <?php endforeach; ?>
  </ol>
</section>
<?php if ($live): ?>
<details class="card stack-sm"<?= $form === 'edit' ? ' open' : '' ?>>
  <summary class="card__title"><?= $e($t('talent.edit')) ?></summary>
  <form method="post" action="<?= $e($url($base)) ?>" class="stack" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'name', 'label' => $t('talent.field.name'), 'required' => true, 'value' => $o('name', (string) $p['name']), 'error' => $err('edit', 'name')]) ?>
      <?= $ui('field', ['name' => 'email', 'type' => 'email', 'label' => $t('talent.field.email'), 'value' => $o('email', (string) ($p['email'] ?? '')), 'error' => $err('edit', 'email')]) ?>
      <?= $ui('field', ['name' => 'phone', 'type' => 'tel', 'label' => $t('talent.field.phone'), 'value' => $o('phone', (string) ($p['phone'] ?? '')), 'error' => $err('edit', 'phone')]) ?>
      <?= $ui('field', ['name' => 'positions', 'label' => $t('talent.field.positions'), 'value' => $o('positions', (string) $p['positions'])]) ?>
      <?= $ui('field', ['name' => 'experience_years', 'label' => $t('talent.field.experience_years'), 'value' => $o('experience_years', $p['experience_years'] !== null ? (string) (float) $p['experience_years'] : ''), 'inputmode' => 'decimal', 'error' => $err('edit', 'experience_years')]) ?>
      <?= $ui('field', ['name' => 'education_level', 'type' => 'select', 'label' => $t('talent.field.education_level'), 'value' => $o('education_level', (string) ($p['education_level'] ?? '')),
          'options' => ['' => '—'] + array_combine(Lists::EDU_LEVELS, array_map(static fn (string $x): string => $t('recruit.edu.' . $x), Lists::EDU_LEVELS))]) ?>
    </div>
    <?= $ui('field', ['name' => 'skills', 'type' => 'textarea', 'label' => $t('talent.field.skills'), 'value' => $o('skills', (string) $p['skills'])]) ?>
    <?= $ui('field', ['name' => 'notes', 'type' => 'textarea', 'label' => $t('talent.field.notes'), 'value' => $o('notes', (string) ($p['notes'] ?? ''))]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('talent.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</details>
<?php if ($canErase): ?>
<details class="card stack-sm"<?= $form === 'erase' ? ' open' : '' ?>>
  <summary class="card__title"><?= $e($t('talent.erase')) ?></summary>
  <p class="text-sm text-muted"><?= $e($t('talent.erase_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/erase')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('talent.erase_reason'), 'required' => true, 'error' => $err('erase', 'reason')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('talent.erase'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'trash']) ?></div>
  </form>
</details>
<?php endif; ?>
<?php endif; ?>
