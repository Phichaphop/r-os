<?php
/**
 * Verification campaigns of a company and a form to set one up (name, cutoff, scope, second-count threshold).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var list<array<string, mixed>> $sites @var list<array<string, mixed>> $types
 * @var string $today @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canView @var bool $canManage @var bool $canCount
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
?>
<?= $ui('page-header', ['title' => $t('assets.campaigns_title'), 'subtitle' => $t('assets.campaigns_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<section class="card card--flush" aria-labelledby="ac-h">
  <div class="card__head"><h2 id="ac-h" class="card__title"><?= $e($t('assets.campaigns')) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'clipboard-check', 'title' => $t('assets.no_campaigns'), 'text' => $t('assets.no_campaigns_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ac-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('assets.field.campaign_name')) ?></th><th scope="col"><?= $e($t('assets.field.cutoff_date')) ?></th><th scope="col"><?= $e($t('assets.col.scope')) ?></th><th scope="col"><?= $e($t('assets.col.in_scope')) ?></th><th scope="col"><?= $e($t('assets.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $cst = (string) $row['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('assets.field.campaign_name')) ?>"><a href="<?= $e($url('/assets/campaigns/' . $row['public_id'])) ?>"><?= $e((string) $row['name']) ?></a></th>
          <td data-label="<?= $e($t('assets.field.cutoff_date')) ?>"><?= $e($date((string) $row['cutoff_date'], false)) ?></td>
          <td data-label="<?= $e($t('assets.col.scope')) ?>"><?= $e(($row['site_code'] ?? $t('assets.all')) . ' · ' . ($row['type_name'] ?? $t('assets.all'))) ?></td>
          <td data-label="<?= $e($t('assets.col.in_scope')) ?>"><?= $cst === 'draft' ? '—' : (int) $row['scope_count'] ?></td>
          <td data-label="<?= $e($t('assets.col.status')) ?>"><?php include __DIR__ . '/_cstatus.php'; ?><?= (int) $row['open_cases'] > 0 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('assets.open_cases_n', ['n' => (string) $row['open_cases']])]) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($canManage): ?>
<section class="card stack-sm" aria-labelledby="ac-n">
  <h2 id="ac-n" class="card__title"><?= $e($t('assets.new_campaign')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('assets.new_campaign_help')) ?></p>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/assets/campaigns')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'name', 'label' => $t('assets.field.campaign_name'), 'required' => true, 'value' => $o('name'), 'placeholder' => $t('assets.campaign_name_example'), 'error' => $errors['campaign_name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'cutoff_date', 'type' => 'date', 'label' => $t('assets.field.cutoff_date'), 'required' => true, 'value' => $o('cutoff_date', $today), 'help' => $t('assets.cutoff_help'), 'error' => $errors['cutoff_date'] ?? null]) ?>
      <?= $ui('field', ['name' => 'double_cost', 'type' => 'money', 'label' => $t('assets.field.double_cost'), 'value' => $o('double_cost'), 'help' => $t('assets.double_cost_help'), 'error' => $errors['double_cost'] ?? null]) ?>
      <?= $ui('field', ['name' => 'site', 'type' => 'select', 'label' => $t('assets.field.site'), 'value' => $o('site'), 'options' => ['' => $t('assets.all')] + array_column($sites, 'code', 'public_id'), 'error' => $errors['scope_site'] ?? null]) ?>
      <?= $ui('field', ['name' => 'type', 'type' => 'select', 'label' => $t('assets.field.type'), 'value' => $o('type'), 'options' => ['' => $t('assets.all')] + array_column($types, 'name', 'public_id'), 'error' => $errors['scope_type'] ?? null]) ?>
    </div>
    <div class="row"><?= $ui('button', ['label' => $t('assets.create_campaign'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
