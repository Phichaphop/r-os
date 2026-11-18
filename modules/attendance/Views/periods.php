<?php
/**
 * Attendance periods of a company (DEC-66): list with status, and a form to open the next period.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<int, string> $companies @var int $le @var list<array<string, mixed>> $periods @var bool $canManage
 * @var array<string, string> $errors @var array<string, string> $old @var string $today
 */
use Blue\Core\I18n\DateFormat;

include __DIR__ . '/_helpers.php';
$loc = (string) ($v['locale'] ?? 'th');
$day = static fn (string $d): string => DateFormat::day($d, $loc);
?>
<?= $ui('page-header', ['title' => $t('attendance.periods_title'), 'subtitle' => $t('attendance.periods_subtitle'),
    'actions' => $ui('button', ['label' => $t('attendance.title'), 'href' => $url('/attendance'), 'variant' => 'ghost', 'icon' => 'arrow-back-up'])]) ?>
<?php if (count($companies) > 1): ?>
<form method="get" action="<?= $e($url('/attendance/periods')) ?>" class="filter-bar">
  <?= $ui('field', ['name' => 'le', 'type' => 'select', 'label' => $t('employees.field.company'), 'value' => (string) $le, 'options' => $companies]) ?>
  <?= $ui('button', ['label' => $t('attendance.switch_company'), 'type' => 'submit', 'icon' => 'building']) ?>
</form>
<?php endif; ?>

<?= $ui('alert', ['tone' => 'info', 'title' => $t('attendance.periods_how_title'), 'text' => $t('attendance.periods_how')]) ?>

<section class="card card--flush" aria-labelledby="pl-h">
  <div class="card__head"><h2 id="pl-h" class="card__title"><?= $e($t('attendance.periods_list')) ?> · <?= $e($companies[$le] ?? '') ?></h2></div>
  <div class="table-wrap" role="region" aria-labelledby="pl-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr>
        <th scope="col"><?= $e($t('attendance.period_field.range')) ?></th><th scope="col"><?= $e($t('attendance.period_field.status')) ?></th>
        <th scope="col" class="num"><?= $e($t('attendance.period_field.revision')) ?></th><th scope="col" class="num"><?= $e($t('attendance.period_field.employees')) ?></th>
        <th scope="col"><?= $e($t('attendance.period_field.finalized')) ?></th><th scope="col" class="num"><?= $e($t('attendance.period_field.exports')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($periods as $p): ?>
        <tr>
          <td data-label="<?= $e($t('attendance.period_field.range')) ?>"><a href="<?= $e($url('/attendance/periods/' . $p['public_id'])) ?>"><?= $e($day((string) $p['period_start']) . ' – ' . $day((string) $p['period_end'])) ?></a>
            <?php if (!empty($p['label'])): ?><div class="text-sm text-muted"><?= $e($p['label']) ?></div><?php endif; ?></td>
          <td data-label="<?= $e($t('attendance.period_field.status')) ?>"><?= $periodStatus((string) $p['status'], $p['first_exported_at'] !== null ? (string) $p['first_exported_at'] : null) ?></td>
          <td data-label="<?= $e($t('attendance.period_field.revision')) ?>" class="num"><?= (int) $p['revision'] ?></td>
          <td data-label="<?= $e($t('attendance.period_field.employees')) ?>" class="num"><?= $p['employees'] !== null ? (int) $p['employees'] : '—' ?></td>
          <td data-label="<?= $e($t('attendance.period_field.finalized')) ?>"><?= $e($p['finalized_at'] !== null ? $date((string) $p['finalized_at']) : '—') ?></td>
          <td data-label="<?= $e($t('attendance.period_field.exports')) ?>" class="num"><?= (int) $p['exports'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($periods === []): ?><tr><td colspan="6"><?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('attendance.periods_none'), 'text' => $t($canManage ? 'attendance.periods_none_manage' : 'attendance.periods_none_view')]) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($canManage): ?>
<section class="card stack" aria-labelledby="pn-h">
  <h2 id="pn-h" class="card__title"><?= $e($t('attendance.period_new')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('attendance.period_new_help')) ?></p>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/attendance/periods')) ?>" class="form-grid" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="le" value="<?= (int) $le ?>">
    <?= $ui('field', ['name' => 'period_start', 'type' => 'date', 'label' => $t('attendance.period_field.start'), 'required' => true, 'value' => (string) ($old['period_start'] ?? ''), 'error' => $errors['period_start'] ?? null]) ?>
    <?= $ui('field', ['name' => 'period_end', 'type' => 'date', 'label' => $t('attendance.period_field.end'), 'required' => true, 'value' => (string) ($old['period_end'] ?? ''), 'error' => $errors['period_end'] ?? null,
        'help' => $t('attendance.period_end_help', ['n' => '62'])]) ?>
    <?= $ui('field', ['name' => 'label', 'label' => $t('attendance.period_field.label'), 'value' => (string) ($old['label'] ?? ''), 'placeholder' => $t('attendance.period_label_example'), 'full' => true]) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('attendance.period_create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
