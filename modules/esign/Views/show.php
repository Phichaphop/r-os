<?php
/**
 * One signing request: details and hashes, the files, signers and their state, what I can do now (sign, decline,
 * send, cancel, void) and the history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var string $myName @var array<string, mixed> $req @var list<array<string, mixed>> $events @var array<string, string> $errors @var string $form @var string $today @var array<string, mixed> $old
 */
include __DIR__ . '/_status.php';
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$st = (string) $req['status'];
$me = $req['me'];
$myTurn = $me !== null && $me['status'] === 'pending' && in_array($st, ['sent', 'partial'], true);
$open = in_array($st, ['sent', 'partial'], true);
$reason = static fn (string $id, string $f) => $ui('field', ['name' => 'reason', 'id' => $id, 'label' => $t('esign.field.reason'), 'required' => true, 'value' => $form === $f && is_scalar($old['reason'] ?? null) ? (string) $old['reason'] : '', 'error' => $form === $f ? ($errors['reason'] ?? null) : null]);
?>
<?= $ui('page-header', ['title' => (string) $req['title'], 'subtitle' => ($req['request_no'] ?? '') !== '' ? (string) $req['request_no'] : $t('esign.status.draft'), 'crumbs' => [[$t('esign.title'), $url('/esign')], [(string) ($req['request_no'] ?? $t('esign.status.draft')), null]]]) ?>
<?php if ($req['closed_reason'] !== null && in_array($st, ['declined', 'cancelled', 'voided', 'expired'], true)): ?>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('esign.closed_' . $st, ['reason' => (string) $req['closed_reason']])]) ?>
<?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="es-d">
    <h2 id="es-d" class="card__title"><?= $e($t('esign.details')) ?> <?= $stBadge($st) ?></h2>
    <dl class="facts">
      <?php if ($req['category'] !== null): ?><div><dt><?= $e($t('esign.field.category')) ?></dt><dd><?= $e((string) $req['category']) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('esign.sender')) ?></dt><dd><?= $e((string) $req['sender_name']) ?></dd></div>
      <?php if ($req['sent_at'] !== null): ?><div><dt><?= $e($t('esign.sent_at')) ?></dt><dd><?= $e($date((string) $req['sent_at'])) ?></dd></div><?php endif; ?>
      <div><dt><?= $e($t('esign.field.expires_on')) ?></dt><dd><?= $e($date((string) $req['expires_on'], false)) ?></dd></div>
      <div><dt><?= $e($t('esign.field.order')) ?></dt><dd><?= $e($t((int) $req['sequential'] === 1 ? 'esign.sequential' : 'esign.parallel')) ?></dd></div>
      <?php if ($req['message'] !== null): ?><div><dt><?= $e($t('esign.field.message')) ?></dt><dd class="pre-line"><?= $e((string) $req['message']) ?></dd></div><?php endif; ?>
    </dl>
  </section>
  <section class="card stack-sm" aria-labelledby="es-f">
    <h2 id="es-f" class="card__title"><?= $e($t('esign.files')) ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('esign.original')) ?></dt><dd><a href="<?= $e($url('/esign/r/' . $req['public_id'] . '/file/source')) ?>" target="_blank" rel="noopener"><?= $ui('icon', ['name' => 'file-type-pdf', 'size' => 'sm']) ?> <?= $e((string) $req['source_name']) ?></a> · <?= $e($t('esign.pages_n', ['n' => (string) $req['pages']])) ?></dd></div>
      <div><dt>SHA-256</dt><dd><code class="hash"><?= $e((string) $req['source_sha256']) ?></code></dd></div>
      <?php if ($req['signed_document_pid'] !== null): ?>
      <div><dt><?= $e($t('esign.signed_file')) ?></dt><dd><a href="<?= $e($url('/esign/r/' . $req['public_id'] . '/file/signed')) ?>" target="_blank" rel="noopener"><?= $ui('icon', ['name' => 'file-type-pdf', 'size' => 'sm']) ?> <?= $e($req['request_no'] . '-signed.pdf') ?></a></dd></div>
      <div><dt>SHA-256</dt><dd><code class="hash"><?= $e((string) $req['signed_sha256']) ?></code></dd></div>
      <?php endif; ?>
    </dl>
    <p class="text-sm text-muted"><?= $e($t('esign.hash_help')) ?></p>
  </section>
