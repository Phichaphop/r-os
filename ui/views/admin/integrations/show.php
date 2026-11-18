<?php
/**
 * @var callable $e @var callable $t @var callable $ui @var callable $url @var callable $date @var array<string, mixed> $v
 * @var array<string, mixed> $i @var array{rows: list<array<string, mixed>>, total: int} $logs @var string $status @var int $page @var int $per
 * @var bool $canManage @var bool $canKeys @var ?array<string, string> $secret
 */
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf'] ?? '') . '">';
$base = '/admin/integrations/' . $i['public_id'];
$rows = ['code' => $i['code'], 'direction' => $t('integration.direction.' . $i['direction']), 'system_type' => $i['system_type'], 'owner' => $i['owner_name'],
    'auth_method' => $t('integration.auth.' . $i['auth_method']), 'scopes' => $i['scopes'], 'data_classification' => $t('document.classification.' . $i['data_classification']),
    'schema_version' => $i['schema_version'], 'endpoint' => $i['endpoint'], 'rate_limit_per_min' => $i['rate_limit_per_min'], 'timeout_seconds' => $i['timeout_seconds'],
    'retry_policy' => $i['retry_policy'], 'idempotency' => $i['idempotency'], 'monitoring' => $i['monitoring'], 'incident_contact' => $i['incident_contact'],
    'cost_note' => $i['cost_note'], 'privacy_review' => $t('integration.privacy.' . $i['privacy_review']) . ($i['privacy_ref'] !== null ? ' · ' . $i['privacy_ref'] : ''),
    'status' => $t('integration.status.' . $i['status']), 'notes' => $i['notes']];
$ls = ['success' => ['success', 'circle-check'], 'failed' => ['danger', 'alert-triangle'], 'rejected' => ['danger', 'lock'], 'retrying' => ['warning', 'refresh'], 'dead' => ['danger', 'circle-x']];
?>
<?= $ui('page-header', ['title' => (string) $i['name'], 'subtitle' => (string) $i['code'], 'crumbs' => [[$t('integration.title'), $url('/admin/integrations')], [(string) $i['code'], null]],
    'actions' => $canManage ? $ui('button', ['label' => $t('common.edit'), 'href' => $url($base . '/edit'), 'icon' => 'pencil']) : '']) ?>
<?php if ($secret !== null): ?>
<section class="card" aria-labelledby="sec-h">
  <h2 id="sec-h" class="card__title"><?= $e($t('integration.secret.title')) ?></h2>
  <?= $ui('alert', ['tone' => 'warning', 'text' => $t('integration.secret.once')]) ?>
  <p><?= $e($t('integration.secret.header')) ?></p>
  <div class="code-box" id="secret-value">X-ROS-Key: <?= $e($secret['key_id'] . '.' . $secret['secret']) ?></div>
  <p class="row"><?= $ui('button', ['label' => $t('integration.secret.copy'), 'icon' => 'copy', 'attrs' => ['data-copy-target' => 'secret-value']]) ?></p>
  <p class="text-sm text-muted"><?= $e($t('integration.secret.hmac_note', ['key' => $secret['key_id']])) ?></p>
