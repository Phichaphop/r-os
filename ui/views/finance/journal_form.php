<?php
/**
 * Manual journal (finance maker). Rows: account, debit OR credit, description, site. Balanced before it can be saved.
 *
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var ?string $pid @var array<string, mixed> $old @var list<array<string, mixed>> $accounts @var array<int, string> $sites @var array<string, string> $errors @var int $le
 */
$rows = array_values((array) $old['lines']);
$count = max(4, count($rows) + 2);
$action = $pid === null ? '/finance/journals/new?le=' . $le : '/finance/journals/' . $pid . '/edit';
?>
<?= $ui('page-header', ['title' => $t($pid === null ? 'finance.journal.new' : 'finance.journal.edit'), 'subtitle' => $t('finance.journal.form_help'),
    'crumbs' => [[$t('finance.title'), $url('/finance?le=' . $le)], [$t('finance.journals.title'), $url('/finance/journals?le=' . $le)], [$t($pid === null ? 'finance.journal.new' : 'finance.journal.edit'), null]]]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'text' => $errors['lines'] ?? '', 'live' => true]) ?><?php endif; ?>
<form method="post" action="<?= $e($url($action)) ?>" novalidate data-journal-form>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <section class="card form-grid">
    <?= $ui('field', ['name' => 'date', 'label' => $t('finance.field.date'), 'type' => 'date', 'required' => true, 'value' => (string) $old['date'], 'error' => $errors['date'] ?? null]) ?>
    <?= $ui('field', ['name' => 'description', 'label' => $t('finance.field.description'), 'required' => true, 'value' => (string) $old['description'], 'error' => $errors['description'] ?? null]) ?>
  </section>
  <section class="card" aria-labelledby="lines-h">
    <h2 id="lines-h" class="card__title"><?= $e($t('finance.journal.lines')) ?></h2>
    <datalist id="acc-list"><?php foreach ($accounts as $a): ?><option value="<?= $e($a['code']) ?>"><?= $e($a['name']) ?></option><?php endforeach; ?></datalist>
    <div class="table-wrap" role="region" aria-label="<?= $e($t('finance.journal.lines')) ?>" tabindex="0">
      <table class="table jlines">
        <caption class="sr-only"><?= $e($t('finance.journal.lines')) ?></caption>
        <thead><tr><th scope="col">#</th><th scope="col"><?= $e($t('finance.field.account')) ?></th><th scope="col"><?= $e($t('finance.field.debit')) ?></th>
          <th scope="col"><?= $e($t('finance.field.credit')) ?></th><th scope="col"><?= $e($t('finance.field.description')) ?></th><th scope="col"><?= $e($t('finance.field.site')) ?></th></tr></thead>
        <tbody>
        <?php for ($i = 0; $i < $count; $i++): $l = $rows[$i] ?? ['account' => '', 'debit' => '', 'credit' => '', 'description' => '', 'site' => '']; $err = $errors['line_' . $i] ?? null; ?>
          <tr<?= $err !== null ? ' class="row-error"' : '' ?>>
            <td class="num" data-label="#"><?= $i + 1 ?></td>
            <td data-label="<?= $e($t('finance.field.account')) ?>"><input class="input" name="account[]" list="acc-list" value="<?= $e((string) $l['account']) ?>" aria-label="<?= $e($t('finance.field.account') . ' ' . ($i + 1)) ?>" autocomplete="off"<?= $err !== null ? ' aria-invalid="true" aria-describedby="le-' . $i . '"' : '' ?>>
              <?php if ($err !== null): ?><div class="field__error" id="le-<?= $i ?>"><?= $ui('icon', ['name' => 'alert-circle', 'size' => 'sm']) ?><?= $e($err) ?></div><?php endif; ?></td>
            <td data-label="<?= $e($t('finance.field.debit')) ?>"><input class="input input--money" name="debit[]" inputmode="decimal" value="<?= $e((string) $l['debit']) ?>" aria-label="<?= $e($t('finance.field.debit') . ' ' . ($i + 1)) ?>"></td>
            <td data-label="<?= $e($t('finance.field.credit')) ?>"><input class="input input--money" name="credit[]" inputmode="decimal" value="<?= $e((string) $l['credit']) ?>" aria-label="<?= $e($t('finance.field.credit') . ' ' . ($i + 1)) ?>"></td>
            <td data-label="<?= $e($t('finance.field.description')) ?>"><input class="input" name="line_description[]" value="<?= $e((string) $l['description']) ?>" aria-label="<?= $e($t('finance.field.description') . ' ' . ($i + 1)) ?>"></td>
            <td data-label="<?= $e($t('finance.field.site')) ?>"><select class="select" name="site[]" aria-label="<?= $e($t('finance.field.site') . ' ' . ($i + 1)) ?>"><option value="">—</option>
              <?php foreach ($sites as $sid => $code): ?><option value="<?= (int) $sid ?>"<?= (string) $sid === (string) $l['site'] ? ' selected' : '' ?>><?= $e($code) ?></option><?php endforeach; ?></select></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
    <p class="jtotals" data-journal-totals aria-live="polite"><?= $e($t('finance.field.debit')) ?> <strong data-dr>0.00</strong> · <?= $e($t('finance.field.credit')) ?> <strong data-cr>0.00</strong> · <?= $e($t('finance.journal.difference')) ?> <strong data-diff>0.00</strong></p>
    <p class="text-sm text-muted"><?= $e($t('finance.journal.lines_help')) ?></p>
  </section>
  <div class="row">
    <?= $ui('button', ['label' => $t('finance.journal.save_draft'), 'type' => 'submit', 'icon' => 'checks']) ?>
    <?php if ($pid === null): ?><?= $ui('button', ['label' => $t('finance.journal.save_submit'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'send', 'attrs' => ['name' => 'submit_now', 'value' => '1']]) ?><?php endif; ?>
    <?= $ui('button', ['label' => $t('common.cancel'), 'href' => $url($pid === null ? '/finance/journals?le=' . $le : '/finance/journals/' . $pid), 'variant' => 'ghost']) ?>
  </div>
</form>
