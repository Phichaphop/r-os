<?php
/**
 * S01 tool page, laid out like ILovePDF (0.39.0, item 8.1): tools in groups as coloured cards, then the results the
 * person saved. Tools this host cannot offer are folded away with the reason (never faked).
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, array<string, mixed>> $features @var array<string, int> $limits @var list<array<string, mixed>> $jobs @var bool $canManage
 */
use Blue\Modules\Pdf\PdfFeatures;

$href = static fn (string $code): string => $code === 'sign' ? '/pdf/sign' : '/pdf/tool/' . $code;
$off = [];
?>
<?= $ui('page-header', ['title' => $t('pdf.title'), 'subtitle' => $t('pdf.subtitle'),
    'actions' => $canManage ? $ui('button', ['label' => $t('pdf.admin.title'), 'href' => $url('/pdf/admin'), 'variant' => 'secondary', 'icon' => 'settings']) : '']) ?>
<?= $ui('alert', ['tone' => 'info', 'title' => $t('pdf.local_title'), 'text' => $t('pdf.local_text', ['minutes' => (string) $limits['result_minutes'], 'mb' => (string) $limits['max_mb'], 'pages' => (string) $limits['max_pages']])]) ?>
<?php foreach (PdfFeatures::GROUPS as $group => $codes): $shown = array_values(array_filter($codes, static fn (string $c): bool => ($features[$c]['state'] ?? '') !== 'unavailable')); ?>
  <?php foreach (array_diff($codes, $shown) as $c): if (isset($features[$c])) { $off[$c] = $features[$c]; } endforeach; ?>
  <?php if ($shown === []) { continue; } ?>
<section class="stack-sm pdf-group pdf-group--<?= $e($group) ?>" aria-labelledby="pg-<?= $e($group) ?>">
  <h2 id="pg-<?= $e($group) ?>" class="pdf-group__title"><?= $e($t('pdf.group.' . $group)) ?></h2>
  <ul class="pdf-tools" role="list">
    <?php foreach ($shown as $code): $f = $features[$code]; $ok = $f['state'] === 'available'; ?>
      <li>
      <?php if ($ok): ?>
        <a class="pdf-tool" href="<?= $e($url($href($code))) ?>">
      <?php else: ?>
        <div class="pdf-tool is-off" aria-disabled="true">
      <?php endif; ?>
          <span class="pdf-tool__icon" aria-hidden="true"><?= $ui('icon', ['name' => PdfFeatures::ICONS[$code] ?? 'file-type-pdf']) ?></span>
          <span class="pdf-tool__name"><?= $e($t('pdf.tool.' . $code)) ?></span>
          <span class="pdf-tool__desc"><?= $e($t('pdf.about.' . $code)) ?></span>
          <?php if (!empty($f['client'])): ?><span class="pdf-tool__tag"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('pdf.browser_only')) ?></span><?php endif; ?>
          <?php if (!$ok): ?><span class="pdf-tool__tag"><?= $ui('icon', ['name' => 'lock', 'size' => 'sm']) ?> <?= $e($t('pdf.state.' . $f['state'])) ?></span><?php endif; ?>
      <?= $ok ? '</a>' : '</div>' ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php if ($off !== []): ?>
<details class="card stack-sm pdf-unavailable">
  <summary><?= $e($t('pdf.unavailable_title', ['n' => (string) count($off)])) ?></summary>
  <ul class="plain-list stack-sm">
    <?php foreach ($off as $code => $f): ?>
      <li><strong><?= $e($t('pdf.tool.' . $code)) ?></strong> — <span class="text-sm text-muted"><?= $e($f['reason'] !== null ? $t((string) $f['reason'], ['ext' => (string) $f['missing']]) : $t('pdf.state_text.disabled')) ?></span></li>
    <?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>
<section class="card stack-sm" aria-labelledby="pj-h">
  <h2 id="pj-h" class="card__title"><?= $e($t('pdf.my_jobs')) ?></h2>
  <p class="text-sm text-muted"><?= $e($t('pdf.my_jobs_help')) ?></p>
  <?php if ($jobs === []): ?>
    <p class="text-sm text-muted"><?= $e($t('pdf.no_jobs')) ?></p>
  <?php else: ?>
  <div class="table-wrap" role="region" aria-labelledby="pj-h" tabindex="0">
    <table class="table table--stack">
      <thead><tr><th scope="col"><?= $e($t('pdf.col.tool')) ?></th><th scope="col"><?= $e($t('pdf.col.record')) ?></th><th scope="col"><?= $e($t('pdf.col.saved')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($jobs as $j): ?>
        <tr>
          <td data-label="<?= $e($t('pdf.col.tool')) ?>"><a href="<?= $e($url('/pdf/job/' . $j['public_id'])) ?>"><?= $e($t('pdf.tool.' . $j['operation'])) ?></a></td>
          <td data-label="<?= $e($t('pdf.col.record')) ?>">
            <?php foreach ((array) json_decode((string) $j['outputs_json'], true) as $o): if (($o['document'] ?? null) === null) { continue; } ?>
              <a class="d-block break-all" href="<?= $e($url('/documents/' . $o['document'])) ?>"><?= $ui('icon', ['name' => 'file-text', 'size' => 'sm']) ?> <?= $e((string) $o['name']) ?></a>
            <?php endforeach; ?>
          </td>
          <td data-label="<?= $e($t('pdf.col.saved')) ?>"><?= $e($date((string) $j['saved_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
