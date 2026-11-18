<?php
/**
 * One notice version (privacy.manage): edit the draft, submit, publish / return (another officer), withdraw, acknowledgements.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $n @var array<string, string> $errors @var list<string> $gaps @var bool $canApprove @var bool $canWithdraw
 * @var array{done: list<array<string, mixed>>, waiting: list<array<string, mixed>>}|null $acks @var string $leCode @var string $today
 */
use Blue\Modules\Privacy\PrivacyCenterService;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $n['public_id'];
$act = static fn (string $a): string => '/privacy/center/n/' . $pid . '/' . $a;
$tone = ['draft' => 'neutral', 'pending' => 'warning', 'published' => 'success', 'superseded' => 'neutral', 'withdrawn' => 'danger'];
?>
<?= $ui('page-header', ['title' => (string) $n['title'], 'subtitle' => $t('privacy.audience.' . $n['audience']) . ' · ' . $t('privacy.version_n', ['n' => (string) $n['version']]),
    'crumbs' => [[$t('privacy.center.title'), $url('/privacy/center?le=' . (int) $n['legal_entity_id'])], [$t('privacy.version_n', ['n' => (string) $n['version']]), null]]]) ?>
<div class="row">
  <?= $ui('badge', ['tone' => $tone[$n['status']] ?? 'neutral', 'label' => $t('privacy.status.' . $n['status'])]) ?>
  <?php if ((int) $n['ai_draft'] === 1 && in_array($n['status'], ['draft', 'pending'], true)): ?><?= $ui('badge', ['tone' => 'warning', 'icon' => 'robot-off', 'label' => $t('privacy.ai_label')]) ?><?php endif; ?>
  <?php if ($n['status'] === 'published'): ?><a href="<?= $e($url('/privacy-notice/' . rawurlencode($leCode) . '/' . $n['audience'])) ?>" target="_blank" rel="noopener"><?= $e($t('privacy.public_link')) ?><span class="sr-only"> (<?= $e($t('home.new_tab')) ?>)</span></a><?php endif; ?>
</div>
<?php if ($n['return_note'] !== null && $n['status'] === 'draft'): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('privacy.returned_note'), 'text' => (string) $n['return_note']]) ?><?php endif; ?>
<?php if ($gaps !== [] && in_array($n['status'], ['draft', 'pending'], true)): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('privacy.gaps_title', ['n' => (string) count($gaps)]), 'text' => implode(' · ', $gaps)]) ?>
<?php endif; ?>

<?php if ($n['status'] === 'draft'): ?>
<section class="card stack" aria-labelledby="ne-h">
  <h2 id="ne-h" class="card__title"><?= $e($t('privacy.edit_draft')) ?></h2>
  <?php if ((int) $n['ai_draft'] === 1): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('privacy.ai_help')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url($act('save'))) ?>" class="stack privacy-editor" novalidate>
    <?= $csrf ?>
    <?= $ui('field', ['name' => 'title', 'id' => 'nt-title', 'label' => $t('privacy.field.title'), 'required' => true, 'value' => (string) $n['title'], 'error' => $errors['title'] ?? null]) ?>
    <?= $ui('field', ['name' => 'body', 'id' => 'nt-body', 'type' => 'textarea', 'label' => $t('privacy.field.body'), 'required' => true, 'value' => (string) $n['body'], 'help' => $t('privacy.body_help'), 'error' => $errors['body'] ?? null, 'full' => true]) ?>
    <?= $ui('field', ['name' => 'body_en', 'id' => 'nt-body-en', 'type' => 'textarea', 'label' => $t('privacy.field.body_en'), 'value' => (string) ($n['body_en'] ?? ''), 'error' => $errors['body_en'] ?? null, 'full' => true]) ?>
    <?php if ($n['audience'] !== 'complainant'): ?>
      <label class="check"><input type="checkbox" name="requires_ack" value="1"<?= (int) $n['requires_ack'] === 1 ? ' checked' : '' ?>> <?= $e($t('privacy.field.requires_ack')) ?></label>
    <?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.save'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'pencil']) ?></div>
  </form>
  <div class="row">
    <form method="post" action="<?= $e($url($act('submit'))) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('privacy.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
    <form method="post" action="<?= $e($url($act('delete'))) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('privacy.delete_draft'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?></form>
  </div>
  <p class="text-sm text-muted"><?= $e($t('privacy.submit_help')) ?></p>
</section>
<?php else: ?>
<section class="card stack-sm privacy-text" aria-labelledby="nb-h">
  <h2 id="nb-h" class="card__title"><?= $e($t('privacy.field.body')) ?></h2>
  <?= PrivacyCenterService::render((string) $n['body']) ?>
  <?php if (!empty($n['body_en'])): ?><h3><?= $e($t('privacy.field.body_en')) ?></h3><div lang="en"><?= PrivacyCenterService::render((string) $n['body_en']) ?></div><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($n['status'] === 'pending'): ?>