</div>
<section class="card stack-sm" aria-labelledby="es-s">
  <h2 id="es-s" class="card__title"><?= $e($t('esign.signers')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="es-s" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col">#</th><th scope="col"><?= $e($t('esign.col.signer')) ?></th><th scope="col"><?= $e($t('esign.field.role')) ?></th><th scope="col"><?= $e($t('esign.col.place')) ?></th><th scope="col"><?= $e($t('esign.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($req['signers'] as $s): ?>
        <tr><td data-label="#"><?= (int) $s['seq'] ?></td>
          <th scope="row" data-label="<?= $e($t('esign.col.signer')) ?>"><?= $e((string) $s['display_name']) ?></th>
          <td data-label="<?= $e($t('esign.field.role')) ?>"><?= $e((string) $s['role_label']) ?></td>
          <td data-label="<?= $e($t('esign.col.place')) ?>"><?= $e($t('esign.place', ['page' => (string) $s['page'], 'where' => $t('esign.anchor.' . $s['anchor'])])) ?></td>
          <td data-label="<?= $e($t('esign.col.status')) ?>"><?= $ui('badge', ['tone' => match ((string) $s['status']) { 'signed' => 'success', 'pending' => 'warning', 'declined' => 'danger', default => 'neutral' }, 'label' => $t('esign.signer_status.' . $s['status'])]) ?>
            <?php if ($s['signed_at'] !== null): ?><span class="d-block text-sm text-muted"><?= $e($date((string) $s['signed_at'])) ?><?= $s['decline_reason'] !== null ? ' · ' . $e((string) $s['decline_reason']) : '' ?></span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($myTurn): ?>
<section class="card stack" aria-labelledby="es-sign">
  <h2 id="es-sign" class="card__title"><?= $e($t('esign.sign_title')) ?></h2>
  <ol class="steps-inline text-sm">
    <li><?= $e($t('esign.sign_step1')) ?> <a href="<?= $e($url('/esign/r/' . $req['public_id'] . '/file/source')) ?>" target="_blank" rel="noopener"><?= $e($t('esign.open_document')) ?></a></li>
    <li><?= $e($t('esign.sign_step2')) ?></li>
  </ol>
  <form method="post" action="<?= $e($url('/esign/r/' . $req['public_id'] . '/sign')) ?>" class="stack" novalidate autocomplete="off">
    <?= $csrf ?><input type="hidden" name="sha256" value="<?= $e((string) $req['source_sha256']) ?>">
    <label class="check"><input type="checkbox" name="read" value="1"<?= ($old['read'] ?? '') === '1' ? ' checked' : '' ?>> <?= $e($t('esign.read_confirm')) ?></label>
    <?php if (isset($errors['read'])): ?><p class="field__error"><?= $e($errors['read']) ?></p><?php endif; ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'typed_name', 'label' => $t('esign.field.typed_name'), 'required' => true, 'value' => $form === 'sign' && is_scalar($old['typed_name'] ?? null) ? (string) $old['typed_name'] : '', 'help' => $t('esign.typed_help', ['name' => $myName]), 'error' => $errors['typed_name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'password', 'type' => 'password', 'label' => $t('esign.field.password'), 'required' => true, 'autocomplete' => 'current-password', 'error' => $errors['password'] ?? null]) ?>
    </div>
    <p class="text-sm text-muted"><?= $e($t('esign.sign_note', ['role' => (string) $me['role_label']])) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('esign.sign'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'pencil']) ?></div>
  </form>
  <details<?= $form === 'decline' ? ' open' : '' ?>><summary><?= $e($t('esign.decline_title')) ?></summary>
    <form method="post" action="<?= $e($url('/esign/r/' . $req['public_id'] . '/decline')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?= $reason('dc-reason', 'decline') ?>
      <div class="row"><?= $ui('button', ['label' => $t('esign.decline'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
    </form>
  </details>
</section>
<?php elseif ($me !== null && $me['status'] === 'waiting' && $open): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('esign.wait_turn')]) ?>
<?php endif; ?>

<?php if ($req['is_sender'] && $st === 'draft'): ?>
<section class="card stack-sm" aria-labelledby="es-send">
  <h2 id="es-send" class="card__title"><?= $e($t('esign.send_title')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('esign.send_help')) ?></p>
  <div class="row">
    <form method="post" action="<?= $e($url('/esign/r/' . $req['public_id'] . '/send')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('esign.send'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></form>
    <?= $ui('button', ['label' => $t('esign.edit_title'), 'href' => $url('/esign/r/' . $req['public_id'] . '/edit'), 'variant' => 'secondary', 'icon' => 'pencil']) ?>
  </div>
</section>
<?php endif; ?>
<?php if (($req['is_sender'] || $req['is_manager']) && in_array($st, ['draft', 'sent', 'partial'], true)): ?>
<details class="card"<?= $form === 'cancel' ? ' open' : '' ?>><summary><?= $e($t('esign.cancel_title')) ?></summary>
  <form method="post" action="<?= $e($url('/esign/r/' . $req['public_id'] . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <p class="text-sm text-muted"><?= $e($t('esign.cancel_help')) ?></p>
    <?= $reason('cn-reason', 'cancel') ?>
    <div class="row"><?= $ui('button', ['label' => $t('esign.cancel'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</details>
<?php endif; ?>
<?php if ($req['is_manager'] && $st === 'completed'): ?>
<details class="card"<?= $form === 'void' ? ' open' : '' ?>><summary><?= $e($t('esign.void_title')) ?></summary>
  <form method="post" action="<?= $e($url('/esign/r/' . $req['public_id'] . '/void')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <p class="text-sm text-muted"><?= $e($t('esign.void_help')) ?></p>
    <?= $reason('vd-reason', 'void') ?>
    <div class="row"><?= $ui('button', ['label' => $t('esign.void'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
  </form>
</details>
<?php endif; ?>

<?php if ($events !== []): ?>
<section class="card stack-sm" aria-labelledby="es-h">
  <h2 id="es-h" class="card__title"><?= $e($t('esign.history')) ?></h2>
  <ul class="plain-list stack-sm">
    <?php foreach ($events as $ev): ?><li><?= $e($t('esign.event.' . $ev['action'])) ?> · <?= $e((string) ($ev['actor'] ?? $t('esign.system'))) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['occurred_at'])) ?></span>
      <?php if ($ev['note'] !== null && !in_array($ev['action'], ['signed'], true)): ?><span class="d-block text-sm"><?= $e((string) $ev['note']) ?></span><?php endif; ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
