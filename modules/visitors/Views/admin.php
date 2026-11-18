<?php
/**
 * Visitor settings, blacklist and monthly figures (visitors.manage).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array{id: int, code: string, name: string}> $companies @var int $le @var string $ym
 * @var array{retention_days: int|null, overdue_minutes: int|null, record_vehicle: bool} $settings
 * @var list<array<string, mixed>> $blacklist @var list<array{day: string, visits: int, people: int, walk_in: int, denied: int}> $report
 * @var string|null $notice @var array<string, string> $errors
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '"><input type="hidden" name="le" value="' . $le . '">';
?>
<?= $ui('page-header', ['title' => $t('visitors.admin.title'), 'subtitle' => $t('visitors.admin.subtitle')]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('visitors.company')) ?>">
  <?php foreach ($companies as $c): ?><a href="<?= $e($url('/visitors/admin?le=' . $c['id'])) ?>"<?= $c['id'] === $le ? ' aria-current="page"' : '' ?>><?= $e($c['code']) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($notice === null): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('visitors.no_notice_admin')]) ?><?php endif; ?>
<section class="card stack" aria-labelledby="as-h">
  <h2 id="as-h" class="card__title"><?= $e($t('visitors.admin.settings')) ?></h2>
  <form method="post" action="<?= $e($url('/visitors/admin/settings')) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'retention_days', 'id' => 'as-ret', 'type' => 'number', 'label' => $t('visitors.field.retention_days'), 'value' => (string) ($settings['retention_days'] ?? ''), 'help' => $t('visitors.retention_help'), 'error' => $errors['retention_days'] ?? null]) ?>
      <?= $ui('field', ['name' => 'overdue_minutes', 'id' => 'as-over', 'type' => 'number', 'label' => $t('visitors.field.overdue_minutes'), 'value' => (string) ($settings['overdue_minutes'] ?? ''), 'help' => $t('visitors.overdue_help'), 'error' => $errors['overdue_minutes'] ?? null]) ?>
    </div>
    <label class="check"><input type="checkbox" name="record_vehicle" value="1"<?= $settings['record_vehicle'] ? ' checked' : '' ?>> <?= $e($t('visitors.field.record_vehicle')) ?></label>
    <div class="row"><?= $ui('button', ['label' => $t('visitors.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
<section class="card stack" aria-labelledby="ab-h">
  <h2 id="ab-h" class="card__title"><?= $e($t('visitors.admin.blacklist')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('visitors.blacklist_help')) ?></p>
  <?php if ($blacklist !== []): ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($blacklist as $b): ?>
      <li class="row">
        <strong><?= $e((string) $b['display_name']) ?></strong><?= $b['company'] !== null ? ' · ' . $e((string) $b['company']) : '' ?> — <?= $e((string) $b['reason']) ?>
        <?php if ((int) $b['active'] === 1): ?>
          <form method="post" action="<?= $e($url('/visitors/admin/unlist')) ?>" class="row"><?= $csrf ?><input type="hidden" name="id" value="<?= $e((string) $b['public_id']) ?>">
            <label class="sr-only" for="ub-<?= $e((string) $b['public_id']) ?>"><?= $e($t('visitors.unlist_reason')) ?></label>
            <input class="input" id="ub-<?= $e((string) $b['public_id']) ?>" name="reason" maxlength="500" placeholder="<?= $e($t('visitors.unlist_reason')) ?>">
            <?= $ui('button', ['label' => $t('visitors.unlist'), 'type' => 'submit', 'variant' => 'ghost', 'size' => 'sm']) ?></form>
        <?php else: ?><?= $ui('badge', ['tone' => 'neutral', 'label' => $t('visitors.unlisted')]) ?><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('/visitors/admin/blacklist')) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'name', 'id' => 'ab-name', 'label' => $t('visitors.field.visitor_name'), 'required' => true, 'error' => $errors['name'] ?? null]) ?>
      <?= $ui('field', ['name' => 'company', 'id' => 'ab-company', 'label' => $t('visitors.field.visitor_company')]) ?>
    </div>
    <?= $ui('field', ['name' => 'reason', 'id' => 'ab-reason', 'label' => $t('visitors.field.blacklist_reason'), 'required' => true, 'help' => $t('visitors.blacklist_reason_help'), 'error' => $errors['reason'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('visitors.blacklist_add'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'ban']) ?></div>
  </form>
</section>
<section class="card stack-sm" aria-labelledby="ar-h">
  <h2 id="ar-h" class="card__title"><?= $e($t('visitors.admin.report', ['m' => $ym])) ?></h2>
  <form method="get" action="<?= $e($url('/visitors/admin')) ?>" class="row">
    <input type="hidden" name="le" value="<?= $le ?>">
    <label class="field__label" for="ar-month"><?= $e($t('visitors.month')) ?></label>
    <input class="input" type="month" id="ar-month" name="month" value="<?= $e($ym) ?>">
    <?= $ui('button', ['label' => $t('visitors.gate.show'), 'type' => 'submit', 'variant' => 'secondary']) ?>
  </form>
  <?php if ($report === []): ?><p class="text-sm text-muted"><?= $e($t('visitors.report_none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="ar-h" tabindex="0">
    <table class="table">
      <thead><tr><th scope="col"><?= $e($t('visitors.report.day')) ?></th><th scope="col"><?= $e($t('visitors.report.visits')) ?></th><th scope="col"><?= $e($t('visitors.report.people')) ?></th><th scope="col"><?= $e($t('visitors.report.walk_in')) ?></th><th scope="col"><?= $e($t('visitors.report.denied')) ?></th></tr></thead>
      <tbody><?php foreach ($report as $r): ?><tr><th scope="row"><?= $e($r['day']) ?></th><td><?= $r['visits'] ?></td><td><?= $r['people'] ?></td><td><?= $r['walk_in'] ?></td><td><?= $r['denied'] ?></td></tr><?php endforeach; ?></tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
