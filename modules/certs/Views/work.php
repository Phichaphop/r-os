<?php
/**
 * HR work page of a request: take, ask for information, issue (fields prefilled from the employee record), ready,
 * handover with the pickup code, cancel, waive the rating.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $req @var array{fields: list<array<string, mixed>>, values: array<string, string>, template: string|null} $pre
 * @var list<array<string, mixed>> $issues @var list<array<string, mixed>> $events @var bool $canIssue
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 */
$st = (string) $req['status'];
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/certs/admin/r/' . $req['public_id'];
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$current = null;
foreach ($issues as $i) {
    if ($i['status'] === 'issued') {
        $current = $i;
        break;
    }
}
$oldF = $form === 'issue' ? (array) ($old['f'] ?? []) : [];
?>
<?= $ui('page-header', ['title' => (string) $req['request_no'], 'subtitle' => $req['employee_code'] . ' ' . $req['emp_name'] . ' · ' . $req['name_th'],
    'crumbs' => [[$t('certs.admin_title'), $url('/certs/admin')], [(string) $req['request_no'], null]]]) ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="cw-d">
    <h2 id="cw-d" class="card__title"><?= $e($t('certs.details')) ?> <?php include __DIR__ . '/_status.php'; ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('certs.field.language')) ?></dt><dd><?= $e($t('certs.lang.' . $req['language'])) ?></dd></div>
      <div><dt><?= $e($t('certs.field.copies')) ?></dt><dd><?= (int) $req['copies'] ?></dd></div>
      <div><dt><?= $e($t('certs.field.purpose')) ?></dt><dd><?= $e((string) $req['purpose']) ?></dd></div>
      <?php if ($req['info_note'] !== null): ?><div><dt><?= $e($t('certs.asked')) ?></dt><dd><?= $e((string) $req['info_note']) ?></dd></div><?php endif; ?>
      <?php if ($st === 'received'): ?><div><dt><?= $e($t('certs.col.received')) ?></dt><dd><?= $e($t('certs.received_by', ['name' => (string) $req['receiver_name'], 'at' => $date((string) $req['received_at'])])) ?><?= $req['receiver_relation'] === 'proxy' ? ' · ' . $e((string) $req['proxy_note']) : '' ?></dd></div>
        <div><dt><?= $e($t('certs.rate_title')) ?></dt><dd><?= $e($req['rating'] !== null ? $t('certs.rated', ['n' => (string) $req['rating']]) . ($req['rating_comment'] !== null ? ' — ' . $req['rating_comment'] : '') : ($req['rating_waived_by'] !== null ? $t('certs.waived', ['reason' => (string) $req['rating_waive_reason']]) : $t('certs.not_rated'))) ?></dd></div><?php endif; ?>
    </dl>
    <div class="row">
      <?php if ($st === 'submitted'): ?><form method="post" action="<?= $e($url($base . '/take')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('certs.take'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'clipboard-check']) ?></form><?php endif; ?>
      <?php if ($st === 'preparing' && $current !== null): ?><form method="post" action="<?= $e($url($base . '/ready')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('certs.mark_ready'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'bell']) ?></form><?php endif; ?>
      <?php if ($current !== null): ?><?= $ui('button', ['label' => $t('certs.open_doc', ['no' => (string) ($current['document_number'] ?? '')]), 'href' => $url('/certs/r/' . $req['public_id'] . '/doc'), 'variant' => 'secondary', 'icon' => 'file-text']) ?><?php endif; ?>
    </div>
  </section>
  <?php if (in_array($st, ['submitted', 'preparing'], true)): ?>
  <details class="card stack-sm"<?= $form === 'info' ? ' open' : '' ?>>
    <summary class="card__title"><?= $e($t('certs.ask_info')) ?></summary>
    <form method="post" action="<?= $e($url($base . '/info')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'note', 'type' => 'textarea', 'label' => $t('certs.info_note'), 'required' => true, 'error' => $err('info', 'note')]) ?>
      <?= $ui('button', ['label' => $t('certs.ask_send'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'send']) ?>
    </form>
  </details>
  <?php endif; ?>
</div>
<?php if (in_array($st, ['preparing', 'ready'], true)): ?>
<section class="card stack" aria-labelledby="cw-i">
  <h2 id="cw-i" class="card__title"><?= $e($current !== null ? $t('certs.reissue') : $t('certs.issue')) ?></h2>
  <?php if (!$canIssue): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('certs.error.no_issue_right')]) ?>
  <?php elseif ($pre['template'] === null): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('certs.error.language')]) ?>
  <?php else: ?>
  <p class="text-sm text-muted"><?= $e($t('certs.issue_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/issue')) ?>" class="stack" novalidate><?= $csrf ?>
    <div class="grid grid--2">
      <?php foreach ($pre['fields'] as $f): $k = (string) $f['key']; ?>
        <?= $ui('field', ['name' => 'f[' . $k . ']', 'id' => 'cf-' . $k, 'type' => match ((string) $f['type']) { 'date' => 'date', 'multiline' => 'textarea', default => 'text' },
            'label' => (string) $f['label'], 'required' => !empty($f['required']), 'value' => is_scalar($oldF[$k] ?? null) ? (string) $oldF[$k] : ($pre['values'][$k] ?? ''),
            'error' => $err('issue', 'f_' . $k)]) ?>
      <?php endforeach; ?>
    </div>
    <?php if ($current !== null): ?>
      <?= $ui('field', ['name' => 'void_reason', 'label' => $t('certs.void_reason'), 'required' => true, 'error' => $err('issue', 'void_reason'), 'help' => $t('certs.void_help', ['no' => (string) ($current['document_number'] ?? '')])]) ?>
    <?php endif; ?>
    <div class="row"><?= $ui('button', ['label' => $current !== null ? $t('certs.reissue') : $t('certs.issue'), 'type' => 'submit', 'variant' => $current !== null ? 'secondary' : 'primary', 'icon' => 'file-text']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($st === 'ready'): ?>
<section class="card stack" aria-labelledby="cw-h">
  <h2 id="cw-h" class="card__title"><?= $e($t('certs.handover')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('certs.handover_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/handover')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'label' => $t('certs.pickup_code'), 'required' => true, 'inputmode' => 'numeric', 'autocomplete' => 'off', 'error' => $err('handover', 'code')]) ?>
      <?= $ui('field', ['name' => 'receiver', 'label' => $t('certs.receiver'), 'required' => true, 'value' => $form === 'handover' && is_scalar($old['receiver'] ?? null) ? (string) $old['receiver'] : (string) $req['emp_name'], 'error' => $err('handover', 'receiver')]) ?>
      <?= $ui('field', ['name' => 'relation', 'type' => 'select', 'label' => $t('certs.relation'), 'value' => $form === 'handover' && is_scalar($old['relation'] ?? null) ? (string) $old['relation'] : 'self', 'options' => ['self' => $t('certs.relation.self'), 'proxy' => $t('certs.relation.proxy')]]) ?>
    </div>
    <?= $ui('field', ['name' => 'proxy_note', 'label' => $t('certs.proxy_note'), 'help' => $t('certs.proxy_note_help'), 'error' => $err('handover', 'proxy_note')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('certs.handover_do'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<?php endif; ?>
<?php if ($st === 'received' && $req['rating'] === null && $req['rating_waived_by'] === null): ?>
<details class="card stack-sm"<?= $form === 'waive' ? ' open' : '' ?>>
  <summary class="card__title"><?= $e($t('certs.waive')) ?></summary>
  <p class="text-sm text-muted"><?= $e($t('certs.waive_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/waive')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'waive_reason', 'label' => $t('certs.reason'), 'required' => true, 'error' => $err('waive', 'waive_reason')]) ?>
    <?= $ui('button', ['label' => $t('certs.waive'), 'type' => 'submit', 'variant' => 'secondary']) ?>
  </form>
</details>
<?php endif; ?>
<?php if ($issues !== []): ?>
<section class="card stack-sm" aria-labelledby="cw-s">
  <h2 id="cw-s" class="card__title"><?= $e($t('certs.issued_docs')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($issues as $i): ?>
    <li><?= $e((string) ($i['document_number'] ?? '—')) ?> <?= $ui('badge', ['tone' => $i['status'] === 'issued' ? 'success' : 'neutral', 'label' => $t('certs.issue_status.' . $i['status'])]) ?>
      <span class="d-block text-sm text-muted"><?= $e((string) ($i['issuer'] ?? '')) ?> · <?= $e($date((string) $i['issued_at'])) ?><?= $i['void_reason'] !== null ? ' · ' . $e((string) $i['void_reason']) : '' ?></span></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php if (in_array($st, ['submitted', 'need_info', 'preparing', 'ready'], true)): ?>
<details class="card stack-sm"<?= $form === 'cancel' ? ' open' : '' ?>>
  <summary class="card__title"><?= $e($t('certs.cancel')) ?></summary>
  <form method="post" action="<?= $e($url($base . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('certs.reason'), 'required' => true, 'error' => $err('cancel', 'reason')]) ?>
    <?= $ui('button', ['label' => $t('certs.cancel'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?>
  </form>
</details>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="cw-e">
  <h2 id="cw-e" class="card__title"><?= $e($t('certs.history')) ?></h2>
  <ol class="plain-list stack-sm">
    <?php foreach ($events as $ev): ?>
      <li><strong><?= $e($t('certs.event.' . $ev['action'])) ?></strong><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?>
        <span class="d-block text-sm text-muted"><?= $e((string) ($ev['actor'] ?? '—')) ?> · <?= $e($date((string) $ev['occurred_at'])) ?></span></li>
    <?php endforeach; ?>
  </ol>
</section>
