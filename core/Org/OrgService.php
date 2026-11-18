<?php

declare(strict_types=1);

namespace Blue\Core\Org;

use Blue\Core\Audit\AuditLogger;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Authz\ScopeSet;
use Blue\Core\Database\Connection;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Clock;

/**
 * Organization structure (03A, DEC-19/21): Group (organization) → company (legal entity) → site → department.
 * Application service: permission + scope checks, validation, transaction, audit.
 */
final class OrgService
{
    public const SITE_TYPES = ['hq', 'branch', 'factory', 'warehouse', 'office'];

    /**
     * Fixed unit levels under a company (DEC-68), top to bottom. Levels may be skipped, but a child unit is always on
     * a lower level than its parent. Sites are a separate dimension (a unit may be tagged with one).
     */
    public const UNIT_LEVELS = ['top_management', 'division', 'department', 'section', 'sub_section'];

    private LegalEntityRepository $les;
    private SiteRepository $sites;
    private DepartmentRepository $depts;
    private Translations $tr;

    public function __construct(
        private readonly Connection $db,
        Clock $clock,
        private readonly AuditLogger $audit,
    ) {
        $this->les = new LegalEntityRepository($db, $clock);
        $this->sites = new SiteRepository($db, $clock);
        $this->depts = new DepartmentRepository($db, $clock);
        $this->tr = new Translations($db);
    }

    /** @return array<string, mixed>|null the single organization of this installation (DEC-10) */
    public function organization(): ?array
    {
        return $this->db->one('SELECT * FROM organizations ORDER BY id LIMIT 1');
    }

    /**
     * Companies → sites → departments visible to $az through core.org.view.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(Authorizer $az, string $locale): array
    {
        $scope = $az->scope('core.org.view');
        $les = $this->les->listInScope($scope, [], 1, 300, 't.code')['rows'];
        $sites = $this->sites->listInScope($scope, [], 1, 300, 't.legal_entity_id, t.code')['rows'];
        $depts = $this->depts->listInScope($scope, [], 1, 300, 't.legal_entity_id, t.code')['rows'];
        $names = $this->tr->many('legal_entity', array_map(static fn ($r) => (int) $r['id'], $les))
            + [];
        $siteNames = $this->tr->many('site', array_map(static fn ($r) => (int) $r['id'], $sites));
        $deptNames = $this->tr->many('department', array_map(static fn ($r) => (int) $r['id'], $depts));

        $out = [];
        $leIndex = [];
        foreach ($les as $le) {
            $le['name'] = Translations::pick($names[(int) $le['id']] ?? [], $locale);
            $le['names'] = $names[(int) $le['id']] ?? [];
            $le['sites'] = [];
            $le['departments'] = [];
            $leIndex[(int) $le['id']] = count($out);
            $out[] = $le;
        }
        // A site/department user may see a site without seeing the whole company: show it under a stub.
        $stub = function (int $leId) use (&$out, &$leIndex, $locale): int {
            if (!isset($leIndex[$leId])) {
                $row = $this->db->one('SELECT id, public_id, code FROM legal_entities WHERE id = ?', [$leId]) ?? ['id' => $leId, 'public_id' => '', 'code' => '?'];
                $n = $this->tr->many('legal_entity', [$leId])[$leId] ?? [];
                $leIndex[$leId] = count($out);
                $out[] = $row + ['name' => Translations::pick($n, $locale), 'names' => $n, 'sites' => [], 'departments' => [], 'partial' => true, 'status' => 'active', 'row_version' => 0];
            }
            return $leIndex[$leId];
        };
        foreach ($sites as $s) {
            $s['name'] = Translations::pick($siteNames[(int) $s['id']] ?? [], $locale);
            $s['names'] = $siteNames[(int) $s['id']] ?? [];
            $out[$stub((int) $s['legal_entity_id'])]['sites'][] = $s;
        }
        foreach ($depts as $d) {
            $d['name'] = Translations::pick($deptNames[(int) $d['id']] ?? [], $locale);
            $d['names'] = $deptNames[(int) $d['id']] ?? [];
            $out[$stub((int) $d['legal_entity_id'])]['departments'][] = $d;
        }
        return $out;
    }

    /** @return array<string, mixed> legal entity row + names, or 404 when outside the caller's scope */
    public function legalEntity(Authorizer $az, string $publicId, string $permission = 'core.org.view'): array
    {
        $row = $this->les->findInScope($publicId, $az->scope($permission));
        if ($row === null) {
            $this->outOfScope($this->les->findByPublicId($publicId), 'legalEntity', $publicId, $permission);
        }
        $row['names'] = $this->tr->many('legal_entity', [(int) $row['id']])[(int) $row['id']] ?? [];
        return $row;
    }

