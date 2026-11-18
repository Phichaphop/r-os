<?php
/**
 * One breach record: contain → assess → decide (privacy.approve) → notification dates → close with lessons.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $b @var array<string, string> $errors @var int|null $hours @var bool $canApprove
 */
use Blue\Modules\Privacy\PrivacyCenterService;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$act = static fn (string $s): string => '/privacy/center/b/' . $b['public_id'] . '/' . $s;
$open = $b['status'] === 'open';
?>
<?= $ui('page-header', ['title' => $t('privacy.breach.title', ['ref' => (string) $b['ref']]), 'subtitle' => $t('privacy.field.discovered_at') . ': ' . $date((string) $b['discovered_at']),
    'crumbs' => [[$t('privacy.center.title'), $url('/privacy/center?tab=breaches&le=' . (int) $b['legal_entity_id'])], [(string) $b['ref'], null]]]) ?>
<?php if ($b['deadline'] === null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('privacy.breach.no_deadline')]) ?>
<?php else: ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('privacy.breach.deadline', ['at' => $date((string) $b['deadline']), 'h' => (string) $hours])]) ?>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="bs-h">
  <h2 id="bs-h" class="card__title"><?= $e($t('privacy.breach.what')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('privacy.field.summary')) ?></dt><dd><?= PrivacyCenterService::render((string) $b['summary']) ?></dd></div>
    <div><dt><?= $e($t('privacy.field.data_categories')) ?></dt><dd><?= $e((string) $b['data_categories']) ?></dd></div>
    <div><dt><?= $e($t('privacy.field.people_estimate')) ?></dt><dd><?= $e($b['people_estimate'] === null ? $t('privacy.unknown') : (string) $b['people_estimate']) ?></dd></div>
    <div><dt><?= $e($t('privacy.field.status')) ?></dt><dd><?= $ui('badge', ['tone' => $open ? 'warning' : 'neutral', 'label' => $t('privacy.status.' . $b['status'])]) ?></dd></div>
  </dl>
</section>

