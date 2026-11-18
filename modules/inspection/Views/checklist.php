<?php
/**
 * A checklist: its items now, earlier versions, and a new version (applies to rounds from its date).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $cl @var string $today @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Modules\Inspection\InspectionService;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$text = implode("\n", array_map(static fn (array $i): string => InspectionService::itemLine($i), $cl['items']));
?>
<?= $ui('page-header', ['title' => (string) $cl['name'], 'subtitle' => $t('inspection.checklist_subtitle'), 'crumbs' => [[$t('inspection.admin_title'), $url('/inspection/admin')], [(string) $cl['name'], null]]]) ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
<section class="card stack-sm" aria-labelledby="ic-v">
  <h2 id="ic-v" class="card__title"><?= $e($t('inspection.versions')) ?></h2>
  <?php foreach ($cl['versions'] as $ver): $items = (array) json_decode((string) $ver['items_json'], true); ?>
  <details<?= $ver === $cl['versions'][0] ? ' open' : '' ?>><summary><?= $e($t('inspection.version_line', ['n' => (string) $ver['version_no'], 'd' => $date((string) $ver['effective_from'], false), 'c' => (string) count($items)])) ?><?= $ver['note'] !== null ? ' — ' . $e((string) $ver['note']) : '' ?></summary>
    <ol class="stack-sm"><?php foreach ($items as $i): ?><li><?= $e(InspectionService::itemLine($i)) ?></li><?php endforeach; ?></ol>
  </details>
  <?php endforeach; ?>
</section>
<section class="card stack-sm" aria-labelledby="ic-n">
  <h2 id="ic-n" class="card__title"><?= $e($t('inspection.new_version')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('inspection.new_version_help')) ?></p>
  <form method="post" action="<?= $e($url('/inspection/c/' . $cl['public_id'])) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'name', 'label' => $t('inspection.field.checklist_name'), 'required' => true, 'value' => $o('name', (string) $cl['name']), 'error' => $errors['name'] ?? null]) ?>
    <?= $ui('field', ['name' => 'effective_from', 'type' => 'date', 'label' => $t('inspection.field.effective_from'), 'required' => true, 'value' => $o('effective_from', $today), 'min' => $today, 'error' => $errors['effective_from'] ?? null]) ?>
    <?= $ui('field', ['name' => 'items', 'type' => 'textarea', 'label' => $t('inspection.field.items'), 'required' => true, 'value' => $o('items', $text), 'help' => $t('inspection.items_help'), 'error' => $errors['items'] ?? null]) ?>
    <?= $ui('field', ['name' => 'note', 'label' => $t('inspection.field.version_note'), 'value' => $o('note', ''), 'error' => $errors['note'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('inspection.save_version'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
</div>
