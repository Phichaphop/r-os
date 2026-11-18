<?php
/**
 * New signing request (or edit a draft): the PDF, details, last day, order, and signers with where their box goes.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{pid: string, label: string, mime: string}|null $fromForm
 * @var array<string, string> $companies @var string $lePid @var array<string, mixed>|null $edit @var array<string, string> $errors @var array<string, mixed> $old @var string $today
 */
$cur = $old !== [] ? $old : ($edit !== null ? ['title' => $edit['title'], 'category' => $edit['category'], 'message' => $edit['message'], 'sequential' => (string) $edit['sequential'], 'expires_on' => $edit['expires_on'],
    'signers' => array_map(static fn (array $s): array => ['username' => $s['username'], 'role' => $s['role_label'], 'page' => (string) $s['page'], 'anchor' => $s['anchor']], $edit['signers'])] : ['sequential' => '1']);
$val = static fn (string $k, string $d = ''): string => is_scalar($cur[$k] ?? null) ? (string) $cur[$k] : $d;
$signers = array_values((array) ($cur['signers'] ?? []));
$rows = max(count($signers) + 1, 3);
$anchors = [];
foreach (\Blue\Modules\Esign\EsignService::ANCHORS as $a) {
    $anchors[$a] = $t('esign.anchor.' . $a);
}
?>
<?= $ui('page-header', ['title' => $t($edit !== null ? 'esign.edit_title' : 'esign.new_title'), 'subtitle' => $t('esign.new_subtitle'), 'crumbs' => [[$t('esign.title'), $url('/esign')], [$t($edit !== null ? 'esign.edit_title' : 'esign.new_title'), null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['signers'] ?? $t('esign.fix_errors')]) ?><?php endif; ?>
<form method="post" action="<?= $e($url($edit !== null ? '/esign/r/' . $edit['public_id'] . '/edit' : '/esign/new')) ?>" enctype="multipart/form-data" class="stack" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <section class="card stack" aria-labelledby="ef-d">
    <h2 id="ef-d" class="card__title"><?= $e($t('esign.document')) ?></h2>
    <?php if (count($companies) > 1 && $edit === null): ?>
      <?= $ui('field', ['name' => 'le', 'id' => 'f-le-pick', 'type' => 'select', 'label' => $t('esign.field.company'), 'value' => $lePid, 'options' => $companies]) ?>
    <?php endif; ?>
    <?php if ($fromForm !== null): ?>
      <input type="hidden" name="form" value="<?= $e($fromForm['pid']) ?>">
      <?= $ui('alert', ['tone' => 'info', 'text' => $t('esign.from_form', ['form' => $fromForm['label']])]) ?>
    <?php endif; ?>
    <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('esign.field.file'), 'required' => $edit === null && $fromForm === null, 'accept' => 'application/pdf,.pdf', 'help' => $edit !== null ? $t('esign.file_keep', ['name' => (string) $edit['source_name']]) : $t($fromForm !== null ? 'esign.file_from_form' : 'esign.file_help'), 'error' => $errors['file'] ?? null]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'title', 'label' => $t('esign.field.title'), 'required' => true, 'value' => $val('title'), 'error' => $errors['title'] ?? null]) ?>
      <?= $ui('field', ['name' => 'category', 'label' => $t('esign.field.category'), 'value' => $val('category'), 'placeholder' => $t('esign.category_ph')]) ?>
      <?= $ui('field', ['name' => 'expires_on', 'type' => 'date', 'label' => $t('esign.field.expires_on'), 'required' => true, 'value' => $val('expires_on'), 'min' => $today, 'help' => $t('esign.expires_help'), 'error' => $errors['expires_on'] ?? null]) ?>
      <?= $ui('field', ['name' => 'sequential', 'type' => 'select', 'label' => $t('esign.field.order'), 'value' => $val('sequential', '1'), 'options' => ['1' => $t('esign.sequential'), '0' => $t('esign.parallel')]]) ?>
    </div>
    <?= $ui('field', ['name' => 'message', 'type' => 'textarea', 'label' => $t('esign.field.message'), 'value' => $val('message')]) ?>
  </section>
  <section class="card stack" aria-labelledby="ef-s">
    <h2 id="ef-s" class="card__title"><?= $e($t('esign.signers')) ?></h2>
    <p class="text-sm text-muted"><?= $e($t('esign.signers_help')) ?></p>
    <?php for ($i = 0; $i < $rows; $i++): $s = (array) ($signers[$i] ?? []); $err = $errors['signer_' . $i] ?? null; ?>
    <fieldset class="stack-sm apr-item<?= $err !== null ? ' is-invalid' : '' ?>">
      <legend class="field__label"><?= $e($t('esign.signer_n', ['n' => (string) ($i + 1)])) ?></legend>
      <?php if ($err !== null): ?><p class="field__error"><?= $e($err) ?></p><?php endif; ?>
      <div class="grid grid--4">
        <?= $ui('field', ['name' => 'signers[' . $i . '][username]', 'id' => 'sg-' . $i . '-u', 'label' => $t('esign.field.username'), 'autocomplete' => 'off', 'value' => (string) ($s['username'] ?? '')]) ?>
        <?= $ui('field', ['name' => 'signers[' . $i . '][role]', 'id' => 'sg-' . $i . '-r', 'label' => $t('esign.field.role'), 'value' => (string) ($s['role'] ?? ''), 'placeholder' => $t('esign.role_ph')]) ?>
        <?= $ui('field', ['name' => 'signers[' . $i . '][page]', 'id' => 'sg-' . $i . '-p', 'label' => $t('esign.field.page'), 'value' => (string) ($s['page'] ?? 'last'), 'help' => $i === 0 ? $t('esign.page_help') : null]) ?>
        <?= $ui('field', ['name' => 'signers[' . $i . '][anchor]', 'id' => 'sg-' . $i . '-a', 'type' => 'select', 'label' => $t('esign.field.anchor'), 'value' => (string) ($s['anchor'] ?? 'bottom-right'), 'options' => $anchors]) ?>
      </div>
    </fieldset>
    <?php endfor; ?>
    <p class="text-sm text-muted"><?= $e($t('esign.more_rows')) ?></p>
  </section>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('esign.legal_note')]) ?>
  <div class="row"><?= $ui('button', ['label' => $t('esign.save_draft'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
</form>
