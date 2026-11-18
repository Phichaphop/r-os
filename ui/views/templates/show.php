<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $tpl @var bool $manage @var bool $canIssue @var array<string, mixed> $draft
 * @var list<array<string, mixed>> $issueFields @var array{legal_entity: array<int,string>, site: array<int,string>} $options
 * @var array<string, string> $errors @var array<string, mixed> $issueIn @var list<string> $systemFields
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$pub = $tpl['published'];
$versions = $tpl['versions'];
?>
<?= $ui('page-header', ['title' => (string) $tpl['name'], 'subtitle' => $t('template.subtitle_show', ['code' => (string) $tpl['code']]),
    'crumbs' => [[$t('template.title'), $url('/templates')], [(string) $tpl['name'], null]],
    'actions' => $pub !== null ? $ui('button', ['label' => $t('template.preview_published'), 'href' => $url('/templates/versions/' . $pub['public_id'] . '/preview'), 'variant' => 'secondary', 'icon' => 'eye', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) : '']) ?>
<?php if (isset($errors['issue'])): ?><?= $ui('alert', ['tone' => 'danger', 'text' => $errors['issue'], 'live' => true]) ?><?php endif; ?>

<?php if ($canIssue): ?>
<section class="card" id="issue" aria-labelledby="issue-h">
  <h2 id="issue-h" class="card__title"><?= $e($t('template.issue.title', ['n' => (int) $pub['version_no']])) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('template.issue.help')) ?></p>
  <form method="post" action="<?= $e($url('/templates/' . $tpl['public_id'] . '/issue')) ?>" class="form-grid" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'legal_entity', 'label' => $t('document.field.company'), 'type' => 'select', 'required' => true, 'value' => (string) ($issueIn['legal_entity'] ?? ''), 'options' => $options['legal_entity']]) ?>
    <?= $ui('field', ['name' => 'site', 'label' => $t('document.field.site'), 'type' => 'select', 'value' => (string) ($issueIn['site'] ?? ''), 'options' => ['' => '—'] + $options['site']]) ?>
    <?= $ui('field', ['name' => 'doc_title', 'label' => $t('template.issue.doc_title'), 'required' => true, 'full' => true, 'value' => (string) ($issueIn['doc_title'] ?? $pub['title']), 'error' => $errors['title'] ?? null]) ?>
    <?php foreach ($issueFields as $f): $k = 'f_' . $f['key']; ?>
      <?= $ui('field', ['name' => $k, 'label' => (string) $f['label'], 'required' => !empty($f['required']), 'full' => $f['type'] === 'multiline',
          'type' => ['multiline' => 'textarea', 'date' => 'date', 'number' => 'text', 'text' => 'text'][$f['type']] ?? 'text',
          'inputmode' => $f['type'] === 'number' ? 'decimal' : null, 'value' => (string) ($issueIn[$k] ?? ''), 'error' => $errors[$k] ?? null]) ?>
    <?php endforeach; ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('template.issue.button'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'file-text']) ?></div>
    <p class="text-sm text-muted field--full"><?= $e($t('template.issue.freeze_note')) ?></p>
  </form>
</section>
<?php elseif ($pub === null): ?>
  <?= $ui('alert', ['tone' => 'info', 'text' => $t('template.not_published_help')]) ?>
<?php endif; ?>

