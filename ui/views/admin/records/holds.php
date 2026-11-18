<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $holds @var bool $canManage @var array<int, string> $companies
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $tab
 */
use Blue\Core\Records\DocumentCatalog;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('records.holds.title'), 'subtitle' => $t('records.holds.subtitle')]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<div class="table-wrap" role="region" aria-label="<?= $e($t('records.holds.title')) ?>" tabindex="0">
  <table class="table table--stack">
    <caption class="sr-only"><?= $e($t('records.holds.title')) ?></caption>
    <thead><tr><th scope="col"><?= $e($t('records.field.reference')) ?></th><th scope="col"><?= $e($t('records.field.scope')) ?></th>
      <th scope="col"><?= $e($t('records.field.status')) ?></th><th scope="col"><?= $e($t('records.field.placed')) ?></th><th scope="col"><?= $e($t('records.field.actions')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($holds as $h): ?>
      <tr id="<?= $e($h['public_id']) ?>">
        <td data-label="<?= $e($t('records.field.reference')) ?>"><span class="mono"><?= $e($h['reference_code']) ?></span><div class="text-sm text-muted"><?= $e($h['reason']) ?></div></td>
        <td data-label="<?= $e($t('records.field.scope')) ?>"><?= $e($t('records.scope.' . $h['scope_type'])) ?>:
          <?php if ($h['scope_type'] === 'document'): ?><a href="<?= $e($url('/documents/' . $h['document_public_id'])) ?>"><?= $e($h['document_title']) ?></a>
          <?php elseif ($h['scope_type'] === 'related'): ?><span class="mono"><?= $e($h['related_type'] . ' ' . $h['related_id']) ?></span>
          <?php else: ?><?= $e($t('records.class.' . $h['record_class'])) ?><?php endif; ?></td>
        <td data-label="<?= $e($t('records.field.status')) ?>"><?= $ui('badge', ['tone' => $h['status'] === 'released' ? 'neutral' : 'warning', 'icon' => $h['status'] === 'released' ? 'lock-open' : 'lock', 'label' => $t('records.hold_status.' . $h['status'])]) ?></td>
        <td data-label="<?= $e($t('records.field.placed')) ?>"><?= $e($date($h['placed_at'])) ?><div class="text-sm text-muted"><?= $e($h['placed_name'] ?? '') ?></div></td>
        <td data-label="<?= $e($t('records.field.actions')) ?>"><?php if ($canManage && $h['status'] === 'active'): ?>
          <form method="post" action="<?= $e($url('/admin/records/holds/' . $h['public_id'] . '/release')) ?>" class="inline-form"><?= $csrf ?>
            <label class="sr-only" for="rr-<?= $e($h['public_id']) ?>"><?= $e($t('iam.field.reason')) ?></label>
            <input class="input" id="rr-<?= $e($h['public_id']) ?>" name="reason" placeholder="<?= $e($t('records.release_reason')) ?>" required>
            <?= $ui('button', ['label' => $t('records.release'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'lock-open']) ?></form>
          <?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($holds === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'lock-open', 'title' => $t('records.holds.empty')]) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php if ($canManage): ?>
<section class="card" aria-labelledby="ph-h">
  <h2 id="ph-h" class="card__title"><?= $e($t('records.hold.place')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('records.hold.help')) ?></p>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/admin/records/holds')) ?>" class="form-grid" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'scope_type', 'label' => $t('records.field.scope'), 'type' => 'select', 'value' => (string) ($old['scope_type'] ?? 'record_class'), 'error' => $errors['scope_type'] ?? null,
        'options' => ['record_class' => $t('records.scope.record_class'), 'related' => $t('records.scope.related')], 'help' => $t('records.help.scope')]) ?>
    <?= $ui('field', ['name' => 'legal_entity', 'label' => $t('document.field.company'), 'type' => 'select', 'value' => (string) ($old['legal_entity'] ?? ''), 'options' => ['' => $t('records.all_companies')] + $companies]) ?>
    <?= $ui('field', ['name' => 'record_class', 'label' => $t('records.field.record_class'), 'type' => 'select', 'value' => (string) ($old['record_class'] ?? ''), 'error' => $errors['record_class'] ?? null,
        'options' => ['' => '—'] + array_combine(DocumentCatalog::RECORD_CLASSES, array_map(static fn ($c) => $t('records.class.' . $c), DocumentCatalog::RECORD_CLASSES))]) ?>
    <?= $ui('field', ['name' => 'related_type', 'label' => $t('records.field.related_type'), 'value' => (string) ($old['related_type'] ?? ''), 'placeholder' => 'employee']) ?>
    <?= $ui('field', ['name' => 'related_id', 'label' => $t('records.field.related_id'), 'value' => (string) ($old['related_id'] ?? ''), 'error' => $errors['related_id'] ?? null]) ?>
    <?= $ui('field', ['name' => 'reference', 'label' => $t('records.field.reference'), 'required' => true, 'value' => (string) ($old['reference'] ?? ''), 'error' => $errors['reference'] ?? null]) ?>
    <?= $ui('field', ['name' => 'review_date', 'label' => $t('records.field.review_date'), 'type' => 'date', 'value' => (string) ($old['review_date'] ?? ''), 'error' => $errors['review_date'] ?? null]) ?>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'full' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $errors['reason'] ?? null]) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('records.hold.place'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'lock']) ?></div>
  </form>
</section>
<?php endif; ?>