    /** @return array<string, mixed> */
    public function site(Authorizer $az, string $publicId, string $permission = 'core.org.view'): array
    {
        $row = $this->sites->findInScope($publicId, $az->scope($permission));
        if ($row === null) {
            $this->outOfScope($this->sites->findByPublicId($publicId), 'site', $publicId, $permission);
        }
        $row['names'] = $this->tr->many('site', [(int) $row['id']])[(int) $row['id']] ?? [];
        return $row;
    }

    /** @return array<string, mixed> */
    public function department(Authorizer $az, string $publicId, string $permission = 'core.org.view'): array
    {
        $row = $this->depts->findInScope($publicId, $az->scope($permission));
        if ($row === null) {
            $this->outOfScope($this->depts->findByPublicId($publicId), 'department', $publicId, $permission);
        }
        $row['names'] = $this->tr->many('department', [(int) $row['id']])[(int) $row['id']] ?? [];
        $row['parent_public_id'] = $row['parent_id'] !== null ? $this->db->scalar('SELECT public_id FROM departments WHERE id = ?', [$row['parent_id']]) : null;
        $row['site_public_id'] = $row['site_id'] !== null ? $this->db->scalar('SELECT public_id FROM sites WHERE id = ?', [$row['site_id']]) : null;
        return $row;
    }

    /** @param array<string, mixed> $in */
    public function createLegalEntity(Authorizer $az, array $in): string
    {
        $scope = $az->scope('core.org.manage');
        if (!$scope->all) {
            $this->deny($az, 'org.legal_entity.create');
        }
        $data = $this->validateLegalEntity($in);
        $org = $this->organization() ?? throw new \RuntimeException('Organization missing — run the installer.');
        return $this->db->transactional(function () use ($data, $org, $az): string {
            $this->assertUnique('legal_entities', 'organization_id', (int) $org['id'], $data['code'], null);
            $id = $this->les->create([
                'organization_id' => (int) $org['id'],
                'code' => $data['code'],
                'country_code' => $data['country_code'],
                'base_currency' => $data['base_currency'],
                'fiscal_year_start_month' => $data['fiscal_year_start_month'],
                'registration_no' => $data['registration_no'],
            ], $az->userId);
            $row = $this->les->findByPublicId($id) ?? [];
            $this->tr->put('legal_entity', (int) $row['id'], 'name', $data['names']);
            $this->audit->record('org.legal_entity.create', 'success', 'legal_entity', $id, (int) $row['id'], null, ['after' => $data]);
            return $id;
        });
    }

    /** @param array<string, mixed> $in */
    public function updateLegalEntity(Authorizer $az, string $publicId, int $version, array $in): void
    {
        $before = $this->legalEntity($az, $publicId, 'core.org.manage');
        $data = $this->validateLegalEntity($in + ['code' => $before['code']]);
        $this->db->transactional(function () use ($before, $publicId, $version, $data, $az, $in): void {
            $this->assertUnique('legal_entities', 'organization_id', (int) $before['organization_id'], $data['code'], (int) $before['id']);
            $this->les->update($publicId, $version, [
                'code' => $data['code'],
                'registration_no' => $data['registration_no'],
                'fiscal_year_start_month' => $data['fiscal_year_start_month'],
                'status' => ($in['status'] ?? $before['status']) === 'inactive' ? 'inactive' : 'active',
            ], $az->userId);
            $this->tr->put('legal_entity', (int) $before['id'], 'name', $data['names']);
            $this->audit->record('org.legal_entity.update', 'success', 'legal_entity', $publicId, (int) $before['id'], null, [
                'before' => ['code' => $before['code'], 'names' => $before['names'], 'registration_no' => $before['registration_no']],
                'after' => ['code' => $data['code'], 'names' => $data['names'], 'registration_no' => $data['registration_no']],
            ]);
        });
    }

