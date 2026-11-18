<?php
/**
 * My celebrations: wishes I received, what colleagues see, hide mine.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array{settings: array<string, mixed>|null, optouts: list<string>, events: list<array<string, mixed>>, wishes: list<array<string, mixed>>} $mine
 */
$s = $mine['settings'];
?>
<?= $ui('page-header', ['title' => $t('celebrate.me_title'), 'subtitle' => $t('celebrate.me_subtitle'), 'crumbs' => [[$t('celebrate.title'), $url('/celebrate')], [$t('celebrate.me_title'), null]]]) ?>
<?php if ($s === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('celebrate.not_employee')]) ?>
<?php else: ?>
<section class="card stack-sm" aria-labelledby="cm-w">
  <h2 id="cm-w" class="card__title"><?= $e($t('celebrate.wishes_for_me', ['n' => (string) count($mine['wishes'])])) ?></h2>
  <?php if ($mine['wishes'] === []): ?><p class="text-sm text-muted"><?= $e($t('celebrate.no_wishes')) ?></p><?php else: ?>
  <ul class="plain-list stack-sm">
    <?php foreach ($mine['wishes'] as $w): ?>
      <li><span class="pre-line"><?= $e((string) $w['message']) ?></span><span class="d-block text-sm text-muted"><?= $e((string) ($w['from_name'] ?? '—')) ?> · <?= $e($t('celebrate.kind.' . $w['kind'])) ?> · <?= $e($date((string) $w['created_at'])) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="cm-p">
  <h2 id="cm-p" class="card__title"><?= $e($t('celebrate.privacy')) ?></h2>
  <?php if ($s['notice_text'] !== ''): ?>
    <details><summary class="text-sm"><?= $e($t('celebrate.read_notice', ['n' => (string) $s['notice_version']])) ?></summary><div class="notice-box pre-line"><?= $e((string) $s['notice_text']) ?></div></details>
  <?php endif; ?>
  <p class="text-sm"><?= $e($t('celebrate.what_shown')) ?></p>
  <form method="post" action="<?= $e($url('/celebrate/me')) ?>" class="stack-sm" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
    <label class="check"><input type="checkbox" name="hide[]" value="birthday"<?= in_array('birthday', $mine['optouts'], true) ? ' checked' : '' ?>> <?= $e($t('celebrate.hide.birthday')) ?></label>
    <label class="check"><input type="checkbox" name="hide[]" value="anniversary"<?= in_array('anniversary', $mine['optouts'], true) ? ' checked' : '' ?>> <?= $e($t('celebrate.hide.anniversary')) ?></label>
    <div class="row"><?= $ui('button', ['label' => $t('celebrate.save'), 'type' => 'submit', 'variant' => 'primary']) ?></div>
  </form>
</section>
<?php endif; ?>