</section>
<?php endif; ?>
<div class="grid grid--2">
  <section class="card">
    <dl class="dl">
      <?php foreach ($rows as $k => $val): if ($val === null || $val === '') { continue; } ?>
        <dt><?= $e($t('integration.field.' . $k)) ?></dt><dd<?= in_array($k, ['code', 'endpoint'], true) ? ' class="mono"' : '' ?>><?= $e((string) $val) ?></dd>
      <?php endforeach; ?>
    </dl>
  </section>
  <section class="card" aria-labelledby="keys-h">
    <h2 id="keys-h" class="card__title"><?= $e($t('integration.keys')) ?></h2>
    <?php if (in_array($i['auth_method'], ['api_key', 'hmac'], true)): ?>
      <ul class="stack-sm">
      <?php foreach ($i['credentials'] as $c): ?>
        <li><span class="mono"><?= $e($c['key_id']) ?></span> ···<?= $e($c['last4']) ?> · <?= $e($t('integration.key_status.' . $c['status'])) ?>
          <div class="text-sm text-muted"><?= $e($t('integration.key_meta', ['created' => $date($c['created_at']), 'by' => (string) ($c['created_name'] ?? ''), 'used' => $c['last_used_at'] !== null ? $date($c['last_used_at']) : '—',
              'expires' => $c['expires_at'] !== null ? $date($c['expires_at']) : $t('integration.no_expiry')])) ?></div>
          <?php if ($c['status'] === 'revoked'): ?><div class="text-sm"><?= $e($t('iam.field.reason')) ?>: <?= $e((string) $c['revoke_reason']) ?></div><?php endif; ?>
          <?php if ($canKeys && $c['status'] === 'active'): ?>
            <details><summary><?= $e($t('integration.revoke')) ?></summary>
              <form method="post" action="<?= $e($url($base . '/keys/' . $c['public_id'] . '/revoke')) ?>" class="inline-form"><?= $csrf ?>
                <label class="sr-only" for="rv-<?= $e($c['public_id']) ?>"><?= $e($t('iam.field.reason')) ?></label>
                <input class="input" id="rv-<?= $e($c['public_id']) ?>" name="reason" required placeholder="<?= $e($t('iam.field.reason')) ?>">
                <?= $ui('button', ['label' => $t('integration.revoke'), 'type' => 'submit', 'size' => 'sm', 'variant' => 'danger']) ?>
              </form></details>
          <?php endif; ?></li>
      <?php endforeach; ?>
      <?php if ($i['credentials'] === []): ?><li class="text-muted"><?= $e($t('integration.no_keys')) ?></li><?php endif; ?>
      </ul>
      <?php if ($canKeys): ?>
      <form method="post" action="<?= $e($url($base . '/keys')) ?>" class="form-grid"><?= $csrf ?><input type="hidden" name="_back" value="<?= $e($base) ?>">
        <?= $ui('field', ['name' => 'valid_days', 'label' => $t('integration.valid_days'), 'type' => 'number', 'value' => '365', 'help' => $t('integration.valid_days_help')]) ?>
        <div class="row field--full"><?= $ui('button', ['label' => $t('integration.issue'), 'type' => 'submit', 'variant' => 'primary', 'icon' => 'key']) ?></div>
        <p class="text-sm text-muted field--full"><?= $e($t('integration.rotate_help')) ?></p>
      </form>
      <?php endif; ?>
    <?php else: ?><p class="text-muted"><?= $e($t('integration.no_keys_method')) ?></p><?php endif; ?>
  </section>
</div>
<section class="card" aria-labelledby="log-h">
  <h2 id="log-h" class="card__title"><?= $e($t('integration.log')) ?></h2>
  <form method="get" action="<?= $e($url($base)) ?>" class="filter-bar">
    <?= $ui('field', ['name' => 'status', 'label' => $t('integration.field.status'), 'type' => 'select', 'value' => $status,
        'options' => ['' => $t('common.all')] + array_combine(array_keys($ls), array_map(static fn ($s) => $t('integration.log_status.' . $s), array_keys($ls)))]) ?>
    <?= $ui('button', ['label' => $t('common.filter'), 'type' => 'submit', 'icon' => 'filter']) ?>
  </form>
  <div class="table-wrap" role="region" aria-label="<?= $e($t('integration.log')) ?>" tabindex="0">
    <table class="table table--stack"><caption class="sr-only"><?= $e($t('integration.log')) ?></caption>
      <thead><tr><th scope="col"><?= $e($t('integration.log_field.at')) ?></th><th scope="col"><?= $e($t('integration.log_field.operation')) ?></th><th scope="col"><?= $e($t('integration.field.status')) ?></th>
        <th scope="col"><?= $e($t('integration.log_field.detail')) ?></th><th scope="col" class="num"><?= $e($t('integration.log_field.ms')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($logs['rows'] as $l): [$tone, $icon] = $ls[$l['status']] ?? ['neutral', 'info-circle']; ?>
        <tr><td data-label="<?= $e($t('integration.log_field.at')) ?>"><?= $e($date($l['occurred_at'])) ?></td>
          <td data-label="<?= $e($t('integration.log_field.operation')) ?>" class="mono"><?= $e($l['operation']) ?></td>
          <td data-label="<?= $e($t('integration.field.status')) ?>"><?= $ui('badge', ['tone' => $tone, 'icon' => $icon, 'label' => $t('integration.log_status.' . $l['status'])]) ?></td>
          <td data-label="<?= $e($t('integration.log_field.detail')) ?>" class="text-sm"><?= $e(trim(($l['http_status'] !== null ? 'HTTP ' . $l['http_status'] . ' ' : '') . ($l['error_code'] ?? '') . ($l['records'] !== null ? ' · ' . $l['records'] . ' ' . $t('integration.records') : '') . ($l['key_id'] !== null ? ' · ' . $l['key_id'] : ''))) ?></td>
          <td data-label="<?= $e($t('integration.log_field.ms')) ?>" class="num"><?= $l['duration_ms'] !== null ? (int) $l['duration_ms'] : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if ($logs['rows'] === []): ?><tr><td colspan="5"><?= $ui('states', ['icon' => 'history', 'title' => $t('integration.log_empty'), 'text' => '']) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= $ui('pagination', ['path' => $base, 'query' => array_filter(['status' => $status]), 'page' => $page, 'per' => $per, 'total' => $logs['total']]) ?>
</section>
