<?php
/**
 * Send an idea (named or anonymous), my ideas, the company board (vote), anonymous tracking link.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var int|null $le @var list<array<string, mixed>> $mine @var list<array<string, mixed>> $board @var array<string, string> $f
 * @var array<string, string> $errors @var array<string, mixed> $old @var array<string, mixed>|null $sent @var string $here
 * @var bool $canManage @var bool $canSurvey
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : '';
$sopts = ['' => $t('kaizen.all')];
foreach (\Blue\Modules\Kaizen\KaizenService::BOARD as $s) {
    $sopts[$s] = $t('kaizen.status.' . $s);
}
?>
<?= $ui('page-header', ['title' => $t('kaizen.title'), 'subtitle' => $t('kaizen.subtitle'),
    'actions' => $ui('button', ['label' => $t('kaizen.track'), 'href' => $url('/kaizen/track'), 'variant' => 'secondary', 'icon' => 'search'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($sent !== null): ?>
  <?= $ui('alert', ['tone' => 'success', 'title' => $t('kaizen.sent_anonymous', ['ref' => (string) $sent['ref']]),
      'html' => '<p>' . $e($t('kaizen.code_once')) . '</p><p class="kz-code"><strong>' . $e((string) $sent['code']) . '</strong></p>']) ?>
<?php endif; ?>
<?php if ($le === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('kaizen.employees_only')]) ?>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="kz-new">
  <h2 id="kz-new" class="card__title"><?= $e($t('kaizen.new_idea')) ?></h2>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/kaizen')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'title', 'label' => $t('kaizen.field.title'), 'required' => true, 'value' => $o('title'), 'error' => $errors['title'] ?? null]) ?>
    <?= $ui('field', ['name' => 'problem', 'type' => 'textarea', 'label' => $t('kaizen.field.problem'), 'required' => true, 'value' => $o('problem'), 'help' => $t('kaizen.problem_help'), 'error' => $errors['problem'] ?? null]) ?>
    <?= $ui('field', ['name' => 'proposal', 'type' => 'textarea', 'label' => $t('kaizen.field.proposal'), 'required' => true, 'value' => $o('proposal'), 'error' => $errors['proposal'] ?? null]) ?>
    <?= $ui('field', ['name' => 'benefit', 'type' => 'textarea', 'label' => $t('kaizen.field.benefit'), 'value' => $o('benefit'), 'help' => $t('kaizen.benefit_help'), 'error' => $errors['benefit'] ?? null]) ?>
    <?= $ui('field', ['name' => 'photo', 'type' => 'file', 'label' => $t('kaizen.field.photo'), 'accept' => '.png,.jpg,.jpeg,.webp,.pdf', 'help' => $t('kaizen.photo_help'), 'error' => $errors['photo'] ?? null]) ?>
    <label class="check"><input type="checkbox" name="anonymous" value="1"<?= $o('anonymous') === '1' ? ' checked' : '' ?>> <?= $e($t('kaizen.field.anonymous')) ?></label>
    <p class="field__help"><?= $e($t('kaizen.anonymous_help')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('kaizen.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php endif; ?>
<section class="card card--flush" aria-labelledby="kz-mine">
  <div class="card__head"><h2 id="kz-mine" class="card__title"><?= $e($t('kaizen.mine', ['n' => (string) count($mine)])) ?></h2></div>
  <?php if ($mine === []): ?><p class="card__pad text-sm text-muted"><?= $e($t('kaizen.mine_none')) ?></p><?php else: ?>
  <ul class="plain-list card__pad stack-sm">
    <?php foreach ($mine as $m): $ist = (string) $m['status']; ?><li class="row"><a href="<?= $e($url('/kaizen/i/' . $m['public_id'])) ?>"><?= $e($m['ref'] . ' · ' . $m['title']) ?></a> <?php include __DIR__ . '/_status.php'; ?> <span class="text-sm text-muted"><?= $e($t('kaizen.votes_n', ['n' => (string) $m['votes']])) ?></span></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if ($le !== null): ?>
<section class="card card--flush" aria-labelledby="kz-board">
  <div class="card__head"><h2 id="kz-board" class="card__title"><?= $e($t('kaizen.board', ['n' => (string) count($board)])) ?></h2></div>
  <form method="get" action="<?= $e($url('/kaizen')) ?>" class="card__pad row kz-filter">
    <?= $ui('field', ['name' => 'q', 'label' => $t('kaizen.search'), 'value' => $f['q']]) ?>
    <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('kaizen.col.status'), 'value' => $f['status'], 'options' => $sopts]) ?>
    <?= $ui('field', ['name' => 'sort', 'type' => 'select', 'label' => $t('kaizen.sort'), 'value' => $f['sort'], 'options' => ['' => $t('kaizen.sort_votes'), 'recent' => $t('kaizen.sort_recent')]]) ?>
    <?= $ui('button', ['label' => $t('kaizen.show'), 'type' => 'submit', 'variant' => 'secondary']) ?>
  </form>
  <?php if ($board === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'pencil', 'title' => $t('kaizen.board_none'), 'text' => $t('kaizen.board_none_help')]) ?></div><?php else: ?>
  <ul class="plain-list card__pad stack">
    <?php foreach ($board as $b): $ist = (string) $b['status']; ?>
    <li class="kz-item">
      <div class="stack-sm">
        <a href="<?= $e($url('/kaizen/i/' . $b['public_id'])) ?>"><strong><?= $e((string) $b['title']) ?></strong></a>
        <span class="text-sm text-muted"><?= $e($b['ref'] . ' · ' . ((int) $b['anonymous'] === 1 ? $t('kaizen.anonymous') : (string) $b['submitter_name'])) ?></span>
        <span><?php include __DIR__ . '/_status.php'; ?></span>
      </div>
      <form method="post" action="<?= $e($url('/kaizen/i/' . $b['public_id'] . '/vote')) ?>"><?= $csrf ?><input type="hidden" name="back" value="board">
        <?= $ui('button', ['label' => $t('kaizen.votes_n', ['n' => (string) $b['votes']]), 'type' => 'submit', 'size' => 'sm', 'variant' => (int) $b['voted'] > 0 ? 'primary' : 'secondary', 'icon' => 'circle-check',
            'attrs' => ['aria-pressed' => (int) $b['voted'] > 0 ? 'true' : 'false', 'aria-label' => $t('kaizen.vote_for', ['title' => (string) $b['title'], 'n' => (string) $b['votes']])]]) ?>
      </form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
