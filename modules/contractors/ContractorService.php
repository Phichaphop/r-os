<?php

declare(strict_types=1);

namespace Blue\Modules\Contractors;

use DateTimeImmutable;
use DateTimeZone;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Ulid;

/**
 * M44 contractor management (9C-3 · DEC-96).
 *
 * The company decides which documents it requires (company documents such as contract or insurance; worker documents
 * such as a safety induction) and whether they expire — there are no defaults. A contractor company is qualified when
 * every required company document is valid; a worker when every required worker document is valid. A work permit (area,
 * work, tools, hazard categories, controls, time window, internal supervisor, workers) can only be sent when the company
 * and every worker are qualified for the whole window, nobody is suspended and no worker is on another live permit at the
 * same time; a holder of contractors.approve (never the requester) approves. The gate checks each worker in and out
 * inside the window only and re-checks documents on the day. The supervisor (or a manager) completes the permit after the
 * area check and the return of equipment, with a rating. Suspending a company suspends its live permits. Expiring
 * documents are flagged ahead by the company's alert days (none set = no alert). No legal requirement is decided here:
 * which documents and controls are needed under OSH law is the safety officer's choice.
 *
 * Permit statuses: draft → review → approved → on_site → completed; rejected · cancelled · expired · suspended.
 */
final class ContractorService
{
    public const HAZARDS = ['hot_work', 'height', 'confined_space', 'electrical', 'chemical', 'lifting', 'excavation', 'other'];

    public const LIVE = ['review', 'approved', 'on_site'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- time and scope ------------------------------------------------------------------------------------------------------

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function nowLocal(): DateTimeImmutable
    {
        $n = $this->app->clock->now()->setTimezone($this->tz());
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $n->format('Y-m-d H:i:s')) ?: $n;
    }

    private function now(): string
    {
        return $this->nowLocal()->format('Y-m-d H:i:s');
    }

