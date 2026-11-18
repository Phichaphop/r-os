<?php
/**
 * One requisition: what was asked, its postings (recruiters create / edit / publish them).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $req @var list<array<string, mixed>> $postings @var bool $recruiter @var string $unit @var string $requester @var bool $notice @var string|null $budget
 * @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Core\I18n\DateFormat;

$loc = (string) ($v['locale'] ?? 'th');
$rs = (string) $req['status'];
$money = static fn (mixed $x): string => $x !== null ? number_format((float) $x, 2) : '—';
$o = static fn (string $k, string $d = ''): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : $d;
?>
<?= $ui('page-header', ['title' => (string) $req['position_title'] . ' × ' . (int) $req['headcount'], 'subtitle' => $unit, 'crumbs' => [[$t('recruit.req_title'), $url('/recruit/requisitions')], [(string) $req['position_title'], null]]]) ?>
<div class="settings-grid">
  <section class="card stack-sm" aria-labelledby="rd-h">
    <h2 id="rd-h" class="card__title"><?= $e($t('recruit.req_details')) ?> <?php include __DIR__ . '/_req_status.php'; ?></h2>
    <dl class="facts">
      <div><dt><?= $e($t('recruit.field.requester')) ?></dt><dd><?= $e($requester) ?></dd></div>
      <div><dt><?= $e($t('recruit.field.employment_type')) ?></dt><dd><?= $e($t('employees.type.' . $req['employment_type'])) ?></dd></div>
      <div><dt><?= $e($t('recruit.field.reason')) ?></dt><dd><?= $e($t('recruit.reason.' . $req['reason']) . ($req['replaces'] !== null ? ' — ' . $req['replaces'] : '')) ?></dd></div>
      <div><dt><?= $e($t('recruit.field.salary_range')) ?></dt><dd><?= $e($money($req['salary_min']) . ' – ' . $money($req['salary_max'])) ?></dd></div>
      <div><dt><?= $e($t('recruit.field.needed_by')) ?></dt><dd><?= $e($req['needed_by'] !== null ? DateFormat::day((string) $req['needed_by'], $loc) : '—') ?></dd></div>
      <div><dt><?= $e($t('recruit.field.justification')) ?></dt><dd class="pre-line"><?= $e((string) $req['justification']) ?></dd></div>
      <?php if ($budget !== null): ?><div><dt><?= $e($t('recruit.field.budget')) ?></dt><dd><?= $e($budget) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($rs === 'pending'): ?><p class="text-sm text-muted"><?= $e($t('recruit.req_pending_help')) ?></p><?php endif; ?>
  </section>
  <section class="card stack-sm" aria-labelledby="rp-h">
    <h2 id="rp-h" class="card__title"><?= $e($t('recruit.postings')) ?></h2>
    <?php if ($postings === []): ?><p class="text-sm text-muted"><?= $e($t($rs === 'approved' ? 'recruit.no_posting' : 'recruit.posting_after_approval')) ?></p><?php endif; ?>
    <ul class="plain-list">
      <?php foreach ($postings as $p): ?>
        <li><a href="<?= $e($url('/recruit/p/' . $p['public_id'])) ?>"><?= $e((string) $p['title']) ?></a> · <?= $ui('badge', ['tone' => $p['status'] === 'published' ? 'success' : 'neutral', 'label' => $t('recruit.post_status.' . $p['status'])]) ?>
          <span class="text-sm text-muted"><?= $e($t('recruit.applicants_n', ['n' => (string) $p['applicants']])) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
<?php if ($recruiter && $rs === 'approved'): ?>
<section class="card stack" aria-labelledby="rn-h">
  <h2 id="rn-h" class="card__title"><?= $e($t('recruit.posting_new')) ?></h2>
  <?php if (!$notice): ?><?= $ui('alert', ['tone' => 'warning', 'text' => $t('recruit.error.no_notice')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/recruit/r/' . $req['public_id'] . '/postings')) ?>" class="stack" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <div class="grid grid--3">
      <?= $ui('field', ['name' => 'title', 'label' => $t('recruit.field.post_title'), 'required' => true, 'value' => $o('title', (string) $req['position_title']), 'error' => $errors['title'] ?? null]) ?>
      <?= $ui('field', ['name' => 'location', 'label' => $t('recruit.field.location'), 'value' => $o('location')]) ?>
      <?= $ui('field', ['name' => 'closes_on', 'type' => 'date', 'label' => $t('recruit.field.closes_on'), 'value' => $o('closes_on'), 'error' => $errors['closes_on'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'description', 'type' => 'textarea', 'label' => $t('recruit.field.description'), 'required' => true, 'value' => $o('description'), 'error' => $errors['description'] ?? null, 'help' => $t('recruit.description_help')]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('recruit.posting_create'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
<?php endif; ?>
