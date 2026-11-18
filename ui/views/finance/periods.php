<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $years @var bool $canManage @var bool $canClose @var array<string, string> $errors @var array<string, mixed> $old @var int $le
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('finance.periods.title'), 'subtitle' => $t('finance.periods.subtitle')]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<?php foreach ($years as $y): ?>
<section class="card" aria-labelledby="fy-<?= (int) $y['id'] ?>">
  <h2 id="fy-<?= (int) $y['id'] ?>" class="card__title"><?= $e($y['code']) ?> <span class="text-sm text-muted"><?= $e((string) $y['start_date'] . ' – ' . (string) $y['end_date']) ?></span>
    <?php if ((int) $y['is_demo'] === 1): ?> <?= $ui('badge', ['tone' => 'neutral', 'label' => 'DEMO']) ?><?php endif; ?></h2>
  <div class="table-wrap" role="region" aria-label="<?= $e($y['code']) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($y['code']) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('finance.field.period')) ?></th><th scope="col"><?= $e($t('finance.field.dates')) ?></th><th scope="col" class="num"><?= $e($t('finance.stat.posted')) ?></th>
        <th scope="col" class="num"><?= $e($t('finance.field.open_journals')) ?></th><th scope="col"><?= $e($t('finance.field.status')) ?></th><?php if ($canClose): ?><th scope="col"><span class="sr-only"><?= $e($t('common.actions')) ?></span></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($y['periods'] as $p): ?>
        <tr>
          <td data-label="<?= $e($t('finance.field.period')) ?>" class="mono"><?= $e($p['period_code']) ?></td>
          <td data-label="<?= $e($t('finance.field.dates')) ?>"><?= $e((string) $p['start_date'] . ' – ' . (string) $p['end_date']) ?></td>
          <td data-label="<?= $e($t('finance.stat.posted')) ?>" class="num"><?= (int) $p['posted_count'] ?></td>
          <td data-label="<?= $e($t('finance.field.open_journals')) ?>" class="num"><?= (int) $p['open_count'] ?></td>
          <td data-label="<?= $e($t('finance.field.status')) ?>">
            <?php if ($p['closed']): ?><?= $ui('badge', ['status' => 'locked', 'label' => $t($p['lock_status'] === 'reopen_requested' ? 'finance.period.reopen_requested' : 'finance.period.closed')]) ?>
            <?php else: ?><?= $ui('badge', ['tone' => 'success', 'icon' => 'lock-open', 'label' => $t('finance.period.open')]) ?><?php endif; ?></td>
          <?php if ($canClose): ?>
          <td data-label="<?= $e($t('common.actions')) ?>">
            <?php if (!$p['closed'] || $p['lock_status'] === 'locked'): ?>
            <details><summary><?= $e($t($p['closed'] ? 'finance.period.reopen' : 'finance.period.close')) ?></summary>
              <form method="post" action="<?= $e($url('/finance/periods/close?le=' . $le)) ?>" class="inline-form"><?= $csrf ?>
                <input type="hidden" name="period" value="<?= $e($p['period_code']) ?>"><input type="hidden" name="action" value="<?= $p['closed'] ? 'reopen' : 'close' ?>">
                <input type="hidden" name="_back" value="/finance/periods?le=<?= (int) $le ?>">
                <label class="sr-only" for="pr-<?= (int) $p['id'] ?>"><?= $e($t('iam.field.reason')) ?></label>
                <input class="input" id="pr-<?= (int) $p['id'] ?>" name="reason" required placeholder="<?= $e($t('iam.field.reason')) ?>">
                <?= $ui('button', ['label' => $t($p['closed'] ? 'finance.period.reopen' : 'finance.period.close'), 'type' => 'submit', 'size' => 'sm', 'variant' => $p['closed'] ? 'secondary' : 'danger', 'icon' => $p['closed'] ? 'lock-open' : 'lock']) ?>
              </form></details>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endforeach; ?>
<?php if ($years === []): ?><?= $ui('states', ['icon' => 'calendar-event', 'title' => $t('finance.periods.empty'), 'text' => $t('finance.periods.empty_text')]) ?><?php endif; ?>
<?php if ($canManage): ?>
<section class="card" aria-labelledby="fy-new">
  <h2 id="fy-new" class="card__title"><?= $e($t('finance.year.new')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('finance.year.help')) ?></p>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/finance/periods?le=' . $le)) ?>" class="form-grid" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'code', 'label' => $t('finance.field.year_code'), 'required' => true, 'value' => (string) ($old['code'] ?? ''), 'error' => $errors['code'] ?? null, 'placeholder' => 'FY2027']) ?>
    <?= $ui('field', ['name' => 'start_date', 'label' => $t('finance.field.year_start'), 'type' => 'date', 'required' => true, 'value' => (string) ($old['start_date'] ?? ''), 'error' => $errors['start_date'] ?? null, 'help' => $t('finance.year.start_help')]) ?>
    <?= $ui('field', ['name' => 'months', 'label' => $t('finance.field.months'), 'type' => 'number', 'required' => true, 'value' => (string) ($old['months'] ?? '12'), 'error' => $errors['months'] ?? null, 'min' => '1', 'max' => '24']) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('finance.year.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
