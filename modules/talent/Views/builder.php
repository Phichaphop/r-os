<?php
/**
 * Resume builder: fill in → a clean A4 resume to print or save as PDF. Nothing is stored on the server.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $asset @var array<string, mixed> $v
 * @var array<string, mixed>|null $resume @var array<string, mixed> $old
 */
use Blue\Modules\Talent\ResumeBuilder;

$o = static fn (string $k): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : '';
?>
<?= $ui('page-header', ['title' => $t('talent.builder_title'), 'subtitle' => $t('talent.builder_subtitle')]) ?>
<?php if ($resume !== null): ?>
<div class="row resume-actions">
  <?= $ui('button', ['label' => $t('talent.print'), 'type' => 'button', 'variant' => 'primary', 'icon' => 'printer', 'attrs' => ['data-print' => '1']]) ?>
  <a class="btn btn--ghost" href="#rb-form"><?= $e($t('talent.edit_again')) ?></a>
</div>
<p class="text-sm text-muted resume-actions"><?= $e($t('talent.print_help')) ?></p>
<article class="resume-sheet" aria-label="<?= $e($t('talent.resume')) ?>">
  <header class="resume-sheet__head">
    <h2 class="resume-sheet__name"><?= $e($resume['name'] !== '' ? $resume['name'] : '—') ?></h2>
    <?php if ($resume['headline'] !== ''): ?><p class="resume-sheet__headline"><?= $e($resume['headline']) ?></p><?php endif; ?>
    <p class="resume-sheet__contact"><?= $e(implode(' · ', array_filter([$resume['phone'], $resume['email'], $resume['location']], static fn (string $x): bool => $x !== ''))) ?></p>
  </header>
  <?php if ($resume['summary'] !== ''): ?><section><h3><?= $e($t('talent.rb.summary')) ?></h3><p class="pre-line"><?= $e($resume['summary']) ?></p></section><?php endif; ?>
  <?php if ($resume['work'] !== []): ?>
  <section><h3><?= $e($t('talent.rb.work')) ?></h3>
    <?php foreach ($resume['work'] as $w): ?>
      <div class="resume-sheet__item"><p><strong><?= $e($w['title']) ?></strong><?= $w['org'] !== '' ? ' · ' . $e($w['org']) : '' ?><span class="resume-sheet__when"><?= $e(trim($w['from'] . ' – ' . $w['to'], ' –')) ?></span></p>
        <?php if ($w['desc'] !== ''): ?><p class="pre-line"><?= $e($w['desc']) ?></p><?php endif; ?></div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
  <?php if ($resume['education'] !== []): ?>
  <section><h3><?= $e($t('talent.rb.education')) ?></h3>
    <?php foreach ($resume['education'] as $d): ?>
      <div class="resume-sheet__item"><p><strong><?= $e($d['degree']) ?></strong><?= $d['school'] !== '' ? ' · ' . $e($d['school']) : '' ?><span class="resume-sheet__when"><?= $e($d['year']) ?></span></p></div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
  <?php if ($resume['skills'] !== []): ?><section><h3><?= $e($t('talent.rb.skills')) ?></h3><ul class="resume-sheet__skills"><?php foreach ($resume['skills'] as $s): ?><li><?= $e($s) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
  <?php if ($resume['languages'] !== ''): ?><section><h3><?= $e($t('talent.rb.languages')) ?></h3><p><?= $e($resume['languages']) ?></p></section><?php endif; ?>
