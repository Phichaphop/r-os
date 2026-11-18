<?php
/**
 * HR: appraisal forms of a company + add/edit (scale, rating bands, weighted KPI / competency items that total 100).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array<string, mixed>|null $edit
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
$num = static fn (mixed $n): string => $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
if ($old !== []) {
    $cur = $old;
    $items = array_values((array) ($old['items'] ?? []));
} elseif ($edit !== null) {
    $bands = (array) json_decode((string) ($edit['bands_json'] ?? '[]'), true);
    $cur = ['code' => $edit['code'], 'name' => $edit['name'], 'scale_max' => (string) $edit['scale_max'], 'status' => $edit['status'],
        'bands' => implode("\n", array_map(static fn (array $b): string => $num($b['from']) . ' | ' . $b['label'], $bands))];
    $items = array_map(static fn (array $i): array => ['section' => $i['section'], 'title' => $i['title'], 'weight' => $num($i['weight']), 'description' => $i['description'] ?? ''], (array) json_decode((string) $edit['items_json'], true));
} else {
    $cur = ['scale_max' => '5'];
    $items = [];
}
$val = static fn (string $k, string $d = ''): string => is_scalar($cur[$k] ?? null) ? (string) $cur[$k] : $d;
$slots = max(count($items) + 2, 5);
$total = array_sum(array_map(static fn (array $i): float => (float) str_replace(',', '', (string) ($i['weight'] ?? 0)), $items));
$sections = ['kpi' => $t('appraisal.section.kpi'), 'competency' => $t('appraisal.section.competency')];
?>
<?= $ui('page-header', ['title' => $t('appraisal.forms_title'), 'subtitle' => $t('appraisal.forms_subtitle'), 'crumbs' => [[$t('appraisal.admin_title'), $url('/appraisal/admin?le=' . rawurlencode($lePid))], [$t('appraisal.forms_title'), null]]]) ?>
<section class="card stack-sm" aria-labelledby="af-l">
  <h2 id="af-l" class="card__title"><?= $e($t('appraisal.forms')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('appraisal.none')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($rows as $x): $n = count((array) json_decode((string) $x['items_json'], true)); ?>
      <li><a href="<?= $e($url('/appraisal/admin/forms/' . $x['public_id'])) ?>"><?= $e($x['code'] . ' · ' . $x['name']) ?></a> <?= $x['status'] === 'archived' ? $ui('badge', ['tone' => 'neutral', 'label' => $t('appraisal.archived')]) : '' ?>
        <span class="d-block text-sm text-muted"><?= $e($t('appraisal.form_line', ['n' => (string) $n, 'max' => (string) $x['scale_max'], 'cycles' => (string) $x['cycles']])) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="af-f">
  <h2 id="af-f" class="card__title"><?= $e($edit !== null ? $t('appraisal.form_edit', ['code' => (string) $edit['code']]) : $t('appraisal.form_new')) ?></h2>
  <?php if ($edit !== null && (int) ($edit['cycles'] ?? 0) > 0): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('appraisal.form_frozen_note')]) ?><?php endif; ?>
  <?php if (isset($errors['items'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['items']]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url($edit !== null ? '/appraisal/admin/forms/' . $edit['public_id'] : '/appraisal/admin/forms')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>"><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'label' => $t('appraisal.field.code'), 'required' => true, 'value' => $val('code'), 'placeholder' => 'STAFF-2026', 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'name', 'label' => $t('appraisal.field.name'), 'required' => true, 'value' => $val('name'), 'error' => $errors['name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'scale_max', 'label' => $t('appraisal.field.scale_max'), 'required' => true, 'inputmode' => 'numeric', 'value' => $val('scale_max', '5'), 'help' => $t('appraisal.scale_help'), 'error' => $errors['scale_max'] ?? null]) ?>
      <?php if ($edit !== null): ?><?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('appraisal.col.status'), 'value' => $val('status', 'active'), 'options' => ['active' => $t('appraisal.active'), 'archived' => $t('appraisal.archived')]]) ?><?php endif; ?>
    </div>
    <?= $ui('field', ['name' => 'bands', 'type' => 'textarea', 'label' => $t('appraisal.field.bands'), 'value' => $val('bands'), 'placeholder' => "4.5 | …\n3.5 | …", 'help' => $t('appraisal.bands_help'), 'error' => $errors['bands'] ?? null]) ?>
    <h3 class="text-sm"><?= $e($t('appraisal.items')) ?> · <?= $e($t('appraisal.weight_total', ['w' => $num($total)])) ?></h3>
    <p class="text-sm text-muted"><?= $e($t('appraisal.items_help')) ?></p>
    <?php for ($i = 0; $i < $slots; $i++): $it = (array) ($items[$i] ?? []); $err = $errors['item_' . $i] ?? null; ?>
    <fieldset class="stack-sm apr-item<?= $err !== null ? ' is-invalid' : '' ?>">
      <legend class="field__label"><?= $e($t('appraisal.item_n', ['n' => (string) ($i + 1)])) ?></legend>
      <?php if ($err !== null): ?><p class="field__error"><?= $e($err) ?></p><?php endif; ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'items[' . $i . '][section]', 'id' => 'it-' . $i . '-s', 'type' => 'select', 'label' => $t('appraisal.field.section'), 'value' => (string) ($it['section'] ?? 'kpi'), 'options' => $sections]) ?>
        <?= $ui('field', ['name' => 'items[' . $i . '][title]', 'id' => 'it-' . $i . '-t', 'label' => $t('appraisal.field.item_title'), 'value' => (string) ($it['title'] ?? '')]) ?>
        <?= $ui('field', ['name' => 'items[' . $i . '][weight]', 'id' => 'it-' . $i . '-w', 'label' => $t('appraisal.field.weight'), 'inputmode' => 'decimal', 'value' => (string) ($it['weight'] ?? '')]) ?>
      </div>
      <?= $ui('field', ['name' => 'items[' . $i . '][description]', 'id' => 'it-' . $i . '-d', 'type' => 'textarea', 'label' => $t('appraisal.field.item_description'), 'value' => (string) ($it['description'] ?? ''), 'help' => $i === 0 ? $t('appraisal.description_help') : null]) ?>
    </fieldset>
    <?php endfor; ?>
    <p class="text-sm text-muted"><?= $e($t('appraisal.more_rows')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('appraisal.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
