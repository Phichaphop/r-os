<?php
/**
 * Employee page (Entity Detail Pattern — M09 A4): header with status + completeness, tabs, one tab rendered.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed> $emp @var array<string, mixed> $acc @var list<string> $tabs @var string $tab
 * @var array{configured: bool, percent: ?int, required: list<array{type: string, state: string}>}|null $completeness
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$day = static fn (?string $d): string => $d === null || $d === '' ? '—' : DateFormat::day($d, $loc);
include __DIR__ . '/_helpers.php';
$base = '/employees/' . $emp['public_id'];
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$sub = $emp['employee_code'] . ' · ' . $t('employees.status.' . $emp['status']);
?>
<?= $ui('page-header', ['title' => (string) $title, 'subtitle' => $sub,
    'actions' => $ui('button', ['label' => $t('employees.title'), 'href' => $url('/employees'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>

<div class="emp-head">
  <?= $statusBadge((string) $emp['status']) ?>
  <?php if ($completeness !== null): ?>
    <?php if ($completeness['configured']): ?>
      <span class="emp-meter">
        <progress class="emp-meter__bar" max="100" value="<?= (int) $completeness['percent'] ?>" aria-labelledby="emp-meter-l"></progress>
        <span class="text-sm" id="emp-meter-l"><?= $e($t('employees.completeness_pct', ['n' => (string) (int) $completeness['percent']])) ?></span>
      </span>
    <?php else: ?>
      <?= $ui('badge', ['tone' => 'neutral', 'icon' => 'info-circle', 'label' => $t('employees.completeness_no_rules')]) ?>
    <?php endif; ?>
  <?php endif; ?>
  <?php foreach ($openRequests as $q): ?>
    <?= $ui('badge', ['tone' => $q['status'] === 'failed' ? 'danger' : 'warning', 'icon' => $q['status'] === 'failed' ? 'alert-triangle' : 'hourglass',
        'label' => $t('employees.change_kind.' . $q['kind']) . ' · ' . $t('employees.req_status.' . $q['status'])]) ?>
  <?php endforeach; ?>
</div>

<nav class="tabs__list subnav" aria-label="<?= $e($t('employees.tabs_label')) ?>">
  <?php foreach ($tabs as $k): ?>
    <a class="tabs__tab" href="<?= $e($url($base . '?tab=' . $k)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $e($t('employees.tab.' . $k)) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>

<?php include __DIR__ . '/_tab_' . $tab . '.php'; ?>
