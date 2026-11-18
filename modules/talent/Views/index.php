<?php
/**
 * Talent pool of a company: search and filters + the list.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, string> $companies @var string $lePid
 * @var list<array<string, mixed>> $rows @var array<string, string> $f @var bool $rule
 */
use Blue\Modules\Recruit\Lists;
use Blue\Modules\Talent\TalentService;

$opts = static fn (array $list, string $prefix): array => ['' => $t('talent.any')] + array_combine($list, array_map(static fn (string $x): string => $t($prefix . $x), $list));
$actions = $ui('button', ['label' => $t('talent.new_title'), 'href' => $url('/talent/new?le=' . rawurlencode($lePid)), 'variant' => 'primary', 'icon' => 'plus'])
    . $ui('button', ['label' => $t('talent.builder_title'), 'href' => $url('/talent/builder'), 'variant' => 'ghost', 'icon' => 'file-text']);
?>
<?= $ui('page-header', ['title' => $t('talent.title'), 'subtitle' => $t('talent.subtitle'), 'actions' => $actions]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('talent.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/talent?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if (!$rule): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('talent.no_rule_title'), 'text' => $t('talent.no_rule')]) ?><?php endif; ?>
<form method="get" action="<?= $e($url('/talent')) ?>" class="filter-bar" role="search">
  <input type="hidden" name="le" value="<?= $e($lePid) ?>">
  <?= $ui('field', ['name' => 'q', 'type' => 'search', 'label' => $t('talent.search'), 'value' => $f['q'], 'placeholder' => $t('talent.search_hint')]) ?>
  <?= $ui('field', ['name' => 'status', 'type' => 'select', 'label' => $t('talent.col.status'), 'value' => $f['status'], 'options' => $opts(TalentService::STATUSES, 'talent.status.')]) ?>
  <?= $ui('field', ['name' => 'edu', 'type' => 'select', 'label' => $t('talent.field.education_level'), 'value' => $f['edu'], 'options' => $opts(Lists::EDU_LEVELS, 'recruit.edu.')]) ?>
  <?= $ui('field', ['name' => 'years', 'type' => 'number', 'label' => $t('talent.min_years'), 'value' => $f['years'], 'min' => '0', 'max' => '60']) ?>
  <?= $ui('field', ['name' => 'source', 'type' => 'select', 'label' => $t('talent.field.source'), 'value' => $f['source'], 'options' => $opts(['applicant', 'upload', 'walk_in', 'referral'], 'talent.source.')]) ?>
  <?= $ui('field', ['name' => 'starred', 'type' => 'select', 'label' => $t('talent.starred'), 'value' => $f['starred'], 'options' => ['' => $t('talent.any'), '1' => $t('talent.starred_only')]]) ?>
  <?= $ui('button', ['label' => $t('talent.find'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'search']) ?>
</form>
<section class="card stack-sm" aria-labelledby="tl-l">
  <h2 id="tl-l" class="card__title"><?= $e($t('talent.list', ['n' => (string) count($rows)])) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('talent.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="tl-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('talent.field.name')) ?></th><th scope="col"><?= $e($t('talent.field.positions')) ?></th><th scope="col"><?= $e($t('talent.field.experience_years')) ?></th>
        <th scope="col"><?= $e($t('talent.field.education_level')) ?></th><th scope="col"><?= $e($t('talent.field.source')) ?></th><th scope="col"><?= $e($t('talent.col.status')) ?></th><th scope="col"><?= $e($t('talent.col.updated')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $x): ?>
        <tr><th scope="row" data-label="<?= $e($t('talent.field.name')) ?>"><?= (int) $x['starred'] === 1 ? $ui('icon', ['name' => 'star', 'size' => 'sm', 'label' => $t('talent.starred')]) . ' ' : '' ?><a href="<?= $e($url('/talent/p/' . $x['public_id'])) ?>"><?= $e((string) $x['name']) ?></a></th>
          <td data-label="<?= $e($t('talent.field.positions')) ?>"><?= $e((string) $x['positions'] !== '' ? (string) $x['positions'] : '—') ?></td>
          <td data-label="<?= $e($t('talent.field.experience_years')) ?>"><?= $e($x['experience_years'] !== null ? (string) (float) $x['experience_years'] : '—') ?></td>
          <td data-label="<?= $e($t('talent.field.education_level')) ?>"><?= $e($x['education_level'] !== null ? $t('recruit.edu.' . $x['education_level']) : '—') ?></td>
          <td data-label="<?= $e($t('talent.field.source')) ?>"><?= $e($t('talent.source.' . $x['source'])) ?></td>
          <td data-label="<?= $e($t('talent.col.status')) ?>"><?= $ui('badge', ['tone' => in_array($x['status'], ['hired', 'linked'], true) ? 'success' : ($x['status'] === 'inactive' ? 'neutral' : 'info'), 'label' => $t('talent.status.' . $x['status'])]) ?></td>
          <td data-label="<?= $e($t('talent.col.updated')) ?>"><?= $e($date((string) $x['updated_at'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
