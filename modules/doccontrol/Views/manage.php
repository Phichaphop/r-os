<?php
/**
 * Manage one controlled document. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $doc @var list<array<string, mixed>> $versions @var array<string, mixed>|null $report
 * @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var array<string, mixed> $audIn @var string $audText
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $today
 */
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Doccontrol\DocControlService;

$loc = (string) ($v['locale'] ?? 'th');
$d = $form === 'details' ? $old + $doc : $doc;
$audError = $form === 'details' ? ($errors['audience'] ?? null) : null;
$audId = 'ed';
$err = static fn (string $which, string $k) => $form === $which ? ($errors[$k] ?? null) : null;
$open = array_values(array_filter($versions, static fn (array $x): bool => in_array($x['status'], ['draft', 'review', 'approved'], true)));
$vTone = ['draft' => 'warning', 'review' => 'info', 'approved' => 'info', 'effective' => 'success', 'superseded' => 'neutral', 'rejected' => 'danger', 'withdrawn' => 'neutral'];
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => (string) $doc['title'], 'subtitle' => $doc['code'] . ' · ' . $t('doccontrol.type.' . $doc['doc_type']) . ' · ' . $audText,
    'crumbs' => [[$t('doccontrol.admin_title'), $url('/policies/admin')], [(string) $doc['code'], null]],
    'actions' => $doc['status'] === 'archived' ? $ui('badge', ['tone' => 'neutral', 'label' => $t('doccontrol.archived')]) : '']) ?>