    public function today(): string
    {
        return $this->nowLocal()->format('Y-m-d');
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    /** @return list<int> */
    public function companies(Authorizer $az, string $perm): array
    {
        $s = $az->scope($perm);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $s->coversLegalEntity($id)));
    }

    private function require(Authorizer $az, string $perm, int $le): void
    {
        if (!$az->scope($perm)->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
    }

    private function event(?int $permit, ?int $company, string $event, ?int $actor, ?string $note = null): void
    {
        $this->app->db()->run('INSERT INTO ct_events (permit_id, company_id, event, actor_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$permit, $company, $event, $actor, $note === null ? null : mb_substr($note, 0, 500), $this->stamp()]);
    }

    // ---- settings and document types -----------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function settings(int $le): array
    {
        return $this->app->db()->one('SELECT * FROM ct_settings WHERE legal_entity_id = ?', [$le]) ?? ['legal_entity_id' => $le, 'alert_days' => null];
    }

    public function saveSettings(Authorizer $az, int $le, string $alertDays): void
    {
        $this->require($az, 'contractors.manage', $le);
        $v = trim($alertDays);
        if ($v !== '' && (!ctype_digit($v) || (int) $v < 1 || (int) $v > 365)) {
            throw new ValidationException(['alert_days' => 'contractors.error.days']);
        }
        $this->app->db()->run(
            'INSERT INTO ct_settings (legal_entity_id, alert_days, updated_at, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE alert_days = VALUES(alert_days), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$le, $v === '' ? null : (int) $v, $this->stamp(), $az->userId]
        );
        $this->app->audit()->record('contractors.settings_changed', 'success', 'ct_settings', (string) $le, $le, null, ['alert_days' => $v === '' ? null : (int) $v]);
    }

    /** @return list<array<string, mixed>> */
    public function docTypes(int $le, ?string $appliesTo = null, bool $activeOnly = false): array
    {
        return $this->app->db()->all(
            'SELECT * FROM ct_doc_types WHERE legal_entity_id = ?' . ($appliesTo !== null ? ' AND applies_to = ' . $this->app->db()->pdo()->quote($appliesTo) : '') . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY applies_to, active DESC, name',
            [$le]
        );
    }

    /** @param array<string, mixed> $in name, applies_to, required, needs_expiry, active */
    public function saveDocType(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'contractors.manage', $le);
        $db = $this->app->db();
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        $applies = (string) ($in['applies_to'] ?? '');
        if (!in_array($applies, ['company', 'worker'], true)) {
            $e['applies_to'] = 'contractors.error.choice';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['type_name'] = 'contractors.error.name';
        } elseif ($db->scalar('SELECT id FROM ct_doc_types WHERE legal_entity_id = ? AND applies_to = ? AND name = ? AND public_id <> ?', [$le, $applies, $name, $pid ?? '']) !== null) {
            $e['type_name'] = 'contractors.error.name_taken';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $flags = [!empty($in['required']) ? 1 : 0, !empty($in['needs_expiry']) ? 1 : 0];
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO ct_doc_types (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name, applies_to, required, needs_expiry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $name, $applies, ...$flags]
            );
        } else {
            $id = $db->scalar('SELECT id FROM ct_doc_types WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) ?? throw HttpException::notFound();
            $db->run('UPDATE ct_doc_types SET name = ?, applies_to = ?, required = ?, needs_expiry = ?, active = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$name, $applies, ...$flags, !empty($in['active']) ? 1 : 0, $now, $az->userId, $id]);
        }
        $this->app->audit()->record('contractors.doc_type_saved', 'success', 'ct_doc_type', $pid, $le, null, ['applies_to' => $applies, 'required' => $flags[0]]);
        return $pid;
    }

    // ---- companies and workers -----------------------------------------------------------------------------------------------

    /**
     * What is missing for a company (worker null) or a worker to be qualified from $from to $until: required active
     * document types with no document valid over the whole window.
     *
     * @return list<string> names of missing / expiring document types
     */
    public function missing(int $le, int $companyId, ?int $workerId, string $from, string $until): array
    {
        $db = $this->app->db();
        $out = [];
        foreach ($this->docTypes($le, $workerId === null ? 'company' : 'worker', true) as $dt) {
            if ((int) $dt['required'] !== 1) {
                continue;
            }
            $ok = $db->scalar(
                'SELECT 1 FROM ct_documents WHERE company_id = ? AND ' . ($workerId === null ? 'worker_id IS NULL' : 'worker_id = ' . $workerId) . ' AND doc_type_id = ?
                   AND (valid_from IS NULL OR valid_from <= ?) AND (valid_until IS NULL OR valid_until >= ?) LIMIT 1',
                [$companyId, $dt['id'], $from, $until]
            );
            if ($ok === null) {
                $out[] = (string) $dt['name'];
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> companies with today's qualification */
    public function companyList(Authorizer $az, int $le): array
    {
        $this->require($az, 'contractors.view', $le);
        $today = $this->today();
        $rows = $this->app->db()->all(
            "SELECT c.*, (SELECT COUNT(*) FROM ct_workers w WHERE w.company_id = c.id AND w.active = 1) AS worker_count,
                    (SELECT COUNT(*) FROM ct_permits p WHERE p.company_id = c.id AND p.status IN ('review','approved','on_site')) AS live_permits,
                    (SELECT ROUND(AVG(p.rating), 2) FROM ct_permits p WHERE p.company_id = c.id AND p.rating IS NOT NULL) AS avg_rating
               FROM ct_companies c WHERE c.legal_entity_id = ? ORDER BY c.name",
            [$le]
        );
        foreach ($rows as &$r) {
            $r['missing'] = $this->missing($le, (int) $r['id'], null, $today, $today);
        }
        return $rows;
    }

    /** @return array<string, mixed> a company with workers, documents and permits */
    public function company(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $c = $db->one('SELECT c.*, COALESCE(u.display_name, u.username) AS owner_name, u.username AS owner_username FROM ct_companies c LEFT JOIN users u ON u.id = c.owner_id WHERE c.public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $le = (int) $c['legal_entity_id'];
        $this->require($az, 'contractors.view', $le);
        $today = $this->today();
        $c['can_manage'] = $az->scope('contractors.manage')->coversLegalEntity($le);
        $c['missing'] = $this->missing($le, (int) $c['id'], null, $today, $today);
        $c['workers'] = $db->all('SELECT * FROM ct_workers WHERE company_id = ? ORDER BY active DESC, name', [$c['id']]);
        foreach ($c['workers'] as &$w) {
            $w['missing'] = $this->missing($le, (int) $c['id'], (int) $w['id'], $today, $today);
        }
        unset($w);
        $c['documents'] = $db->all(
            'SELECT d.*, t.name AS type_name, t.applies_to, w.name AS worker_name FROM ct_documents d JOIN ct_doc_types t ON t.id = d.doc_type_id LEFT JOIN ct_workers w ON w.id = d.worker_id
              WHERE d.company_id = ? ORDER BY d.worker_id IS NOT NULL, w.name, t.name, d.valid_until DESC',
            [$c['id']]
        );
        $c['permits'] = $db->all('SELECT public_id, ref, work, area, starts_at, ends_at, status, rating FROM ct_permits WHERE company_id = ? ORDER BY id DESC LIMIT 100', [$c['id']]);
        $c['events'] = $db->all('SELECT e.*, COALESCE(u.display_name, u.username) AS actor FROM ct_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.company_id = ? AND e.permit_id IS NULL ORDER BY e.id DESC LIMIT 50', [$c['id']]);
        return $c;
    }

    private function userByName(string $who): ?int
    {
        $who = trim($who);
        if ($who === '') {
            return null;
        }
        $id = $this->app->db()->scalar("SELECT u.id FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.status = 'active' AND (u.username = ? OR e.employee_code = ?) LIMIT 1", [$who, $who]);
        return $id === null ? null : (int) $id;
    }

    /** @param array<string, mixed> $in name, contact_name, contact_phone, contact_email, owner */
    public function saveCompany(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, 'contractors.manage', $le);
        $db = $this->app->db();
        $e = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $e['name'] = 'contractors.error.name';
        } elseif ($db->scalar('SELECT id FROM ct_companies WHERE legal_entity_id = ? AND name = ? AND public_id <> ?', [$le, $name, $pid ?? '']) !== null) {
            $e['name'] = 'contractors.error.name_taken';
        }
        $contact = trim((string) ($in['contact_name'] ?? ''));
        $phone = trim((string) ($in['contact_phone'] ?? ''));
        $email = trim((string) ($in['contact_email'] ?? ''));
        if (mb_strlen($contact) > 120 || mb_strlen($phone) > 40) {
            $e['contact_name'] = 'contractors.error.text';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $e['contact_email'] = 'contractors.error.email';
        }
        $owner = $this->userByName((string) ($in['owner'] ?? ''));
        if (trim((string) ($in['owner'] ?? '')) !== '' && $owner === null) {
            $e['owner'] = 'contractors.error.person';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $vals = [$name, $contact ?: null, $phone ?: null, $email ?: null, $owner];
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run('INSERT INTO ct_companies (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name, contact_name, contact_phone, contact_email, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$pid, $now, $az->userId, $now, $az->userId, $le, ...$vals]);
            $this->event(null, (int) $db->pdo()->lastInsertId(), 'registered', $az->userId);
        } else {
            $c = $db->one('SELECT id FROM ct_companies WHERE public_id = ? AND legal_entity_id = ?', [$pid, $le]) ?? throw HttpException::notFound();
            $db->run('UPDATE ct_companies SET name = ?, contact_name = ?, contact_phone = ?, contact_email = ?, owner_id = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...$vals, $now, $az->userId, $c['id']]);
        }
        $this->app->audit()->record('contractors.company_saved', 'success', 'ct_company', $pid, $le);
        return $pid;
    }

    /** @return array<string, mixed> */
    private function companyRow(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM ct_companies WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @param array<string, mixed> $in name, position, phone, active */
    public function saveWorker(Authorizer $az, string $companyPid, array $in, ?string $workerPid = null): string
    {
        $c = $this->companyRow($companyPid);
        $this->require($az, 'contractors.manage', (int) $c['legal_entity_id']);
        $db = $this->app->db();
        $name = trim((string) ($in['name'] ?? ''));
        $position = trim((string) ($in['position'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $e = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['worker_name'] = 'contractors.error.name';
        }
        if (mb_strlen($position) > 80 || mb_strlen($phone) > 40) {
            $e['position'] = 'contractors.error.text';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        if ($workerPid === null) {
            $workerPid = Ulid::generate($this->app->clock);
            $db->run('INSERT INTO ct_workers (public_id, created_at, created_by, updated_at, updated_by, company_id, name, position, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$workerPid, $now, $az->userId, $now, $az->userId, $c['id'], $name, $position ?: null, $phone ?: null]);
        } else {
            $id = $db->scalar('SELECT id FROM ct_workers WHERE public_id = ? AND company_id = ?', [$workerPid, $c['id']]) ?? throw HttpException::notFound();
            $db->run('UPDATE ct_workers SET name = ?, position = ?, phone = ?, active = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$name, $position ?: null, $phone ?: null, !empty($in['active']) ? 1 : 0, $now, $az->userId, $id]);
        }
        return $workerPid;
    }

    /**
     * Adds a document (company or worker) with its validity; types that expire need an end date.
     *
     * @param array<string, mixed> $in doc_type, worker, valid_from, valid_until
     */
    public function addDocument(Authorizer $az, string $companyPid, array $in, ?UploadedFile $f): void
    {
        $c = $this->companyRow($companyPid);
        $le = (int) $c['legal_entity_id'];
        $this->require($az, 'contractors.manage', $le);
        $db = $this->app->db();
        $e = [];
        $type = $db->one('SELECT * FROM ct_doc_types WHERE public_id = ? AND legal_entity_id = ? AND active = 1', [(string) ($in['doc_type'] ?? ''), $le]);
        if ($type === null) {
            $e['doc_type'] = 'contractors.error.choice';
        }
        $worker = null;
        if (($in['worker'] ?? '') !== '') {
            $worker = $db->scalar('SELECT id FROM ct_workers WHERE public_id = ? AND company_id = ?', [(string) $in['worker'], $c['id']]);
            if ($worker === null) {
                $e['worker'] = 'contractors.error.choice';
            }
        }
        if ($type !== null && (($type['applies_to'] === 'worker') !== ($worker !== null))) {
            $e['worker'] = $type['applies_to'] === 'worker' ? 'contractors.error.worker_needed' : 'contractors.error.company_doc';
        }
        $from = trim((string) ($in['valid_from'] ?? ''));
        $until = trim((string) ($in['valid_until'] ?? ''));
        $okDate = static fn (string $d): bool => $d === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && DateTimeImmutable::createFromFormat('!Y-m-d', $d) !== false);
        if (!$okDate($from) || !$okDate($until) || ($from !== '' && $until !== '' && $until < $from)) {
            $e['valid_until'] = 'contractors.error.dates';
        } elseif ($type !== null && (int) $type['needs_expiry'] === 1 && $until === '') {
            $e['valid_until'] = 'contractors.error.expiry_needed';
        }
        if ($f === null || !$f->ok()) {
            $e['file'] = $f?->errorKey() ?? 'contractors.error.file';
        }
        if ($e !== [] || $type === null || $f === null) {
            throw new ValidationException($e);
        }
        $bytes = $f->bytes();
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'contractors.file', $bytes, $f->safeName(), ['title' => 'Contractor document', 'legal_entity_id' => $le], 'upload', true);
        } catch (ValidationException $x) {
            throw new ValidationException(['file' => (string) (array_values($x->errors)[0] ?? 'contractors.error.file')]);
        }
        $db->run(
            'INSERT INTO ct_documents (company_id, worker_id, doc_type_id, valid_from, valid_until, document_pid, sha256, filename, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$c['id'], $worker, $type['id'], $from ?: null, $until ?: null, $stored['document'], hash('sha256', $bytes), mb_substr($f->safeName(), 0, 200), $az->userId, $this->stamp()]
        );
        $this->event(null, (int) $c['id'], 'document_added', $az->userId, $type['name'] . ($until !== '' ? ' → ' . $until : ''));
        $this->app->audit()->record('contractors.document_added', 'success', 'ct_company', $companyPid, $le, null, ['type' => (int) $type['id'], 'worker' => $worker !== null]);
    }

    /** @return array{bytes: string, mime: string, filename: string} */
    public function file(Authorizer $az, string $companyPid, int $docId): array
    {
        $c = $this->companyRow($companyPid);
        $this->require($az, 'contractors.view', (int) $c['legal_entity_id']);
        $d = $this->app->db()->one('SELECT * FROM ct_documents WHERE id = ? AND company_id = ?', [$docId, $c['id']]) ?? throw HttpException::notFound();
        $doc = $this->app->documents()->moduleRead((string) $d['document_pid'], 'contractors', $az->userId, 'contractor document');
        return ['bytes' => $doc['bytes'], 'mime' => $doc['mime'], 'filename' => (string) $d['filename']];
    }

    /** Suspends a company (reason) — its live permits are suspended and the gate refuses its workers — or lifts it. */
    public function suspend(Authorizer $az, string $companyPid, bool $on, string $reason): void
    {
        $c = $this->companyRow($companyPid);
        $le = (int) $c['legal_entity_id'];
        $this->require($az, 'contractors.manage', $le);
        if ($on && mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['suspend_reason' => 'contractors.error.reason']);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $c, $on, $reason, $az): void {
            $db->run('UPDATE ct_companies SET suspended = ?, suspended_reason = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$on ? 1 : 0, $on ? mb_substr(trim($reason), 0, 500) : null, $this->stamp(), $az->userId, $c['id']]);
            if ($on) {
                foreach ($db->all("SELECT id, workflow_id, status FROM ct_permits WHERE company_id = ? AND status IN ('review','approved','on_site')", [$c['id']]) as $p) {
                    $db->run("UPDATE ct_permits SET status = 'suspended', updated_at = ? WHERE id = ?", [$this->stamp(), $p['id']]);
                    if ($p['status'] === 'review' && $p['workflow_id'] !== null) {
                        // the approval is withdrawn by the suspension (the engine only lets the requester cancel): void it directly
                        $db->run("UPDATE workflow_instances SET status = 'cancelled', finished_at = ?, updated_at = ? WHERE public_id = ? AND status IN ('pending','returned','needs_attention')", [$this->stamp(), $this->stamp(), $p['workflow_id']]);
                        $db->run("UPDATE workflow_tasks SET status = 'void' WHERE instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?) AND status = 'open'", [$p['workflow_id']]);
                    }
                    $this->event((int) $p['id'], (int) $c['id'], 'suspended', $az->userId, trim($reason));
                }
            }
            $this->event(null, (int) $c['id'], $on ? 'suspended' : 'reinstated', $az->userId, $on ? trim($reason) : null);
        });
        $this->app->audit()->record($on ? 'contractors.suspended' : 'contractors.reinstated', 'success', 'ct_company', $companyPid, $le);
    }

    // ---- permits -------------------------------------------------------------------------------------------------------------

    private function nextRef(int $le): string
    {
        $prefix = 'CP' . $this->nowLocal()->format('ym') . '-';
        $last = (string) ($this->app->db()->scalar('SELECT MAX(ref) FROM ct_permits WHERE legal_entity_id = ? AND ref LIKE ?', [$le, $prefix . '%']) ?? '');
        return $prefix . str_pad((string) ((int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Saves a permit draft (and optionally sends it for approval).
     *
     * @param array<string, mixed> $in company, site, work, area, tools, hazards[], controls, starts_at, ends_at, supervisor, workers[]
     */
    public function savePermit(Authorizer $az, int $le, array $in, ?string $pid = null, bool $send = false): string
    {
        $this->require($az, 'contractors.manage', $le);
        $db = $this->app->db();
        $e = [];
        $company = $db->one('SELECT * FROM ct_companies WHERE public_id = ? AND legal_entity_id = ?', [(string) ($in['company'] ?? ''), $le]);
        if ($company === null) {
            $e['company'] = 'contractors.error.choice';
        }
        $site = $db->scalar("SELECT id FROM sites WHERE public_id = ? AND legal_entity_id = ? AND status = 'active'", [(string) ($in['site'] ?? ''), $le]);
        if ($site === null) {
            $e['site'] = 'contractors.error.choice';
        }
        $work = trim((string) ($in['work'] ?? ''));
        $area = trim((string) ($in['area'] ?? ''));
        $tools = trim((string) ($in['tools'] ?? ''));
        $controls = trim((string) ($in['controls'] ?? ''));
        if (mb_strlen($work) < 5 || mb_strlen($work) > 500) {
            $e['work'] = 'contractors.error.work';
        }
        if (mb_strlen($area) < 2 || mb_strlen($area) > 160) {
            $e['area'] = 'contractors.error.area';
        }
        if (mb_strlen($tools) > 500 || mb_strlen($controls) > 1000) {
            $e['controls'] = 'contractors.error.text';
        }
        $hazards = array_values(array_intersect(self::HAZARDS, array_map('strval', (array) ($in['hazards'] ?? []))));
        if ($hazards !== [] && mb_strlen($controls) < 5) {
            $e['controls'] = 'contractors.error.controls';
        }
        $a = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($in['starts_at'] ?? ''));
        $b = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($in['ends_at'] ?? ''));
        if ($a === false || $b === false || $b <= $a) {
            $e['starts_at'] = 'contractors.error.window';
        } elseif ($a->format('Y-m-d H:i') < $this->nowLocal()->format('Y-m-d H:i')) {
            $e['starts_at'] = 'contractors.error.past';
        } elseif ($a->modify('+31 days') < $b) {
            $e['starts_at'] = 'contractors.error.too_long';
        }
        $supervisor = $this->userByName((string) ($in['supervisor'] ?? ''));
        if ($supervisor === null) {
            $e['supervisor'] = 'contractors.error.person';
        }
        $workers = [];
        if ($company !== null) {
            foreach (array_unique(array_map('strval', (array) ($in['workers'] ?? []))) as $wp) {
                $w = $db->scalar('SELECT id FROM ct_workers WHERE public_id = ? AND company_id = ? AND active = 1', [$wp, $company['id']]);
                if ($w !== null) {
                    $workers[] = (int) $w;
                }
            }
            if ($workers === []) {
                $e['workers'] = 'contractors.error.workers';
            }
        }
        if ($e !== [] || $company === null || $a === false || $b === false || $supervisor === null) {
            throw new ValidationException($e);
        }
        $vals = [(int) $site, (int) $company['id'], $work, $area, $tools ?: null, $hazards === [] ? null : implode(',', $hazards), $controls ?: null, $a->format('Y-m-d H:i:00'), $b->format('Y-m-d H:i:00'), $supervisor];
        $now = $this->stamp();
        $pid = $db->transactional(function () use ($db, $pid, $vals, $now, $az, $le, $workers): string {
            if ($pid === null) {
                $pid = Ulid::generate($this->app->clock);
                $db->run(
                    'INSERT INTO ct_permits (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, site_id, company_id, work, area, tools, hazards, controls, starts_at, ends_at, supervisor_id, requester_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$pid, $now, $az->userId, $now, $az->userId, $le, ...$vals, $az->userId]
                );
                $id = (int) $db->pdo()->lastInsertId();
                $this->event($id, $vals[1], 'drafted', $az->userId);
            } else {
                $p = $db->one('SELECT * FROM ct_permits WHERE public_id = ? AND legal_entity_id = ? FOR UPDATE', [$pid, $le]) ?? throw HttpException::notFound();
                if ($p['status'] !== 'draft') {
                    throw new BusinessException('contractors.error.not_draft', [], 409);
                }
                $id = (int) $p['id'];
                $db->run('UPDATE ct_permits SET site_id = ?, company_id = ?, work = ?, area = ?, tools = ?, hazards = ?, controls = ?, starts_at = ?, ends_at = ?, supervisor_id = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...$vals, $now, $az->userId, $id]);
                $db->run('DELETE FROM ct_permit_workers WHERE permit_id = ?', [$id]);
            }
            foreach ($workers as $w) {
                $db->run('INSERT INTO ct_permit_workers (permit_id, worker_id) VALUES (?, ?)', [$id, $w]);
            }
            return $pid;
        });
        if ($send) {
            $this->submit($az, $pid);
        }
        return $pid;
    }

    /**
     * Checks a permit can go ahead: company not suspended, company and every worker qualified for the whole window, no
     * worker on another live permit at the same time.
     *
     * @param array<string, mixed> $p
     * @return list<string> problems (translated sentences)
     */
    public function problems(array $p): array
    {
        $db = $this->app->db();
        $t = $this->app->translator();
        $le = (int) $p['legal_entity_id'];
        $c = $db->one('SELECT * FROM ct_companies WHERE id = ?', [$p['company_id']]) ?? [];
        $from = substr((string) $p['starts_at'], 0, 10);
        $until = substr((string) $p['ends_at'], 0, 10);
        $out = [];
        if ((int) ($c['suspended'] ?? 0) === 1) {
            $out[] = $t->get('contractors.problem.suspended', ['name' => (string) $c['name']]);
        }
        $miss = $this->missing($le, (int) $p['company_id'], null, $from, $until);
        if ($miss !== []) {
            $out[] = $t->get('contractors.problem.company_docs', ['name' => (string) ($c['name'] ?? ''), 'docs' => implode(', ', $miss)]);
        }
        foreach ($db->all('SELECT w.* FROM ct_permit_workers pw JOIN ct_workers w ON w.id = pw.worker_id WHERE pw.permit_id = ? ORDER BY w.name', [$p['id']]) as $w) {
            if ((int) $w['active'] !== 1) {
                $out[] = $t->get('contractors.problem.worker_inactive', ['name' => (string) $w['name']]);
            }
            $miss = $this->missing($le, (int) $p['company_id'], (int) $w['id'], $from, $until);
            if ($miss !== []) {
                $out[] = $t->get('contractors.problem.worker_docs', ['name' => (string) $w['name'], 'docs' => implode(', ', $miss)]);
            }
            $clash = $db->scalar(
                "SELECT p.ref FROM ct_permit_workers pw JOIN ct_permits p ON p.id = pw.permit_id WHERE pw.worker_id = ? AND p.id <> ? AND p.status IN ('review','approved','on_site')
                    AND p.starts_at < ? AND p.ends_at > ? LIMIT 1",
                [$w['id'], $p['id'], $p['ends_at'], $p['starts_at']]
            );
            if ($clash !== null) {
                $out[] = $t->get('contractors.problem.overlap', ['name' => (string) $w['name'], 'ref' => (string) $clash]);
            }
        }
        return $out;
    }

    /** Sends a draft for approval after the checks (refused with the list of problems). */
    public function submit(Authorizer $az, string $pid): void
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM ct_permits WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $le = (int) $p['legal_entity_id'];
        $this->require($az, 'contractors.manage', $le);
        if ($p['status'] !== 'draft') {
            throw new BusinessException('contractors.error.not_draft', [], 409);
        }
        if ((string) $p['starts_at'] < $this->now()) {
            throw new BusinessException('contractors.error.past', [], 409);
        }
        $problems = $this->problems($p);
        if ($problems !== []) {
            throw new BusinessException('contractors.error.problems', ['list' => implode(' · ', $problems)], 409);
        }
        $ref = $p['ref'] ?? $this->nextRef($le);
        $db->run("UPDATE ct_permits SET status = 'review', ref = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'draft'", [$ref, $this->stamp(), $p['id']]);
        $company = (string) $db->scalar('SELECT name FROM ct_companies WHERE id = ?', [$p['company_id']]);
        $title = $this->app->translator()->get('contractors.wf_title', ['ref' => (string) $ref, 'company' => $company, 'when' => substr((string) $p['starts_at'], 0, 16)]);
        $inst = $this->app->workflow()->start('contractors.permit', $pid, $title, $le, $az->userId, (int) $p['site_id'], null);
        $db->run('UPDATE ct_permits SET workflow_id = ? WHERE id = ?', [$inst, $p['id']]);
        $this->event((int) $p['id'], (int) $p['company_id'], 'submitted', $az->userId);
        $this->app->audit()->record('contractors.permit_submitted', 'success', 'ct_permit', $pid, $le, null, ['ref' => $ref]);
    }

    public function onApproved(string $pid, int $approverId): void
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM ct_permits WHERE public_id = ?', [$pid]);
        if ($p === null || $p['status'] !== 'review') {
            return;
        }
        $db->run("UPDATE ct_permits SET status = 'approved', updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'review'", [$this->stamp(), $p['id']]);
        $this->event((int) $p['id'], (int) $p['company_id'], 'approved', $approverId);
        foreach (array_unique([(int) $p['requester_id'], (int) $p['supervisor_id']]) as $u) {
            $this->app->notifier()->notify($u, 'contractors.permit', 'contractors.notify.approved', ['ref' => (string) $p['ref']], '/contractors/p/' . $pid, 'ct.ok:' . $pid . ':' . $u);
        }
    }

    /** @param 'rejected'|'returned'|'cancelled' $outcome */
    public function onClosed(string $pid, string $outcome): void
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM ct_permits WHERE public_id = ?', [$pid]);
        if ($p === null || $p['status'] !== 'review') {
            return;
        }
        $to = ['rejected' => 'rejected', 'returned' => 'draft', 'cancelled' => 'cancelled'][$outcome];
        $db->run('UPDATE ct_permits SET status = ?, workflow_id = IF(? = \'draft\', NULL, workflow_id), updated_at = ?, row_version = row_version + 1 WHERE id = ?', [$to, $to, $this->stamp(), $p['id']]);
        $this->event((int) $p['id'], (int) $p['company_id'], $outcome, null);
        $this->app->notifier()->notify((int) $p['requester_id'], 'contractors.permit', 'contractors.notify.' . $outcome, ['ref' => (string) $p['ref']], '/contractors/p/' . $pid, 'ct.cl:' . $pid . ':' . $this->stamp());
    }

    /** Cancels a draft, a permit under review (its requester withdraws the approval) or an approved permit nobody entered on. */
    public function cancel(Authorizer $az, string $pid, string $reason): void
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM ct_permits WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $this->require($az, 'contractors.manage', (int) $p['legal_entity_id']);
        if (!in_array($p['status'], ['draft', 'review', 'approved'], true)) {
            throw new BusinessException('contractors.error.cannot_cancel', [], 409);
        }
        if ($p['status'] === 'review' && $p['workflow_id'] !== null) {
            $this->app->workflow()->cancel((string) $p['workflow_id'], $az->userId, $reason === '' ? '-' : $reason); // → onClosed('cancelled')
            return;
        }
        $db->run("UPDATE ct_permits SET status = 'cancelled', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->stamp(), $p['id']]);
        $this->event((int) $p['id'], (int) $p['company_id'], 'cancelled', $az->userId, $reason);
    }

    /** @return array<string, mixed> a permit with workers (inside now?), check-ins, problems and history */
    public function permit(Authorizer $az, string $pid): array
    {
        $db = $this->app->db();
        $p = $db->one(
            'SELECT p.*, c.name AS company_name, c.public_id AS company_pid, c.suspended, s.code AS site_code, s.public_id AS site_pid, COALESCE(u.display_name, u.username) AS supervisor_name, u.username AS supervisor_username,
                    COALESCE(r.display_name, r.username) AS requester_name
               FROM ct_permits p JOIN ct_companies c ON c.id = p.company_id JOIN sites s ON s.id = p.site_id JOIN users u ON u.id = p.supervisor_id JOIN users r ON r.id = p.requester_id WHERE p.public_id = ?',
            [$pid]
        ) ?? throw HttpException::notFound();
        $le = (int) $p['legal_entity_id'];
        $viewer = $az->scope('contractors.view')->coversLegalEntity($le) || $az->scope('contractors.gate')->coversLegalEntity($le) || (int) $p['supervisor_id'] === $az->userId;
        if (!$viewer) {
            throw HttpException::notFound();
        }
        $p['can_manage'] = $az->scope('contractors.manage')->coversLegalEntity($le);
        $p['can_gate'] = $az->scope('contractors.gate')->coversLegalEntity($le);
        $p['is_supervisor'] = (int) $p['supervisor_id'] === $az->userId;
        $p['workers'] = $db->all(
            'SELECT w.*, (SELECT c.id FROM ct_checkins c WHERE c.permit_id = ? AND c.worker_id = w.id AND c.out_at IS NULL LIMIT 1) AS inside
               FROM ct_permit_workers pw JOIN ct_workers w ON w.id = pw.worker_id WHERE pw.permit_id = ? ORDER BY w.name',
            [$p['id'], $p['id']]
        );
        $p['checkins'] = $db->all('SELECT c.*, w.name AS worker_name FROM ct_checkins c JOIN ct_workers w ON w.id = c.worker_id WHERE c.permit_id = ? ORDER BY c.id DESC', [$p['id']]);
        $p['events'] = $db->all('SELECT e.*, COALESCE(u.display_name, u.username) AS actor FROM ct_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.permit_id = ? ORDER BY e.id', [$p['id']]);
        $p['problems'] = in_array($p['status'], ['draft', 'review', 'approved'], true) ? $this->problems($p) : [];
        return $p;
    }

    // ---- gate ------------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> approved / on-site permits whose window is open today at the person's company sites */
    public function atGate(Authorizer $az): array
    {
        $les = $this->companies($az, 'contractors.gate');
        if ($les === []) {
            return [];
        }
        $day = $this->today();
        return $this->app->db()->all(
            "SELECT p.public_id, p.ref, p.work, p.area, p.starts_at, p.ends_at, p.status, c.name AS company_name, s.code AS site_code,
                    (SELECT COUNT(*) FROM ct_permit_workers pw WHERE pw.permit_id = p.id) AS worker_count,
                    (SELECT COUNT(*) FROM ct_checkins k WHERE k.permit_id = p.id AND k.out_at IS NULL) AS inside
               FROM ct_permits p JOIN ct_companies c ON c.id = p.company_id JOIN sites s ON s.id = p.site_id
              WHERE p.legal_entity_id IN (" . implode(',', $les) . ") AND p.status IN ('approved','on_site') AND p.starts_at < ? AND p.ends_at > ? ORDER BY p.starts_at",
            [$day . ' 23:59:59', $day . ' 00:00:00']
        );
    }

    /** Checks a worker in (inside the window; documents and company re-checked today) or out. */
    public function gate(Authorizer $az, string $pid, string $workerPid, bool $in): void
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM ct_permits WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $le = (int) $p['legal_entity_id'];
        $this->require($az, 'contractors.gate', $le);
        $w = $db->one('SELECT w.* FROM ct_permit_workers pw JOIN ct_workers w ON w.id = pw.worker_id WHERE pw.permit_id = ? AND w.public_id = ?', [$p['id'], $workerPid]) ?? throw HttpException::notFound();
        $now = $this->now();
        $db->transactional(function () use ($db, $p, $w, $in, $now, $az, $le): void {
            $x = $db->one('SELECT * FROM ct_permits WHERE id = ? FOR UPDATE', [$p['id']]);
            $open = $db->one('SELECT * FROM ct_checkins WHERE permit_id = ? AND worker_id = ? AND out_at IS NULL', [$p['id'], $w['id']]);
            if (!$in) {
                if ($open === null) {
                    throw new BusinessException('contractors.error.not_inside', [], 409);
                }
                $db->run('UPDATE ct_checkins SET out_at = ?, out_by = ? WHERE id = ?', [$now, $az->userId, $open['id']]);
                $this->event((int) $p['id'], (int) $p['company_id'], 'checked_out', $az->userId, (string) $w['name']);
                return;
            }
            if ($x === null || !in_array($x['status'], ['approved', 'on_site'], true)) {
                throw new BusinessException('contractors.error.not_approved', [], 409);
            }
            if ($now < (string) $x['starts_at'] || $now >= (string) $x['ends_at']) {
                throw new BusinessException('contractors.error.outside_window', ['from' => substr((string) $x['starts_at'], 0, 16), 'to' => substr((string) $x['ends_at'], 0, 16)], 409);
            }
            if ($open !== null) {
                throw new BusinessException('contractors.error.already_inside', [], 409);
            }
            $c = $db->one('SELECT * FROM ct_companies WHERE id = ?', [$p['company_id']]) ?? [];
            $today = $this->today();
            if ((int) ($c['suspended'] ?? 0) === 1 || (int) $w['active'] !== 1 || $this->missing($le, (int) $p['company_id'], null, $today, $today) !== [] || $this->missing($le, (int) $p['company_id'], (int) $w['id'], $today, $today) !== []) {
                throw new BusinessException('contractors.error.not_qualified_today', ['name' => (string) $w['name']], 409);
            }
            $db->run('INSERT INTO ct_checkins (permit_id, worker_id, in_at, in_by) VALUES (?, ?, ?, ?)', [$p['id'], $w['id'], $now, $az->userId]);
            if ($x['status'] === 'approved') {
                $db->run("UPDATE ct_permits SET status = 'on_site', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->stamp(), $p['id']]);
                $this->app->notifier()->notify((int) $p['supervisor_id'], 'contractors.permit', 'contractors.notify.arrived', ['ref' => (string) $p['ref']], '/contractors/p/' . $p['public_id'], 'ct.in:' . $p['public_id']);
            }
            $this->event((int) $p['id'], (int) $p['company_id'], 'checked_in', $az->userId, (string) $w['name']);
        });
    }

    /**
     * The supervisor or a manager closes the work: everyone out, area checked, equipment back, a rating 1–5.
     * @param array<string, mixed> $in
     */
    public function complete(Authorizer $az, string $pid, array $in): void
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM ct_permits WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $le = (int) $p['legal_entity_id'];
        if ((int) $p['supervisor_id'] !== $az->userId && !$az->scope('contractors.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        if ($p['status'] !== 'on_site') {
            throw new BusinessException('contractors.error.not_on_site', [], 409);
        }
        if ((int) $db->scalar('SELECT COUNT(*) FROM ct_checkins WHERE permit_id = ? AND out_at IS NULL', [$p['id']]) > 0) {
            throw new BusinessException('contractors.error.still_inside', [], 409);
        }
        $e = [];
        if (empty($in['area_checked'])) {
            $e['area_checked'] = 'contractors.error.area_checked';
        }
        if (empty($in['equipment_returned'])) {
            $e['equipment_returned'] = 'contractors.error.equipment_returned';
        }
        $rating = (string) ($in['rating'] ?? '');
        if (!in_array($rating, ['1', '2', '3', '4', '5'], true)) {
            $e['rating'] = 'contractors.error.rating';
        }
        $note = trim((string) ($in['review_note'] ?? ''));
        if ($rating !== '' && (int) $rating <= 2 && mb_strlen($note) < 5) {
            $e['review_note'] = 'contractors.error.low_rating_note';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db->run(
            "UPDATE ct_permits SET status = 'completed', area_checked = 1, equipment_returned = 1, rating = ?, review_note = ?, completed_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ? AND status = 'on_site'",
            [(int) $rating, $note === '' ? null : mb_substr($note, 0, 1000), $this->now(), $this->stamp(), $az->userId, $p['id']]
        );
        $this->event((int) $p['id'], (int) $p['company_id'], 'completed', $az->userId, $rating . '/5');
        $this->app->audit()->record('contractors.permit_completed', 'success', 'ct_permit', $pid, $le, null, ['rating' => (int) $rating]);
    }

    // ---- housekeeping and lists ---------------------------------------------------------------------------------------------

    /**
     * Hourly: approved permits whose window ended without anyone entering expire; on-site permits past their end with
     * someone still inside are flagged to the supervisor once; documents ending within the company's alert days are flagged
     * to the contractor's owner (or the managers) once.
     */
    public function tick(): int
    {
        $db = $this->app->db();
        $now = $this->now();
        $n = 0;
        foreach ($db->all("SELECT id, public_id, company_id FROM ct_permits WHERE status = 'approved' AND ends_at <= ? LIMIT 500", [$now]) as $p) {
            $db->run("UPDATE ct_permits SET status = 'expired', updated_at = ? WHERE id = ? AND status = 'approved'", [$this->stamp(), $p['id']]);
            $this->event((int) $p['id'], (int) $p['company_id'], 'expired', null);
            $n++;
        }
        foreach ($db->all("SELECT p.id, p.public_id, p.ref, p.supervisor_id FROM ct_permits p WHERE p.status = 'on_site' AND p.ends_at <= ? AND p.overdue_notified = 0 AND EXISTS (SELECT 1 FROM ct_checkins c WHERE c.permit_id = p.id AND c.out_at IS NULL) LIMIT 500", [$now]) as $p) {
            $db->run('UPDATE ct_permits SET overdue_notified = 1 WHERE id = ?', [$p['id']]);
            $this->app->notifier()->notify((int) $p['supervisor_id'], 'contractors.permit', 'contractors.notify.overdue', ['ref' => (string) $p['ref']], '/contractors/p/' . $p['public_id'], 'ct.od:' . $p['public_id']);
            $n++;
        }
        foreach ($db->all('SELECT legal_entity_id, alert_days FROM ct_settings WHERE alert_days IS NOT NULL') as $s) {
            $limit = $this->nowLocal()->modify('+' . (int) $s['alert_days'] . ' days')->format('Y-m-d');
            foreach (
                $db->all(
                    'SELECT d.id, d.valid_until, t.name AS type_name, c.public_id AS company_pid, c.name AS company_name, c.owner_id, w.name AS worker_name FROM ct_documents d JOIN ct_doc_types t ON t.id = d.doc_type_id
                       JOIN ct_companies c ON c.id = d.company_id LEFT JOIN ct_workers w ON w.id = d.worker_id
                      WHERE c.legal_entity_id = ? AND d.alerted = 0 AND d.valid_until IS NOT NULL AND d.valid_until <= ? AND d.valid_until >= ?
                        AND NOT EXISTS (SELECT 1 FROM ct_documents n WHERE n.company_id = d.company_id AND n.doc_type_id = d.doc_type_id AND (n.worker_id <=> d.worker_id) AND (n.valid_until IS NULL OR n.valid_until > ?))
                      LIMIT 500',
                    [$s['legal_entity_id'], $limit, $this->today(), $limit]
                ) as $d
            ) {
                $db->run('UPDATE ct_documents SET alerted = 1 WHERE id = ?', [$d['id']]);
                $to = $d['owner_id'] !== null ? [(int) $d['owner_id']] : $this->staff((int) $s['legal_entity_id'], 'contractors.manage');
                foreach ($to as $u) {
                    $this->app->notifier()->notify($u, 'contractors.expiry', 'contractors.notify.expiring', ['company' => (string) $d['company_name'], 'doc' => $d['type_name'] . ($d['worker_name'] !== null ? ' (' . $d['worker_name'] . ')' : ''), 'until' => (string) $d['valid_until']], '/contractors/c/' . $d['company_pid'], 'ct.ex:' . $d['id'] . ':' . $u);
                }
                $n++;
            }
        }
        return $n;
    }

    public static function tickJob(App $app): string
    {
        return 'contractors: ' . self::of($app)->tick();
    }

    /** @return list<int> */
    private function staff(int $le, string $perm): array
    {
        $out = [];
        foreach ($this->app->db()->all("SELECT DISTINCT a.user_id FROM user_role_assignments a JOIN users u ON u.id = a.user_id JOIN role_permissions p ON p.role_id = a.role_id WHERE a.status = 'active' AND u.status = 'active' AND p.permission_code = ?", [$perm]) as $r) {
            $az = new Authorizer($this->app->db(), $this->app->clock, (int) $r['user_id']);
            if ($az->scope($perm)->coversLegalEntity($le)) {
                $out[] = (int) $r['user_id'];
            }
        }
        return $out;
    }

    /**
     * Permits of a company (filters) and the documents expiring soon.
     *
     * @param array<string, string> $f status
     * @return array<string, mixed>
     */
    public function overview(Authorizer $az, int $le, array $f = []): array
    {
        $this->require($az, 'contractors.view', $le);
        $db = $this->app->db();
        $where = 'p.legal_entity_id = ?';
        $p = [$le];
        if (($f['status'] ?? '') !== '') {
            $where .= ' AND p.status = ?';
            $p[] = (string) $f['status'];
        }
        $settings = $this->settings($le);
        $limit = $this->nowLocal()->modify('+' . (int) ($settings['alert_days'] ?? 30) . ' days')->format('Y-m-d');
        return [
            'permits' => $db->all(
                "SELECT p.public_id, p.ref, p.work, p.area, p.starts_at, p.ends_at, p.status, p.rating, c.name AS company_name, s.code AS site_code,
                        (SELECT COUNT(*) FROM ct_checkins k WHERE k.permit_id = p.id AND k.out_at IS NULL) AS inside
                   FROM ct_permits p JOIN ct_companies c ON c.id = p.company_id JOIN sites s ON s.id = p.site_id WHERE {$where} ORDER BY p.starts_at DESC LIMIT 300",
                $p
            ),
            'expiring' => $db->all(
                'SELECT d.valid_until, t.name AS type_name, c.public_id AS company_pid, c.name AS company_name, w.name AS worker_name FROM ct_documents d JOIN ct_doc_types t ON t.id = d.doc_type_id
                   JOIN ct_companies c ON c.id = d.company_id LEFT JOIN ct_workers w ON w.id = d.worker_id
                  WHERE c.legal_entity_id = ? AND d.valid_until IS NOT NULL AND d.valid_until <= ? AND d.valid_until >= ?
                    AND NOT EXISTS (SELECT 1 FROM ct_documents n WHERE n.company_id = d.company_id AND n.doc_type_id = d.doc_type_id AND (n.worker_id <=> d.worker_id) AND (n.valid_until IS NULL OR n.valid_until > ?))
                  ORDER BY d.valid_until LIMIT 100',
                [$le, $limit, $this->today(), $limit]
            ),
            'alert_days' => $settings['alert_days'],
        ];
    }
}
