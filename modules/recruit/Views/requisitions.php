<?php
/**
 * Requisitions: ask for people (locked to your own unit unless you recruit for the company) + the list.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var bool $canRequest @var array<string, mixed> $ctx @var array<string, string> $depts @var string $myUnit
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Modules\Employees\EmployeeService;

$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
?>
<?= $ui('page-header', ['title' => $t('recruit.req_title'), 'subtitle' => $t('recruit.req_subtitle')]) ?>
<?php if ($canRequest): ?>
<section class="card stack" aria-labelledby="rq-n">
  <h2 id="rq-n" class="card__title"><?= $e($t('recruit.req_new')) ?></h2>
  <?php if (isset($errors['department']) && !$ctx['free']): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['department']]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/recruit/requisitions')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <div class="grid grid--3">
      <?php if ($ctx['free']): ?>
        <?= $ui('field', ['name' => 'department', 'type' => 'select', 'label' => $t('recruit.field.department'), 'value' => $o('department'), 'error' => $errors['department'] ?? null,
            'options' => ['' => $t('recruit.my_unit', ['unit' => $myUnit !== '' ? $myUnit : '—'])] + $depts]) ?>
      <?php else: ?>
        <div class="field"><span class="field__label"><?= $e($t('recruit.field.department')) ?></span><p class="text-sm"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($myUnit !== '' ? $myUnit : '—') ?></p></div>
      <?php endif; ?>
      <?= $ui('field', ['name' => 'position_title', 'label' => $t('recruit.field.position_title'), 'required' => true, 'value' => $o('position_title'), 'error' => $errors['position_title'] ?? null]) ?>
      <?= $ui('field', ['name' => 'headcount', 'type' => 'number', 'label' => $t('recruit.field.headcount'), 'required' => true, 'value' => $o('headcount', '1'), 'min' => '1', 'max' => '50', 'error' => $errors['headcount'] ?? null]) ?>
      <?= $ui('field', ['name' => 'employment_type', 'type' => 'select', 'label' => $t('recruit.field.employment_type'), 'value' => $o('employment_type', 'permanent'),
          'options' => array_combine(EmployeeService::TYPES, array_map(static fn (string $x): string => $t('employees.type.' . $x), EmployeeService::TYPES))]) ?>
      <?= $ui('field', ['name' => 'reason', 'type' => 'select', 'label' => $t('recruit.field.reason'), 'value' => $o('reason', 'new'), 'options' => ['new' => $t('recruit.reason.new'), 'replacement' => $t('recruit.reason.replacement')]]) ?>
      <?= $ui('field', ['name' => 'needed_by', 'type' => 'date', 'label' => $t('recruit.field.needed_by'), 'value' => $o('needed_by'), 'error' => $errors['needed_by'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'replaces', 'label' => $t('recruit.field.replaces'), 'value' => $o('replaces'), 'help' => $t('recruit.replaces_help'), 'error' => $errors['replaces'] ?? null]) ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'salary_min', 'type' => 'money', 'label' => $t('recruit.field.salary_min'), 'value' => $o('salary_min')]) ?>
      <?= $ui('field', ['name' => 'salary_max', 'type' => 'money', 'label' => $t('recruit.field.salary_max'), 'value' => $o('salary_max'), 'error' => $errors['salary_max'] ?? null, 'help' => $t('recruit.salary_internal')]) ?>
    </div>
    <?= $ui('field', ['name' => 'justification', 'type' => 'textarea', 'label' => $t('recruit.field.justification'), 'required' => true, 'value' => $o('justification'), 'error' => $errors['justification'] ?? null]) ?>
    <p class="text-sm text-muted"><?= $e($t('recruit.req_route')) ?></p>
    <div class="row"><?= $ui('button', ['label' => $t('recruit.req_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send']) ?></div>
  </form>
</section>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="rq-l">
  <h2 id="rq-l" class="card__title"><?= $e($t('recruit.req_list')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('recruit.req_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="rq-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('recruit.field.position_title')) ?></th><th scope="col"><?= $e($t('recruit.field.headcount')) ?></th><th scope="col"><?= $e($t('recruit.col.status')) ?></th><th scope="col"><?= $e($t('recruit.col.hired')) ?></th><th scope="col"><?= $e($t('recruit.col.created')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): $rs = (string) $x['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('recruit.field.position_title')) ?>"><a href="<?= $e($url('/recruit/r/' . $x['public_id'])) ?>"><?= $e((string) $x['position_title']) ?></a></th>
          <td data-label="<?= $e($t('recruit.field.headcount')) ?>"><?= (int) $x['headcount'] ?></td>
          <td data-label="<?= $e($t('recruit.col.status')) ?>"><?php include __DIR__ . '/_req_status.php'; ?><?= (int) $x['published'] > 0 ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('recruit.is_published')]) : '' ?></td>
          <td data-label="<?= $e($t('recruit.col.hired')) ?>"><?= (int) $x['hired'] ?> / <?= (int) $x['headcount'] ?></td>
          <td data-label="<?= $e($t('recruit.col.created')) ?>"><?= $e($date((string) $x['created_at'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
