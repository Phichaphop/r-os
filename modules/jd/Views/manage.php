<?php
/**
 * One JD position: versions (draft editor, submit), acknowledgement report of the current version, exceptions.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $p @var list<array<string, mixed>> $versions @var array<string, mixed>|null $draft @var array<string, mixed> $jdContent
 * @var list<array<string, mixed>> $report @var array<string, string> $errors @var array<string, mixed> $old @var string $form @var string $today
 */
use Blue\Modules\Jd\JdService;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/jd/admin/p/' . $p['public_id'];
$o = static fn (string $k, string $d = ''): string => $form === 'draft' && is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
$err = static fn (string $f, string $k): ?string => $form === $f ? ($errors[$k] ?? null) : null;
$locked = false;
foreach ($versions as $x) {
    if (in_array($x['status'], ['review', 'approved'], true)) {
        $locked = true;
    }
}
$pending = array_values(array_filter($report, static fn (array $a): bool => $a['status'] === 'pending'));
$actions = '<form method="post" action="' . $e($url($base . '/action')) . '">' . $csrf . '<input type="hidden" name="action" value="remind">'
    . $ui('button', ['label' => $t('jd.remind'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'bell']) . '</form>';
?>
<?= $ui('page-header', ['title' => (string) $p['position_title'], 'subtitle' => $p['code'] . ' · ' . $t('jd.owner', ['name' => (string) ($p['owner_name'] ?? '—')]),
    'crumbs' => [[$t('jd.admin_title'), $url('/jd/admin')], [(string) $p['code'], null]], 'actions' => $pending !== [] ? $actions : '']) ?>
<section class="card stack-sm" aria-labelledby="jv-h">
  <h2 id="jv-h" class="card__title"><?= $e($t('jd.versions')) ?></h2>
  <?php if ($versions === []): ?><p class="text-sm text-muted"><?= $e($t('jd.no_versions')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($versions as $x): ?>
      <li><a href="<?= $e($url('/jd/v/' . $x['public_id'])) ?>"><?= $e($t('jd.version_n', ['n' => (string) $x['version_no']])) ?></a>
        <?= $ui('badge', ['tone' => $x['status'] === 'effective' ? 'success' : (in_array($x['status'], ['review', 'approved'], true) ? 'info' : 'neutral'), 'label' => $t('jd.status.' . $x['status'])]) ?>
        · <?= $e($t('jd.effective_on', ['d' => $date((string) $x['effective_from'], false)])) ?><?= $x['change_summary'] !== '' ? ' · ' . $e((string) $x['change_summary']) : '' ?>
        <span class="d-block text-sm text-muted"><?= $e((string) ($x['created_name'] ?? '—')) ?><?= $x['approved_name'] !== null ? ' · ' . $e($t('jd.approved_by', ['name' => (string) $x['approved_name']])) : '' ?></span>
        <?php if (in_array($x['status'], ['draft', 'approved'], true)): ?>
          <form method="post" action="<?= $e($url('/jd/admin/v/' . $x['public_id'])) ?>" class="row"><?= $csrf ?>
            <?php if ($x['status'] === 'draft'): ?><?= $ui('button', ['label' => $t('jd.submit'), 'type' => 'submit', 'variant' => 'primary', 'size' => 'sm', 'icon' => 'send', 'attrs' => ['name' => 'action', 'value' => 'submit']]) ?><?php endif; ?>
            <?= $ui('button', ['label' => $t('jd.withdraw'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm', 'attrs' => ['name' => 'action', 'value' => 'withdraw']]) ?>
          </form>
        <?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if (!$locked && $p['status'] === 'active'): ?>
<details class="card stack"<?= $form === 'draft' || $versions === [] ? ' open' : '' ?>>
  <summary class="card__title"><?= $e($draft !== null ? $t('jd.edit_draft', ['n' => (string) $draft['version_no']]) : $t('jd.new_version')) ?></summary>
  <form method="post" action="<?= $e($url($base . '/draft')) ?>" class="stack" novalidate><?= $csrf ?>
    <p class="text-sm text-muted"><?= $e($t('jd.lines_help')) ?></p>
    <?php foreach (['th', 'en'] as $l): ?>
    <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('jd.lang.' . $l)) ?></legend>
      <?php foreach (JdService::SECTIONS as $s): ?>
        <?= $ui('field', ['name' => $l . '_' . $s, 'type' => 'textarea', 'label' => $t('jd.section.' . $s), 'required' => $l === 'th' && in_array($s, ['summary', 'duties'], true),
            'value' => $o($l . '_' . $s, (string) ($jdContent[$l][$s] ?? '')), 'error' => $err('draft', $l . '_' . $s)]) ?>
      <?php endforeach; ?>
    </fieldset>
    <?php endforeach; ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'effective_from', 'type' => 'date', 'label' => $t('jd.field.effective_from'), 'required' => true, 'value' => $o('effective_from', (string) ($draft['effective_from'] ?? $today)), 'error' => $err('draft', 'effective_from')]) ?>
      <?= $ui('field', ['name' => 'change_summary', 'label' => $t('jd.field.change_summary'), 'required' => $p['current_version_id'] !== null, 'value' => $o('change_summary', (string) ($draft['change_summary'] ?? '')), 'error' => $err('draft', 'change_summary')]) ?>
    </div>
    <p class="text-sm text-muted"><?= $e($t('jd.route_help')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('jd.save_draft'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</details>
<?php elseif ($locked): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('jd.error.in_review')]) ?>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="jr-h">
  <h2 id="jr-h" class="card__title"><?= $e($t('jd.report')) ?></h2>
  <?php if ($p['current_version_id'] === null): ?><p class="text-sm text-muted"><?= $e($t('jd.no_effective')) ?></p>
  <?php elseif ($report === []): ?><p class="text-sm text-muted"><?= $e($t('jd.no_one_assigned')) ?></p><?php else: ?>
  <?php if ($form === 'exception' && isset($errors['reason'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['reason']]) ?><?php endif; ?>
  <div class="table-wrap" role="region" aria-labelledby="jr-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('jd.col.employee')) ?></th><th scope="col"><?= $e($t('jd.field.department')) ?></th><th scope="col"><?= $e($t('jd.col.status')) ?></th><th scope="col"><?= $e($t('jd.col.when')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($report as $a): ?>
        <tr><th scope="row" data-label="<?= $e($t('jd.col.employee')) ?>"><?= $e($a['employee_code'] . ' ' . $a['name_th']) ?></th>
          <td data-label="<?= $e($t('jd.field.department')) ?>"><?= $e((string) ($a['dept_code'] ?? '—')) ?></td>
          <td data-label="<?= $e($t('jd.col.status')) ?>"><?= $ui('badge', ['tone' => $a['status'] === 'acknowledged' ? 'success' : ($a['status'] === 'pending' ? 'warning' : 'neutral'), 'label' => $t('jd.ack_status.' . $a['status'])]) ?>
            <?= $a['status'] === 'exception' ? '<span class="d-block text-sm text-muted">' . $e((string) $a['exception_reason'] . ' · ' . ($a['exception_name'] ?? '')) . '</span>' : '' ?>
            <?php if ($a['status'] === 'pending'): ?>
              <details class="d-block"><summary class="text-sm"><?= $e($t('jd.make_exception')) ?></summary>
                <form method="post" action="<?= $e($url($base . '/exception')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="ack" value="<?= (int) $a['id'] ?>">
                  <?= $ui('field', ['name' => 'reason', 'id' => 'jx-' . (int) $a['id'], 'label' => $t('jd.exception_reason'), 'required' => true]) ?>
                  <?= $ui('button', ['label' => $t('jd.save'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></form></details>
            <?php endif; ?></td>
          <td data-label="<?= $e($t('jd.col.when')) ?>"><?= $e($a['acknowledged_at'] !== null ? $date((string) $a['acknowledged_at']) : ($a['opened_at'] !== null ? $t('jd.opened_at', ['at' => $date((string) $a['opened_at'])]) : '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<details class="card stack-sm">
  <summary class="card__title"><?= $e($t('jd.archive')) ?></summary>
  <p class="text-sm text-muted"><?= $e($t('jd.archive_help')) ?></p>
  <form method="post" action="<?= $e($url($base . '/action')) ?>"><?= $csrf ?><input type="hidden" name="action" value="archive"><?= $ui('button', ['label' => $t('jd.archive'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'trash']) ?></form>
</details>
