<?php
/**
 * One asset: facts, QR tag, custody (acknowledge for the custodian), admin actions (assign, return, status, edit,
 * files, disposal, new tag), "report a fault" to the helpdesk, custody history and the change history.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $item @var ?string $qr @var list<array<string, mixed>> $types @var list<array<string, mixed>> $sites @var list<array<string, mixed>> $depts
 * @var bool $helpdesk @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $here @var bool $canView @var bool $canManage
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$a = $item;
$pid = (string) $a['public_id'];
$st = (string) $a['status'];
$dt = static fn (?string $x): string => $x === null ? '—' : $date(substr($x, 0, 10), false) . (strlen($x) > 10 ? ' ' . substr($x, 11, 5) : '');
$fe = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$fo = static fn (string $f, string $k, string $d = ''): string => $form === $f && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$conds = [];
foreach (['good', 'fair', 'poor', 'broken'] as $c) {
    $conds[$c] = $t('assets.condition.' . $c);
}
$money = static fn (mixed $x): string => $x === null ? '—' : number_format((float) $x, 2);
// history values: field names and coded values in words
$label = static function (string $k) use ($t): string {
    $key = 'assets.field.' . ($k === 'asset_condition' ? 'condition' : $k);
    $s = $t($key);
    return $s === $key ? $k : $s;
};
$val = static function (string $k, mixed $x) use ($t): string {
    if ($x === null || $x === '') {
        return '—';
    }
    return match ($k) {
        'status' => $t('assets.status.' . $x),
        'asset_condition' => $t('assets.condition.' . $x),
        'method' => $t('assets.method.' . $x),
        'kind' => $t('assets.file_kind.' . $x),
        default => (string) $x,
    };
};
$diff = static function (?string $before, ?string $after) use ($label, $val): string {
    $b = $before === null ? [] : (array) json_decode($before, true);
    $af = $after === null ? [] : (array) json_decode($after, true);
    $parts = [];
    foreach (array_unique(array_merge(array_keys($b), array_keys($af))) as $k) {
        $parts[] = $label((string) $k) . ': ' . (array_key_exists($k, $b) ? $val((string) $k, $b[$k]) . ' → ' : '') . $val((string) $k, $af[$k] ?? null);
    }
    return implode(' · ', $parts);
};
$fv = $form === 'edit' ? $old : [
    'code' => $a['code'], 'name' => $a['name'], 'serial_no' => $a['serial_no'], 'manufacturer' => $a['manufacturer'], 'model' => $a['model'], 'acquired_on' => $a['acquired_on'],
    'cost' => $a['cost'], 'supplier' => $a['supplier'], 'purchase_ref' => $a['purchase_ref'], 'warranty_until' => $a['warranty_until'], 'site' => $a['site_pid'], 'location' => $a['location'],
    'department' => $a['dept_pid'], 'notes' => $a['notes'],
];
if ($form !== 'edit') {
    foreach ($types as $ty) {
        if ((int) $ty['id'] === (int) $a['type_id']) {
            $fv['type'] = $ty['public_id'];
        }
    }
}
$ferr = $form === 'edit' ? $errors : [];
$isNew = false;
$statusTo = ['available' => ['maintenance', 'lost'], 'maintenance' => ['available', 'lost'], 'lost' => ['available'], 'assigned' => ['lost']][$st] ?? [];
?>
<?= $ui('page-header', ['title' => $a['code'] . ' · ' . $a['name'], 'subtitle' => $a['type_name'] . ' · ' . $t('assets.class.' . $a['asset_class']),
    'crumbs' => $a['can_view'] ? [[$t('assets.title'), $url('/assets')], [(string) $a['code'], null]] : [[$t('assets.mine_title'), $url('/assets/mine')], [(string) $a['code'], null]]]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
<div class="grid grid--2">
  <section class="card stack-sm" aria-labelledby="as-f-h">
    <h2 id="as-f-h" class="card__title"><?= $e($t('assets.field.status')) ?>: <?php include __DIR__ . '/_status.php'; ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('assets.field.serial_no')) ?></dt><dd><?= $e((string) ($a['serial_no'] ?? '—')) ?></dd></div>
      <div><dt><?= $e($t('assets.field.model')) ?></dt><dd><?= $e(trim(($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? '')) ?: '—') ?></dd></div>
      <div><dt><?= $e($t('assets.field.site')) ?></dt><dd><?= $e($a['site_code'] . ($a['location'] !== null ? ' · ' . $a['location'] : '')) ?></dd></div>
      <div><dt><?= $e($t('assets.field.department')) ?></dt><dd><?= $e((string) ($a['dept_code'] ?? '—')) ?></dd></div>
      <div><dt><?= $e($t('assets.field.condition')) ?></dt><dd><?= $e($t('assets.condition.' . $a['asset_condition'])) ?></dd></div>
      <div><dt><?= $e($t('assets.field.custodian')) ?></dt><dd><?= $e((string) ($a['custodian_name'] ?? '—')) ?><?= $a['custody'] !== null && $a['custody']['acknowledged_at'] === null ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('assets.not_acknowledged')]) : '' ?></dd></div>
      <?php if ($a['can_view']): ?>
      <div><dt><?= $e($t('assets.field.acquired_on')) ?></dt><dd><?= $e($a['acquired_on'] === null ? '—' : $date((string) $a['acquired_on'], false)) ?></dd></div>
      <div><dt><?= $e($t('assets.field.cost')) ?></dt><dd><?= $e($money($a['cost'])) ?></dd></div>
      <div><dt><?= $e($t('assets.field.supplier')) ?></dt><dd><?= $e(trim(($a['supplier'] ?? '') . ($a['purchase_ref'] !== null ? ' · ' . $a['purchase_ref'] : '')) ?: '—') ?></dd></div>
      <div><dt><?= $e($t('assets.field.warranty_until')) ?></dt><dd><?= $e($a['warranty_until'] === null ? '—' : $date((string) $a['warranty_until'], false)) ?></dd></div>
      <?php if ($a['notes'] !== null): ?><div><dt><?= $e($t('assets.field.notes')) ?></dt><dd><?= $e((string) $a['notes']) ?></dd></div><?php endif; ?>
      <?php endif; ?>
    </dl>
    <?php if ($a['disposal'] !== null && in_array($a['disposal']['status'], ['pending', 'approved'], true)): ?>
      <p class="text-sm"><?= $ui('badge', ['tone' => $a['disposal']['status'] === 'approved' ? 'neutral' : 'warning', 'label' => $t('assets.disposal_state.' . $a['disposal']['status'])]) ?>
        <?= $e($t('assets.method.' . $a['disposal']['method']) . ' — ' . $a['disposal']['reason']) ?></p>
    <?php endif; ?>
    <?php if ($helpdesk && $st !== 'disposed'): ?>
      <p><?= $ui('button', ['label' => $t('assets.report_fault'), 'href' => $url('/helpdesk/new?' . http_build_query(['asset' => $a['code'] . ' ' . $a['name'], 'location' => (string) ($a['location'] ?? ''), 'site' => (string) $a['site_pid']])), 'variant' => 'secondary', 'icon' => 'tool']) ?></p>
    <?php endif; ?>
  </section>
  <section class="card stack-sm" aria-labelledby="as-c-h">
    <h2 id="as-c-h" class="card__title"><?= $e($t('assets.custody')) ?></h2>
    <?php if ($a['custody'] === null): ?><p class="text-muted"><?= $e($t('assets.no_custodian')) ?></p><?php else: $c = $a['custody']; ?>
      <p><?= $e($t('assets.held_by', ['who' => (string) $a['custodian_name'], 'since' => $dt((string) $c['assigned_at'])])) ?> · <?= $e($t('assets.condition_out', ['c' => $t('assets.condition.' . $c['condition_out'])])) ?></p>
      <?php if ($c['acknowledged_at'] !== null): ?><p class="text-sm"><?= $ui('icon', ['name' => 'circle-check', 'size' => 'sm']) ?> <?= $e($t('assets.acknowledged_at', ['at' => $dt((string) $c['acknowledged_at'])])) ?></p><?php endif; ?>
      <?php if ($a['mine'] && $c['acknowledged_at'] === null): ?>
      <form method="post" action="<?= $e($url('/assets/a/' . $pid . '/ack')) ?>" class="stack-sm"><?= $csrf ?>
        <p class="text-sm"><?= $e($t('assets.ack_help')) ?></p>
        <?= $ui('button', ['label' => $t('assets.ack'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($a['can_manage'] && $st === 'available'): ?>
    <form method="post" action="<?= $e($url('/assets/a/' . $pid . '/assign')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'who', 'label' => $t('assets.field.who'), 'required' => true, 'value' => $fo('assign', 'who'), 'help' => $t('assets.who_help'), 'error' => $fe('assign', 'who')]) ?>
        <?= $ui('field', ['name' => 'condition_out', 'type' => 'select', 'label' => $t('assets.field.condition_out'), 'value' => $fo('assign', 'condition_out', (string) $a['asset_condition']), 'options' => $conds]) ?>
      </div>
      <?= $ui('field', ['name' => 'assign_note', 'label' => $t('assets.field.note'), 'value' => $fo('assign', 'assign_note')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('assets.assign'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'user']) ?></div>
    </form>
    <?php elseif ($a['can_manage'] && $st === 'assigned'): ?>
    <form method="post" action="<?= $e($url('/assets/a/' . $pid . '/return')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'condition_in', 'type' => 'select', 'label' => $t('assets.field.condition_in'), 'value' => $fo('return', 'condition_in', (string) $a['asset_condition']), 'options' => $conds, 'help' => $t('assets.broken_help')]) ?>
        <?= $ui('field', ['name' => 'return_note', 'label' => $t('assets.field.note'), 'value' => $fo('return', 'return_note')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('assets.return'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'arrow-back-up']) ?></div>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php if ($qr !== null): ?>
<section class="card stack-sm" aria-labelledby="as-q-h">
  <h2 id="as-q-h" class="card__title"><?= $e($t('assets.tag')) ?></h2>
  <div class="row">
    <div class="as-qr" role="img" aria-label="<?= $e($t('assets.qr_alt', ['code' => (string) $a['code']])) ?>"><?= $qr /* generated by QrRender — no user markup */ ?></div>
    <div class="stack-sm">
      <p class="text-sm"><?= $e($t('assets.tag_help')) ?></p>
      <?php if ($a['can_manage']): ?>
      <p><?= $ui('button', ['label' => $t('assets.print_label'), 'href' => $url('/assets/labels?q=' . rawurlencode((string) $a['code'])), 'variant' => 'secondary', 'icon' => 'printer']) ?></p>
      <form method="post" action="<?= $e($url('/assets/a/' . $pid . '/qr')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('assets.new_qr'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'refresh']) ?></form>
      <p class="text-sm text-muted"><?= $e($t('assets.new_qr_help')) ?></p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php if ($a['can_manage'] && $st !== 'disposed'): ?>
