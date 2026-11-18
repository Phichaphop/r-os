<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{rows: list<array<string,mixed>>, total: int} $list @var int $page @var int $per
 */
?>
<?= $ui('page-header', ['title' => $t('notify.title'), 'subtitle' => $t('notify.subtitle')]) ?>
<form method="post" action="<?= $e($url('/notifications/read-all')) ?>" class="row"><input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <?= $ui('button', ['label' => $t('notify.mark_all'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'checks']) ?></form>
<ul class="notif-list">
  <?php foreach ($list['rows'] as $n): $params = json_decode((string) ($n['params_json'] ?? ''), true) ?: []; ?>
    <li class="notif<?= $n['status'] === 'queued' ? ' is-unread' : '' ?>">
      <a href="<?= $e($url('/notifications/' . $n['public_id'])) ?>">
        <?php if ($n['status'] === 'queued'): ?><span class="sr-only"><?= $e($t('notify.unread')) ?></span><span class="notif__dot" aria-hidden="true"></span><?php endif; ?>
        <span class="notif__text"><?= $e($t((string) $n['title_key'], array_map('strval', $params))) ?></span>
        <span class="notif__time text-sm text-muted"><?= $e($date($n['created_at'])) ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>
<?php if ($list['rows'] === []): ?><?= $ui('states', ['icon' => 'bell', 'title' => $t('notify.empty')]) ?><?php endif; ?>
<?= $ui('pagination', ['path' => '/notifications', 'query' => [], 'page' => $page, 'per' => $per, 'total' => $list['total']]) ?>
