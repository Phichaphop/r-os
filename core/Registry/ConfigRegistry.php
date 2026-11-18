<?php

declare(strict_types=1);

namespace Blue\Core\Registry;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Log\Logger;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Workflow\WorkflowSubject;

/**
 * Effective-dated Configuration Registry (6.25, 6.30.9).
 *
 * Timeline rule (the approved precedence — 6.25.1): within one key + scope, approved versions have distinct start
 * times; the value at time T is the approved version with the latest effective_from ≤ T whose window
 * [effective_from, effective_to) contains T. A version with an end date is therefore a temporary override of the
 * open-ended version before it. Windows with explicit end dates may not overlap anything (rejected).
 *
 * Inheritance: site → legal entity → organization (the resolved value reports which scope it came from).
 * Missing value → ConfigMissing (fail safe: no silent default) + log + audit alert.
 * Approved versions are never edited: changes are new versions; only a not-yet-effective version can be cancelled.
 */
final class ConfigRegistry implements WorkflowSubject
{
    public const LEVELS = ['site', 'legal_entity', 'organization'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly WorkflowEngine $workflow,
        private readonly Logger $logger,
    ) {
    }

    // ---- definitions --------------------------------------------------------------------------------

    /** Inserts catalogue definitions that do not exist yet (never changes existing ones). */
    /** @param array<string, array<string, mixed>> $modules module manifests — their `config` definitions are added too */
    public static function sync(Connection $db, Clock $clock, bool $withDemo = false, array $modules = []): int
    {
        $defs = ConfigCatalog::DEFINITIONS + ($withDemo ? ConfigCatalog::DEMO_DEFINITIONS : []);
        $owner = [];
        foreach ($modules as $m) {
            foreach ((array) ($m['config'] ?? []) as $key => $def) {
                $defs[(string) $key] = array_values((array) $def);
                $owner[(string) $key] = (string) $m['code'];
            }
        }
        $n = 0;
        foreach ($defs as $key => [$cat, $type, $unit, $levels, $rules, $risk, $source, $required]) {
            if ($db->scalar('SELECT id FROM config_definitions WHERE config_key = ?', [$key]) !== null) {
                continue;
            }
            $now = $clock->now()->format('Y-m-d H:i:s.u');
            $db->run(
                'INSERT INTO config_definitions (public_id, created_at, updated_at, config_key, module_code, category, data_type, unit, precision_digits,
                    allowed_scope_levels, validation_json, risk_level, source_type, is_required, is_demo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Ulid::generate($clock), $now, $now, $key, $owner[$key] ?? 'core', $cat, $type, $unit,
                    $rules['precision'] ?? null, $levels, count((array) $rules) === 0 ? null : json_encode($rules), $risk, $source, $required ? 1 : 0, $source === 'DEMO' ? 1 : 0]
            );
            $n++;
        }
        return $n;
    }

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        return $this->db->one('SELECT * FROM config_definitions WHERE config_key = ?', [$key]) ?? throw HttpException::notFound();
    }

    /** @return list<array<string, mixed>> */
    public function definitions(string $category = '', string $q = ''): array
    {
        $where = "status = 'active'";
        $params = [];
        if ($category !== '') {
            $where .= ' AND category = ?';
            $params[] = $category;
        }
        if ($q !== '') {
            $where .= ' AND config_key LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $rows = $this->db->all("SELECT * FROM config_definitions WHERE {$where} ORDER BY category, config_key", $params);
        $org = (int) ($this->db->scalar('SELECT id FROM organizations ORDER BY id LIMIT 1') ?? 0);
        foreach ($rows as &$r) {
            $r['current'] = $this->resolveOrNull((string) $r['config_key']);
            $r['pending'] = (int) $this->db->scalar("SELECT COUNT(*) FROM config_versions WHERE definition_id = ? AND status IN ('draft','in_review','returned')", [$r['id']]);
            $r['scheduled'] = (int) $this->db->scalar("SELECT COUNT(*) FROM config_versions WHERE definition_id = ? AND status = 'approved' AND effective_from > ?", [$r['id'], $this->now()]);
            $r['org_id'] = $org;
        }
        return $rows;
    }

    // ---- resolve ------------------------------------------------------------------------------------

    public function resolve(string $key, ?int $legalEntityId = null, ?int $siteId = null, ?DateTimeImmutable $at = null): ResolvedConfig
    {
        $r = $this->resolveOrNull($key, $legalEntityId, $siteId, $at);
        if ($r === null) {
            $this->logger->warning('config_missing', ['key' => $key, 'legal_entity_id' => $legalEntityId, 'site_id' => $siteId]);
            $this->audit->record('config.resolve_failed', 'failure', 'config', $key, $legalEntityId, 'missing value');
            throw new ConfigMissing($key);
        }
        return $r;
    }

    public function resolveOrNull(string $key, ?int $legalEntityId = null, ?int $siteId = null, ?DateTimeImmutable $at = null): ?ResolvedConfig
    {
        $def = $this->db->one('SELECT id, unit, allowed_scope_levels FROM config_definitions WHERE config_key = ?', [$key]);
        if ($def === null) {
            return null;
        }
        $at ??= $this->clock->now();
        $atStr = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        if ($siteId !== null && $legalEntityId === null) {
            $legalEntityId = (int) ($this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$siteId]) ?? 0) ?: null;
        }
        $orgId = (int) ($this->db->scalar('SELECT id FROM organizations ORDER BY id LIMIT 1') ?? 0);
        $chain = array_filter([['site', $siteId], ['legal_entity', $legalEntityId], ['organization', $orgId]], static fn (array $c): bool => $c[1] !== null && $c[1] !== 0);
        $path = [];
        foreach ($chain as [$level, $id]) {
            $path[] = $level . ':' . $id;
            $row = $this->db->one(
                "SELECT id, public_id, value_json, effective_from FROM config_versions
                  WHERE definition_id = ? AND scope_level = ? AND scope_id = ? AND status = 'approved'
                    AND effective_from <= ? AND (effective_to IS NULL OR effective_to > ?)
                  ORDER BY effective_from DESC LIMIT 1",
                [$def['id'], $level, $id, $atStr, $atStr]
            );
            if ($row !== null) {
                return new ResolvedConfig(
                    $key,
                    json_decode((string) $row['value_json'], true),
                    $def['unit'] !== null ? (string) $def['unit'] : null,
                    (int) $row['id'],
                    (string) $row['public_id'],
                    $level,
                    (int) $id,
                    (string) $row['effective_from'],
                    $atStr,
                    $path
                );
            }
        }
        return null;
    }

    /** @var array<string, array{propose: string, workflow: string, title?: string, describe?: string}> module-owned keys (module.json "config_owners") */
    private array $owners = [];

    private string $displayTz = 'UTC';

    /**
     * Keys owned by a module: proposed with the module's permission and approved through the module's route
     * (e.g. leave.policy — HR proposes, another HR approver checks) instead of core.config.propose / core.config.approve.
     *
     * @param array<string, array{propose: string, workflow: string, title?: string, describe?: string}> $owners
     */
    public function setOwners(array $owners, string $displayTz = 'UTC'): void
    {
        $this->owners = $owners;
        $this->displayTz = $displayTz;
    }

    /** @return array{propose: string, workflow: string, title?: string, describe?: string}|null */
    public function owner(string $key): ?array
    {
        return $this->owners[$key] ?? null;
    }

    // ---- propose / submit / decide ----------------------------------------------------------------

    /**
     * Creates a DRAFT version. Nothing changes until it is approved.
     *
     * @return string version public id
     */
    public function propose(Authorizer $az, string $key, string $scopeLevel, int $scopeId, string $rawValue, DateTimeImmutable $from, ?DateTimeImmutable $to, string $reason, string $sourceRef = ''): string
    {
        $def = $this->definition($key);
        $this->requireScope($az, $this->owners[$key]['propose'] ?? 'core.config.propose', $scopeLevel, $scopeId);
        $errors = [];
        if (!in_array($scopeLevel, explode(',', (string) $def['allowed_scope_levels']), true)) {
            $errors['scope'] = 'config.error.scope_not_allowed';
        }
        [$value, $err] = ConfigValue::normalize($def, $rawValue);
        if ($err !== null) {
            $errors['value'] = $err;
        }
        if ($to !== null && $to <= $from) {
            $errors['effective_to'] = 'config.error.dates';
        }
        if (trim($reason) === '') {
            $errors['reason'] = 'iam.error.reason_required';
        }
        if ($def['source_type'] === 'LAW' && trim($sourceRef) === '') {
            $errors['source_reference'] = 'config.error.source_required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->clock);
        $now = $this->now();
        $this->db->run(
            'INSERT INTO config_versions (public_id, created_at, created_by, updated_at, updated_by, definition_id, scope_level, scope_id, value_json,
                effective_from, effective_to, status, source_reference, reason, proposed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $def['id'], $scopeLevel, $scopeId, json_encode($value, JSON_UNESCAPED_UNICODE),
                $this->utc($from), $to !== null ? $this->utc($to) : null, $sourceRef !== '' ? $sourceRef : null, $reason, $az->userId]
        );
        $this->audit->record('config.proposed', 'success', 'config_version', $pid, $this->leOf($scopeLevel, $scopeId), $reason, ['key' => $key, 'value' => $value, 'scope' => "{$scopeLevel}:{$scopeId}"]);
        return $pid;
    }

    /** Sends a draft for approval (central workflow). Overlaps are refused here and again at approval. */
    public function submit(Authorizer $az, string $versionPid): string
    {
        $v = $this->version($versionPid);
        if ((int) $v['proposed_by'] !== $az->userId) {
            throw HttpException::forbidden();
        }
        if ($v['status'] !== 'draft') {
            throw new BusinessException('config.error.not_draft', [], 409);
        }
        $this->assertNoOverlap($v);
        $this->db->run("UPDATE config_versions SET status = 'in_review', submitted_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $this->now(), $v['id']]);
        $owner = $this->owners[(string) $v['config_key']] ?? null;
        $title = isset($owner['title']) && $owner['title'] !== ''
            ? $owner['title'] . ' · ' . (new \DateTimeImmutable((string) $v['effective_from'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($this->displayTz))->format('Y-m-d') // module-owned (JSON) values are explained on the approval page
            : (string) $v['config_key'] . ' = ' . mb_strimwidth(ConfigValue::display(json_decode((string) $v['value_json'], true)), 0, 60, '…'); // full value on the detail page
        $instance = $this->workflow->start($this->owners[(string) $v['config_key']]['workflow'] ?? 'core.config_version', $versionPid, $title, $this->leOf((string) $v['scope_level'], (int) $v['scope_id']), $az->userId);
        $this->db->run('UPDATE config_versions SET workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) WHERE id = ?', [$instance, $v['id']]);
        $this->audit->record('config.submitted', 'success', 'config_version', $versionPid);
        return $instance;
    }

    /**
     * Normal-risk keys (brand, theme …) may be applied directly by someone who can both propose and approve.
     * High-risk keys always go through maker-checker (6.25.1).
     */
    public function applyNow(Authorizer $az, string $key, string $rawValue, string $reason): string
    {
        $def = $this->definition($key);
        if ($def['risk_level'] !== 'normal') {
            throw new BusinessException('config.error.needs_checker', [], 403);
        }
        $org = (int) $this->db->scalar('SELECT id FROM organizations ORDER BY id LIMIT 1');
        $this->requireScope($az, 'core.config.approve', 'organization', $org);
        $from = $this->clock->now();
        $pid = $this->propose($az, $key, 'organization', $org, $rawValue, $from, null, $reason);
        $v = $this->version($pid);
        // an earlier version starting in the same microsecond (double click) — keep the first one
        $this->assertNoOverlap($v);
        $this->db->run(
            "UPDATE config_versions SET status = 'approved', submitted_at = ?, decided_by = ?, decided_at = ?, decision_comment = 'applied directly (normal risk)',
                updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'draft'",
            [$this->now(), $az->userId, $this->now(), $this->now(), $v['id']]
        );
        $this->audit->record('config.applied', 'success', 'config_version', $pid, null, $reason, ['key' => $key]);
        return $pid;
    }

    /** Cancels a draft (proposer) or an approved version that has not started yet (approver). History is kept. */
    public function cancel(Authorizer $az, string $versionPid, string $reason): void
    {
        $v = $this->version($versionPid);
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $isDraft = $v['status'] === 'draft' && (int) $v['proposed_by'] === $az->userId;
        $isScheduled = $v['status'] === 'approved' && (string) $v['effective_from'] > $this->now();
        if ($isScheduled) {
            $this->requireScope($az, 'core.config.approve', (string) $v['scope_level'], (int) $v['scope_id']);
        }
        if (!$isDraft && !$isScheduled) {
            throw new BusinessException('config.error.cannot_cancel', [], 409);
        }
        $this->db->run("UPDATE config_versions SET status = 'cancelled', decision_comment = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$reason, $this->now(), $az->userId, $v['id']]);
        $this->audit->record('config.cancelled', 'success', 'config_version', $versionPid, null, $reason);
    }

    // ---- WorkflowSubject -----------------------------------------------------------------------------

    public function type(): string
    {
        return 'config_version';
    }

    public function link(string $subjectId): string
    {
        return '/admin/config/version/' . $subjectId;
    }

    public function onApproved(array $instance, int $approverId): void
    {
        $v = $this->version((string) $instance['subject_id']);
        if ($v['status'] === 'approved') {
            return; // idempotent retry
        }
        if (!in_array($v['status'], ['in_review', 'returned'], true)) {
            throw new BusinessException('config.error.not_in_review', [], 409);
        }
        if ((int) $v['proposed_by'] === $approverId) {
            throw new BusinessException('workflow.error.self_approval', [], 403);
        }
        $this->assertNoOverlap($v);
        $this->db->run(
            "UPDATE config_versions SET status = 'approved', decided_by = ?, decided_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
            [$approverId, $this->now(), $this->now(), $v['id']]
        );
        $this->audit->record('config.approved', 'success', 'config_version', (string) $v['public_id'], $this->leOf((string) $v['scope_level'], (int) $v['scope_id']), null, ['key' => $v['config_key']]);
    }

    public function onClosed(array $instance, string $outcome): void
    {
        $status = ['rejected' => 'rejected', 'returned' => 'returned', 'cancelled' => 'cancelled'][$outcome] ?? null;
        if ($status !== null) {
            $this->db->run("UPDATE config_versions SET status = ?, decided_at = ?, updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status IN ('in_review','returned')", [$status, $this->now(), $this->now(), $instance['subject_id']]);
        }
    }

    public function onResubmitted(array $instance): void
    {
        $this->db->run("UPDATE config_versions SET status = 'in_review', updated_at = ?, row_version = row_version + 1 WHERE public_id = ? AND status = 'returned'", [$this->now(), $instance['subject_id']]);
    }

    // ---- read models -------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function version(string $publicId): array
    {
        return $this->db->one(
            'SELECT v.*, d.config_key, d.data_type, d.unit, d.risk_level, d.source_type, d.is_required, d.allowed_scope_levels, d.validation_json,
                    p.display_name AS proposer_name, a.display_name AS decider_name, i.public_id AS workflow_public_id
               FROM config_versions v JOIN config_definitions d ON d.id = v.definition_id
               JOIN users p ON p.id = v.proposed_by LEFT JOIN users a ON a.id = v.decided_by
               LEFT JOIN workflow_instances i ON i.id = v.workflow_instance_id
              WHERE v.public_id = ?',
            [$publicId]
        ) ?? throw HttpException::notFound();
    }

    /** @return list<array<string, mixed>> all versions of a key, newest first, with a display status */
    public function versions(string $key): array
    {
        $def = $this->definition($key);
        $rows = $this->db->all(
            'SELECT v.*, p.display_name AS proposer_name, a.display_name AS decider_name FROM config_versions v
               JOIN users p ON p.id = v.proposed_by LEFT JOIN users a ON a.id = v.decided_by
              WHERE v.definition_id = ? ORDER BY v.scope_level, v.scope_id, v.effective_from DESC, v.id DESC',
            [$def['id']]
        );
        foreach ($rows as &$r) {
            $r['display_status'] = $this->displayStatus($r, (int) $def['id']);
        }
        return $rows;
    }

    /**
     * draft/in_review/returned/rejected/cancelled, or for approved: scheduled / active / superseded / expired.
     *
     * @param array<string, mixed> $v
     */
    public function displayStatus(array $v, int $definitionId): string
    {
        if ($v['status'] !== 'approved') {
            return (string) $v['status'];
        }
        $now = $this->now();
        if ((string) $v['effective_from'] > $now) {
            return 'scheduled';
        }
        if ($v['effective_to'] !== null && (string) $v['effective_to'] <= $now) {
            return 'expired';
        }
        $winner = $this->db->scalar(
            "SELECT id FROM config_versions WHERE definition_id = ? AND scope_level = ? AND scope_id = ? AND status = 'approved'
               AND effective_from <= ? AND (effective_to IS NULL OR effective_to > ?) ORDER BY effective_from DESC LIMIT 1",
            [$definitionId, $v['scope_level'], $v['scope_id'], $now, $now]
        );
        return (int) $winner === (int) $v['id'] ? 'active' : 'superseded';
    }

    /**
     * What changes if this version is approved (6.25.2 impact analysis).
     *
     * @param array<string, mixed> $v
     * @return array{current: ?ResolvedConfig, retroactive: bool, inheriting: int, warnings: list<string>}
     */
    public function impact(array $v): array
    {
        $level = (string) $v['scope_level'];
        $id = (int) $v['scope_id'];
        $from = new DateTimeImmutable((string) $v['effective_from'], new DateTimeZone('UTC'));
        $le = $level === 'legal_entity' ? $id : ($level === 'site' ? (int) $this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$id]) : null);
        $current = $this->resolveOrNull((string) $v['config_key'], $le, $level === 'site' ? $id : null, $from);
        $warnings = [];
        $retro = $from < $this->clock->now()->modify('-1 minute');
        if ($retro) {
            $warnings[] = 'config.impact.retroactive';
        }
        try {
            $this->assertNoOverlap($v);
        } catch (BusinessException $e) {
            $warnings[] = $e->messageKey;
        }
        if ((int) $v['is_required'] === 1 && $v['effective_to'] !== null) {
            $after = new DateTimeImmutable((string) $v['effective_to'], new DateTimeZone('UTC'));
            if ($this->resolveOrNull((string) $v['config_key'], $le, $level === 'site' ? $id : null, $after) === null) {
                $warnings[] = 'config.impact.gap_after';
            }
        }
        $inheriting = match ($level) {
            'organization' => (int) $this->db->scalar('SELECT COUNT(*) FROM legal_entities') + (int) $this->db->scalar('SELECT COUNT(*) FROM sites'),
            'legal_entity' => (int) $this->db->scalar('SELECT COUNT(*) FROM sites WHERE legal_entity_id = ?', [$id]),
            default => 0,
        };
        return ['current' => $current, 'retroactive' => $retro, 'inheriting' => $inheriting, 'warnings' => $warnings];
    }

    // ---- rules ----------------------------------------------------------------------------------------

    /** @param array<string, mixed> $v */
    private function assertNoOverlap(array $v): void
    {
        $from = (string) $v['effective_from'];
        $to = $v['effective_to'] !== null ? (string) $v['effective_to'] : null;
        $others = $this->db->all(
            "SELECT effective_from, effective_to FROM config_versions WHERE definition_id = ? AND scope_level = ? AND scope_id = ? AND status = 'approved' AND id <> ?",
            [$v['definition_id'], $v['scope_level'], $v['scope_id'], $v['id']]
        );
        foreach ($others as $o) {
            $of = (string) $o['effective_from'];
            $ot = $o['effective_to'] !== null ? (string) $o['effective_to'] : null;
            $same = $of === $from;
            $boundedOverlap = $ot !== null && $from < $ot && ($to === null || $of < $to);
            $nested = $to !== null && $of >= $from && $of < $to;
            if ($same || $boundedOverlap || $nested) {
                throw new BusinessException('config.error.overlap', [], 409);
            }
        }
    }

    private function requireScope(Authorizer $az, string $permission, string $level, int $id): void
    {
        $scope = $az->scope($permission);
        $ok = $scope->all || ($level === 'legal_entity' && in_array($id, $scope->legalEntityIds, true))
            || ($level === 'site' && (in_array($id, $scope->siteIds, true) || in_array((int) $this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$id]), $scope->legalEntityIds, true)));
        if (!$ok) {
            $this->audit->record('config.denied', 'denied', 'config', $permission, null, "{$level}:{$id}");
            throw HttpException::forbidden();
        }
    }

    private function leOf(string $level, int $id): ?int
    {
        return match ($level) {
            'legal_entity' => $id,
            'site' => (int) $this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$id]),
            default => null,
        };
    }

    private function utc(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
