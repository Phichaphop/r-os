<?php
/**
 * Privacy officer: data-subject requests by status, the oldest due first.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date
 * @var list<array<string, mixed>> $rows @var string $status @var bool $dueSet
 */
include __DIR__ . '/_status.php';
?>
<?= $ui('page-header', ['title' => $t('privacy.desk_title'), 'subtitle' => $t('privacy.desk_subtitle')]) ?>
<?php if (!$dueSet) :
    ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('privacy.due_not_configured')]) ?><?php
endif; ?>
<nav class="segmented-links" aria-label="<?= $e($t('privacy.field.status')) ?>">
  <?php foreach (['' => 'privacy.all', 'received' => 'privacy.status.received', 'verified' => 'privacy.status.verified', 'erasure_pending' => 'privacy.status.erasure_pending', 'erasure_done' => 'privacy.status.erasure_done', 'completed' => 'privacy.status.completed', 'rejected' => 'privacy.status.rejected'] as $k => $label) : ?>
    <a href="<?= $e($url('/privacy/requests' . ($k !== '' ? '?status=' . $k : ''))) ?>"<?= $k === $status ? ' aria-current="page"' : '' ?>><?= $e($t($label)) ?></a>
  <?php endforeach; ?>
</nav>
<section class="card stack-sm" aria-labelledby="pd-l">
  <h2 id="pd-l" class="card__title"><?= $e($t('privacy.requests')) ?></h2>
  <?php if ($rows === []) :
        ?><p class="text-sm text-muted"><?= $e($t('privacy.nothing')) ?></p><?php
  else : ?>
  <div class="table-wrap" role="region" aria-labelledby="pd-l" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('privacy.col.no')) ?></th><th scope="col"><?= $e($t('privacy.col.person')) ?></th><th scope="col"><?= $e($t('privacy.field.type')) ?></th><th scope="col"><?= $e($t('privacy.due_on')) ?></th><th scope="col"><?= $e($t('privacy.field.status')) ?></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $q) : ?>
        <tr><th scope="row" data-label="<?= $e($t('privacy.col.no')) ?>"><a href="<?= $e($url('/privacy/requests/' . $q['public_id'])) ?>"><?= $e((string) $q['request_no']) ?></a></th>
          <td data-label="<?= $e($t('privacy.col.person')) ?>"><?= $e((string) $q['display_name']) ?></td>
          <td data-label="<?= $e($t('privacy.field.type')) ?>"><?= $e($t('privacy.type.' . $q['request_type'])) ?></td>
          <td data-label="<?= $e($t('privacy.due_on')) ?>"><?= $e($q['due_on'] !== null ? $date((string) $q['due_on'], false) : '—') ?></td>
          <td data-label="<?= $e($t('privacy.field.status')) ?>"><?= $dsrBadge((string) $q['status']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
