<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $b @var list<array<string, mixed>> $items @var array<string, mixed>|null $certificate @var string $tab
 */
?>
<?= $ui('page-header', ['title' => $t('records.batch.title'), 'subtitle' => $t('records.batch_status.' . $b['status']),
    'crumbs' => [[$t('records.title'), $url('/admin/records')], [$t('records.disposition.title'), $url('/admin/records/disposition')], [$t('records.batch.title'), null]]]) ?>
<?php include __DIR__ . '/_tabs.php'; ?>
<section class="card">
  <dl class="dl">
    <dt><?= $e($t('records.field.requested_by')) ?></dt><dd><?= $e($b['requested_name'] ?? '') ?> · <?= $e($date($b['created_at'])) ?></dd>
    <dt><?= $e($t('iam.field.reason')) ?></dt><dd><?= $e($b['reason']) ?></dd>
    <dt><?= $e($t('records.field.status')) ?></dt><dd><?= $e($t('records.batch_status.' . $b['status'])) ?></dd>
  </dl>
</section>
<?php if ($certificate !== null): ?>
<section class="card" aria-labelledby="cert-h">
  <h2 id="cert-h" class="card__title"><?= $e($t('records.certificate')) ?></h2>
  <dl class="dl">
    <dt><?= $e($t('records.cert.executed')) ?></dt><dd><?= $e($date((string) $certificate['executed_at_utc'])) ?></dd>
    <dt><?= $e($t('records.cert.disposed')) ?></dt><dd><?= (int) $certificate['disposed'] ?></dd>
    <dt><?= $e($t('records.cert.skipped')) ?></dt><dd><?= (int) $certificate['skipped'] ?></dd>
    <dt><?= $e($t('records.cert.classes')) ?></dt><dd><?= $e(implode(', ', array_map(static fn ($k, $n) => $t('records.class.' . $k) . ' × ' . $n, array_keys((array) $certificate['record_classes']), (array) $certificate['record_classes']))) ?: '—' ?></dd>
    <dt><?= $e($t('records.cert.rules')) ?></dt><dd class="mono"><?= $e(implode(', ', (array) $certificate['rules'])) ?: '—' ?></dd>
    <dt><?= $e($t('records.cert.method')) ?></dt><dd><?= $e((string) $certificate['method']) ?></dd>
    <dt><?= $e($t('records.cert.workflow')) ?></dt><dd><a href="<?= $e($url('/requests/' . $certificate['workflow'])) ?>" class="mono"><?= $e((string) $certificate['workflow']) ?></a></dd>
  </dl>
</section>
<?php endif; ?>
<section class="card" aria-labelledby="it-h">
  <h2 id="it-h" class="card__title"><?= $e($t('records.items')) ?></h2>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('records.items')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('records.items')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('document.field.title')) ?></th><th scope="col"><?= $e($t('records.field.record_class')) ?></th><th scope="col"><?= $e($t('records.field.result')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($items as $i): ?>
        <tr><td data-label="<?= $e($t('document.field.title')) ?>"><a href="<?= $e($url('/documents/' . $i['document_public_id'])) ?>"><?= $e($i['title']) ?></a> <span class="mono text-sm"><?= $e($i['document_number'] ?? '') ?></span></td>
          <td data-label="<?= $e($t('records.field.record_class')) ?>"><?= $e($t('records.class.' . $i['record_class'])) ?></td>
          <td data-label="<?= $e($t('records.field.result')) ?>"><?= $e($t('records.result.' . $i['result'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
