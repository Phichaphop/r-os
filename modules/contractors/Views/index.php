<?php
/**
 * Contractor companies with today's qualification (missing documents), workers, live permits and average rating; add one.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var list<array<string, mixed>> $types
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canView @var bool $canManage @var bool $canGate
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$o = static fn (string $k): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : '';
?>
<?= $ui('page-header', ['title' => $t('contractors.title'), 'subtitle' => $t('contractors.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('contractors.new_permit'), 'href' => $url('/contractors/permits/new?le=' . rawurlencode($lePid)), 'variant' => 'primary', 'icon' => 'plus']) : '']) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($types === [] && $canManage): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('contractors.no_types'), 'html' => $e($t('contractors.no_types_help')) . ' <a href="' . $e($url('/contractors/settings')) . '">' . $e($t('contractors.tab.settings')) . '</a>']) ?><?php endif; ?>
<section class="card card--flush" aria-labelledby="cc-h">
  <div class="card__head"><h2 id="cc-h" class="card__title"><?= $e($t('contractors.companies', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'id-badge-2', 'title' => $t('contractors.none'), 'text' => $t('contractors.none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="cc-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('contractors.field.company')) ?></th><th scope="col"><?= $e($t('contractors.col.qualification')) ?></th><th scope="col"><?= $e($t('contractors.col.workers')) ?></th><th scope="col"><?= $e($t('contractors.col.live_permits')) ?></th><th scope="col"><?= $e($t('contractors.col.rating')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr><th scope="row" data-label="<?= $e($t('contractors.field.company')) ?>"><a href="<?= $e($url('/contractors/c/' . $row['public_id'])) ?>"><?= $e((string) $row['name']) ?></a></th>
          <td data-label="<?= $e($t('contractors.col.qualification')) ?>"><?php if ((int) $row['suspended'] === 1): ?><?= $ui('badge', ['tone' => 'danger', 'label' => $t('contractors.suspended')]) ?><?php elseif ($row['missing'] === []): ?><?= $ui('badge', ['tone' => 'success', 'label' => $t('contractors.qualified')]) ?><?php else: ?><?= $ui('badge', ['tone' => 'warning', 'label' => $t('contractors.docs_pending')]) ?> <span class="text-sm"><?= $e(implode(', ', $row['missing'])) ?></span><?php endif; ?></td>
          <td data-label="<?= $e($t('contractors.col.workers')) ?>"><?= (int) $row['worker_count'] ?></td>
          <td data-label="<?= $e($t('contractors.col.live_permits')) ?>"><?= (int) $row['live_permits'] ?></td>
          <td data-label="<?= $e($t('contractors.col.rating')) ?>"><?= $row['avg_rating'] === null ? '—' : $e((string) $row['avg_rating']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php if ($canManage): ?>
<section class="card stack-sm" aria-labelledby="cc-n">
  <h2 id="cc-n" class="card__title"><?= $e($t('contractors.add_company')) ?></h2>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/contractors')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?php $cv = $old; $cerr = $errors; include __DIR__ . '/_company_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('contractors.add'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
