<?php
/**
 * Handlers' inbox: the cases of my route(s) and those assigned to me. No sender information exists to show.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var callable $partial @var array<string, mixed> $v
 * @var list<array{id: int, code: string, name: string}> $companies @var int $le @var string $filter @var list<array<string, mixed>> $rows
 * @var array<string, mixed> $settings @var bool $manage
 */
?>
<?= $ui('page-header', ['title' => $t('complaints.inbox.title'), 'subtitle' => $t('complaints.inbox.subtitle')]) ?>
<?= $partial('@complaints/_companies', ['companies' => $companies, 'le' => $le, 'path' => '/complaints/inbox']) ?>
<?php if (!$settings['open'] && $manage): ?><?= $ui('alert', ['tone' => 'info', 'text' => $t('complaints.inbox.closed')]) ?><?php endif; ?>
<nav class="segmented-links" aria-label="<?= $e($t('complaints.inbox.filter')) ?>">
  <?php foreach (['open', 'done', 'all'] as $f): ?><a href="<?= $e($url('/complaints/inbox?le=' . $le . '&f=' . $f)) ?>"<?= $f === $filter ? ' aria-current="page"' : '' ?>><?= $e($t('complaints.inbox.f_' . $f)) ?></a><?php endforeach; ?>
</nav>
<section class="card stack-sm" aria-labelledby="ib-h">
  <h2 id="ib-h" class="card__title"><?= $e($t('complaints.inbox.list', ['n' => (string) count($rows)])) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('complaints.inbox.note')) ?></p>
  <?php if ($rows === []): ?><p class="text-sm text-muted"><?= $e($t('complaints.inbox.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ib-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr>
        <th scope="col"><?= $e($t('complaints.field.ref')) ?></th><th scope="col"><?= $e($t('complaints.field.category')) ?></th><th scope="col"><?= $e($t('complaints.field.status')) ?></th>
        <th scope="col"><?= $e($t('complaints.field.investigator')) ?></th><th scope="col"><?= $e($t('complaints.field.sent_on')) ?></th><th scope="col"><?= $e($t('complaints.field.due')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <th scope="row" data-label="<?= $e($t('complaints.field.ref')) ?>"><a href="<?= $e($url('/complaints/c/' . $r['public_id'])) ?>"><?= $e((string) $r['ref']) ?></a>
            <?= $r['route'] === 'alternate' ? $ui('badge', ['tone' => 'warning', 'label' => $t('complaints.route.alternate_short')]) : '' ?>
            <?= $r['urgency'] === 'high' ? $ui('badge', ['tone' => 'danger', 'label' => $t('complaints.urgency.high')]) : '' ?>
            <?= $r['waiting'] ? $ui('badge', ['tone' => 'info', 'label' => $t('complaints.inbox.new_reply')]) : '' ?></th>
          <td data-label="<?= $e($t('complaints.field.category')) ?>"><?= $e($t('complaints.category.' . $r['category'])) ?></td>
          <td data-label="<?= $e($t('complaints.field.status')) ?>"><?= $partial('@complaints/_status', ['s' => (string) $r['status']]) ?></td>
          <td data-label="<?= $e($t('complaints.field.investigator')) ?>"><?= $e((string) ($r['investigator'] ?? '—')) ?><?= $r['investigator'] !== null && $r['accepted_at'] === null ? ' (' . $e($t('complaints.not_accepted')) . ')' : '' ?></td>
          <td data-label="<?= $e($t('complaints.field.sent_on')) ?>"><?= $e($date((string) $r['created_at'], false)) ?></td>
          <td data-label="<?= $e($t('complaints.field.due')) ?>"><?php $d = $r['due']['response'] ?? $r['due']['resolve']; ?><?= $d === null ? '—' : $e($date((string) $d, false)) ?><?= $r['due']['late'] ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('complaints.late')]) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
