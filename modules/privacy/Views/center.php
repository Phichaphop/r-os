<?php
/**
 * Privacy centre (privacy.manage): notices per audience · record of processing activities · breach log.
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var list<array{id: int, code: string, name: string}> $companies @var int $le @var string $leCode @var string $tab
 * @var list<array<string, mixed>> $notices @var list<array<string, mixed>> $activities @var list<array<string, mixed>> $breaches
 * @var bool $canApprove @var int|null $hours @var array<string, string> $errors @var array<string, mixed> $old
 */
use Blue\Modules\Privacy\Templates;

$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '"><input type="hidden" name="le" value="' . $le . '">';
$q = static fn (string $tb): string => '/privacy/center?tab=' . $tb . '&le=' . $le;
$tone = ['draft' => 'neutral', 'pending' => 'warning', 'published' => 'success', 'active' => 'success', 'retired' => 'neutral', 'open' => 'warning', 'closed' => 'neutral'];
$series = [];
foreach ($notices as $n) {
    $series[(string) $n['audience']][] = $n;
}
?>
<?= $ui('page-header', ['title' => $t('privacy.center.title'), 'subtitle' => $t('privacy.center.subtitle'),
    'actions' => $ui('button', ['label' => $t('privacy.center.dsr'), 'href' => $url('/privacy/requests'), 'variant' => 'secondary', 'icon' => 'inbox'])]) ?>
<?php if (count($companies) > 1): ?>
<nav class="segmented-links" aria-label="<?= $e($t('privacy.center.company')) ?>">
  <?php foreach ($companies as $c): ?><a href="<?= $e($url('/privacy/center?tab=' . $tab . '&le=' . $c['id'])) ?>"<?= $c['id'] === $le ? ' aria-current="page"' : '' ?>><?= $ui('icon', ['name' => 'building', 'size' => 'sm']) ?> <?= $e($c['code']) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<nav class="segmented-links" aria-label="<?= $e($t('privacy.center.sections')) ?>">
  <?php foreach (['notices', 'activities', 'breaches'] as $tb): ?>
    <a href="<?= $e($url($q($tb))) ?>"<?= $tab === $tb ? ' aria-current="page"' : '' ?>><?= $e($t('privacy.center.tab.' . $tb)) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'notices'): ?>
<?= $ui('alert', ['tone' => 'info', 'text' => $t('privacy.center.notices_help')]) ?>
<section class="card stack-sm" aria-labelledby="pn-h">
  <h2 id="pn-h" class="card__title"><?= $e($t('privacy.center.notices')) ?></h2>
  <div class="table-wrap" role="region" aria-labelledby="pn-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('privacy.field.audience')) ?></th><th scope="col"><?= $e($t('privacy.field.published')) ?></th><th scope="col"><?= $e($t('privacy.field.working')) ?></th><th scope="col"><?= $e($t('privacy.field.action')) ?></th></tr></thead>
      <tbody>
      <?php foreach (Templates::AUDIENCES as $au):
          $pub = null;
          $work = null;
          foreach ($series[$au] ?? [] as $n) {
              if ($n['status'] === 'published') { $pub = $n; } elseif ($work === null) { $work = $n; }
          } ?>
        <tr>
          <th scope="row" data-label="<?= $e($t('privacy.field.audience')) ?>"><?= $e($t('privacy.audience.' . $au)) ?></th>
          <td data-label="<?= $e($t('privacy.field.published')) ?>">
            <?php if ($pub !== null): ?>
              <a href="<?= $e($url('/privacy/center/n/' . $pub['public_id'])) ?>"><?= $e($t('privacy.version_n', ['n' => (string) $pub['version']])) ?></a>
              · <a href="<?= $e($url('/privacy-notice/' . rawurlencode($leCode) . '/' . $au)) ?>" target="_blank" rel="noopener"><?= $e($t('privacy.public_link')) ?><span class="sr-only"> (<?= $e($t('home.new_tab')) ?>)</span></a>
              <?php if ((int) $pub['requires_ack'] === 1): ?> · <?= $e($t('privacy.acked_n', ['n' => (string) $pub['acks']])) ?><?php endif; ?>
            <?php else: ?><span class="text-muted"><?= $e($t('privacy.none_published')) ?></span><?php endif; ?>
          </td>
          <td data-label="<?= $e($t('privacy.field.working')) ?>">
            <?php if ($work !== null): ?>
              <a href="<?= $e($url('/privacy/center/n/' . $work['public_id'])) ?>"><?= $e($t('privacy.version_n', ['n' => (string) $work['version']])) ?></a>
              <?= $ui('badge', ['tone' => $tone[$work['status']] ?? 'neutral', 'label' => $t('privacy.status.' . $work['status'])]) ?>
              <?php if ((int) $work['ai_draft'] === 1): ?><?= $ui('badge', ['tone' => 'warning', 'label' => $t('privacy.ai_label')]) ?><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td data-label="<?= $e($t('privacy.field.action')) ?>">
            <?php if ($work === null): ?>
              <form method="post" action="<?= $e($url('/privacy/center/notices')) ?>" class="row">
                <?= $csrf ?><input type="hidden" name="audience" value="<?= $e($au) ?>">
                <?php if ($pub !== null): ?>
                  <?= $ui('button', ['label' => $t('privacy.new_version'), 'type' => 'submit', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'pencil']) ?>
                <?php endif; ?>
                <?= $ui('button', ['label' => $t('privacy.from_template'), 'type' => 'submit', 'variant' => $pub === null ? 'primary' : 'ghost', 'size' => 'sm', 'icon' => 'plus', 'attrs' => ['name' => 'template', 'value' => '1']]) ?>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php elseif ($tab === 'activities'): ?>
