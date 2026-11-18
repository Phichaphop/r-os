<?php
/**
 * My tickets — and the reminder to rate a closed one (it blocks a new ticket).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array<string, mixed>> $rows @var ?array<string, mixed> $unrated @var bool $staff @var bool $canManage
 */
$here = '/helpdesk';
?>
<?= $ui('page-header', ['title' => $t('helpdesk.title'), 'subtitle' => $t('helpdesk.subtitle'),
    'actions' => $ui('button', ['label' => $t('helpdesk.tab.new'), 'href' => $url('/helpdesk/new'), 'variant' => 'primary', 'icon' => 'plus'])]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php if ($unrated !== null): ?>
  <?= $ui('alert', ['tone' => 'warning', 'title' => $t('helpdesk.rate_first_title'), 'html' => $e($t('helpdesk.rate_first_text', ['ref' => (string) $unrated['ref']])) . ' <a href="' . $e($url('/helpdesk/t/' . $unrated['public_id'] . '#hd-rate')) . '">' . $e($t('helpdesk.rate_now')) . '</a>']) ?>
<?php endif; ?>
<section class="card card--flush" aria-labelledby="hi-m">
  <div class="card__head"><h2 id="hi-m" class="card__title"><?= $e($t('helpdesk.my_tickets')) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'tool', 'title' => $t('helpdesk.none'), 'text' => $t('helpdesk.none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="hi-m" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('helpdesk.col.ref')) ?></th><th scope="col"><?= $e($t('helpdesk.field.title')) ?></th><th scope="col"><?= $e($t('helpdesk.field.category')) ?></th><th scope="col"><?= $e($t('helpdesk.col.opened')) ?></th><th scope="col"><?= $e($t('helpdesk.col.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr><th scope="row" data-label="<?= $e($t('helpdesk.col.ref')) ?>"><a href="<?= $e($url('/helpdesk/t/' . $row['public_id'])) ?>"><?= $e((string) $row['ref']) ?></a></th>
          <td data-label="<?= $e($t('helpdesk.field.title')) ?>"><?= $e((string) $row['title']) ?></td>
          <td data-label="<?= $e($t('helpdesk.field.category')) ?>"><?= $e((string) $row['category_name']) ?></td>
          <td data-label="<?= $e($t('helpdesk.col.opened')) ?>"><?= $e($date(substr((string) $row['opened_at'], 0, 10), false) . ' ' . substr((string) $row['opened_at'], 11, 5)) ?></td>
          <td data-label="<?= $e($t('helpdesk.col.status')) ?>"><?php include __DIR__ . '/_badges.php'; ?><?= $row['status'] === 'closed' && $row['rating'] === null ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('helpdesk.needs_rating')]) : '' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
