<?php
/**
 * Privacy officer: one request — the person, what modules keep (counts), the package, identity check, erasure plan
 * (prepared by one officer, approved by another), the answer, and the history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $req @var list<array{module: string, label: string, rows: int}> $coverage @var int $me @var array<string, string> $moduleNames
 * @var array<string, string> $errors @var string $form @var array<string, mixed> $old
 */
include __DIR__ . '/_status.php';
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$st = (string) $req['status'];
$plan = $req['erasure_plan'] !== null ? (array) json_decode((string) $req['erasure_plan'], true) : [];
$result = $req['erasure_result'] !== null ? (array) json_decode((string) $req['erasure_result'], true) : [];
$renderErase = static function (array $set) use ($e, $t, $moduleNames): string {
    $out = '<ul class="plain-list stack-sm">';
    foreach ($set as $module => $r) {
        $done = [];
        foreach ((array) ($r['done'] ?? []) as $k => $n) {
            $done[] = $t((string) $k) . ' ' . (int) $n;
        }
        $kept = [];
        foreach ((array) ($r['kept'] ?? []) as $k => $why) {
            $kept[] = $t((string) $k) . ' — ' . $t((string) $why);
        }
        $out .= '<li><strong>' . $e($moduleNames[(string) $module] ?? (string) $module) . '</strong><span class="d-block text-sm">' . $e($t('privacy.erase_done') . ': ' . ($done !== [] ? implode(' · ', $done) : '—')) . '</span>'
            . ($kept !== [] ? '<span class="d-block text-sm text-muted">' . $e($t('privacy.erase_kept') . ': ' . implode(' · ', $kept)) . '</span>' : '') . '</li>';
    }
    return $out . '</ul>';
};
?>
<?= $ui('page-header', ['title' => (string) $req['request_no'], 'subtitle' => $t('privacy.type.' . $req['request_type']) . ' · ' . $req['display_name'], 'crumbs' => [[$t('privacy.desk_title'), $url('/privacy/requests')], [(string) $req['request_no'], null]]]) ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="pq-d">
    <h2 id="pq-d" class="card__title"><?= $e($t('privacy.details')) ?> <?= $dsrBadge($st) ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('privacy.col.person')) ?></dt><dd><?= $e($req['display_name'] . ' (' . $req['username'] . ')') ?></dd></div>
      <div><dt><?= $e($t('privacy.field.details')) ?></dt><dd class="pre-line"><?= $e((string) $req['details']) ?></dd></div>
      <div><dt><?= $e($t('privacy.received_at')) ?></dt><dd><?= $e($date((string) $req['created_at'])) ?></dd></div>
      <div><dt><?= $e($t('privacy.due_on')) ?></dt><dd><?= $e($req['due_on'] !== null ? $date((string) $req['due_on'], false) : $t('privacy.due_unset')) ?></dd></div>
      <?php if ($req['identity_note'] !== null) :
            ?><div><dt><?= $e($t('privacy.identity')) ?></dt><dd><?= $e((string) $req['identity_note']) ?></dd></div><?php
      endif; ?>
      <?php if ($req['answer'] !== null) :
            ?><div><dt><?= $e($t('privacy.answer')) ?></dt><dd class="pre-line"><?= $e((string) $req['answer']) ?></dd></div><?php
      endif; ?>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="pq-c">
    <h2 id="pq-c" class="card__title"><?= $e($t('privacy.what_we_keep_person')) ?></h2>
    <ul class="plain-list stack-sm"><?php foreach ($coverage as $c) :
        ?><li><?= $e($t($c['label'])) ?> · <?= $e($t('privacy.rows_n', ['n' => (string) $c['rows']])) ?></li><?php
                                    endforeach; ?></ul>
    <?php if (!in_array($st, ['received', 'rejected'], true)) : ?>
      <div class="row"><?= $ui('button', ['label' => $t('privacy.package'), 'href' => $url('/privacy/requests/' . $req['public_id'] . '/package'), 'variant' => 'secondary', 'icon' => 'download']) ?></div>
      <p class="text-sm text-muted"><?= $e($t('privacy.package_help')) ?></p>
    <?php endif; ?>
  </section>
</div>

<?php if ($st === 'received') : ?>
<section class="card stack-sm" aria-labelledby="pq-v">
  <h2 id="pq-v" class="card__title"><?= $e($t('privacy.verify_title')) ?></h2>
  <form method="post" action="<?= $e($url('/privacy/requests/' . $req['public_id'] . '/verify')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'identity_note', 'label' => $t('privacy.identity'), 'required' => true, 'help' => $t('privacy.identity_help'), 'value' => $form === 'verify' && is_scalar($old['identity_note'] ?? null) ? (string) $old['identity_note'] : '', 'error' => $errors['identity_note'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.verify'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
<?php endif; ?>

<?php if ($req['request_type'] === 'deletion' && in_array($st, ['verified', 'erasure_pending', 'erasure_done'], true)) : ?>
<section class="card stack-sm" aria-labelledby="pq-e">
  <h2 id="pq-e" class="card__title"><?= $e($t('privacy.erasure_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('privacy.erasure_help')) ?></p>
    <?php if ($st === 'verified') : ?>
    <form method="post" action="<?= $e($url('/privacy/requests/' . $req['public_id'] . '/prepare')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('privacy.prepare'), 'type' => 'submit', 'variant' => 'primary']) ?></form>
    <?php elseif ($st === 'erasure_pending') : ?>
    <h3 class="text-sm"><?= $e($t('privacy.plan')) ?></h3>
        <?= $renderErase($plan) ?>
        <?php if ((int) $req['prepared_by'] === $me) :
            ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('privacy.need_other')]) ?><?php
        else : ?>
      <form method="post" action="<?= $e($url('/privacy/requests/' . $req['public_id'] . '/approve')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('privacy.approve'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
        <?php endif; ?>
    <?php else : ?>
    <h3 class="text-sm"><?= $e($t('privacy.result')) ?></h3>
        <?= $renderErase($result) ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (in_array($st, ['received', 'verified', 'erasure_done'], true)) : ?>
<section class="card stack-sm" aria-labelledby="pq-a">
  <h2 id="pq-a" class="card__title"><?= $e($t('privacy.answer_title')) ?></h2>
  <form method="post" action="<?= $e($url('/privacy/requests/' . $req['public_id'] . '/close')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'answer', 'type' => 'textarea', 'label' => $t('privacy.answer'), 'required' => true, 'help' => $t('privacy.answer_help'), 'value' => $form === 'close' && is_scalar($old['answer'] ?? null) ? (string) $old['answer'] : '', 'error' => $errors['answer'] ?? null]) ?>
    <div class="row">
      <?= $ui('button', ['label' => $t('privacy.complete'), 'type' => 'submit', 'variant' => 'primary', 'attrs' => ['name' => 'outcome', 'value' => 'completed']]) ?>
      <?= $ui('button', ['label' => $t('privacy.reject'), 'type' => 'submit', 'variant' => 'secondary', 'attrs' => ['name' => 'outcome', 'value' => 'rejected']]) ?>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="card stack-sm" aria-labelledby="pq-h">
  <h2 id="pq-h" class="card__title"><?= $e($t('privacy.history')) ?></h2>
  <ul class="plain-list stack-sm">
    <?php foreach ($req['events'] as $ev) :
        ?><li><?= $e($t('privacy.event.' . $ev['action'])) ?> · <?= $e((string) ($ev['actor'] ?? '—')) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['occurred_at'])) ?></span></li><?php
    endforeach; ?>
  </ul>
</section>