    /** @param array<string, mixed> $in */
    public function createSite(Authorizer $az, string $legalEntityPublicId, array $in): string
    {
        $le = $this->legalEntity($az, $legalEntityPublicId, 'core.org.manage');
        $errors = [];
        $code = $this->code($in['code'] ?? '', $errors);
        $names = $this->names($in, $errors);
        $type = (string) ($in['site_type'] ?? 'branch');
        if (!in_array($type, self::SITE_TYPES, true)) {
            $errors['site_type'] = 'form.invalid_choice';
        }
        $branch = trim((string) ($in['branch_no'] ?? ''));
        if ($branch !== '' && !preg_match('/^\d{1,5}$/', $branch)) {
            $errors['branch_no'] = 'org.error.branch_no';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transactional(function () use ($le, $code, $names, $type, $branch, $az): string {
            $this->assertUnique('sites', 'legal_entity_id', (int) $le['id'], $code, null);
            $id = $this->sites->create([
                'legal_entity_id' => (int) $le['id'], 'code' => $code, 'site_type' => $type,
                'branch_no' => $branch === '' ? null : str_pad($branch, 5, '0', STR_PAD_LEFT),
            ], $az->userId);
            $row = $this->sites->findByPublicId($id) ?? [];
            $this->tr->put('site', (int) $row['id'], 'name', $names);
            $this->audit->record('org.site.create', 'success', 'site', $id, (int) $le['id'], null, ['after' => ['code' => $code, 'names' => $names, 'type' => $type]]);
            return $id;
        });
    }

    /** @param array<string, mixed> $in */
    public function updateSite(Authorizer $az, string $publicId, int $version, array $in): void
    {
        $before = $this->site($az, $publicId, 'core.org.manage');
        $errors = [];
        $names = $this->names($in, $errors);
        $type = (string) ($in['site_type'] ?? $before['site_type']);
        if (!in_array($type, self::SITE_TYPES, true)) {
            $errors['site_type'] = 'form.invalid_choice';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->db->transactional(function () use ($before, $publicId, $version, $names, $type, $az, $in): void {
            $this->sites->update($publicId, $version, [
                'site_type' => $type,
                'status' => ($in['status'] ?? $before['status']) === 'inactive' ? 'inactive' : 'active',
            ], $az->userId);
            $this->tr->put('site', (int) $before['id'], 'name', $names);
            $this->audit->record('org.site.update', 'success', 'site', $publicId, (int) $before['legal_entity_id'], null, [
                'before' => ['names' => $before['names'], 'type' => $before['site_type']], 'after' => ['names' => $names, 'type' => $type],
            ]);
        });
    }

    /** @param array<string, mixed> $in code, names, level, parent (unit public id), site (public id) */
    public function createDepartment(Authorizer $az, string $legalEntityPublicId, array $in): string
    {
        $le = $this->legalEntity($az, $legalEntityPublicId, 'core.org.manage');
        $errors = [];
        $code = $this->code($in['code'] ?? '', $errors);
        $names = $this->names($in, $errors);
        [$level, $parentId, $siteId] = $this->placement((int) $le['id'], $in, null, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $this->db->transactional(function () use ($le, $code, $names, $siteId, $parentId, $level, $az): string {
            $this->assertUnique('departments', 'legal_entity_id', (int) $le['id'], $code, null);
            $id = $this->depts->create([
                'legal_entity_id' => (int) $le['id'], 'site_id' => $siteId, 'parent_id' => $parentId, 'unit_level' => $level, 'code' => $code,
            ], $az->userId);
            $row = $this->depts->findByPublicId($id) ?? [];
            $this->tr->put('department', (int) $row['id'], 'name', $names);
            $this->audit->record('org.department.create', 'success', 'department', $id, (int) $le['id'], null, ['after' => ['code' => $code, 'names' => $names, 'level' => $level]]);
            return $id;
        });
    }

    /** @param array<string, mixed> $in names, status, level, parent, site */
    public function updateDepartment(Authorizer $az, string $publicId, int $version, array $in): void
    {
        $before = $this->department($az, $publicId, 'core.org.manage');
        $errors = [];
        $names = $this->names($in, $errors);
        [$level, $parentId, $siteId] = $this->placement((int) $before['legal_entity_id'], $in + ['level' => $before['unit_level']], $before, $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->db->transactional(function () use ($before, $publicId, $version, $names, $az, $in, $level, $parentId, $siteId): void {
            $this->depts->update($publicId, $version, [
                'status' => ($in['status'] ?? $before['status']) === 'inactive' ? 'inactive' : 'active',
                'unit_level' => $level, 'parent_id' => $parentId, 'site_id' => $siteId,
            ], $az->userId);
            $this->tr->put('department', (int) $before['id'], 'name', $names);
            $this->audit->record('org.department.update', 'success', 'department', $publicId, (int) $before['legal_entity_id'], null, [
                'before' => ['names' => $before['names'], 'level' => $before['unit_level'], 'parent_id' => $before['parent_id'], 'site_id' => $before['site_id']],
                'after' => ['names' => $names, 'level' => $level, 'parent_id' => $parentId, 'site_id' => $siteId],
            ]);
        });
    }

    /**
     * Level / parent / site of a unit, checked: the parent is in the same company, on a HIGHER level and not the unit
     * itself or one of its descendants; the unit's own children stay below it; the site belongs to the company.
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $self the unit being edited
     * @param array<string, string> $errors
     * @return array{0: string, 1: ?int, 2: ?int}
     */
    private function placement(int $leId, array $in, ?array $self, array &$errors): array
    {
        $level = (string) ($in['level'] ?? '');
        if ($level === '') {
            $level = (string) ($self['unit_level'] ?? 'department'); // not given = keep the unit's level / the default level
        }
        if (!in_array($level, self::UNIT_LEVELS, true)) {
            $errors['level'] = 'form.invalid_choice';
            $level = 'department';
        }
        $rank = array_flip(self::UNIT_LEVELS);
        $siteId = null;
        if (($in['site'] ?? '') !== '') {
            $site = $this->sites->findByPublicId((string) $in['site']);
            if ($site === null || (int) $site['legal_entity_id'] !== $leId) {
                $errors['site'] = 'org.error.site_other_company';
            } else {
                $siteId = (int) $site['id'];
            }
        }
        $parentId = null;
        if (($in['parent'] ?? '') !== '') {
            $parent = $this->depts->findByPublicId((string) $in['parent']);
            if ($parent === null || (int) $parent['legal_entity_id'] !== $leId) {
                $errors['parent'] = 'org.error.parent_other_company';
            } elseif ($self !== null && $this->isSelfOrBelow((int) $parent['id'], (int) $self['id'])) {
                $errors['parent'] = 'org.error.parent_loop';
            } elseif ($rank[(string) $parent['unit_level']] >= $rank[$level]) {
                $errors['parent'] = 'org.error.parent_level';
            } else {
                $parentId = (int) $parent['id'];
                // 0.8.2 (DEC-69): under a unit of one branch, sub-units belong to that branch too (empty = take it)
                if ($parent['site_id'] !== null) {
                    if ($siteId === null && !isset($errors['site'])) {
                        $siteId = (int) $parent['site_id'];
                    } elseif ($siteId !== null && $siteId !== (int) $parent['site_id']) {
                        $errors['site'] = 'org.error.site_parent';
                    }
                }
            }
        }
        if ($self !== null && !isset($errors['level'])) {
            foreach ($this->db->all('SELECT unit_level FROM departments WHERE parent_id = ?', [$self['id']]) as $child) {
                if ($rank[(string) $child['unit_level']] <= $rank[$level]) {
                    $errors['level'] = 'org.error.children_level';
                    break;
                }
            }
        }
        return [$level, $parentId, $siteId];
    }

    private function isSelfOrBelow(int $candidateId, int $unitId): bool
    {
        $cur = $candidateId;
        for ($i = 0; $i < 20 && $cur !== 0; $i++) {
            if ($cur === $unitId) {
                return true;
            }
            $cur = (int) ($this->db->scalar('SELECT parent_id FROM departments WHERE id = ?', [$cur]) ?? 0);
        }
        return false;
    }

    /**
     * Options for scope pickers: every company/site/department the caller may hand out.
     *
     * @return array{legal_entity:array<int,string>, site:array<int,string>, department:array<int,string>}
     */
    public function scopeOptions(ScopeSet $scope, string $locale): array
    {
        $out = ['legal_entity' => [], 'site' => [], 'department' => []];
        foreach ([['legal_entity', $this->les], ['site', $this->sites], ['department', $this->depts]] as [$type, $repo]) {
            $rows = $repo->listInScope($scope, [], 1, 300, 't.code')['rows'];
            $names = $this->tr->many($type, array_map(static fn ($r) => (int) $r['id'], $rows));
            $leCodes = $type === 'legal_entity' ? [] : $this->leCodes(array_map(static fn ($r) => (int) $r['legal_entity_id'], $rows));
            foreach ($rows as $r) {
                $prefix = $type === 'legal_entity' ? '' : ($leCodes[(int) $r['legal_entity_id']] ?? '?') . ' / ';
                $out[$type][(int) $r['id']] = $prefix . $r['code'] . ' · ' . Translations::pick($names[(int) $r['id']] ?? [], $locale);
            }
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> legal entity id => code (labels only — no scope implied)
     */
    private function leCodes(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT id, code FROM legal_entities WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $out[(int) $r['id']] = (string) $r['code'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $in
     * @return array{code:string, names:array<string,string>, country_code:string, base_currency:string, fiscal_year_start_month:int, registration_no:?string}
     */
    private function validateLegalEntity(array $in): array
    {
        $errors = [];
        $code = $this->code($in['code'] ?? '', $errors);
        $names = $this->names($in, $errors);
        $country = strtoupper(trim((string) ($in['country_code'] ?? '')));
        $country = $country === '' ? 'TH' : $country;
        if ($country !== 'TH') {
            $errors['country_code'] = 'org.error.country_pack'; // only Country Pack TH exists in rollout 1
        }
        $fy = (int) ($in['fiscal_year_start_month'] ?? 1);
        if ($fy < 1 || $fy > 12) {
            $errors['fiscal_year_start_month'] = 'form.invalid_choice';
        }
        $reg = trim((string) ($in['registration_no'] ?? ''));
        if ($reg !== '' && !preg_match('/^(DEMO-[A-Z0-9-]{1,30}|\d{13})$/', $reg)) {
            $errors['registration_no'] = 'org.error.registration_no';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'code' => $code, 'names' => $names, 'country_code' => $country, 'base_currency' => 'THB',
            'fiscal_year_start_month' => $fy, 'registration_no' => $reg === '' ? null : $reg,
        ];
    }

    /** @param array<string, string> $errors */
    private function code(mixed $raw, array &$errors): string
    {
        $code = strtoupper(trim((string) $raw));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/', $code)) {
            $errors['code'] = 'org.error.code';
        }
        return $code;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private function names(array $in, array &$errors): array
    {
        $th = trim((string) ($in['name_th'] ?? ''));
        $en = trim((string) ($in['name_en'] ?? ''));
        if ($th === '' && $en === '') {
            $errors['name_th'] = 'form.required';
        }
        foreach (['name_th' => $th, 'name_en' => $en] as $k => $v) {
            if (mb_strlen($v) > 200) {
                $errors[$k] = 'form.too_long';
            }
        }
        return ['th' => $th, 'en' => $en];
    }

    private function assertUnique(string $table, string $parentCol, int $parentId, string $code, ?int $exceptId): void
    {
        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$parentCol}` = ? AND code = ?" . ($exceptId !== null ? ' AND id <> ?' : '');
        $params = $exceptId !== null ? [$parentId, $code, $exceptId] : [$parentId, $code];
        if ((int) $this->db->scalar($sql, $params) > 0) {
            throw new ValidationException(['code' => 'org.error.code_taken']);
        }
    }

    /**
     * Row missing OR outside the caller's scope → the same 404 (no probing of other companies' IDs);
     * an existing row reached out of scope is a possible IDOR attempt and is audited.
     *
     * @param array<string, mixed>|null $existing
     */
    private function outOfScope(?array $existing, string $type, string $publicId, string $permission): never
    {
        if ($existing !== null) {
            $le = (int) ($existing['legal_entity_id'] ?? $existing['id']);
            $this->audit->record('authz.out_of_scope', 'denied', $type, $publicId, $le, $permission);
        }
        throw HttpException::notFound();
    }

    private function deny(Authorizer $az, string $action): never
    {
        $this->audit->record($action, 'denied', null, null, null, 'missing organization-level core.org.manage');
        throw HttpException::forbidden();
    }
}