<section class="card stack" aria-labelledby="nr-h">
  <h2 id="nr-h" class="card__title"><?= $e($t('privacy.review')) ?></h2>
  <?php if (!$canApprove): ?>
    <p class="text-sm text-muted"><?= $e($t('privacy.review_other')) ?></p>
  <?php else: ?>
  <form method="post" action="<?= $e($url($act('publish'))) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <label class="check"><input type="checkbox" name="legal_checked" value="1"<?= isset($errors['legal_checked']) ? ' aria-invalid="true" aria-describedby="lc-err"' : '' ?>> <?= $e($t('privacy.legal_checked')) ?></label>
    <?= $ui('field', ['name' => 'note', 'id' => 'nr-note', 'label' => $t('privacy.field.legal_note'), 'required' => true, 'help' => $t('privacy.legal_note_help')]) ?>
    <?php if (isset($errors['legal_checked'])): ?><p class="field__error" id="lc-err"><?= $e($errors['legal_checked']) ?></p><?php endif; ?>
    <?= $ui('field', ['name' => 'effective_from', 'id' => 'nr-eff', 'type' => 'date', 'label' => $t('privacy.field.effective_from'), 'required' => true, 'value' => $today, 'error' => $errors['effective_from'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.publish'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
  <form method="post" action="<?= $e($url($act('return'))) ?>" class="row" novalidate>
    <?= $csrf ?>
    <label class="sr-only" for="nr-ret"><?= $e($t('privacy.return_note')) ?></label>
    <input class="input" id="nr-ret" name="note" maxlength="500" placeholder="<?= $e($t('privacy.return_note')) ?>"<?= isset($errors['note']) ? ' aria-invalid="true"' : '' ?>>
    <?= $ui('button', ['label' => $t('privacy.return'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'arrow-back-up']) ?>
  </form>
  <?php if (isset($errors['note'])): ?><p class="field__error"><?= $e($errors['note']) ?></p><?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($n['status'] === 'published'): ?>
<section class="card stack-sm" aria-labelledby="np-h">
  <h2 id="np-h" class="card__title"><?= $e($t('privacy.published_facts')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('privacy.field.effective_from')) ?></dt><dd><?= $e((string) $n['effective_from']) ?></dd></div>
    <div><dt><?= $e($t('privacy.field.legal_note')) ?></dt><dd><?= $e((string) $n['legal_checked_note']) ?></dd></div>
    <div><dt>SHA-256</dt><dd><code class="privacy-hash"><?= $e((string) $n['sha256']) ?></code></dd></div>
  </dl>
  <?php if ($acks !== null): ?>
    <h3><?= $e($t('privacy.acks_title', ['a' => (string) count($acks['done']), 'n' => (string) (count($acks['done']) + count($acks['waiting']))])) ?></h3>
    <?php if ($acks['waiting'] !== []): ?><p class="text-sm"><?= $e($t('privacy.acks_waiting')) ?>: <?= $e(implode(', ', array_map(static fn (array $w): string => (string) $w['name'], $acks['waiting']))) ?></p><?php endif; ?>
  <?php endif; ?>
  <?php if (!empty($canWithdraw)): ?>
  <form method="post" action="<?= $e($url($act('withdraw'))) ?>" class="row" novalidate>
    <?= $csrf ?>
    <label class="sr-only" for="nw-reason"><?= $e($t('privacy.withdraw_reason')) ?></label>
    <input class="input" id="nw-reason" name="reason" maxlength="500" placeholder="<?= $e($t('privacy.withdraw_reason')) ?>"<?= isset($errors['reason']) ? ' aria-invalid="true"' : '' ?>>
    <?= $ui('button', ['label' => $t('privacy.withdraw'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'eye-off']) ?>
  </form>
  <?php if (isset($errors['reason'])): ?><p class="field__error"><?= $e($errors['reason']) ?></p><?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="nh-h">
  <h2 id="nh-h" class="card__title"><?= $e($t('privacy.history')) ?></h2>
  <ul class="plain-list">
    <?php foreach ($n['history'] as $h): ?><li><?= $e($date((string) $h['created_at'])) ?> · <?= $e($t('privacy.event.' . $h['action'])) ?> · <?= $e((string) ($h['actor'] ?? '—')) ?><?= $h['note'] !== null ? ' — ' . $e((string) $h['note']) : '' ?></li><?php endforeach; ?>
  </ul>
  <?php if ($n['older'] !== []): ?>
    <p class="text-sm"><?= $e($t('privacy.other_versions')) ?>:
      <?php foreach ($n['older'] as $o): ?><a href="<?= $e($url('/privacy/center/n/' . $o['public_id'])) ?>"><?= $e($t('privacy.version_n', ['n' => (string) $o['version']])) ?></a> (<?= $e($t('privacy.status.' . $o['status'])) ?>) <?php endforeach; ?></p>
  <?php endif; ?>
</section>