</article>
<?php endif; ?>
<form method="post" action="<?= $e($url('/talent/builder')) ?>" id="rb-form" class="card stack resume-form" novalidate>
  <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
  <p class="text-sm text-muted"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('talent.builder_privacy')) ?></p>
  <div class="grid grid--2">
    <?= $ui('field', ['name' => 'name', 'label' => $t('talent.rb.name'), 'required' => true, 'value' => $o('name')]) ?>
    <?= $ui('field', ['name' => 'headline', 'label' => $t('talent.rb.headline'), 'value' => $o('headline'), 'placeholder' => $t('talent.rb.headline_hint')]) ?>
    <?= $ui('field', ['name' => 'phone', 'type' => 'tel', 'label' => $t('talent.rb.phone'), 'value' => $o('phone'), 'inputmode' => 'tel']) ?>
    <?= $ui('field', ['name' => 'email', 'type' => 'email', 'label' => $t('talent.rb.email'), 'value' => $o('email')]) ?>
    <?= $ui('field', ['name' => 'location', 'label' => $t('talent.rb.location'), 'value' => $o('location')]) ?>
  </div>
  <?= $ui('field', ['name' => 'summary', 'type' => 'textarea', 'label' => $t('talent.rb.summary'), 'value' => $o('summary'), 'help' => $t('talent.rb.summary_help')]) ?>
  <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('talent.rb.work')) ?></legend>
    <?php for ($i = 0; $i < ResumeBuilder::WORK_ROWS; $i++): ?>
      <div class="grid grid--4">
        <?= $ui('field', ['name' => 'work_title_' . $i, 'id' => 'rb-wt' . $i, 'label' => $t('talent.rb.work_title', ['n' => (string) ($i + 1)]), 'value' => $o('work_title_' . $i)]) ?>
        <?= $ui('field', ['name' => 'work_org_' . $i, 'id' => 'rb-wo' . $i, 'label' => $t('talent.rb.work_org'), 'value' => $o('work_org_' . $i)]) ?>
        <?= $ui('field', ['name' => 'work_from_' . $i, 'id' => 'rb-wf' . $i, 'label' => $t('talent.rb.from'), 'value' => $o('work_from_' . $i), 'placeholder' => '2020']) ?>
        <?= $ui('field', ['name' => 'work_to_' . $i, 'id' => 'rb-wu' . $i, 'label' => $t('talent.rb.to'), 'value' => $o('work_to_' . $i), 'placeholder' => $t('talent.rb.now')]) ?>
      </div>
      <?= $ui('field', ['name' => 'work_desc_' . $i, 'id' => 'rb-wd' . $i, 'type' => 'textarea', 'label' => $t('talent.rb.work_desc'), 'value' => $o('work_desc_' . $i)]) ?>
    <?php endfor; ?>
  </fieldset>
  <fieldset class="stack-sm"><legend class="card__title"><?= $e($t('talent.rb.education')) ?></legend>
    <?php for ($i = 0; $i < ResumeBuilder::EDU_ROWS; $i++): ?>
      <div class="grid grid--3">
        <?= $ui('field', ['name' => 'edu_degree_' . $i, 'id' => 'rb-ed' . $i, 'label' => $t('talent.rb.degree', ['n' => (string) ($i + 1)]), 'value' => $o('edu_degree_' . $i)]) ?>
        <?= $ui('field', ['name' => 'edu_school_' . $i, 'id' => 'rb-es' . $i, 'label' => $t('talent.rb.school'), 'value' => $o('edu_school_' . $i)]) ?>
        <?= $ui('field', ['name' => 'edu_year_' . $i, 'id' => 'rb-ey' . $i, 'label' => $t('talent.rb.year'), 'value' => $o('edu_year_' . $i)]) ?>
      </div>
    <?php endfor; ?>
  </fieldset>
  <?= $ui('field', ['name' => 'skills', 'type' => 'textarea', 'label' => $t('talent.rb.skills'), 'value' => $o('skills'), 'help' => $t('talent.rb.skills_help')]) ?>
  <?= $ui('field', ['name' => 'languages', 'label' => $t('talent.rb.languages'), 'value' => $o('languages'), 'placeholder' => $t('talent.rb.languages_hint')]) ?>
  <div class="row"><?= $ui('button', ['label' => $t('talent.rb.make'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'file-text']) ?></div>
</form>
<script src="<?= $e($asset('ui/js/print.js')) ?>" defer></script>
