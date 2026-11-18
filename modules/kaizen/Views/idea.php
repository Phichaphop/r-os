<?php
/**
 * One idea: what was proposed, its state, votes, the next steps for the manager / owner, reward, history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $i @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $here
 * @var bool $canManage @var bool $canSurvey
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pid = (string) $i['public_id'];
$ist = (string) $i['status'];
$mgr = (bool) $i['is_manager'];
$own = (bool) $i['is_owner'];
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$fo = static fn (string $f, string $k): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : '';
$act = static fn (string $a): string => $url('/kaizen/i/' . $pid . '/' . $a);
$noteField = static fn (string $f, string $label, bool $req = false): string => $ui('field', ['name' => 'note', 'id' => 'f-' . $f . '-note', 'type' => 'textarea', 'label' => $label, 'required' => $req, 'value' => $fo($f, 'note'), 'error' => $fe($f, 'note')]);
?>
<?= $ui('page-header', ['title' => (string) $i['title'], 'subtitle' => $i['ref'] . ' · ' . ((int) $i['anonymous'] === 1 ? $t('kaizen.anonymous') : (string) $i['submitter_name']),
    'crumbs' => [[$t('kaizen.title'), $url($mgr ? '/kaizen/admin' : '/kaizen')], [(string) $i['ref'], null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="kz-f">
    <h2 id="kz-f" class="card__title"><?= $e($t('kaizen.col.status')) ?>: <?php include __DIR__ . '/_status.php'; ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('kaizen.field.problem')) ?></dt><dd class="kz-text"><?= $e((string) $i['problem']) ?></dd></div>
      <div><dt><?= $e($t('kaizen.field.proposal')) ?></dt><dd class="kz-text"><?= $e((string) $i['proposal']) ?></dd></div>
      <div><dt><?= $e($t('kaizen.field.benefit')) ?></dt><dd class="kz-text"><?= $e((string) ($i['benefit'] ?? '—')) ?></dd></div>
      <?php if ($i['photo_pid'] !== null): ?><div><dt><?= $e($t('kaizen.field.photo')) ?></dt><dd><a href="<?= $e($url('/kaizen/i/' . $pid . '/photo')) ?>"><?= $e($t('kaizen.open_photo')) ?></a></dd></div><?php endif; ?>
      <div><dt><?= $e($t('kaizen.col.owner')) ?></dt><dd><?= $e((string) ($i['owner_name'] ?? '—')) ?></dd></div>
      <?php if (isset($i['score']) && $i['score'] !== null): ?><div><dt><?= $e($t('kaizen.col.score')) ?></dt><dd><?= $e(number_format((float) $i['score'], 2)) ?> / 5<?= $i['scores'] !== [] ? ' — ' . $e(implode(' · ', array_map(static fn (array $s): string => $s['name'] . ' ' . $s['score'] . ' (×' . $s['weight'] . ')', $i['scores']))) : '' ?></dd></div><?php endif; ?>
      <?php if ($i['decision_note'] !== null): ?><div><dt><?= $e($t('kaizen.col.decision')) ?></dt><dd><?= $e((string) $i['decision_note']) ?></dd></div><?php endif; ?>
      <?php if ($i['plan'] !== null): ?><div><dt><?= $e($t('kaizen.field.plan')) ?></dt><dd><?= $e((string) $i['plan']) ?> · <?= $e($t('kaizen.due_on', ['date' => $date((string) $i['due_date'], false)])) ?></dd></div><?php endif; ?>
      <?php if ($i['result_note'] !== null): ?><div><dt><?= $e($t('kaizen.field.result')) ?></dt><dd class="kz-text"><?= $e((string) $i['result_note']) ?></dd></div><?php endif; ?>
      <?php if ($i['reward_status'] !== 'none' && ($mgr || $i['is_sender'] || $i['can_reward'])): ?><div><dt><?= $e($t('kaizen.field.reward')) ?></dt><dd><?= $e((string) $i['reward']) ?> · <?= $e($t('kaizen.reward_status.' . $i['reward_status'])) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($i['can_vote']): ?>
    <form method="post" action="<?= $e($url('/kaizen/i/' . $pid . '/vote')) ?>"><?= $csrf ?>
      <?= $ui('button', ['label' => $i['voted'] ? $t('kaizen.unvote') : $t('kaizen.vote'), 'type' => 'submit', 'variant' => $i['voted'] ? 'secondary' : 'primary', 'icon' => 'circle-check', 'attrs' => ['aria-pressed' => $i['voted'] ? 'true' : 'false']]) ?>
      <span class="text-sm text-muted"><?= $e($t('kaizen.votes_n', ['n' => (string) $i['votes']])) ?></span>
    </form>
    <?php else: ?><p class="text-sm text-muted"><?= $e($t('kaizen.votes_n', ['n' => (string) $i['votes']])) ?></p><?php endif; ?>
  </section>
  <section class="card stack" aria-labelledby="kz-n">
    <h2 id="kz-n" class="card__title"><?= $e($t('kaizen.next_step')) ?></h2>
    <?php $shown = false; ?>
    <?php if ($mgr && in_array($ist, ['submitted', 'screening'], true)): $shown = true; ?>
      <form method="post" action="<?= $e($act('screen')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'owner', 'label' => $t('kaizen.field.owner'), 'required' => true, 'value' => $fo('screen', 'owner'), 'help' => $t('kaizen.owner_help'), 'error' => $fe('screen', 'owner')]) ?>
        <?= $noteField('screen', $t('kaizen.field.note')) ?>
        <div class="row"><?= $ui('button', ['label' => $ist === 'submitted' ? $t('kaizen.screen') : $t('kaizen.change_owner'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
      </form>
    <?php endif; ?>
    <?php if (($mgr || $own) && in_array($ist, ['screening', 'evaluation'], true)): $shown = true; ?>
      <?php if ($i['criteria'] === []): ?>
        <?= $ui('alert', ['tone' => 'warning', 'title' => $t('kaizen.error.no_criteria'), 'html' => $mgr ? '<a href="' . $e($url('/kaizen/settings')) . '">' . $e($t('kaizen.tab.settings')) . '</a>' : '']) ?>
      <?php else: ?>
      <form method="post" action="<?= $e($act('score')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('kaizen.score_title')) ?></legend>
          <p class="field__help"><?= $e($t('kaizen.score_help')) ?></p>
          <div class="grid grid--3">
          <?php foreach ($i['criteria'] as $c): $cp = (string) $c['public_id']; ?>
            <?= $ui('field', ['name' => 'scores[' . $cp . ']', 'id' => 'f-score-' . $cp, 'type' => 'select', 'label' => $c['name'] . ' (×' . $c['weight'] . ')', 'required' => true,
                'value' => $form === 'score' ? (string) (((array) ($old['scores'] ?? []))[$cp] ?? '') : '', 'options' => ['' => $t('kaizen.pick'), '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5'], 'error' => $fe('score', 'score_' . $cp)]) ?>
          <?php endforeach; ?>
          </div>
        </fieldset>
        <?= $noteField('score', $t('kaizen.field.note')) ?>
        <div class="row"><?= $ui('button', ['label' => $t('kaizen.save_score'), 'type' => 'submit', 'variant' => $ist === 'evaluation' ? 'secondary' : 'primary']) ?></div>
      </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($mgr && $ist === 'evaluation'): $shown = true; ?>
      <form method="post" action="<?= $e($act('accept')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $noteField('accept', $t('kaizen.field.decision_note')) ?>
        <div class="row"><?= $ui('button', ['label' => $t('kaizen.accept'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
      </form>
    <?php endif; ?>
    <?php if ($mgr && in_array($ist, ['submitted', 'screening', 'evaluation'], true)): $shown = true; ?>
      <details<?= $form === 'reject' ? ' open' : '' ?>><summary><?= $e($t('kaizen.reject')) ?></summary>
        <form method="post" action="<?= $e($act('reject')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $noteField('reject', $t('kaizen.field.reason'), true) ?>
          <div class="row"><?= $ui('button', ['label' => $t('kaizen.reject'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
        </form>
      </details>
    <?php endif; ?>
    <?php if (($mgr || $own) && $ist === 'accepted'): $shown = true; ?>
      <form method="post" action="<?= $e($act('start')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'plan', 'type' => 'textarea', 'label' => $t('kaizen.field.plan'), 'required' => true, 'value' => $fo('start', 'plan'), 'error' => $fe('start', 'plan')]) ?>
        <?= $ui('field', ['name' => 'due_date', 'type' => 'date', 'label' => $t('kaizen.field.due_date'), 'required' => true, 'value' => $fo('start', 'due_date'), 'error' => $fe('start', 'due_date')]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('kaizen.start'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
      </form>
    <?php endif; ?>
    <?php if (($mgr || $own) && $ist === 'implementing'): $shown = true; ?>
      <form method="post" action="<?= $e($act('result')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'result', 'type' => 'textarea', 'label' => $t('kaizen.field.result'), 'required' => true, 'value' => $fo('result', 'result') ?: (string) ($i['result_note'] ?? ''), 'help' => $t('kaizen.result_help'), 'error' => $fe('result', 'result')]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('kaizen.save_result'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
      </form>
    <?php endif; ?>
    <?php if ($mgr && $ist === 'implementing' && $i['result_note'] !== null): $shown = true; ?>
      <?php if ($own): ?><p class="text-sm text-muted"><?= $e($t('kaizen.owner_cannot_verify')) ?></p><?php else: ?>
      <form method="post" action="<?= $e($act('verify')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $noteField('verify', $t('kaizen.field.verify_note')) ?>
        <div class="row"><?= $ui('button', ['label' => $t('kaizen.verify'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
      </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($mgr && in_array($ist, ['accepted', 'implementing'], true)): $shown = true; ?>
      <details<?= $form === 'close' ? ' open' : '' ?>><summary><?= $e($t('kaizen.close')) ?></summary>
        <form method="post" action="<?= $e($act('close')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $noteField('close', $t('kaizen.field.reason'), true) ?>
          <div class="row"><?= $ui('button', ['label' => $t('kaizen.close'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
        </form>
      </details>
    <?php endif; ?>
    <?php if ($mgr && $ist === 'verified' && in_array($i['reward_status'], ['none', 'rejected'], true)): $shown = true; ?>
      <?php if ((int) $i['anonymous'] === 1): ?><p class="text-sm text-muted"><?= $e($t('kaizen.error.anonymous_reward')) ?></p><?php else: ?>
      <form method="post" action="<?= $e($act('reward')) ?>" class="stack-sm" novalidate><?= $csrf ?>
        <?= $ui('field', ['name' => 'reward', 'label' => $t('kaizen.field.reward'), 'required' => true, 'value' => $fo('reward', 'reward'), 'help' => $t('kaizen.reward_help'), 'error' => $fe('reward', 'reward')]) ?>
        <div class="row"><?= $ui('button', ['label' => $t('kaizen.propose_reward'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
      </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($i['can_reward']): $shown = true; ?>
      <p><?= $e($t('kaizen.reward_proposed', ['reward' => (string) $i['reward']])) ?></p>
      <form method="post" action="<?= $e($act('reward_ok')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('kaizen.approve_reward'), 'type' => 'submit', 'variant' => 'primary']) ?></form>
      <details<?= $form === 'reward_no' ? ' open' : '' ?>><summary><?= $e($t('kaizen.reject_reward')) ?></summary>
        <form method="post" action="<?= $e($act('reward_no')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $noteField('reward_no', $t('kaizen.field.reason'), true) ?>
          <div class="row"><?= $ui('button', ['label' => $t('kaizen.reject_reward'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
        </form>
      </details>
    <?php endif; ?>
    <?php if (!$shown): ?><p class="text-sm text-muted"><?= $e($t('kaizen.no_next')) ?></p><?php endif; ?>
    <?php if ($mgr || $own): ?>
      <details<?= $form === 'note' ? ' open' : '' ?>><summary><?= $e($t('kaizen.add_note')) ?></summary>
        <form method="post" action="<?= $e($act('note')) ?>" class="stack-sm" novalidate><?= $csrf ?>
          <?= $noteField('note', $t('kaizen.field.note'), true) ?>
          <label class="check"><input type="checkbox" name="internal" value="1"> <?= $e($t('kaizen.field.internal')) ?></label>
          <div class="row"><?= $ui('button', ['label' => $t('kaizen.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
        </form>
      </details>
    <?php endif; ?>
  </section>
</div>
<section class="card stack-sm" aria-labelledby="kz-h">
  <h2 id="kz-h" class="card__title"><?= $e($t('kaizen.history')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($i['events'] as $ev): ?><li><?= $e($t('kaizen.event.' . $ev['event'])) ?><?= (int) $ev['internal'] === 1 ? ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('kaizen.internal')]) : '' ?> · <?= $e((string) ($ev['actor'] ?? $t('kaizen.anonymous'))) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