<?= $ui('alert', ['tone' => 'info', 'text' => $t('privacy.center.activities_help')]) ?>
<section class="card stack-sm" aria-labelledby="pa-h">
  <h2 id="pa-h" class="card__title"><?= $e($t('privacy.center.activities')) ?></h2>
  <div class="row">
    <form method="post" action="<?= $e($url('/privacy/center/activities/seed')) ?>"><?= $csrf ?><?= $ui('button', ['label' => $t('privacy.seed'), 'type' => 'submit', 'variant' => 'secondary', 'icon' => 'plus']) ?></form>
    <?= $ui('button', ['label' => $t('privacy.activity.new'), 'href' => $url('/privacy/center/activities/new?le=' . $le), 'variant' => 'primary', 'icon' => 'plus']) ?>
  </div>
  <?php if ($activities === []): ?><p class="text-sm text-muted"><?= $e($t('privacy.activity.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="pa-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('privacy.field.code')) ?></th><th scope="col"><?= $e($t('privacy.field.name')) ?></th><th scope="col"><?= $e($t('privacy.field.legal_basis')) ?></th><th scope="col"><?= $e($t('privacy.field.retention')) ?></th><th scope="col"><?= $e($t('privacy.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($activities as $a): ?>
        <tr>
          <th scope="row" data-label="<?= $e($t('privacy.field.code')) ?>"><a href="<?= $e($url('/privacy/center/a/' . $a['public_id'])) ?>"><?= $e((string) $a['code']) ?></a></th>
          <td data-label="<?= $e($t('privacy.field.name')) ?>"><?= $e((string) $a['name']) ?><?= (int) $a['is_sensitive'] === 1 ? ' ' . $ui('badge', ['tone' => 'danger', 'label' => $t('privacy.sensitive')]) : '' ?></td>
          <td data-label="<?= $e($t('privacy.field.legal_basis')) ?>"><?= $a['legal_basis'] !== null ? $e($t('privacy.basis.' . $a['legal_basis'])) : '<span class="text-muted">' . $e($t('privacy.not_set')) . '</span>' ?></td>
          <td data-label="<?= $e($t('privacy.field.retention')) ?>"><?= $a['retention'] !== null ? $e((string) $a['retention']) : '<span class="text-muted">' . $e($t('privacy.not_set')) . '</span>' ?></td>
          <td data-label="<?= $e($t('privacy.field.status')) ?>"><?= $ui('badge', ['tone' => $tone[$a['status']] ?? 'neutral', 'label' => $t('privacy.status.' . $a['status'])]) ?><?= (int) $a['ai_draft'] === 1 ? ' ' . $ui('badge', ['tone' => 'warning', 'label' => $t('privacy.ai_label')]) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php else: ?>
<?= $ui('alert', ['tone' => $hours === null ? 'warning' : 'info', 'text' => $hours === null ? $t('privacy.breach.no_deadline') : $t('privacy.breach.deadline_set', ['h' => (string) $hours])]) ?>
<section class="card stack-sm" aria-labelledby="pb-h">
  <h2 id="pb-h" class="card__title"><?= $e($t('privacy.center.breaches')) ?></h2>
  <?php if ($breaches === []): ?><p class="text-sm text-muted"><?= $e($t('privacy.breach.none')) ?></p><?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="pb-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('privacy.field.ref')) ?></th><th scope="col"><?= $e($t('privacy.field.discovered_at')) ?></th><th scope="col"><?= $e($t('privacy.field.risk')) ?></th><th scope="col"><?= $e($t('privacy.field.decision')) ?></th><th scope="col"><?= $e($t('privacy.field.status')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($breaches as $b): ?>
        <tr>
          <th scope="row" data-label="<?= $e($t('privacy.field.ref')) ?>"><a href="<?= $e($url('/privacy/center/b/' . $b['public_id'])) ?>"><?= $e((string) $b['ref']) ?></a></th>
          <td data-label="<?= $e($t('privacy.field.discovered_at')) ?>"><?= $e($date((string) $b['discovered_at'])) ?></td>
          <td data-label="<?= $e($t('privacy.field.risk')) ?>"><?= $e($t('privacy.risk.' . $b['risk'])) ?></td>
          <td data-label="<?= $e($t('privacy.field.decision')) ?>"><?= $e($t('privacy.decision.' . $b['decision'])) ?></td>
          <td data-label="<?= $e($t('privacy.field.status')) ?>"><?= $ui('badge', ['tone' => $tone[$b['status']] ?? 'neutral', 'label' => $t('privacy.status.' . $b['status'])]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<section class="card stack" aria-labelledby="pbn-h">
  <h2 id="pbn-h" class="card__title"><?= $e($t('privacy.breach.new')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('privacy.breach.new_help')) ?></p>
  <form method="post" action="<?= $e($url('/privacy/center/breaches')) ?>" class="stack" novalidate>
    <?= $csrf ?>
    <div class="grid grid--2">
      <?= $ui('field', ['name' => 'discovered_at', 'id' => 'br-at', 'type' => 'datetime-local', 'label' => $t('privacy.field.discovered_at'), 'required' => true, 'value' => (string) ($old['discovered_at'] ?? ''), 'error' => $errors['discovered_at'] ?? null]) ?>
      <?= $ui('field', ['name' => 'people_estimate', 'id' => 'br-people', 'type' => 'number', 'label' => $t('privacy.field.people_estimate'), 'value' => (string) ($old['people_estimate'] ?? ''), 'error' => $errors['people_estimate'] ?? null]) ?>
    </div>
    <?= $ui('field', ['name' => 'summary', 'id' => 'br-summary', 'type' => 'textarea', 'label' => $t('privacy.field.summary'), 'required' => true, 'value' => (string) ($old['summary'] ?? ''), 'help' => $t('privacy.breach.summary_help'), 'error' => $errors['summary'] ?? null]) ?>
    <?= $ui('field', ['name' => 'data_categories', 'id' => 'br-cats', 'label' => $t('privacy.field.data_categories'), 'required' => true, 'value' => (string) ($old['data_categories'] ?? ''), 'error' => $errors['data_categories'] ?? null]) ?>
    <div class="row"><?= $ui('button', ['label' => $t('privacy.breach.record'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'alert-triangle']) ?></div>
  </form>
</section>
<?php endif; ?>
