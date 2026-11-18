<?php
/**
 * My documents. @var callable $e @var callable $t @var callable $ui @var callable $url @var array<string, mixed> $v
 * @var array{pending: list<array<string, mixed>>, documents: list<array<string, mixed>>} $mine @var string $q @var bool $canManage @var array<string, mixed>|null $me
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
?>
<?= $ui('page-header', ['title' => $t('doccontrol.title'), 'subtitle' => $t('doccontrol.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('doccontrol.nav_admin'), 'href' => $url('/policies/admin'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<?php if ($me === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'title' => $t('doccontrol.no_employee_title'), 'text' => $t('doccontrol.no_employee_text')]) ?>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="pd-h">
  <h2 id="pd-h" class="card__title"><?= $ui('icon', ['name' => 'clipboard-check']) ?> <?= $e($t('doccontrol.to_ack', ['n' => (string) count($mine['pending'])])) ?></h2>
  <?php if ($mine['pending'] === []): ?><p class="text-sm text-muted"><?= $e($t('doccontrol.to_ack_none')) ?></p><?php else: ?>
  <ul class="form-list" role="list">
    <?php foreach ($mine['pending'] as $p): ?>
    <li class="form-item">
      <span class="form-item__icon" aria-hidden="true"><?= $ui('icon', ['name' => 'file-type-pdf']) ?></span>
      <div class="form-item__body"><a class="form-item__title" href="<?= $e($url('/policies/d/' . $p['public_id'])) ?>"><?= $e((string) $p['title']) ?></a>
        <span class="text-sm text-muted"><?= $e($p['code'] . ' · ' . $t('doccontrol.type.' . $p['doc_type']) . ' · ' . $t('doccontrol.version_n', ['n' => (string) $p['version_no']]) . ' · ' . $t('doccontrol.effective_on', ['date' => DateFormat::day((string) $p['effective_from'], $loc)])) ?></span></div>
      <?= $ui('badge', ['tone' => 'warning', 'icon' => 'hourglass', 'label' => $t('doccontrol.ack_status.pending')]) ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<form method="get" action="<?= $e($url('/policies')) ?>" class="row row--tight" role="search">
  <label class="sr-only" for="dq"><?= $e($t('common.search')) ?></label><input class="input" type="search" id="dq" name="q" value="<?= $e($q) ?>" placeholder="<?= $e($t('doccontrol.search_hint')) ?>">
  <?= $ui('button', ['label' => $t('common.search'), 'type' => 'submit', 'size' => 'sm', 'icon' => 'search']) ?>
</form>
<section class="card stack-sm" aria-labelledby="dl-h">
  <h2 id="dl-h" class="card__title"><?= $e($t('doccontrol.in_force')) ?></h2>
  <?php if ($mine['documents'] === []): ?><p class="text-sm text-muted"><?= $e($t('doccontrol.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="dl-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('doccontrol.field.title')) ?></th><th scope="col"><?= $e($t('doccontrol.field.type')) ?></th><th scope="col"><?= $e($t('doccontrol.field.version')) ?></th><th scope="col"><?= $e($t('doccontrol.field.my_status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($mine['documents'] as $d): ?>
        <tr><th scope="row" data-label="<?= $e($t('doccontrol.field.title')) ?>"><a href="<?= $e($url('/policies/d/' . $d['public_id'])) ?>"><?= $e($d['code'] . ' · ' . $d['title']) ?></a></th>
          <td data-label="<?= $e($t('doccontrol.field.type')) ?>"><?= $e($t('doccontrol.type.' . $d['doc_type'])) ?></td>
          <td data-label="<?= $e($t('doccontrol.field.version')) ?>"><?= $e($t('doccontrol.version_n', ['n' => (string) $d['version_no']]) . ' · ' . DateFormat::day((string) $d['effective_from'], $loc)) ?></td>
          <td data-label="<?= $e($t('doccontrol.field.my_status')) ?>"><?= $e($d['my_status'] !== null ? $t('doccontrol.ack_status.' . $d['my_status']) : ((int) $d['requires_ack'] === 1 ? '—' : $t('doccontrol.read_only'))) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