<section class="card stack-sm" aria-labelledby="as-m-h">
  <h2 id="as-m-h" class="card__title"><?= $e($t('assets.manage')) ?></h2>
  <?php if ($statusTo !== []): ?>
  <details<?= $form === 'status' ? ' open' : '' ?>><summary><?= $e($t('assets.change_status')) ?></summary>
    <form method="post" action="<?= $e($url('/assets/a/' . $pid . '/status')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('assets.field.new_status'), 'value' => $fo('status', 'status'), 'options' => array_combine($statusTo, array_map(static fn (string $s): string => $t('assets.status.' . $s), $statusTo))]) ?>
        <?= $ui('field', ['name' => 'status_reason', 'label' => $t('assets.field.reason'), 'required' => true, 'value' => $fo('status', 'status_reason'), 'error' => $fe('status', 'status_reason')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('assets.save'), 'type' => 'submit', 'variant' => 'secondary']) ?></div>
    </form>
  </details>
  <?php endif; ?>
  <details<?= $form === 'edit' ? ' open' : '' ?>><summary><?= $e($t('assets.edit')) ?></summary>
    <form method="post" action="<?= $e($url('/assets/a/' . $pid . '/edit')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <?php include __DIR__ . '/_fields.php'; ?>
      <?= $ui('field', ['name' => 'reason', 'id' => 'f-edit-reason', 'label' => $t('assets.field.reason'), 'value' => $fo('edit', 'reason'), 'help' => $t('assets.reason_change_help'), 'error' => $fe('edit', 'reason')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('assets.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
    </form>
  </details>
  <details<?= $form === 'file' ? ' open' : '' ?>><summary><?= $e($t('assets.add_file')) ?></summary>
    <form method="post" action="<?= $e($url('/assets/a/' . $pid . '/file')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate><?= $csrf ?>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'file_kind', 'type' => 'select', 'label' => $t('assets.field.kind'), 'value' => $fo('file', 'file_kind', 'purchase'), 'options' => array_combine(['purchase', 'warranty', 'photo', 'handover', 'repair', 'disposal', 'other'], array_map(static fn (string $k): string => $t('assets.file_kind.' . $k), ['purchase', 'warranty', 'photo', 'handover', 'repair', 'disposal', 'other'])), 'error' => $fe('file', 'file_kind')]) ?>
        <?= $ui('field', ['name' => 'file', 'type' => 'file', 'label' => $t('assets.field.file'), 'required' => true, 'accept' => '.pdf,.png,.jpg,.jpeg,.webp', 'help' => $t('assets.file_help'), 'error' => $fe('file', 'file')]) ?>
      </div>
      <div class="row"><?= $ui('button', ['label' => $t('assets.upload'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'upload']) ?></div>
    </form>
  </details>
  <?php if (in_array($st, ['available', 'maintenance', 'lost'], true)): ?>
  <details<?= $form === 'dispose' ? ' open' : '' ?>><summary><?= $e($t('assets.dispose')) ?></summary>
    <form method="post" action="<?= $e($url('/assets/a/' . $pid . '/dispose')) ?>" class="stack-sm" novalidate><?= $csrf ?>
      <p class="text-sm text-muted"><?= $e($t('assets.dispose_help')) ?></p>
      <div class="grid grid--2">
        <?= $ui('field', ['name' => 'method', 'type' => 'select', 'label' => $t('assets.field.method'), 'required' => true, 'value' => $fo('dispose', 'method'), 'options' => ['' => $t('assets.pick'), 'sell' => $t('assets.method.sell'), 'scrap' => $t('assets.method.scrap'), 'donate' => $t('assets.method.donate'), 'write_off' => $t('assets.method.write_off')], 'error' => $fe('dispose', 'method')]) ?>
        <?= $ui('field', ['name' => 'proceeds', 'type' => 'money', 'label' => $t('assets.field.proceeds'), 'value' => $fo('dispose', 'proceeds'), 'error' => $fe('dispose', 'proceeds')]) ?>
      </div>
      <?= $ui('field', ['name' => 'disposal_reason', 'type' => 'textarea', 'label' => $t('assets.field.disposal_reason'), 'required' => true, 'value' => $fo('dispose', 'disposal_reason'), 'error' => $fe('dispose', 'disposal_reason')]) ?>
      <div class="row"><?= $ui('button', ['label' => $t('assets.request_disposal'), 'type' => 'submit', 'variant' => 'danger']) ?></div>
    </form>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($a['can_view']): ?>
<?php if ($a['files'] !== []): ?>
<section class="card stack-sm" aria-labelledby="as-fl-h">
  <h2 id="as-fl-h" class="card__title"><?= $e($t('assets.files')) ?></h2>
  <ul class="plain-list stack-sm">
    <?php foreach ($a['files'] as $f): ?><li><?= $ui('badge', ['tone' => 'neutral', 'label' => $t('assets.file_kind.' . $f['kind'])]) ?> <a href="<?= $e($url('/assets/a/' . $pid . '/f/' . $f['id'])) ?>"><?= $e((string) $f['filename']) ?></a> <span class="text-sm text-muted"><?= $e($date((string) $f['added_at'])) ?></span></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<section class="card card--flush" aria-labelledby="as-ch-h">
  <div class="card__head"><h2 id="as-ch-h" class="card__title"><?= $e($t('assets.custody_history')) ?></h2></div>
  <?php if ($a['custody_history'] === []): ?><div class="card__pad"><p class="text-muted"><?= $e($t('assets.no_custody_history')) ?></p></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="as-ch-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('assets.field.custodian')) ?></th><th scope="col"><?= $e($t('assets.col.assigned')) ?></th><th scope="col"><?= $e($t('assets.col.acknowledged')) ?></th><th scope="col"><?= $e($t('assets.col.returned')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($a['custody_history'] as $c): ?>
        <tr><th scope="row" data-label="<?= $e($t('assets.field.custodian')) ?>"><?= $e((string) $c['user_name']) ?></th>
          <td data-label="<?= $e($t('assets.col.assigned')) ?>"><?= $e($dt((string) $c['assigned_at']) . ' · ' . $t('assets.condition.' . $c['condition_out'])) ?></td>
          <td data-label="<?= $e($t('assets.col.acknowledged')) ?>"><?= $e($dt($c['acknowledged_at'])) ?></td>
          <td data-label="<?= $e($t('assets.col.returned')) ?>"><?= $e($dt($c['returned_at']) . ($c['condition_in'] !== null ? ' · ' . $t('assets.condition.' . $c['condition_in']) : '')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="as-h-h">
  <h2 id="as-h-h" class="card__title"><?= $e($t('assets.history')) ?></h2>
  <ul class="plain-list stack-sm"><?php foreach ($a['moves'] as $m): ?><li><strong><?= $e($t('assets.move.' . $m['kind'])) ?></strong> · <?= $e((string) ($m['actor_name'] ?? $t('assets.system'))) ?> <span class="text-sm text-muted"><?= $e($dt((string) $m['at_local'])) ?></span>
    <?php $d = $diff($m['before_json'] !== null ? (string) $m['before_json'] : null, $m['after_json'] !== null ? (string) $m['after_json'] : null); if ($d !== ''): ?><div class="text-sm"><?= $e($d) ?></div><?php endif; ?>
    <?php if ($m['reason'] !== null): ?><div class="text-sm text-muted"><?= $e($t('assets.field.reason') . ': ' . $m['reason']) ?></div><?php endif; ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
