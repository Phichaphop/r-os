<?php
/**
 * My OT + new request. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, mixed>|null $me @var list<array<string, mixed>> $requests @var array<string, string> $team @var array<string, string> $units
 * @var array<string, mixed>|null $rules @var string $today @var int $toVerify @var bool $canAdmin @var array<string, string> $errors @var array<string, mixed> $old
 */
$loc = (string) ($v['locale'] ?? 'th');
include __DIR__ . '/_helpers.php';
$for = (string) ($old['for'] ?? 'me');
$picked = (array) ($old['employees'] ?? []);
$actions = ($toVerify > 0 ? $ui('button', ['label' => $t('overtime.verify_n', ['n' => (string) $toVerify]), 'href' => $url('/overtime/verify'), 'variant' => 'primary', 'icon' => 'checks']) : '')
    . ($canAdmin ? $ui('button', ['label' => $t('overtime.nav_admin'), 'href' => $url('/overtime/admin'), 'variant' => 'secondary', 'icon' => 'settings']) : '');
?>
<?= $ui('page-header', ['title' => $t('overtime.title'), 'subtitle' => $t('overtime.subtitle'), 'actions' => $actions]) ?>
<?php if ($me === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('overtime.no_employee_title'), 'text' => $t('overtime.no_employee_text')]) ?>
<?php else: ?>
<section class="card stack" aria-labelledby="new-h">
  <h2 id="new-h" class="card__title"><?= $e($t('overtime.new')) ?></h2>
  <?php if ($rules === null): ?><p class="text-sm text-muted"><?= $ui('icon', ['name' => 'info-circle', 'size' => 'sm']) ?> <?= $e($t('overtime.no_rules')) ?></p><?php endif; ?>
  <form method="post" action="<?= $e($url('/overtime')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <?php if ($team !== []): ?>
    <fieldset class="stack-sm"><legend class="field__label"><?= $e($t('overtime.for')) ?></legend>
      <div class="row row--tight">
        <label class="choice"><input type="radio" name="for" value="me"<?= $for !== 'team' ? ' checked' : '' ?>> <?= $e($t('overtime.for_me')) ?></label>
        <label class="choice"><input type="radio" name="for" value="team"<?= $for === 'team' ? ' checked' : '' ?>> <?= $e($t('overtime.for_team')) ?></label>
      </div>
      <?php if (isset($errors['employees'])): ?><p class="field__error"><?= $e($errors['employees']) ?></p><?php endif; ?>
      <div class="check-list">
        <?php foreach ($team as $pid => $label): ?><label class="check"><input type="checkbox" name="employees[]" value="<?= $e($pid) ?>"<?= in_array($pid, $picked, true) ? ' checked' : '' ?>> <?= $e($label) ?></label><?php endforeach; ?>
      </div>
      <p class="text-sm text-muted"><?= $e($t('overtime.for_team_help')) ?></p>
    </fieldset>
    <?php endif; ?>
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'work_date', 'type' => 'date', 'label' => $t('overtime.field.work_date'), 'required' => true, 'value' => (string) ($old['work_date'] ?? $today), 'error' => $errors['work_date'] ?? null]) ?>
      <?= $ui('field', ['name' => 'start', 'label' => $t('overtime.field.start'), 'required' => true, 'value' => (string) ($old['start'] ?? ''), 'placeholder' => '17:30', 'inputmode' => 'numeric', 'error' => $errors['start'] ?? null]) ?>
      <?= $ui('field', ['name' => 'end', 'label' => $t('overtime.field.end'), 'required' => true, 'value' => (string) ($old['end'] ?? ''), 'placeholder' => '20:30', 'inputmode' => 'numeric', 'error' => $errors['end'] ?? null, 'help' => $t('overtime.end_help')]) ?>
    </div>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'reason', 'label' => $t('overtime.field.reason'), 'required' => true, 'value' => (string) ($old['reason'] ?? ''), 'error' => $errors['reason'] ?? null, 'placeholder' => $t('overtime.reason_example')]) ?>
      <?= $ui('field', ['name' => 'cost_center', 'type' => 'select', 'label' => $t('overtime.field.cost_center'), 'value' => (string) ($old['cost_center'] ?? ''), 'options' => ['' => $t('overtime.cost_center_own')] + $units, 'error' => $errors['cost_center'] ?? null]) ?>
    </div>
    <p class="text-sm text-muted"><?= $e($t('overtime.how')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('overtime.submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>

<section class="card stack-sm" aria-labelledby="mine-h">
  <h2 id="mine-h" class="card__title"><?= $e($t('overtime.mine')) ?></h2>
  <?php if ($requests === []): ?><p class="text-sm text-muted"><?= $e($t('overtime.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="mine-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('overtime.field.when')) ?></th><th scope="col"><?= $e($t('overtime.field.day_type')) ?></th><th scope="col"><?= $e($t('overtime.field.requested')) ?></th>
        <th scope="col"><?= $e($t('overtime.field.payable')) ?></th><th scope="col"><?= $e($t('overtime.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($requests as $q): ?>
        <tr>
          <td data-label="<?= $e($t('overtime.field.when')) ?>"><a href="<?= $e($url('/overtime/r/' . $q['public_id'])) ?>"><?= $e($otWhen($q)) ?></a></td>
          <td data-label="<?= $e($t('overtime.field.day_type')) ?>"><?= $e($t('overtime.day_type.' . $q['day_type'])) ?></td>
          <td data-label="<?= $e($t('overtime.field.requested')) ?>" class="num"><?= $e($hm((int) $q['minutes_requested'])) ?></td>
          <td data-label="<?= $e($t('overtime.field.payable')) ?>" class="num"><?= $e($hm($q['payable_minutes'] !== null ? (int) $q['payable_minutes'] : null)) ?></td>
          <td data-label="<?= $e($t('overtime.field.status')) ?>"><?= $otStatus((string) $q['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
