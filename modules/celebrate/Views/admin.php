<?php
/**
 * HR: turn birthdays/anniversaries on (with the company's notice), who is told, board scope, milestones + upcoming list.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var array<string, mixed> $s @var array<string, mixed>|null $raw
 * @var list<array<string, mixed>> $rows @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Modules\Employees\EmployeeService;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d): string => $old !== [] ? (is_scalar($old[$k] ?? null) ? (string) $old[$k] : '') : $d;
$chk = static fn (string $k, bool $d): string => ($old !== [] ? (($old[$k] ?? '') === '1') : $d) ? ' checked' : '';
?>
<?= $ui('page-header', ['title' => $t('celebrate.admin_title'), 'subtitle' => $t('celebrate.admin_subtitle'), 'crumbs' => [[$t('celebrate.title'), $url('/celebrate')], [$t('celebrate.admin_title'), null]]]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('celebrate.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/celebrate/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack" aria-labelledby="cs-h">
  <h2 id="cs-h" class="card__title"><?= $e($t('celebrate.settings')) ?></h2>
  <?php if (!$s['birthdays_enabled'] && !$s['anniversaries_enabled']): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('celebrate.off_admin')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/celebrate/admin')) ?>" class="stack" novalidate><?= $csrf ?>
    <input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('celebrate.show')) ?></legend>
      <label class="check"><input type="checkbox" name="birthdays_enabled" value="1"<?= $chk('birthdays_enabled', (int) ($raw['birthdays_enabled'] ?? 0) === 1) ?>> <?= $e($t('celebrate.enable.birthday')) ?></label>
      <label class="check"><input type="checkbox" name="anniversaries_enabled" value="1"<?= $chk('anniversaries_enabled', (int) ($raw['anniversaries_enabled'] ?? 0) === 1) ?>> <?= $e($t('celebrate.enable.anniversary')) ?></label>
    </fieldset>
    <?= $ui('field', ['name' => 'notice_text', 'type' => 'textarea', 'label' => $t('celebrate.notice'), 'value' => $o('notice_text', (string) $s['notice_text']), 'help' => $t('celebrate.notice_help'), 'error' => $errors['notice_text'] ?? null]) ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('celebrate.who_told')) ?></legend>
      <label class="check"><input type="checkbox" name="notify_manager" value="1"<?= $chk('notify_manager', (bool) $s['notify_manager']) ?>> <?= $e($t('celebrate.notify_manager')) ?></label>
      <label class="check"><input type="checkbox" name="notify_hr" value="1"<?= $chk('notify_hr', (bool) $s['notify_hr']) ?>> <?= $e($t('celebrate.notify_hr')) ?></label>
    </fieldset>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'board_scope', 'type' => 'select', 'label' => $t('celebrate.board_scope'), 'value' => $o('board_scope', (string) $s['board_scope']), 'options' => ['company' => $t('celebrate.scope.company'), 'department' => $t('celebrate.scope.department')]]) ?>
      <?= $ui('field', ['name' => 'milestones', 'label' => $t('celebrate.milestones'), 'value' => $o('milestones', implode(', ', $s['milestones'])), 'help' => $t('celebrate.milestones_help'), 'error' => $errors['milestones'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('celebrate.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="cu-h">
  <h2 id="cu-h" class="card__title"><?= $e($t('celebrate.upcoming')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('celebrate.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="cu-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('celebrate.col.date')) ?></th><th scope="col"><?= $e($t('celebrate.col.employee')) ?></th><th scope="col"><?= $e($t('celebrate.col.kind')) ?></th><th scope="col"><?= $e($t('celebrate.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): $d = EmployeeService::ymd((string) $x['event_date']); ?>
        <tr><th scope="row" data-label="<?= $e($t('celebrate.col.date')) ?>"><?= $e($d->format('j') . ' ' . $t('celebrate.month.' . $d->format('n'))) ?></th>
          <td data-label="<?= $e($t('celebrate.col.employee')) ?>"><?= $e($x['employee_code'] . ' ' . $x['name_th']) ?> <span class="text-sm text-muted"><?= $e((string) ($x['dept_code'] ?? '')) ?></span></td>
          <td data-label="<?= $e($t('celebrate.col.kind')) ?>"><?= $e($x['kind'] === 'birthday' ? $t('celebrate.kind.birthday') : $t('celebrate.years', ['n' => (string) $x['years']])) ?><?= $x['milestone'] ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('celebrate.milestone')]) : '' ?></td>
          <td data-label="<?= $e($t('celebrate.col.status')) ?>"><?= $e($t('celebrate.status.' . $x['status'])) ?><?= $x['cancel_reason'] !== null ? ' · ' . $e((string) $x['cancel_reason']) : '' ?>
            <?php if ($x['status'] === 'scheduled'): ?>
              <details class="d-block"><summary class="text-sm"><?= $e($t('celebrate.cancel')) ?></summary>
                <form method="post" action="<?= $e($url('/celebrate/admin/e/' . $x['public_id'] . '/cancel')) ?>" class="stack-sm" novalidate><?= $csrf ?>
                  <?= $ui('field', ['name' => 'reason', 'id' => 'cr-' . $x['public_id'], 'label' => $t('celebrate.reason'), 'required' => true]) ?>
                  <?= $ui('button', ['label' => $t('celebrate.cancel'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm']) ?></form></details>
            <?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
