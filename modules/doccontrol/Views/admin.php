<?php
/**
 * Managers: documents + new. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array<string, string> $companies @var string $lePid @var list<array<string, mixed>> $rows @var array{sites: array<string, string>, departments: array<string, string>} $audOptions
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Core\I18n\DateFormat;
use Blue\Modules\Doccontrol\DocControlService;

$loc = (string) ($v['locale'] ?? 'th');
$audIn = $old;
$audError = $errors['audience'] ?? null;
$audId = 'nd';
?>
<?= $ui('page-header', ['title' => $t('doccontrol.admin_title'), 'subtitle' => $t('doccontrol.admin_subtitle'), 'crumbs' => [[$t('doccontrol.title'), $url('/policies')], [$t('doccontrol.admin_title'), null]]]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('doccontrol.pick_company')) ?>">
  <?php foreach ($companies as $pid => $code): ?><a href="<?= $e($url('/policies/admin?le=' . rawurlencode($pid))) ?>"<?= $pid === $lePid ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($code) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="da-h">
  <h2 id="da-h" class="card__title"><?= $e($t('doccontrol.all')) ?></h2>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('doccontrol.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="da-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('doccontrol.field.code')) ?></th><th scope="col"><?= $e($t('doccontrol.field.title')) ?></th><th scope="col"><?= $e($t('doccontrol.field.version')) ?></th>
        <th scope="col"><?= $e($t('doccontrol.field.acked')) ?></th><th scope="col"><?= $e($t('doccontrol.field.review_due')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $d): ?>
        <tr><th scope="row" data-label="<?= $e($t('doccontrol.field.code')) ?>"><a href="<?= $e($url('/policies/admin/d/' . $d['public_id'])) ?>"><?= $e((string) $d['code']) ?></a></th>
          <td data-label="<?= $e($t('doccontrol.field.title')) ?>"><?= $e((string) $d['title']) ?><?= $d['status'] === 'archived' ? ' ' . $ui('badge', ['tone' => 'neutral', 'label' => $t('doccontrol.archived')]) : '' ?><?= (int) $d['open_versions'] > 0 ? ' ' . $ui('badge', ['tone' => 'info', 'label' => $t('doccontrol.has_open')]) : '' ?></td>
          <td data-label="<?= $e($t('doccontrol.field.version')) ?>"><?= $e($d['current_no'] !== null ? $t('doccontrol.version_n', ['n' => (string) $d['current_no']]) : '—') ?></td>
          <td data-label="<?= $e($t('doccontrol.field.acked')) ?>"><?= $e($d['current_no'] !== null && (int) $d['requires_ack'] === 1 ? $t('doccontrol.acked_of', ['a' => (string) $d['acked'], 'n' => (string) ((int) $d['acked'] + (int) $d['pending'])]) : '—') ?></td>
          <td data-label="<?= $e($t('doccontrol.field.review_due')) ?>"><?= $e($d['review_due'] !== null ? DateFormat::day((string) $d['review_due'], $loc) : '—') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="dn-h">
  <h2 id="dn-h" class="card__title"><?= $e($t('doccontrol.new')) ?></h2>
  <form method="post" action="<?= $e($url('/policies/admin')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <input type="hidden" name="legal_entity" value="<?= $e($lePid) ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'code', 'id' => 'nd-code', 'label' => $t('doccontrol.field.code'), 'required' => true, 'value' => (string) ($old['code'] ?? ''), 'placeholder' => 'POL-HR-01', 'error' => $errors['code'] ?? null]) ?>
      <?= $ui('field', ['name' => 'doc_type', 'id' => 'nd-type', 'type' => 'select', 'label' => $t('doccontrol.field.type'), 'value' => (string) ($old['doc_type'] ?? 'policy'),
          'options' => array_combine(DocControlService::TYPES, array_map(static fn (string $x): string => $t('doccontrol.type.' . $x), DocControlService::TYPES))]) ?>
      <?= $ui('field', ['name' => 'review_months', 'id' => 'nd-review', 'type' => 'number', 'label' => $t('doccontrol.field.review_months'), 'value' => (string) ($old['review_months'] ?? ''), 'help' => $t('doccontrol.review_help'), 'error' => $errors['review_months'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'title', 'id' => 'nd-title', 'label' => $t('doccontrol.field.title'), 'required' => true, 'value' => (string) ($old['title'] ?? ''), 'error' => $errors['title'] ?? null]) ?>
    <?php include __DIR__ . '/../../employees/Views/_audience.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('doccontrol.create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
