<?php
/**
 * HR: the company's surveys and a new one.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v @var array<string, string> $companies @var string $lePid
 * @var list<array<string, mixed>> $rows @var array{sites: array<string, string>, departments: array<string, string>} $audOptions @var array<string, mixed> $audIn
 * @var array<string, string> $errors @var array<string, mixed> $old @var string $here @var bool $canManage @var bool $canSurvey
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
?>
<?= $ui('page-header', ['title' => $t('kaizen.survey_admin_title'), 'subtitle' => $t('kaizen.survey_admin_subtitle')]) ?>
<?php include __DIR__ . '/_nav.php'; ?>
<?php include __DIR__ . '/_companies.php'; ?>
<section class="card card--flush" aria-labelledby="kz-sa">
  <div class="card__head"><h2 id="kz-sa" class="card__title"><?= $e($t('kaizen.surveys_n', ['n' => (string) count($rows)])) ?></h2></div>
  <?php if ($rows === []): ?><div class="card__pad"><?= $ui('states', ['icon' => 'clipboard-check', 'title' => $t('kaizen.survey_admin_none'), 'text' => $t('kaizen.survey_admin_none_help')]) ?></div><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="kz-sa" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('kaizen.field.survey_title')) ?></th><th scope="col"><?= $e($t('kaizen.col.window')) ?></th><th scope="col"><?= $e($t('kaizen.col.status')) ?></th><th scope="col"><?= $e($t('kaizen.col.answers')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): $sst = (string) $s['status']; ?>
        <tr><th scope="row" data-label="<?= $e($t('kaizen.field.survey_title')) ?>"><a href="<?= $e($url('/kaizen/surveys/m/' . $s['public_id'])) ?>"><?= $e((string) $s['title']) ?></a></th>
          <td data-label="<?= $e($t('kaizen.col.window')) ?>"><?= $e($date((string) $s['opens_on'], false) . ' – ' . $date((string) $s['closes_on'], false)) ?></td>
          <td data-label="<?= $e($t('kaizen.col.status')) ?>"><?php include __DIR__ . '/_sstatus.php'; ?></td>
          <td data-label="<?= $e($t('kaizen.col.answers')) ?>"><?= (int) $s['answers'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack-sm" aria-labelledby="kz-sn">
  <h2 id="kz-sn" class="card__title"><?= $e($t('kaizen.new_survey')) ?></h2>
  <?php if ($errors !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors')]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/kaizen/surveys/admin')) ?>" class="stack-sm" novalidate><?= $csrf ?><input type="hidden" name="le" value="<?= $e($lePid) ?>">
    <?php $sv = $old === [] ? ['anonymous' => '1'] : $old; $serr = $errors; include __DIR__ . '/_survey_fields.php'; ?>
    <div class="row"><?= $ui('button', ['label' => $t('kaizen.create_survey'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'plus']) ?></div>
  </form>
</section>
