<?php
/**
 * Librarian desk: copies held for pickup and books on loan (overdue first) across the company.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, string> $companies @var string $lePid
 * @var list<array<string, mixed>> $rows @var string $today @var bool $canManage @var string $here
 */
$late = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'loaned' && (string) $r['due_on'] < $today));
?>
<?= $ui('page-header', ['title' => $t('library.desk_title'), 'subtitle' => $t('library.desk_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<?php if ($late > 0): ?><?= $ui('alert', ['tone' => 'warning', 'title' => $t('library.overdue_n', ['n' => (string) $late])]) ?><?php endif; ?>
<section class="card card--flush" aria-labelledby="lb-dk">
  <div class="card__head"><h2 id="lb-dk" class="card__title"><?= $e($t('library.out_n', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'book', 'title' => $t('library.desk_empty'), 'text' => $t('library.desk_empty_help')]) ?></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('library.field.title')) ?></th><th scope="col"><?= $e($t('library.col.person')) ?></th><th scope="col"><?= $e($t('library.col.copy')) ?></th><th scope="col"><?= $e($t('library.col.status')) ?></th><th scope="col"><?= $e($t('library.col.date')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $isLate = $r['status'] === 'loaned' && (string) $r['due_on'] < $today; ?>
      <tr>
        <td><a href="<?= $e($url('/library/m/' . $r['item_pid'] . '#loans')) ?>"><?= $e((string) $r['title']) ?></a></td>
        <td><?= $e($r['employee_code'] . ' ' . $r['name_th']) ?></td>
        <td><?= $e((string) ($r['copy_code'] ?? '—')) ?></td>
        <td><?= $ui('badge', ['tone' => $isLate ? 'danger' : ($r['status'] === 'ready' ? 'success' : 'info'), 'label' => $isLate ? $t('library.loan.overdue') : $t('library.loan.' . $r['status'])]) ?></td>
        <td class="text-sm"><?= $e($r['status'] === 'loaned' ? $t('library.due_on', ['date' => (string) $r['due_on']]) : $t('library.pick_up_by', ['date' => (string) $r['ready_until']])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
