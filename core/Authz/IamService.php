<?php

declare(strict_types=1);

namespace Blue\Core\Authz;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Auth\AuthService;
use Blue\Core\Auth\LoginThrottle;
use Blue\Core\Auth\SessionManager;
use Blue\Core\Database\Connection;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;
use Blue\Core\Support\Ulid;

/**
 * User lifecycle and role/scope administration (6.4, 6.30.2–6.30.3, P0-04 §4, DEC-21).
 *
 * Rules enforced here (not just by hiding buttons):
 * - managing users needs organization-level core.user.manage; granting roles needs core.user.grant
 * - nobody can grant roles to themselves or approve their own request (SoD, P0-04 §4.4)
 * - high-risk roles start as `pending_approval` and need a SECOND system admin (re-authenticated)
 * - scopes handed out must lie inside the granter's own core.user.grant scope
 * - every assignment needs at least one scope row — no scope means no data (deny-by-default)
 */
final class IamService
{
    public const LIFECYCLE = ['invited', 'active', 'suspended', 'locked', 'offboarding', 'deactivated'];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly AuthService $auth,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>, total:int} */
    public function listUsers(Authorizer $az, string $q = '', string $status = '', int $page = 1, int $perPage = 20): array
    {
        $this->requireOrgLevel($az, 'core.user.view');
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (username LIKE ? OR display_name LIKE ? OR email LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if (in_array($status, self::LIFECYCLE, true)) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        $perPage = max(1, min(300, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM users WHERE {$where}", $params);
        $rows = $this->db->all(
            "SELECT u.id, u.public_id, u.username, u.display_name, u.email, u.status, u.mfa_enabled, u.last_login_at, u.locked_until, u.is_demo,
                    (SELECT GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', ') FROM user_role_assignments a JOIN roles r ON r.id = a.role_id
                      WHERE a.user_id = u.id AND a.status = 'active') AS roles
               FROM users u WHERE {$where} ORDER BY u.username LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function user(Authorizer $az, string $publicId): array
    {
        $this->requireOrgLevel($az, 'core.user.view');
        $row = $this->db->one('SELECT * FROM users WHERE public_id = ?', [$publicId]);
        if ($row === null) {
            throw HttpException::notFound();
        }
        unset($row['password_hash']);
        return $row;
    }

    /**
     * @param array<string, mixed> $in username, display_name, email, locale
     * @return array{public_id:string, token:string}
     */
    public function invite(Authorizer $az, array $in): array
    {
        $this->requireOrgLevel($az, 'core.user.manage');
        $errors = [];
        $username = mb_strtolower(trim((string) ($in['username'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $username)) {
            $errors['username'] = 'iam.error.username';
        }
        $display = trim((string) ($in['display_name'] ?? ''));
        if ($display === '' || mb_strlen($display) > 120) {
            $errors['display_name'] = 'form.required';
        }
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'iam.error.email';
        }
        $locale = in_array($in['locale'] ?? '', ['th', 'en'], true) ? (string) $in['locale'] : 'th';
        if ($errors === []) {
            if ($this->db->scalar('SELECT 1 FROM users WHERE username = ?', [$username])) {
                $errors['username'] = 'iam.error.username_taken';
            }
            if ($email !== '' && $this->db->scalar('SELECT 1 FROM users WHERE email = ?', [$email])) {
                $errors['email'] = 'iam.error.email_taken';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transactional(function () use ($username, $display, $email, $locale, $az): array {
            $publicId = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                "INSERT INTO users (public_id, username, display_name, email, status, locale, created_at, created_by, updated_at, updated_by, row_version)
                 VALUES (?, ?, ?, ?, 'invited', ?, ?, ?, ?, ?, 1)",
                [$publicId, $username, $display, $email === '' ? null : $email, $locale, $now, $az->userId, $now, $az->userId]
            );
            $id = (int) $this->db->pdo()->lastInsertId();
            $token = $this->auth->issueLinkToken($id, 'activation', $az->userId);
            $this->audit->record('iam.user.invite', 'success', 'user', $publicId, null, null, ['after' => ['username' => $username, 'display_name' => $display]]);
            return ['public_id' => $publicId, 'token' => $token];
        });
    }

    /** suspend / reactivate / unlock / offboard / deactivate (lifecycle 6.30.2) */
    public function changeStatus(Authorizer $az, string $publicId, string $action, string $reason): void
    {
        $this->requireOrgLevel($az, 'core.user.manage');
        $user = $this->user($az, $publicId);
        if ((int) $user['id'] === $az->userId && $action !== 'unlock') {
            throw new BusinessException('iam.error.self_status');
        }
        $map = [
            'suspend' => ['active', 'suspended'], 'reactivate' => ['suspended', 'active'], 'offboard' => ['active', 'offboarding'],
            'deactivate' => [null, 'deactivated'], 'unlock' => [null, null],
        ];
        if (!isset($map[$action])) {
            throw HttpException::notFound();
        }
        [$from, $to] = $map[$action];
        if ($from !== null && $user['status'] !== $from) {
            throw new BusinessException('iam.error.status_transition', ['from' => (string) $user['status']]);
        }
        if (in_array($action, ['suspend', 'offboard', 'deactivate'], true) && trim($reason) === '') {
            throw new ValidationException(['reason' => 'form.required']);
        }
        $this->db->transactional(function () use ($user, $to, $action, $reason, $az): void {
            if ($action === 'unlock') {
                $this->db->run('UPDATE users SET locked_until = NULL, row_version = row_version + 1 WHERE id = ?', [$user['id']]);
                $this->throttle->hit('login_user', (string) $user['id'], true); // resets the failure counter
            } else {
                $this->db->run('UPDATE users SET status = ?, row_version = row_version + 1, updated_at = ?, updated_by = ? WHERE id = ?', [$to, $this->now(), $az->userId, $user['id']]);
            }
            if (in_array($action, ['suspend', 'offboard', 'deactivate'], true)) {
                $this->sessions->revokeAll((int) $user['id'], 'status_' . $to);
            }
            if ($action === 'deactivate') {
                $this->db->run("UPDATE user_role_assignments SET status = 'revoked', updated_at = ? WHERE user_id = ? AND status IN ('active','pending_approval')", [$this->now(), $user['id']]);
            }
            $this->audit->record('iam.user.' . $action, 'success', 'user', $user['public_id'], null, $reason, ['before' => ['status' => $user['status']], 'after' => ['status' => $to ?? $user['status']]]);
        });
    }

    /**
     * Employment ended (M09 separation became effective — 6.19.5): the linked account goes to "offboarding" and every
     * session ends at once, so no system admin has to remember it. Roles are kept for the admin's final deactivation.
     * Returns false when there was nothing to do (no account, or already offboarding/deactivated).
     */
    public function offboardForSeparation(int $userId, string $reason, ?int $actorId): bool
    {
        $user = $this->db->one('SELECT id, public_id, status FROM users WHERE id = ?', [$userId]);
        if ($user === null || !in_array($user['status'], ['invited', 'active', 'suspended', 'locked'], true)) {
            return false;
        }
        $this->db->transactional(function () use ($user, $reason, $actorId): void {
            $this->db->run("UPDATE users SET status = 'offboarding', row_version = row_version + 1, updated_at = ?, updated_by = ? WHERE id = ?", [$this->now(), $actorId, $user['id']]);
            $this->sessions->revokeAll((int) $user['id'], 'status_offboarding');
            $this->audit->record('iam.user.offboard', 'success', 'user', $user['public_id'], null, $reason, ['before' => ['status' => $user['status']], 'after' => ['status' => 'offboarding']], $actorId);
        });
        return true;
    }

    /** Admin-issued single-use link to set a new password (when no e-mail channel exists). */
    public function issueResetLink(Authorizer $az, string $publicId): string
    {
        $this->requireOrgLevel($az, 'core.user.manage');
        $user = $this->user($az, $publicId);
        if ((int) $user['id'] === $az->userId) {
            throw new BusinessException('iam.error.self_reset');
        }
        $purpose = $user['status'] === 'invited' ? 'activation' : 'reset_link';
        $token = $this->auth->issueLinkToken((int) $user['id'], $purpose, $az->userId);
        $this->audit->record('iam.user.' . $purpose . '_link', 'success', 'user', $user['public_id']);
        return $token;
    }

    /**
     * Grants a role with scopes. High-risk roles wait for a second system admin.
     *
     * @param list<array{0:string, 1:int}> $scopes [[scope_level, id], ...]
     * @return array{public_id:string, status:string, sod_conflicts:list<string>}
     */
    public function grant(Authorizer $az, string $userPublicId, string $roleCode, array $scopes, string $validFrom, ?string $validTo, string $reason): array
    {
        $grantScope = $az->scope('core.user.grant');
        if ($grantScope->isEmpty()) {
            $this->audit->record('iam.role.grant', 'denied', 'user', $userPublicId, null, 'missing core.user.grant');
            throw HttpException::forbidden();
        }
        $this->requireOrgLevel($az, 'core.user.view');
        $user = $this->user($az, $userPublicId);
        $role = $this->db->one('SELECT * FROM roles WHERE code = ?', [$roleCode]);
        $errors = [];
        if ($role === null) {
            $errors['role'] = 'form.invalid_choice';
        }
        if ((int) $user['id'] === $az->userId) {
            $this->audit->record('iam.role.grant', 'denied', 'user', $userPublicId, null, 'self_grant');
            throw new BusinessException('iam.error.self_grant', [], 403);
        }
        if (in_array($user['status'], ['deactivated', 'offboarding'], true)) {
            $errors['role'] = 'iam.error.user_inactive';
        }
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
        $to = $validTo === null || $validTo === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $validTo);
        if ($from === false) {
            $errors['valid_from'] = 'form.invalid_date';
        }
        if ($to === false || ($to instanceof \DateTimeImmutable && $from instanceof \DateTimeImmutable && $to < $from)) {
            $errors['valid_to'] = 'iam.error.valid_to';
        }
        $scopes = $this->normaliseScopes($scopes);
        if ($scopes === []) {
            $errors['scopes'] = 'iam.error.scope_required';
        } elseif (!$this->scopesInside($scopes, $grantScope)) {
            $errors['scopes'] = 'iam.error.scope_outside';
        }
        if (trim($reason) === '') {
            $errors['reason'] = 'form.required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        /** @var array<string, mixed> $role */
        $status = $role['high_risk'] ? 'pending_approval' : 'active';
        $conflicts = $this->conflictsFor((int) $user['id'], (string) $role['code'], $scopes);

        return $this->db->transactional(function () use ($user, $role, $scopes, $validFrom, $validTo, $reason, $status, $az, $conflicts): array {
            $publicId = Ulid::generate($this->clock);
            $now = $this->now();
            $this->db->run(
                'INSERT INTO user_role_assignments (public_id, user_id, role_id, status, valid_from, valid_to, requested_by, reason, created_at, created_by, updated_at, updated_by, row_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [$publicId, $user['id'], $role['id'], $status, $validFrom, $validTo === '' ? null : $validTo, $az->userId, mb_substr($reason, 0, 255), $now, $az->userId, $now, $az->userId]
            );
            $aid = (int) $this->db->pdo()->lastInsertId();
            foreach ($scopes as [$level, $id]) {
                $this->db->run('INSERT INTO access_scopes (assignment_id, scope_level, scope_id) VALUES (?, ?, ?)', [$aid, $level, $id]);
            }
            $this->audit->record('iam.role.grant', 'success', 'user', $user['public_id'], null, $reason, [
                'after' => ['role' => $role['code'], 'status' => $status, 'scopes' => $scopes, 'valid_from' => $validFrom, 'valid_to' => $validTo, 'sod_conflicts' => $conflicts],
            ]);
            if ($status === 'active') {
                $this->sessions->revokeAll((int) $user['id'], 'rights_changed'); // rights take effect at next sign-in
            }
            return ['public_id' => $publicId, 'status' => $status, 'sod_conflicts' => $conflicts];
        });
    }

    /** Second system admin approves / rejects a pending high-risk grant (caller must have re-authenticated). */
    public function decide(Authorizer $az, string $assignmentPublicId, bool $approve, string $comment): void
    {
        $this->requireOrgLevel($az, 'core.user.grant');
        $a = $this->db->one('SELECT * FROM user_role_assignments WHERE public_id = ?', [$assignmentPublicId]);
        if ($a === null || $a['status'] !== 'pending_approval') {
            throw HttpException::notFound();
        }
        if ((int) $a['requested_by'] === $az->userId || (int) $a['user_id'] === $az->userId) {
            $this->audit->record('iam.role.decide', 'denied', 'assignment', $assignmentPublicId, null, 'sod_same_person');
            throw new BusinessException('iam.error.sod_approve', [], 403);
        }
        $status = $approve ? 'active' : 'rejected';
        $this->db->transactional(function () use ($a, $status, $az, $comment, $assignmentPublicId): void {
            $this->db->run(
                'UPDATE user_role_assignments SET status = ?, approved_by = ?, approved_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = ?',
                [$status, $az->userId, $this->now(), $this->now(), $az->userId, $a['id'], 'pending_approval']
            );
            if ($status === 'active') {
                $this->sessions->revokeAll((int) $a['user_id'], 'rights_changed');
            }
            $this->audit->record('iam.role.' . ($status === 'active' ? 'approve' : 'reject'), 'success', 'assignment', $assignmentPublicId, null, $comment);
        });
    }

    public function revoke(Authorizer $az, string $assignmentPublicId, string $reason): void
    {
        $this->requireOrgLevel($az, 'core.user.grant');
        $a = $this->db->one('SELECT a.*, u.public_id AS user_public_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id WHERE a.public_id = ?', [$assignmentPublicId]);
        if ($a === null || !in_array($a['status'], ['active', 'pending_approval'], true)) {
            throw HttpException::notFound();
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'form.required']);
        }
        $this->db->transactional(function () use ($a, $az, $reason, $assignmentPublicId): void {
            $this->db->run("UPDATE user_role_assignments SET status = 'revoked', updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $az->userId, $a['id']]);
            $this->sessions->revokeAll((int) $a['user_id'], 'rights_changed');
            $this->audit->record('iam.role.revoke', 'success', 'assignment', $assignmentPublicId, null, $reason, ['user' => $a['user_public_id']]);
        });
    }

    /** @return list<array<string, mixed>> assignments of a user with role code and scope labels */
    public function assignments(int $userId): array
    {
        $rows = $this->db->all(
            "SELECT a.*, r.code AS role_code, r.high_risk, r.requires_mfa,
                    (SELECT GROUP_CONCAT(CONCAT(s.scope_level, ':', s.scope_id) SEPARATOR ',') FROM access_scopes s WHERE s.assignment_id = a.id) AS scopes
               FROM user_role_assignments a JOIN roles r ON r.id = a.role_id WHERE a.user_id = ? ORDER BY a.status = 'active' DESC, a.created_at DESC",
            [$userId]
        );
        return $rows;
    }

    /** @return list<array<string, mixed>> pending high-risk grants waiting for a second admin */
    public function pendingApprovals(): array
    {
        return $this->db->all(
            "SELECT a.public_id, a.created_at, a.reason, a.requested_by, r.code AS role_code, u.username, u.display_name, u.public_id AS user_public_id,
                    rq.username AS requested_by_name
               FROM user_role_assignments a JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = a.user_id
               LEFT JOIN users rq ON rq.id = a.requested_by
              WHERE a.status = 'pending_approval' ORDER BY a.created_at"
        );
    }

    /**
     * People holding conflicting roles in the same company (P0-04 §4.4 report).
     *
     * @return list<array{username:string, roles:string, legal_entity_id:int}>
     */
    public function sodReport(): array
    {
        $rows = $this->db->all(
            "SELECT u.username, r.code, s.scope_level, s.scope_id, COALESCE(si.legal_entity_id, d.legal_entity_id, IF(s.scope_level = 'legal_entity', s.scope_id, 0)) AS le
               FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN roles r ON r.id = a.role_id
               JOIN access_scopes s ON s.assignment_id = a.id
               LEFT JOIN sites si ON s.scope_level = 'site' AND si.id = s.scope_id
               LEFT JOIN departments d ON s.scope_level = 'department' AND d.id = s.scope_id
              WHERE a.status = 'active'"
        );
        $held = [];
        foreach ($rows as $r) {
            $held[(string) $r['username']][(int) $r['le']][(string) $r['code']] = true;
        }
        $out = [];
        foreach ($held as $username => $byLe) {
            $orgWide = $byLe[0] ?? []; // organization-level roles apply in every company
            foreach ($byLe as $le => $codes) {
                $all = $codes + $orgWide;
                foreach (Catalog::CONFLICTING_ROLES as [$a, $b]) {
                    if (isset($all[$a], $all[$b])) {
                        $out[] = ['username' => $username, 'roles' => "{$a} + {$b}", 'legal_entity_id' => $le];
                    }
                }
            }
        }
        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     * @param list<array{0:string, 1:int}> $scopes
     * @return list<string> conflicting role pairs the user would hold after this grant
     */
    private function conflictsFor(int $userId, string $roleCode, array $scopes): array
    {
        $existing = $this->db->all(
            "SELECT DISTINCT r.code FROM user_role_assignments a JOIN roles r ON r.id = a.role_id WHERE a.user_id = ? AND a.status IN ('active','pending_approval')",
            [$userId]
        );
        $codes = array_column($existing, 'code');
        $out = [];
        foreach (Catalog::CONFLICTING_ROLES as [$a, $b]) {
            if (($roleCode === $a && in_array($b, $codes, true)) || ($roleCode === $b && in_array($a, $codes, true))) {
                $out[] = "{$a} + {$b}";
            }
        }
        return $out;
    }

    /**
     * @param list<array{0:string, 1:int}|mixed> $scopes
     * @return list<array{0:string, 1:int}>
     */
    private function normaliseScopes(array $scopes): array
    {
        $out = [];
        foreach ($scopes as $s) {
            if (!is_array($s) || count($s) !== 2) {
                continue;
            }
            [$level, $id] = [(string) $s[0], (int) $s[1]];
            $table = ['organization' => 'organizations', 'legal_entity' => 'legal_entities', 'site' => 'sites', 'department' => 'departments'][$level] ?? null;
            if ($table !== null && $id > 0 && $this->db->scalar("SELECT 1 FROM `{$table}` WHERE id = ?", [$id])) {
                $out[$level . ':' . $id] = [$level, $id];
            }
        }
        return array_values($out);
    }

    /** @param list<array{0:string, 1:int}> $scopes */
    private function scopesInside(array $scopes, ScopeSet $granter): bool
    {
        if ($granter->all) {
            return true;
        }
        foreach ($scopes as [$level, $id]) {
            $ok = match ($level) {
                'legal_entity' => in_array($id, $granter->legalEntityIds, true),
                'site' => in_array($id, $granter->siteIds, true)
                    || in_array((int) $this->db->scalar('SELECT legal_entity_id FROM sites WHERE id = ?', [$id]), $granter->legalEntityIds, true),
                'department' => in_array($id, $granter->departmentIds, true)
                    || in_array((int) $this->db->scalar('SELECT legal_entity_id FROM departments WHERE id = ?', [$id]), $granter->legalEntityIds, true),
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    private function requireOrgLevel(Authorizer $az, string $permission): void
    {
        if (!$az->scope($permission)->all) {
            $this->audit->record('authz.denied', 'denied', null, null, null, $permission);
            throw HttpException::forbidden();
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