<?php if ($report !== null): ?>
<section class="card stack-sm" aria-labelledby="rp-h">
  <h2 id="rp-h" class="card__title"><?= $e($t('doccontrol.report')) ?></h2>
  <dl class="facts">
    <div><dt><?= $e($t('doccontrol.stat.total')) ?></dt><dd><?= (int) $report['total'] ?></dd></div>
    <div><dt><?= $e($t('doccontrol.ack_status.acknowledged')) ?></dt><dd><?= (int) $report['acked'] ?> (<?= $e((string) $report['pct']) ?>%)</dd></div>
    <div><dt><?= $e($t('doccontrol.ack_status.pending')) ?></dt><dd><?= (int) $report['pending'] ?></dd></div>
    <div><dt><?= $e($t('doccontrol.ack_status.exception')) ?></dt><dd><?= (int) $report['exception'] ?></dd></div>
  </dl>
  <div class="row">
    <?php if ($report['pending'] > 0): ?>
    <form method="post" action="<?= $e($url('/policies/admin/d/' . $doc['public_id'] . '/action')) ?>"><?= $csrf ?><input type="hidden" name="action" value="remind">
      <?= $ui('button', ['label' => $t('doccontrol.remind'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'bell']) ?></form>
    <?php endif; ?>
    <?= $ui('button', ['label' => $t('doccontrol.export'), 'href' => $url('/policies/admin/d/' . $doc['public_id'] . '/report?format=xlsx'), 'variant' => 'ghost', 'icon' => 'download']) ?>
  </div>
  <?php if ($report['rows'] !== []): ?>
  <div class="table-wrap" role="region" aria-labelledby="rp-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('doccontrol.field.employee')) ?></th><th scope="col"><?= $e($t('doccontrol.field.status')) ?></th><th scope="col"><?= $e($t('doccontrol.field.when')) ?></th><th scope="col"><?= $e($t('doccontrol.field.action')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report['rows'] as $a): ?>
        <tr><th scope="row" data-label="<?= $e($t('doccontrol.field.employee')) ?>"><?= $e((string) $a['display']) ?></th>
          <td data-label="<?= $e($t('doccontrol.field.status')) ?>"><?= $ui('badge', ['tone' => ['acknowledged' => 'success', 'pending' => 'warning', 'exception' => 'neutral'][$a['status']] ?? 'neutral', 'label' => $t('doccontrol.ack_status.' . $a['status'])]) ?><?= $a['exception_reason'] !== null ? ' ' . $e((string) $a['exception_reason']) : '' ?></td>
          <td data-label="<?= $e($t('doccontrol.field.when')) ?>"><?= $e($a['acknowledged_at'] !== null ? DateFormat::day(substr((string) $a['acknowledged_at'], 0, 10), $loc) . ' ' . substr((string) $a['acknowledged_at'], 11, 5) : ($a['opened_at'] !== null ? $t('doccontrol.opened') : '—')) ?></td>
          <td data-label="<?= $e($t('doccontrol.field.action')) ?>"><?php if ($a['status'] === 'pending'): ?>
            <details><summary><?= $e($t('doccontrol.mark_exception')) ?></summary>
              <form method="post" action="<?= $e($url('/policies/admin/d/' . $doc['public_id'] . '/action')) ?>" class="stack-sm"><?= $csrf ?><input type="hidden" name="action" value="exception"><input type="hidden" name="ack" value="<?= (int) $a['id'] ?>">
                <?= $ui('field', ['name' => 'exception_reason', 'id' => 'ex-' . (int) $a['id'], 'label' => $t('doccontrol.field.reason'), 'required' => true]) ?>
                <?= $ui('button', ['label' => $t('doccontrol.save'), 'type' => 'submit', 'size' => 'sm']) ?></form></details>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if (($errors['exception_reason'] ?? null) !== null): ?><p class="field__error"><?= $e($errors['exception_reason']) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card stack" aria-labelledby="vs-h">
  <h2 id="vs-h" class="card__title"><?= $e($t('doccontrol.versions')) ?></h2>
  <?php if ($versions === []): ?><p class="text-sm text-muted"><?= $e($t('doccontrol.no_versions')) ?></p><?php endif; ?>
  <ul class="stack-sm" role="list">
    <?php foreach ($versions as $x): ?>
    <li class="version-row">
      <div><strong><?= $e($t('doccontrol.version_n', ['n' => (string) $x['version_no']])) ?></strong> <?= $ui('badge', ['tone' => $vTone[$x['status']] ?? 'neutral', 'label' => $t('doccontrol.vstatus.' . $x['status'])]) ?>
        <div class="text-sm text-muted"><?= $e($t('doccontrol.effective_on', ['date' => DateFormat::day((string) $x['effective_from'], $loc)]) . ' · ' . ($x['created_name'] ?? '—') . ($x['approved_name'] !== null ? ' · ' . $t('doccontrol.approved_by', ['name' => (string) $x['approved_name']]) : '') . ' · ' . $t((int) $x['requires_ack'] === 1 ? 'doccontrol.needs_ack' : 'doccontrol.read_only')) ?></div>
        <div class="text-sm"><?= $e((string) $x['change_summary']) ?></div>
      </div>
      <div class="row row--tight">
        <a class="btn btn--ghost btn--sm" href="<?= $e($url('/policies/d/' . $doc['public_id'] . '/v/' . $x['public_id'])) ?>" target="_blank" rel="noopener"><?= $ui('icon', ['name' => 'file-type-pdf', 'size' => 'sm']) ?><span><?= $e($t('doccontrol.open_file')) ?></span></a>
        <?php if (in_array($x['status'], ['draft', 'approved'], true)): ?>
        <form method="post" action="<?= $e($url('/policies/admin/v/' . $x['public_id'])) ?>" class="row row--tight"><?= $csrf ?>
          <?php if ($x['status'] === 'draft'): ?><button class="btn btn--primary btn--sm" type="submit" name="action" value="submit"><?= $ui('icon', ['name' => 'send', 'size' => 'sm']) ?><span><?= $e($t('doccontrol.submit')) ?></span></button><?php endif; ?>
          <button class="btn btn--ghost btn--sm" type="submit" name="action" value="withdraw"><?= $ui('icon', ['name' => 'x', 'size' => 'sm']) ?><span><?= $e($t('doccontrol.withdraw')) ?></span></button>
        </form>
        <?php elseif ($x['status'] === 'review' && $x['instance_pid'] !== null): ?><a class="btn btn--ghost btn--sm" href="<?= $e($url('/approvals/' . $x['instance_pid'])) ?>"><?= $e($t('doccontrol.approval_link')) ?></a><?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($open === [] && $doc['status'] === 'active'): ?>
  <form method="post" action="<?= $e($url('/policies/admin/d/' . $doc['public_id'] . '/upload')) ?>" enctype="multipart/form-data" class="stack-sm" novalidate>
    <?= $csrf ?>
    <?= $ui('field', ['name' => 'file', 'id' => 'vu-file', 'type' => 'file', 'label' => $t('doccontrol.field.file'), 'required' => true, 'accept' => '.pdf,application/pdf', 'help' => $t('doccontrol.file_help'), 'error' => $err('upload', 'file')]) ?>
    <?= $ui('field', ['name' => 'change_summary', 'id' => 'vu-sum', 'type' => 'textarea', 'label' => $t('doccontrol.field.change_summary'), 'required' => true, 'value' => (string) ($old['change_summary'] ?? ''), 'error' => $err('upload', 'change_summary')]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'effective_from', 'id' => 'vu-eff', 'type' => 'date', 'label' => $t('doccontrol.field.effective_from'), 'required' => true, 'value' => (string) ($old['effective_from'] ?? $today), 'error' => $err('upload', 'effective_from')]) ?>
      <div class="field"><span class="field__label"><?= $e($t('doccontrol.field.requires_ack')) ?></span><label class="check"><input type="checkbox" name="requires_ack" value="1"<?= ($old['requires_ack'] ?? '1') === '1' ? ' checked' : '' ?>> <?= $e($t('doccontrol.requires_ack_label')) ?></label></div>
    </div>
    <?= $ui('button', ['label' => $t('doccontrol.upload'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'upload']) ?>
  </form>
  <?php elseif ($open !== []): ?><p class="text-sm text-muted"><?= $e($t('doccontrol.open_note')) ?></p><?php endif; ?>
  <p class="text-sm text-muted"><?= $e($t('doccontrol.versions_help')) ?></p>
</section>

<section class="card stack" aria-labelledby="dt-h">
  <h2 id="dt-h" class="card__title"><?= $e($t('doccontrol.details')) ?></h2>
  <form method="post" action="<?= $e($url('/policies/admin/d/' . $doc['public_id'])) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'id' => 'ed-code', 'label' => $t('doccontrol.field.code'), 'required' => true, 'value' => (string) ($d['code'] ?? ''), 'error' => $err('details', 'code')]) ?>
      <?= $ui('field', ['name' => 'doc_type', 'id' => 'ed-type', 'type' => 'select', 'label' => $t('doccontrol.field.type'), 'value' => (string) ($d['doc_type'] ?? 'policy'),
          'options' => array_combine(DocControlService::TYPES, array_map(static fn (string $x): string => $t('doccontrol.type.' . $x), DocControlService::TYPES))]) ?>
      <?= $ui('field', ['name' => 'review_months', 'id' => 'ed-review', 'type' => 'number', 'label' => $t('doccontrol.field.review_months'), 'value' => (string) ($d['review_months'] ?? ''), 'error' => $err('details', 'review_months')]) ?>
    </div>
    <?= $ui('field', ['name' => 'title', 'id' => 'ed-title', 'label' => $t('doccontrol.field.title'), 'required' => true, 'value' => (string) ($d['title'] ?? ''), 'error' => $err('details', 'title')]) ?>
    <?php include __DIR__ . '/../../employees/Views/_audience.php'; ?>
    <p class="text-sm text-muted"><?= $e($t('doccontrol.audience_change_note')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('doccontrol.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?></div>
  </form>
</section>

<?php if ($doc['status'] === 'active'): ?>
<section class="card stack-sm" aria-labelledby="ar-h">
  <h2 id="ar-h" class="card__title"><?= $e($t('doccontrol.archive')) ?></h2>
  <form method="post" action="<?= $e($url('/policies/admin/d/' . $doc['public_id'] . '/action')) ?>" class="row row--tight" novalidate><?= $csrf ?><input type="hidden" name="action" value="archive">
    <?= $ui('field', ['name' => 'reason', 'id' => 'ar-reason', 'label' => $t('doccontrol.field.reason'), 'required' => true, 'error' => $err('archive', 'reason')]) ?>
    <?= $ui('button', ['label' => $t('doccontrol.archive'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'trash']) ?></form>
</section>
<?php endif; ?>
