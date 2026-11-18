<?php
/**
 * Counting desk of a campaign: scan the tag (or type the code / serial), then record what is seen. Something outside the
 * frozen scope is recorded as "unexpected"; an unknown tag can be described.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $camp @var string $code @var ?array{asset: ?array<string, mixed>, item: ?array<string, mixed>, input: string, custodian_username: string} $found
 * @var list<array<string, mixed>> $sites @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canView @var bool $canManage @var bool $canCount
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$c = $camp;
$pid = (string) $c['public_id'];
$s = $c['stats'];
$asset = $found['asset'] ?? null;
$item = $found['item'] ?? null;
$exp = $item !== null && $item['expected_json'] !== null ? (array) json_decode((string) $item['expected_json'], true) : null;
$sitePid = '';
$wantSite = (int) ($exp['site_id'] ?? ($asset['site_id'] ?? 0));
foreach ($sites as $st) {
    if ((int) $st['id'] === $wantSite) {
        $sitePid = (string) $st['public_id'];
    }
}
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$conds = [];
foreach (['good', 'fair', 'poor', 'broken'] as $cd) {
    $conds[$cd] = $t('assets.condition.' . $cd);
}
?>
<?= $ui('page-header', ['title' => $t('assets.count_title'), 'subtitle' => $c['name'] . ' · ' . $t('assets.progress', ['n' => (string) $s['counted'], 'of' => (string) $s['applicable']]),
    'crumbs' => [[$t('assets.campaigns_title'), $url('/assets/campaigns')], [(string) $c['name'], $url('/assets/campaigns/' . $pid)], [$t('assets.count_title'), null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($c['status'] !== 'counting'): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('assets.not_counting')]) ?>
<?php else: ?>
<form method="get" action="<?= $e($url('/assets/campaigns/' . $pid . '/count')) ?>" class="card row as-filter" role="search" aria-label="<?= $e($t('assets.scan')) ?>">
  <?= $ui('field', ['name' => 'code', 'id' => 'as-scan', 'type' => 'search', 'label' => $t('assets.scan'), 'value' => $found === null ? '' : $code, 'help' => $t('assets.scan_help'), 'autocomplete' => 'off', 'autofocus' => $found === null]) ?>
  <?= $ui('button', ['label' => $t('assets.find'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'search']) ?>
</form>
<?php if ($found !== null): ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<section class="card stack-sm" aria-labelledby="cn-h">
  <?php if ($asset !== null): ?>
    <h2 id="cn-h" class="card__title"><?= $e($asset['code'] . ' · ' . $asset['name']) ?>
      <?= $item === null || (int) $item['in_scope'] === 0 ? $ui('badge', ['tone' => 'info', 'label' => $t('assets.out_of_scope')]) : '' ?>
      <?= $item !== null && $item['counted_by'] !== null ? $ui('badge', ['tone' => 'warning', 'label' => $t('assets.already_counted')]) : '' ?></h2>
    <?php if ($exp !== null): ?><p class="text-sm"><?= $e($t('assets.expected_line', ['site' => (string) ($exp['site'] ?? ''), 'location' => (string) ($exp['location'] ?? '—'), 'custodian' => (string) ($exp['custodian'] ?? '—'), 'condition' => $t('assets.condition.' . ($exp['condition'] ?? 'good'))])) ?></p><?php endif; ?>
    <?php if ($item !== null && (int) $item['double_needed'] === 1): ?><p class="text-sm"><?= $ui('badge', ['tone' => 'warning', 'label' => $t('assets.needs_second')]) ?> <?= $e($t('assets.second_help')) ?></p><?php endif; ?>
  <?php else: ?>
    <h2 id="cn-h" class="card__title"><?= $e($t('assets.unknown_tag')) ?></h2>
    <p class="text-sm"><?= $e($t('assets.unknown_help')) ?></p>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/assets/campaigns/' . $pid . '/count')) ?>" class="stack-sm" novalidate><?= $csrf ?>
    <input type="hidden" name="code" value="<?= $e($code) ?>">
    <?php if ($asset !== null): ?><input type="hidden" name="asset" value="<?= $e((string) $asset['public_id']) ?>"><?php else: ?>
      <?= $ui('field', ['name' => 'label', 'label' => $t('assets.field.unexpected_label'), 'required' => true, 'value' => $o('label', $found['input']), 'error' => $errors['label'] ?? null]) ?>
    <?php endif; ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'site', 'id' => 'f-obs-site', 'type' => 'select', 'label' => $t('assets.field.obs_site'), 'required' => true, 'value' => $o('site', $sitePid), 'options' => ['' => $t('assets.pick')] + array_column($sites, 'code', 'public_id'), 'error' => $errors['obs_site'] ?? null]) ?>
      <?= $ui('field', ['name' => 'location', 'id' => 'f-obs-location', 'label' => $t('assets.field.obs_location'), 'value' => $o('location', (string) ($exp['location'] ?? ($asset['location'] ?? ''))), 'help' => $t('assets.obs_location_help')]) ?>
      <?= $ui('field', ['name' => 'who', 'id' => 'f-obs-who', 'label' => $t('assets.field.obs_who'), 'value' => $o('who', (string) ($found['custodian_username'] ?? '')), 'help' => $t('assets.obs_who_help'), 'error' => $errors['obs_who'] ?? null]) ?>
      <?= $ui('field', ['name' => 'condition', 'id' => 'f-obs-condition', 'type' => 'select', 'label' => $t('assets.field.obs_condition'), 'value' => $o('condition', (string) ($exp['condition'] ?? ($asset['asset_condition'] ?? 'good'))), 'options' => $conds, 'error' => $errors['obs_condition'] ?? null]) ?>
      <?= $ui('field', ['name' => 'note', 'id' => 'f-obs-note', 'label' => $t('assets.field.note'), 'value' => $o('note'), 'error' => $errors['obs_note'] ?? null]) ?>
    </div>
    <label class="check"><input type="checkbox" name="tag_damaged" value="1"<?= $o('tag_damaged') !== '' ? ' checked' : '' ?>> <?= $e($t('assets.tag_damaged')) ?></label>
    <div class="row"><?= $ui('button', ['label' => $t('assets.record_count'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>
<?php endif; ?>
<?php endif; ?>