<section class="card" aria-labelledby="ver-h">
  <h2 id="ver-h" class="card__title"><?= $e($t('template.versions')) ?></h2>
  <?php if ($manage && count($versions) > 1): ?>
  <form method="get" action="<?= $e($url('/templates/' . $tpl['public_id'] . '/compare')) ?>" class="filter-bar">
    <?php $opts = array_column(array_map(static fn ($x) => ['k' => $x['public_id'], 'l' => 'v' . $x['version_no'] . ' · ' . $t('template.status.' . $x['status'])], $versions), 'l', 'k'); ?>
    <?= $ui('field', ['name' => 'from', 'label' => $t('template.compare.from'), 'type' => 'select', 'value' => (string) ($versions[1]['public_id'] ?? ''), 'options' => $opts]) ?>
    <?= $ui('field', ['name' => 'to', 'label' => $t('template.compare.to'), 'type' => 'select', 'value' => (string) $versions[0]['public_id'], 'options' => $opts]) ?>
    <?= $ui('button', ['label' => $t('template.compare.go'), 'type' => 'submit', 'icon' => 'arrows-sort']) ?>
  </form>
  <?php endif; ?>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('template.versions')) ?>" tabindex="0">
    <table class="table table--stack">
      <caption class="sr-only"><?= $e($t('template.versions')) ?></caption>
      <thead><tr><th scope="col">#</th><th scope="col"><?= $e($t('template.field.status')) ?></th><th scope="col"><?= $e($t('template.field.reason')) ?></th>
        <th scope="col"><?= $e($t('template.field.published_at')) ?></th><th scope="col"><?= $e($t('template.field.preview')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($versions as $ver): if ($ver['status'] === 'discarded' || ($ver['status'] === 'draft' && !$manage)) { continue; } ?>
        <tr>
          <td data-label="#" class="num">v<?= (int) $ver['version_no'] ?></td>
          <td data-label="<?= $e($t('template.field.status')) ?>"><?= $ui('badge', ['tone' => ['published' => 'success', 'draft' => 'info', 'superseded' => 'neutral'][$ver['status']] ?? 'neutral',
              'icon' => ['published' => 'circle-check', 'draft' => 'pencil', 'superseded' => 'history'][$ver['status']] ?? 'info-circle', 'label' => $t('template.status.' . $ver['status'])]) ?></td>
          <td data-label="<?= $e($t('template.field.reason')) ?>"><?= $e($ver['change_reason'] ?? '—') ?></td>
          <td data-label="<?= $e($t('template.field.published_at')) ?>"><?= $e($date($ver['published_at'])) ?><?= $ver['published_name'] !== null ? '<div class="text-sm text-muted">' . $e($ver['published_name']) . '</div>' : '' ?></td>
          <td data-label="<?= $e($t('template.field.preview')) ?>"><a href="<?= $e($url('/templates/versions/' . $ver['public_id'] . '/preview')) ?>" target="_blank" rel="noopener"><?= $e($t('template.open_preview')) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($manage): ?>
<section class="card" id="editor" aria-labelledby="ed-h">
  <h2 id="ed-h" class="card__title"><?= $e($t($tpl['draft'] !== null ? 'template.editor.draft' : 'template.editor.new_draft')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('template.editor.help')) ?></p>
  <?php if (array_intersect_key($errors, array_flip(['title', 'body', 'fields', 'font_family'])) !== []): ?><?= $ui('alert', ['tone' => 'danger', 'title' => $t('form.has_errors'), 'live' => true]) ?><?php endif; ?>
  <form method="post" action="<?= $e($url('/templates/' . $tpl['public_id'] . '/draft')) ?>" class="form-grid" novalidate><?= $csrf ?>
    <?= $ui('field', ['name' => 'title', 'label' => $t('template.field.title'), 'required' => true, 'value' => (string) $draft['title'], 'error' => $errors['title'] ?? null]) ?>
    <?= $ui('field', ['name' => 'font_family', 'label' => $t('template.field.font'), 'value' => (string) $draft['font_family'], 'error' => $errors['font_family'] ?? null, 'help' => $t('template.help.font')]) ?>
    <?= $ui('field', ['name' => 'fields', 'label' => $t('template.field.fields'), 'type' => 'textarea', 'full' => true, 'value' => (string) $draft['fields'], 'error' => $errors['fields'] ?? null, 'help' => $t('template.help.fields')]) ?>
    <?= $ui('field', ['name' => 'body', 'label' => $t('template.field.body'), 'type' => 'textarea', 'full' => true, 'required' => true, 'value' => (string) $draft['body'], 'error' => $errors['body'] ?? null,
        'help' => $t('template.help.body', ['system' => implode(', ', array_map(static fn ($s) => '{{' . $s . '}}', $systemFields))])]) ?>
    <fieldset class="field--full row">
      <legend class="sr-only"><?= $e($t('template.field.layout')) ?></legend>
      <label class="choice"><input type="checkbox" name="show_header" value="1"<?= !empty($draft['show_header']) ? ' checked' : '' ?>> <?= $e($t('template.field.show_header')) ?></label>
      <label class="choice"><input type="checkbox" name="show_footer" value="1"<?= !empty($draft['show_footer']) ? ' checked' : '' ?>> <?= $e($t('template.field.show_footer')) ?></label>
    </fieldset>
    <?= $ui('field', ['name' => 'reason', 'label' => $t('template.field.reason'), 'full' => true, 'value' => (string) $draft['reason']]) ?>
    <div class="row field--full"><?= $ui('button', ['label' => $t('template.editor.save'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'checks']) ?>
      <?php if ($tpl['draft'] !== null): ?><?= $ui('button', ['label' => $t('template.editor.preview'), 'href' => $url('/templates/versions/' . $tpl['draft']['public_id'] . '/preview'), 'variant' => 'secondary', 'icon' => 'eye', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) ?><?php endif; ?></div>
  </form>
  <?php if ($tpl['draft'] !== null): ?>
  <div class="grid grid--2">
    <form method="post" action="<?= $e($url('/templates/' . $tpl['public_id'] . '/publish')) ?>" class="stack-sm"><?= $csrf ?>
      <h3 class="card__title"><?= $e($t('template.publish.title', ['n' => (int) $tpl['draft']['version_no']])) ?></h3>
      <p class="text-sm text-muted"><?= $e($t('template.publish.help')) ?></p>
      <?= $ui('field', ['name' => 'reason', 'label' => $t('template.field.reason'), 'required' => true, 'value' => (string) ($tpl['draft']['change_reason'] ?? '')]) ?>
      <?= $ui('button', ['label' => $t('template.publish.button'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'circle-check']) ?>
    </form>
    <form method="post" action="<?= $e($url('/templates/' . $tpl['public_id'] . '/discard')) ?>" class="stack-sm"><?= $csrf ?>
      <h3 class="card__title"><?= $e($t('template.discard.title')) ?></h3>
      <p class="text-sm text-muted"><?= $e($t('template.discard.help')) ?></p>
      <?= $ui('button', ['label' => $t('template.discard.button'), 'type' => 'submit', 'variant' => 'ghost', 'icon' => 'trash']) ?>
    </form>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
