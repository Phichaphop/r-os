<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $due @var list<array<string, mixed>> $batches @var bool $canManage @var string $tab
 */
$locale = (string) ($v['locale'] ?? 'th');
?>
<?= $ui('page-header', ['title' => $t('records.disposition.title'), 'subtitle' => $t('records.disposition.subtitle')]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<section class="card" aria-labelledby="due-h">
  <h2 id="due-h" class="card__title"><?= $e($t('records.disposition.due')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('records.disposition.help')) ?></p>
  <form method="post" action="<?= $e($url('/admin/records/disposition')) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <div class="table-wrap" role="region" aria-label="<?= $e($t('records.disposition.due')) ?>" tabindex="0">
      <table class="table table--stack">
        <caption class="sr-only"><?= $e($t('records.disposition.due')) ?></caption>
        <thead><tr><th scope="col"><?php if ($canManage && $due !== []): ?><label class="choice"><input type="checkbox" data-select-all="[name='documents[]']"><span class="sr-only"><?= $e($t('common.select_all')) ?></span></label><?php endif; ?></th>
          <th scope="col"><?= $e($t('document.field.title')) ?></th><th scope="col"><?= $e($t('records.field.record_class')) ?></th>
          <th scope="col"><?= $e($t('records.field.due_date')) ?></th><th scope="col"><?= $e($t('records.field.rule')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($due as $d): $held = (int) $d['holds'] > 0; ?>
          <tr>
            <td data-label="<?= $e($t('common.select')) ?>"><?php if ($canManage): ?><input type="checkbox" name="documents[]" value="<?= $e($d['public_id']) ?>" aria-label="<?= $e($d['title']) ?>"<?= $held ? ' disabled' : '' ?>><?php endif; ?></td>
            <td data-label="<?= $e($t('document.field.title')) ?>"><a href="<?= $e($url('/documents/' . $d['public_id'])) ?>"><?= $e($d['title']) ?></a>
              <?php if ($held): ?> <?= $ui('badge', ['tone' => 'warning', 'icon' => 'lock', 'label' => $t('records.on_hold')]) ?><?php endif; ?></td>
            <td data-label="<?= $e($t('records.field.record_class')) ?>"><?= $e($t('records.class.' . $d['record_class'])) ?></td>
            <td data-label="<?= $e($t('records.field.due_date')) ?>"><?= $e(\Blue\Core\I18n\DateFormat::day((string) $d['scheduled_disposition_date'], $locale)) ?></td>
            <td data-label="<?= $e($t('records.field.rule')) ?>">v<?= (int) $d['rule_version'] ?> · <?= $e($t('records.months_n', ['n' => (int) $d['duration_months']])) ?> · <?= $e($t('records.action.' . $d['disposition_action'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($due === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'checks', 'title' => $t('records.disposition.none_due'), 'text' => $t('records.disposition.none_due_text')]) ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($canManage && $due !== []): ?>
    <div class="form-grid">
      <?= $ui('field', ['name' => 'reason', 'label' => $t('iam.field.reason'), 'required' => true, 'full' => true]) ?>
      <div class="row field--full"><?= $ui('button', ['label' => $t('records.disposition.request'), 'type' => 'submit', 'variant' => 'danger', 'icon' => 'trash']) ?></div>
    </div>
    <?php endif; ?>
  </form>
</section>
<section class="card" aria-labelledby="bat-h">
  <h2 id="bat-h" class="card__title"><?= $e($t('records.disposition.batches')) ?></h2>
  <ul class="stack-sm">
  <?php foreach ($batches as $b): ?>
    <li><a href="<?= $e($url('/admin/records/disposition/' . $b['public_id'])) ?>"><?= $e($date($b['created_at'])) ?> · <?= $e($t('records.batch_status.' . $b['status'])) ?> · <?= (int) $b['item_count'] ?> <?= $e($t('records.items')) ?></a> — <?= $e($b['requested_name'] ?? '') ?></li>
  <?php endforeach; ?>
  <?php if ($batches === []): ?><li class="text-muted"><?= $e($t('records.disposition.no_batches')) ?></li><?php endif; ?>
  </ul>
</section>