<section class="card stack" aria-labelledby="b1-h">
  <h2 id="b1-h" class="card__title">1 · <?= $e($t('privacy.breach.contain')) ?></h2>
  <?php if ($b['containment'] !== null): ?><div><?= PrivacyCenterService::render((string) $b['containment']) ?></div><?php endif; ?>
  <?php if ($open): ?>
  <form method="post" action="<?= $e($url($act('contain'))) ?>" class="stack" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'containment', 'id' => 'b-contain', 'type' => 'textarea', 'label' => $t('privacy.field.containment'), 'required' => true, 'value' => (string) ($b['containment'] ?? ''), 'error' => $errors['containment'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
  <?php endif; ?>
</section>

<section class="card stack" aria-labelledby="b2-h">
  <h2 id="b2-h" class="card__title">2 · <?= $e($t('privacy.breach.assess')) ?></h2>
  <p><?= $e($t('privacy.field.risk')) ?>: <strong><?= $e($t('privacy.risk.' . $b['risk'])) ?></strong><?= $b['risk_reason'] !== null ? ' — ' . $e((string) $b['risk_reason']) : '' ?></p>
  <?php if ($open): ?>
  <form method="post" action="<?= $e($url($act('assess'))) ?>" class="stack" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'risk', 'id' => 'b-risk', 'type' => 'select', 'label' => $t('privacy.field.risk'), 'value' => (string) $b['risk'],
          'options' => array_combine(PrivacyCenterService::RISKS, array_map(static fn (string $x): string => $t('privacy.risk.' . $x), PrivacyCenterService::RISKS))]) ?>
      <?= $ui('field', ['name' => 'risk_reason', 'id' => 'b-risk-why', 'label' => $t('privacy.field.risk_reason'), 'required' => true, 'value' => (string) ($b['risk_reason'] ?? ''), 'error' => $errors['risk_reason'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
  <?php endif; ?>
</section>

<section class="card stack" aria-labelledby="b3-h">
  <h2 id="b3-h" class="card__title">3 · <?= $e($t('privacy.breach.decide')) ?></h2>
  <p><?= $e($t('privacy.field.decision')) ?>: <strong><?= $e($t('privacy.decision.' . $b['decision'])) ?></strong><?= $b['decision_reason'] !== null ? ' — ' . $e((string) $b['decision_reason']) : '' ?></p>
  <p class="text-sm text-muted"><?= $e($t('privacy.breach.decide_help')) ?></p>
  <?php if ($open && $canApprove): ?>
  <form method="post" action="<?= $e($url($act('decide'))) ?>" class="stack" novalidate><?= $csrf ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('privacy.field.decision')) ?></legend>
      <label class="choice"><input type="radio" name="decision" value="notify"<?= $b['decision'] === 'notify' ? ' checked' : '' ?>> <?= $e($t('privacy.decision.notify')) ?></label>
      <label class="choice"><input type="radio" name="decision" value="no_notify"<?= $b['decision'] === 'no_notify' ? ' checked' : '' ?>> <?= $e($t('privacy.decision.no_notify')) ?></label>
    </fieldset>
    <?= $ui('field', ['name' => 'decision_reason', 'id' => 'b-dec-why', 'type' => 'textarea', 'label' => $t('privacy.field.decision_reason'), 'required' => true, 'value' => (string) ($b['decision_reason'] ?? ''), 'error' => $errors['decision_reason'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
  <?php endif; ?>
</section>

<?php if ($b['decision'] === 'notify'): ?>
<section class="card stack" aria-labelledby="b4-h">
  <h2 id="b4-h" class="card__title">4 · <?= $e($t('privacy.breach.notified')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('privacy.field.authority_notified_at')) ?></dt><dd><?= $e($b['authority_notified_at'] === null ? '—' : $date((string) $b['authority_notified_at'])) ?><?= $b['authority_ref'] !== null ? ' · ' . $e((string) $b['authority_ref']) : '' ?></dd></div>
    <div><dt><?= $e($t('privacy.field.subjects_notified_at')) ?></dt><dd><?= $e($b['subjects_notified_at'] === null ? '—' : $date((string) $b['subjects_notified_at'])) ?></dd></div>
  </dl>
  <?php if ($open): ?>
  <form method="post" action="<?= $e($url($act('notified'))) ?>" class="stack" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'authority_notified_at', 'id' => 'b-auth', 'type' => 'datetime-local', 'label' => $t('privacy.field.authority_notified_at'), 'error' => $errors['authority_notified_at'] ?? null]) ?>
      <?= $ui('field', ['name' => 'authority_ref', 'id' => 'b-auth-ref', 'label' => $t('privacy.field.authority_ref')]) ?>
      <?= $ui('field', ['name' => 'subjects_notified_at', 'id' => 'b-subj', 'type' => 'datetime-local', 'label' => $t('privacy.field.subjects_notified_at'), 'error' => $errors['subjects_notified_at'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card stack" aria-labelledby="b5-h">
  <h2 id="b5-h" class="card__title"><?= $e($t($open ? 'privacy.breach.close' : 'privacy.breach.closed')) ?></h2>
  <?php if ($open): ?>
  <form method="post" action="<?= $e($url($act('close'))) ?>" class="stack" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'lessons', 'id' => 'b-lessons', 'type' => 'textarea', 'label' => $t('privacy.field.lessons'), 'required' => true, 'error' => $errors['lessons'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.breach.close'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
  <?php else: ?>
    <div><?= PrivacyCenterService::render((string) $b['lessons']) ?></div>
    <form method="post" action="<?= $e($url($act('reopen'))) ?>" class="row" novalidate><?= $csrf ?>
      <label class="sr-only" for="b-reopen"><?= $e($t('privacy.reopen_reason')) ?></label>
      <input class="input" id="b-reopen" name="reason" maxlength="500" placeholder="<?= $e($t('privacy.reopen_reason')) ?>">
      <?= $ui('button', ['label' => $t('privacy.breach.reopen'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'refresh']) ?>
    </form>
    <?php if (isset($errors['reason'])): ?><p class="field__error"><?= $e($errors['reason']) ?></p><?php endif; ?>
  <?php endif; ?>
</section>

<section class="card stack-sm" aria-labelledby="bh-h">
  <h2 id="bh-h" class="card__title"><?= $e($t('privacy.history')) ?></h2>
  <ul class="plain-list">
    <?php foreach ($b['history'] as $h): ?><li><?= $e($date((string) $h['created_at'])) ?> · <?= $e($t('privacy.event.' . $h['action'])) ?> · <?= $e((string) ($h['actor'] ?? '—')) ?><?= $h['note'] !== null ? ' — ' . $e((string) $h['note']) : '' ?></li><?php endforeach; ?>
  </ul>
</section>
