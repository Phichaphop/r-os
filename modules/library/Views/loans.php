<?php
/**
 * My reservations and loans.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var list<array<string, mixed>> $rows @var string $today
 * @var bool $canManage @var string $here
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('library.my_loans'), 'subtitle' => $t('library.my_loans_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<section class="card card--flush" aria-labelledby="lb-ml">
  <div class="card__head"><h2 id="lb-ml" class="card__title"><?= $e($t('library.my_loans')) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'bookmark', 'title' => $t('library.no_loans'), 'text' => $t('library.no_loans_help')]) ?></div><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th scope="col"><?= $e($t('library.field.title')) ?></th><th scope="col"><?= $e($t('library.col.status')) ?></th><th scope="col"><?= $e($t('library.col.copy')) ?></th><th scope="col"><?= $e($t('library.col.date')) ?></th><th scope="col"><span class="sr-only"><?= $e($t('library.col.actions')) ?></span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $late = $r['status'] === 'loaned' && (string) $r['due_on'] < $today; ?>
      <tr>
        <td><a href="<?= $e($url('/library/i/' . $r['item_pid'])) ?>"><?= $e((string) $r['title']) ?></a></td>
        <td><?= $ui('badge', ['tone' => $late ? 'danger' : (['ready' => 'success', 'loaned' => 'info', 'waiting' => 'warning'][$r['status']] ?? 'neutral'), 'label' => $late ? $t('library.loan.overdue') : $t('library.loan.' . $r['status'])]) ?></td>
        <td><?= $e((string) ($r['copy_code'] ?? '—')) ?></td>
        <td class="text-sm"><?php if ($r['status'] === 'ready'): ?><?= $e($t('library.pick_up_by', ['date' => (string) $r['ready_until']])) ?><?php elseif ($r['status'] === 'loaned'): ?><?= $e($t('library.due_on', ['date' => (string) $r['due_on']])) ?><?php elseif ($r['status'] === 'waiting'): ?><?= $e($date((string) $r['queued_at'])) ?><?php else: ?><?= $e($date((string) ($r['closed_at'] ?? $r['queued_at']))) ?><?php endif; ?></td>
        <td><?php if (in_array($r['status'], ['waiting', 'ready'], true)): ?><form method="post" action="<?= $e($url('/library/l/' . $r['public_id'] . '/cancel')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('library.cancel_reservation'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm']) ?></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
