<?php
/**
 * One contractor company: qualification today, details, workers (with their missing documents), documents with validity,
 * suspension, permits and history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $co @var list<array<string, mixed>> $types @var array<string, string> $errors @var array<string, mixed> $old @var string $form
 * @var string $here @var bool $canView @var bool $canManage @var bool $canGate
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$c = $co;
$cp = (string) $c['public_id'];
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$fo = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$typeOpts = ['' => $t('contractors.pick')];
foreach ($types as $ty) {
    $typeOpts[(string) $ty['public_id']] = $ty['name'] . ' (' . $t('contractors.applies.' . $ty['applies_to']) . ')';
}
$workerOpts = ['' => $t('contractors.company_doc')];
foreach ($c['workers'] as $w) {
    if ((int) $w['active'] === 1) {
        $workerOpts[(string) $w['public_id']] = (string) $w['name'];
    }
}
?>
<?= $ui('page-header', ['title' => (string) $c['name'], 'subtitle' => trim(($c['contact_name'] ?? '') . ' · ' . ($c['contact_phone'] ?? '') . ' · ' . ($c['contact_email'] ?? ''), ' ·'),
    'crumbs' => [[$t('contractors.title'), $url('/contractors')], [(string) $c['name'], null]],
    'actions' => $c['can_manage'] && (int) $c['suspended'] === 0 ? $ui('button', ['label' => $t('contractors.new_permit'), 'href' => $url('/contractors/permits/new?company=' . $cp), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<?php if ((int) $c['suspended'] === 1): ?>
  <?= $ui('alert', ['tone' => 'danger', 'title' => $t('contractors.suspended'), 'text' => (string) $c['suspended_reason']]) ?>
<?php elseif ($c['missing'] === []): ?>
  <?= $ui('alert', ['tone' => 'success', 'text' => $t('contractors.company_qualified')]) ?>
<?php else: ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('contractors.docs_pending'), 'text' => $t('contractors.missing_list', ['docs' => implode(', ', $c['missing'])])]) ?>
<?php endif; ?>
<section class="card card--flush" id="workers" aria-labelledby="co-w">
  <div class="card__head"><h2 id="co-w" class="card__title"><?= $e($t('contractors.workers', ['n' => (string) count($c['workers'])])) ?></h2></div>
  <?php if ($c['workers'] === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('contractors.no_workers')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="co-w" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('contractors.field.worker_name')) ?></th><th scope="col"><?= $e($t('contractors.field.position')) ?></th><th scope="col"><?= $e($t('contractors.col.qualification')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($c['workers'] as $w): ?>
        <tr><th scope="row" data-label="<?= $e($t('contractors.field.worker_name')) ?>"><?= $e((string) $w['name']) ?><?= (int) $w['active'] === 1 ? '' : ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('contractors.inactive')]) ?></th>
          <td data-label="<?= $e($t('contractors.field.position')) ?>"><?= $e(trim(($w['position'] ?? '') . ' ' . ($w['phone'] ?? '')) ?: '—') ?></td>
          <td data-label="<?= $e($t('contractors.col.qualification')) ?>"><?= $w['missing'] === [] ? $ui('badge', ['tone' => 'success', 'label' => $t('contractors.qualified')]) : $ui('badge', ['tone' => 'warning', 'label' => $t('contractors.docs_pending')]) . ' <span class="text-sm">' . $e(implode(', ', $w['missing'])) . '</span>' ?>
            <?php if ($c['can_manage']): ?>
            <details class="ct-inline"><summary><?= $e($t('contractors.edit_worker')) ?></summary>
              <form method="post" action="<?= $e($url('/contractors/c/' . $cp . '/w/' . $w['public_id'])) ?>" class="stack-sm" novalidate><?= $csrf ?>
                <?= $ui('field', ['name' => 'name', 'id' => 'wn-' . $w['public_id'], 'label' => $t('contractors.field.worker_name'), 'value' => (string) $w['name']]) ?>
                <?= $ui('field', ['name' => 'position', 'id' => 'wp-' . $w['public_id'], 'label' => $t('contractors.field.position'), 'value' => (string) ($w['position'] ?? '')]) ?>
                <?= $ui('field', ['name' => 'phone', 'id' => 'wt-' . $w['public_id'], 'type' => 'tel', 'label' => $t('contractors.field.phone'), 'value' => (string) ($w['phone'] ?? '')]) ?>
                <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $w['active'] === 1 ? ' checked' : '' ?>> <?= $e($t('contractors.active')) ?></label>
                <?= $ui('button', ['label' => $t('contractors.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></form></details>
            <?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($c['can_manage']): ?>
  <form method="post" action="<?= $e($url('/contractors/c/' . $cp . '/worker')) ?>" class="card__pad row ct-add" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'name', 'id' => 'w-new-name', 'label' => $t('contractors.add_worker'), 'value' => $fo('worker', 'name'), 'error' => $fe('worker', 'worker_name')]) ?>
    <?= $ui('field', ['name' => 'position', 'id' => 'w-new-pos', 'label' => $t('contractors.field.position'), 'value' => $fo('worker', 'position')]) ?>
    <?= $ui('field', ['name' => 'phone', 'id' => 'w-new-phone', 'type' => 'tel', 'label' => $t('contractors.field.phone'), 'value' => $fo('worker', 'phone')]) ?>
    <?= $ui('button', ['label' => $t('contractors.add'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?>
  </form>
  <?php endif; ?>
</section>
<section class="card card--flush" aria-labelledby="co-d">
  <div class="card__head"><h2 id="co-d" class="card__title"><?= $e($t('contractors.documents')) ?></h2></div>
  <?php if ($c['documents'] === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('contractors.no_documents')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="co-d" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('contractors.field.doc_type')) ?></th><th scope="col"><?= $e($t('contractors.col.holder')) ?></th><th scope="col"><?= $e($t('contractors.col.valid')) ?></th><th scope="col"><?= $e($t('contractors.field.file')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($c['documents'] as $d): ?>
        <tr><th scope="row" data-label="<?= $e($t('contractors.field.doc_type')) ?>"><?= $e((string) $d['type_name']) ?></th>
          <td data-label="<?= $e($t('contractors.col.holder')) ?>"><?= $e((string) ($d['worker_name'] ?? $t('contractors.company_doc'))) ?></td>
          <td data-label="<?= $e($t('contractors.col.valid')) ?>"><?= $e(($d['valid_from'] !== null ? $date((string) $d['valid_from'], false) : '…') . ' – ' . ($d['valid_until'] !== null ? $date((string) $d['valid_until'], false) : $t('contractors.no_expiry'))) ?></td>
          <td data-label="<?= $e($t('contractors.field.file')) ?>"><a href="<?= $e($url('/contractors/c/' . $cp . '/f/' . $d['id'])) ?>"><?= $e((string) $d['filename']) ?></a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($c['can_manage'] && $types !== []): ?>
  <form method="post" action="<?= $e($url('/contractors/c/' . $cp . '/document')) ?>" enctype="multipart/form-data" class="card__pad stack-sm" novalidate><?= $csrf ?>
    <h3 class="text-md"><?= $e($t('contractors.add_document')) ?></h3>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'doc_type', 'type' => 'select', 'label' => $t('contractors.field.doc_type'), 'required' => true, 'value' => $fo('document', 'doc_type'), 'options' => $typeOpts, 'error' => $fe('document', 'doc_type')]) ?>
      <?= $ui('field', ['name' => 'worker', 'type' => 'select', 'label' => $t('contractors.col.holder'), 'value' => $fo('document', 'worker'), 'options' => $workerOpts, 'error' => $fe('document', 'worker')]) ?>
      <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('contractors.field.file'), 'required' => true, 'accept' => '.pdf,.png,.jpg,.jpeg,.webp', 'error' => $fe('document', 'file')]) ?>
      <?= $ui('field', ['name' => 'valid_from', 'type' => 'date', 'label' => $t('contractors.field.valid_from'), 'value' => $fo('document', 'valid_from')]) ?>
      <?= $ui('field', ['name' => 'valid_until', 'type' => 'date', 'label' => $t('contractors.field.valid_until'), 'value' => $fo('document', 'valid_until'), 'help' => $t('contractors.valid_until_help'), 'error' => $fe('document', 'valid_until')]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('contractors.upload'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?></div>
  </form>
  <?php endif; ?>
</section>
<?php if ($c['permits'] !== []): ?>
<section class="card card--flush" aria-labelledby="co-p">
  <div class="card__head"><h2 id="co-p" class="card__title"><?= $e($t('contractors.tab.permits')) ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="co-p" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('contractors.col.ref')) ?></th><th scope="col"><?= $e($t('contractors.field.work')) ?></th><th scope="col"><?= $e($t('contractors.col.when')) ?></th><th scope="col"><?= $e($t('contractors.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($c['permits'] as $p): $pst = (string) $p['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('contractors.col.ref')) ?>"><a href="<?= $e($url('/contractors/p/' . $p['public_id'])) ?>"><?= $e((string) ($p['ref'] ?? $t('contractors.draft'))) ?></a></th>
          <td data-label="<?= $e($t('contractors.field.work')) ?>"><?= $e($p['work'] . ' · ' . $p['area']) ?></td>
          <td data-label="<?= $e($t('contractors.col.when')) ?>"><?= $e($date(substr((string) $p['starts_at'], 0, 10), false) . ' ' . substr((string) $p['starts_at'], 11, 5) . ' – ' . $date(substr((string) $p['ends_at'], 0, 10), false) . ' ' . substr((string) $p['ends_at'], 11, 5)) ?></td>
          <td data-label="<?= $e($t('contractors.col.status')) ?>"><?php include __DIR__ . '/_pstatus.php'; ?><?= $p['rating'] !== null ? ' ' . $e($t('contractors.rating_n', ['n' => (string) $p['rating']])) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
<?php if ($c['can_manage']): ?>
<section class="card stack-sm" aria-labelledby="co-m">
  <h2 id="co-m" class="card__title"><?= $e($t('contractors.manage')) ?></h2>
  <details<?= $form === 'edit' ? ' open' : '' ?>><summary><?= $e($t('contractors.edit_company')) ?></summary>
    <form method="post" action="<?= $e($url('/contractors/c/' . $cp . '/edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?php $cv = $form === 'edit' ? $old : ['name' => $c['name'], 'contact_name' => $c['contact_name'], 'contact_phone' => $c['contact_phone'], 'contact_email' => $c['contact_email'], 'owner' => $c['owner_username']]; $cerr = $form === 'edit' ? $errors : []; include __DIR__ . '/_company_fields.php'; ?>
      <div class="row"><?= $ui('button', ['label' => $t('contractors.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
    </form>
  </details>
  <?php if ((int) $c['suspended'] === 0): ?>
  <details<?= $form === 'suspend' ? ' open' : '' ?>><summary><?= $e($t('contractors.suspend')) ?></summary>
    <form method="post" action="<?= $e($url('/contractors/c/' . $cp . '/suspend')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <p class="text-sm text-muted"><?= $e($t('contractors.suspend_help')) ?></p>
      <?= $ui('field', ['name' => 'suspend_reason', 'label' => $t('contractors.field.reason'), 'required' => true, 'value' => $fo('suspend', 'suspend_reason'), 'error' => $fe('suspend', 'suspend_reason')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('contractors.suspend'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
    </form>
  </details>
  <?php else: ?>
    <form method="post" action="<?= $e($url('/contractors/c/' . $cp . '/reinstate')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('contractors.reinstate'), 'type' => 'submit', 'variant' => 'secondary']) ?></form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($c['events'] !== []): ?>
<section class="card stack-sm" aria-labelledby="co-h">
  <h2 id="co-h" class="card__title"><?= $e($t('contractors.history')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($c['events'] as $ev): ?><li><?= $e($t('contractors.event.' . $ev['event'])) ?> · <?= $e((string) ($ev['actor'] ?? $t('contractors.system'))) ?> <span class="text-sm text-muted"><?= $e($date((string) $ev['created_at'])) ?></span><?= $ev['note'] !== null ? ' — ' . $e((string) $ev['note']) : '' ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
