<?php
/**
 * Issue desk: scan the pick-up QR (a scanner types like a keyboard) or type the code / request number; check the
 * person and the quantities (lower with a note), then issue — or refuse with a reason. Below: requests waiting.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var string $code @var ?array<string, mixed> $rq @var bool $notFound @var list<array<string, mixed>> $waiting
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canIssue @var bool $canManage
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$dt = static fn (?string $x): string => $x === null ? '—' : $date(substr($x, 0, 10), false) . ' ' . substr($x, 11, 5);
?>
<?= $ui('page-header', ['title' => $t('requisition.issue_title'), 'subtitle' => $t('requisition.issue_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<form method="get" action="<?= $e($url('/requisition/issue')) ?>" class="card row" role="search" aria-label="<?= $e($t('requisition.scan')) ?>">
  <?= $ui('field', ['name' => 'code', 'id' => 'rq-scan', 'type' => 'search', 'label' => $t('requisition.scan'), 'value' => $rq === null ? $code : '', 'help' => $t('requisition.scan_help'), 'autocomplete' => 'off', 'autofocus' => $rq === null]) ?>
  <?= $ui('button', ['label' => $t('requisition.find'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'search']) ?>
</form>
<?php if ($notFound): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('requisition.not_found'), 'text' => $t('requisition.not_found_help'), 'live' => true]) ?><?php endif; ?>
<?php if ($rq !== null): $st = (string) $rq['status']; $pid = (string) $rq['public_id']; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="ri-h">
  <h2 id="ri-h" class="card__title"><?= $e($t('requisition.request_title', ['ref' => (string) $rq['ref']])) ?> <?php include __DIR__ . '/_status.php'; ?></h2>
  <p><strong><?= $e($t('requisition.receiver')) ?></strong> <?= $e((string) $rq['requester_name']) ?><?= $rq['dept_code'] !== null ? $e(' · ' . $rq['dept_code']) : '' ?> · <?= $e($t('requisition.field.site')) ?> <?= $e((string) $rq['site_code']) ?></p>
  <p class="text-sm text-muted"><?= $e($t('requisition.check_person')) ?></p>
  <?php if ($st !== 'approved'): ?>
    <?= $ui('alert', ['tone' => 'info', 'text' => $t('requisition.not_issuable', ['status' => $t('requisition.status.' . $st)]) . ($rq['issued_at'] !== null ? ' · ' . $dt($rq['issued_at']) : '')]) ?>
  <?php elseif ($rq['mine']): ?>
    <?= $ui('alert', ['tone' => 'warning', 'text' => $t('requisition.error.own_issue')]) ?>
  <?php else: ?>
  <form method="post" action="<?= $e($url('/requisition/r/' . $pid . '/issue')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <div class="table-wrap" role="region" aria-labelledby="ri-h" tabindex="0">
      <table class="table table--stack">
        <thead><tr><th scope="col"><?= $e($t('requisition.field.name')) ?></th><th scope="col"><?= $e($t('requisition.col.approved_qty')) ?></th><th scope="col"><?= $e($t('requisition.col.on_hand')) ?></th><th scope="col"><?= $e($t('requisition.col.issue_now')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rq['lines'] as $l): $ipid = (string) $l['item_pid']; $err = $errors['qty_' . $ipid] ?? null; $val = is_array($old['qty'] ?? null) && isset($old['qty'][$ipid]) ? (string) $old['qty'][$ipid] : (string) min((int) $l['qty'], (int) $l['on_hand']); ?>
          <tr><th scope="row" data-label="<?= $e($t('requisition.field.name')) ?>"><?= $e((string) $l['name']) ?> <span class="text-sm text-muted"><?= $e((string) $l['code']) ?></span></th>
            <td data-label="<?= $e($t('requisition.col.approved_qty')) ?>"><?= $e($l['qty'] . ' ' . $l['unit']) ?></td>
            <td data-label="<?= $e($t('requisition.col.on_hand')) ?>"><?= $e($l['on_hand'] . ' ' . $l['unit']) ?></td>
            <td data-label="<?= $e($t('requisition.col.issue_now')) ?>"><label class="sr-only" for="iq-<?= $e($ipid) ?>"><?= $e($t('requisition.qty_for', ['name' => (string) $l['name']])) ?></label>
              <input class="input rq-qty" type="number" min="0" max="<?= (int) $l['qty'] ?>" inputmode="numeric" id="iq-<?= $e($ipid) ?>" name="qty[<?= $e($ipid) ?>]" value="<?= $e($val) ?>"<?= $err !== null ? ' aria-invalid="true" aria-describedby="iq-' . $e($ipid) . '-err"' : '' ?>>
              <?php if ($err !== null): ?><div class="field__error" id="iq-<?= $e($ipid) ?>-err"><?= $e($err) ?></div><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= $ui('field', ['name' => 'issue_note', 'label' => $t('requisition.field.issue_note'), 'value' => (string) ($old['issue_note'] ?? ''), 'help' => $t('requisition.issue_note_help'), 'error' => $errors['issue_note'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('requisition.issue'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
  <details<?= isset($errors['refuse_reason']) ? ' open' : '' ?>><summary><?= $e($t('requisition.refuse')) ?></summary>
    <form method="post" action="<?= $e($url('/requisition/r/' . $pid . '/refuse')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $ui('field', ['name' => 'refuse_reason', 'label' => $t('requisition.field.reason'), 'required' => true, 'value' => (string) ($old['refuse_reason'] ?? ''), 'error' => $errors['refuse_reason'] ?? null]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('requisition.refuse'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'x']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>
<section class="card card--flush" aria-labelledby="rw-h">
  <div class="card__head"><h2 id="rw-h" class="card__title"><?= $e($t('requisition.waiting', ['n' => (string) count($waiting)])) ?></h2></div>
  <?php if ($waiting === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('requisition.waiting_none')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rw-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('requisition.col.ref')) ?></th><th scope="col"><?= $e($t('requisition.col.requester')) ?></th><th scope="col"><?= $e($t('requisition.field.site')) ?></th><th scope="col"><?= $e($t('requisition.col.lines')) ?></th><th scope="col"><?= $e($t('requisition.col.approved')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($waiting as $w): ?>
        <tr><th scope="row" data-label="<?= $e($t('requisition.col.ref')) ?>"><a href="<?= $e($url('/requisition/issue?code=' . rawurlencode((string) $w['ref']))) ?>"><?= $e((string) $w['ref']) ?></a></th>
          <td data-label="<?= $e($t('requisition.col.requester')) ?>"><?= $e((string) $w['requester_name']) ?></td>
          <td data-label="<?= $e($t('requisition.field.site')) ?>"><?= $e((string) $w['site_code']) ?></td>
          <td data-label="<?= $e($t('requisition.col.lines')) ?>"><?= (int) $w['line_count'] ?></td>
          <td data-label="<?= $e($t('requisition.col.approved')) ?>"><?= $e($dt($w['approved_at'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
