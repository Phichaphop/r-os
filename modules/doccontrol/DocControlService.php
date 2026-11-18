<?php

declare(strict_types=1);

namespace Blue\Modules\Doccontrol;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\Audience;
use Blue\Modules\Employees\EmployeeService;

/**
 * M40 Document control & policy acknowledgement (phase 5D, DEC-72).
 *
 *  document  a controlled policy / work rule / procedure / work instruction of a company: code, owner, target group,
 *            review cycle. Only the CURRENT effective version is shown to employees.
 *  version   one immutable PDF (hash kept) + what changed + effective date: draft → review → another person with
 *            doccontrol.approve approves → effective on its date (hourly job) and the former version is superseded.
 *  ack       when a version needs acknowledgement, everyone in the target group on that day gets a pending task;
 *            people who join the group later get one too, people who leave the group lose an open one (done ones stay as
 *            evidence). The employee must open the file first, then confirm the statement; time, IP, browser, statement
 *            and the file hash are kept. An acknowledgement of an older version never counts for a newer one.
 *            HR may mark an exception with a reason (e.g. long leave). Leavers drop out of the open count.
 */
final class DocControlService
{
    public const TYPES = ['policy', 'rule', 'procedure', 'work_instruction', 'other'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public function emp(): EmployeeService
    {
        return EmployeeService::of($this->app);
    }

    public function today(): string
    {
        return $this->emp()->today();
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    // ---- managing -----------------------------------------------------------------------------------------------------

    /** @return list<int> */
    public function companies(Authorizer $az): array
    {
        $scope = $az->scope('doccontrol.manage');
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    /** @return array<string, mixed> */
    public function managed(Authorizer $az, string $pid): array
    {
        $d = $this->app->db()->one('SELECT * FROM dc_documents WHERE public_id = ?', [$pid]);
        if ($d === null || !$az->scope('doccontrol.manage')->coversLegalEntity((int) $d['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $d;
    }

    /** @param array<string, mixed> $in code, title, doc_type, review_months + audience */
    public function save(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$az->scope('doccontrol.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $old = $pid !== null ? $this->managed($az, $pid) : null;
        $errors = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9_.-]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'doccontrol.error.code';
        } elseif ($db->scalar('SELECT id FROM dc_documents WHERE legal_entity_id = ? AND code = ?' . ($old !== null ? ' AND id <> ?' : ''), $old !== null ? [$le, $code, $old['id']] : [$le, $code]) !== null) {
            $errors['code'] = 'doccontrol.error.code_taken';
        }
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'doccontrol.error.title';
        }
        $type = in_array($in['doc_type'] ?? '', self::TYPES, true) ? (string) $in['doc_type'] : 'policy';
        $rm = trim((string) ($in['review_months'] ?? ''));
        if ($rm !== '' && (!ctype_digit($rm) || (int) $rm < 1 || (int) $rm > 120)) {
            $errors['review_months'] = 'doccontrol.error.review_months';
        }
        try {
            $aud = Audience::fromInput($this->app, $le, $in);
        } catch (ValidationException $e) {
            $errors += $e->errors;
            $aud = Audience::empty();
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->now();
        if ($old === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                "INSERT INTO dc_documents (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, title, doc_type, owner_user_id, audience_json, review_months, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $code, $title, $type, $az->userId, Audience::encode($aud), $rm === '' ? null : (int) $rm]
            );
            $this->app->audit()->record('doccontrol.created', 'success', 'dc_document', $pid, $le, null, ['code' => $code]);
            return $pid;
        }
        $db->run(
            'UPDATE dc_documents SET code = ?, title = ?, doc_type = ?, audience_json = ?, review_months = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$code, $title, $type, Audience::encode($aud), $rm === '' ? null : (int) $rm, $now, $az->userId, $old['id']]
        );
        $this->app->audit()->record('doccontrol.changed', 'success', 'dc_document', (string) $pid, $le, null, ['audience' => $aud]);
        if ($old['current_version_id'] !== null) {
            $this->syncAcks((int) $old['current_version_id']); // the target group changed: add / drop open tasks
        }
        return (string) $pid;
    }

    /** @param array<string, mixed> $in change_summary, effective_from, requires_ack */
    public function upload(Authorizer $az, string $docPid, ?UploadedFile $file, array $in): string
    {
        $d = $this->managed($az, $docPid);
        $db = $this->app->db();
        if ($d['status'] === 'archived') {
            throw new BusinessException('doccontrol.error.archived', [], 409);
        }
        if ($db->scalar("SELECT id FROM dc_versions WHERE document_id = ? AND status IN ('draft','review','approved')", [$d['id']]) !== null) {
            throw new BusinessException('doccontrol.error.open_version', [], 409);
        }
        $errors = [];
        if ($file === null || !$file->ok() || $file->size === 0) {
            $errors['file'] = 'file.error.missing';
        }
        $sum = trim((string) ($in['change_summary'] ?? ''));
        if (mb_strlen($sum) < 5 || mb_strlen($sum) > 1000) {
            $errors['change_summary'] = 'doccontrol.error.summary';
        }
        $eff = (string) ($in['effective_from'] ?? '');
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $eff);
        if ($dt === false || $dt->format('Y-m-d') !== $eff) {
            $errors['effective_from'] = 'doccontrol.error.date';
        } elseif ($eff < $this->today()) {
            $errors['effective_from'] = 'doccontrol.error.past';
        }
        if ($errors !== [] || $file === null) {
            throw new ValidationException($errors);
        }
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'doccontrol.version', $file->bytes(), $file->safeName(), [
                'title' => 'Controlled document ' . $d['code'], 'legal_entity_id' => (int) $d['legal_entity_id'],
            ], 'upload', true);
        } catch (ValidationException $ex) {
            throw new ValidationException(['file' => array_values($ex->errors)[0] ?? 'file.error.type_not_allowed']);
        }
        $sha = (string) ($db->scalar('SELECT v.sha256 FROM document_versions v JOIN documents x ON x.id = v.document_id WHERE x.public_id = ? ORDER BY v.version_no DESC LIMIT 1', [(string) $stored['document']]) ?? hash('sha256', $file->bytes()));
        $no = (int) $db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM dc_versions WHERE document_id = ?', [$d['id']]);
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->run(
            "INSERT INTO dc_versions (public_id, created_at, created_by, updated_at, updated_by, document_id, version_no, document_pid, file_name, sha256, change_summary, effective_from, requires_ack, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
            [$pid, $now, $az->userId, $now, $az->userId, $d['id'], $no, (string) $stored['document'], mb_substr($file->safeName(), 0, 200), $sha, $sum, $eff, ($in['requires_ack'] ?? '') === '1' ? 1 : 0]
        );
        $this->app->audit()->record('doccontrol.version_uploaded', 'success', 'dc_version', $pid, (int) $d['legal_entity_id'], null, ['code' => $d['code'], 'version' => $no, 'sha256' => $sha]);
        return $pid;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} version + document (manager) */
    private function managedVersion(Authorizer $az, string $versionPid): array
    {
        $v = $this->app->db()->one('SELECT v.*, d.public_id AS doc_pid FROM dc_versions v JOIN dc_documents d ON d.id = v.document_id WHERE v.public_id = ?', [$versionPid]);
        if ($v === null) {
            throw HttpException::notFound();
        }
        return [$v, $this->managed($az, (string) $v['doc_pid'])];
    }

    public function submit(Authorizer $az, string $versionPid): void
    {
        [$v, $d] = $this->managedVersion($az, $versionPid);
        if ($v['status'] !== 'draft') {
            throw new BusinessException('doccontrol.error.state', [], 409);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $az, $v, $d): void {
            $title = $this->app->translator()->get('doccontrol.title_line', ['code' => (string) $d['code'], 'n' => (string) $v['version_no'], 'title' => (string) $d['title']]);
            $inst = $this->app->workflow()->start('doccontrol.approve', (string) $v['public_id'], $title, (int) $d['legal_entity_id'], $az->userId);
            $db->run("UPDATE dc_versions SET status = 'review', workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?), updated_at = ? WHERE id = ?", [$inst, $this->now(), $v['id']]);
        });
        $this->app->audit()->record('doccontrol.version_submitted', 'success', 'dc_version', $versionPid, (int) $d['legal_entity_id']);
    }

    public function withdraw(Authorizer $az, string $versionPid): void
    {
        [$v, $d] = $this->managedVersion($az, $versionPid);
        if (!in_array($v['status'], ['draft', 'approved'], true)) {
            throw new BusinessException('doccontrol.error.state', [], 409);
        }
        $this->app->db()->run("UPDATE dc_versions SET status = 'withdrawn', updated_at = ? WHERE id = ?", [$this->now(), $v['id']]);
        $this->app->audit()->record('doccontrol.version_withdrawn', 'success', 'dc_version', $versionPid, (int) $d['legal_entity_id']);
    }

    public function onApproved(string $versionPid, int $approverId): void
    {
        $this->app->db()->run("UPDATE dc_versions SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = ? WHERE public_id = ? AND status = 'review'", [$approverId, $this->now(), $this->now(), $versionPid]);
        $this->activateDue();
    }

    public function onClosed(string $versionPid, string $outcome): void
    {
        $this->app->db()->run("UPDATE dc_versions SET status = ?, updated_at = ? WHERE public_id = ? AND status = 'review'", [$outcome === 'returned' ? 'draft' : 'rejected', $this->now(), $versionPid]);
    }

    public static function syncJob(App $app): int
    {
        $svc = self::of($app);
        $n = $svc->activateDue();
        foreach ($app->db()->all("SELECT id FROM dc_versions WHERE status = 'effective' AND requires_ack = 1") as $v) {
            $n += $svc->syncAcks((int) $v['id']);
        }
        return $n + $svc->reviewReminders();
    }

    /** Approved versions whose date has come become effective; the former one is superseded. */
    public function activateDue(): int
    {
        $db = $this->app->db();
        $n = 0;
        foreach (
            $db->all("SELECT v.*, d.review_months, d.legal_entity_id, d.public_id AS doc_pid, d.code, d.title FROM dc_versions v JOIN dc_documents d ON d.id = v.document_id
                            WHERE v.status = 'approved' AND v.effective_from <= ? ORDER BY v.effective_from, v.id", [$this->today()]) as $v
        ) {
            $review = $v['review_months'] !== null ? \Blue\Modules\Employees\EmployeeService::ymd((string) $v['effective_from'])->modify('+' . (int) $v['review_months'] . ' months')->format('Y-m-d') : null;
            $db->transactional(function () use ($db, $v, $review): void {
                $now = $this->now();
                $db->run("UPDATE dc_versions SET status = 'superseded', updated_at = ? WHERE document_id = ? AND status = 'effective'", [$now, $v['document_id']]);
                $db->run("UPDATE dc_versions SET status = 'effective', effective_at = ?, review_due = ?, updated_at = ? WHERE id = ?", [$now, $review, $now, $v['id']]);
                $db->run('UPDATE dc_documents SET current_version_id = ?, updated_at = ? WHERE id = ?', [$v['id'], $now, $v['document_id']]);
            });
            $this->app->audit()->record('doccontrol.effective', 'success', 'dc_version', (string) $v['public_id'], (int) $v['legal_entity_id'], null, ['code' => $v['code'], 'version' => $v['version_no']]);
            if ((int) $v['requires_ack'] === 1) {
                $this->syncAcks((int) $v['id']);
            }
            $n++;
        }
        return $n;
    }

    /** Open acknowledgement tasks follow the target group of the effective version. Returns tasks added. */
    public function syncAcks(int $versionId): int
    {
        $db = $this->app->db();
        $v = $db->one("SELECT v.*, d.legal_entity_id, d.audience_json, d.public_id AS doc_pid, d.title FROM dc_versions v JOIN dc_documents d ON d.id = v.document_id WHERE v.id = ?", [$versionId]);
        if ($v === null || $v['status'] !== 'effective' || (int) $v['requires_ack'] !== 1) {
            return 0;
        }
        $members = Audience::members($this->app, (int) $v['legal_entity_id'], Audience::decode((string) $v['audience_json']), $this->today());
        $now = $this->now();
        $added = 0;
        foreach ($members as $empId => $uid) {
            $ins = $db->run('INSERT IGNORE INTO dc_acks (version_id, employee_id, user_id, status, assigned_at) VALUES (?, ?, ?, \'pending\', ?)', [$versionId, $empId, $uid, $now])->rowCount();
            if ($ins === 1) {
                $added++;
                if ($uid !== null) {
                    $this->app->notifier()->notify($uid, 'doccontrol.ack', 'doccontrol.notify.ack', ['title' => (string) $v['title']], '/policies/d/' . $v['doc_pid'], 'dc.ack:' . $versionId . ':' . $uid);
                }
            } elseif ($uid !== null) {
                $db->run('UPDATE dc_acks SET user_id = ? WHERE version_id = ? AND employee_id = ? AND user_id IS NULL', [$uid, $versionId, $empId]);
            }
        }
        $keep = array_keys($members);
        $db->run(
            "DELETE FROM dc_acks WHERE version_id = ? AND status = 'pending'" . ($keep !== [] ? ' AND employee_id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')' : ''),
            array_merge([$versionId], $keep)
        );
        return $added;
    }

    private function reviewReminders(): int
    {
        $n = 0;
        foreach (
            $this->app->db()->all("SELECT v.public_id, v.review_due, d.owner_user_id, d.public_id AS doc_pid, d.code FROM dc_versions v JOIN dc_documents d ON d.id = v.document_id
                                         WHERE v.status = 'effective' AND v.review_due IS NOT NULL AND v.review_due <= ? AND d.status = 'active'", [$this->today()]) as $r
        ) {
            $this->app->notifier()->notify((int) $r['owner_user_id'], 'doccontrol.review_due', 'doccontrol.notify.review_due', ['code' => (string) $r['code']], '/policies/admin/d/' . $r['doc_pid'], 'dc.review:' . $r['public_id']);
            $n++;
        }
        return $n;
    }

    public function archive(Authorizer $az, string $docPid, string $reason): void
    {
        $d = $this->managed($az, $docPid);
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $db = $this->app->db();
        $db->run("UPDATE dc_documents SET status = 'archived', updated_at = ? WHERE id = ?", [$this->now(), $d['id']]);
        if ($d['current_version_id'] !== null) {
            $db->run("DELETE FROM dc_acks WHERE version_id = ? AND status = 'pending'", [$d['current_version_id']]);
        }
        $this->app->audit()->record('doccontrol.archived', 'success', 'dc_document', $docPid, (int) $d['legal_entity_id'], trim($reason));
    }

    /** @return list<array<string, mixed>> */
    public function adminList(int $le): array
    {
        return $this->app->db()->all(
            "SELECT d.*, v.version_no AS current_no, v.effective_from, v.review_due, v.requires_ack,
                    (SELECT COUNT(*) FROM dc_acks a JOIN employees e ON e.id = a.employee_id WHERE a.version_id = d.current_version_id AND a.status = 'pending' AND e.status <> 'former') AS pending,
                    (SELECT COUNT(*) FROM dc_acks a WHERE a.version_id = d.current_version_id AND a.status = 'acknowledged') AS acked,
                    (SELECT COUNT(*) FROM dc_versions x WHERE x.document_id = d.id AND x.status IN ('draft','review','approved')) AS open_versions
               FROM dc_documents d LEFT JOIN dc_versions v ON v.id = d.current_version_id WHERE d.legal_entity_id = ? ORDER BY d.status, d.code",
            [$le]
        );
    }

    /** @return list<array<string, mixed>> */
    public function versions(int $docId): array
    {
        return $this->app->db()->all(
            "SELECT v.*, u.display_name AS created_name, a.display_name AS approved_name, i.public_id AS instance_pid,
                    (SELECT COUNT(*) FROM dc_acks k WHERE k.version_id = v.id AND k.status = 'acknowledged') AS acked
               FROM dc_versions v LEFT JOIN users u ON u.id = v.created_by LEFT JOIN users a ON a.id = v.approved_by LEFT JOIN workflow_instances i ON i.id = v.workflow_instance_id
              WHERE v.document_id = ? ORDER BY v.version_no DESC",
            [$docId]
        );
    }

    /**
     * Acknowledgement report of a version: counts (leavers left out of "pending") + every person.
     *
     * @return array{total: int, acked: int, pending: int, exception: int, pct: float, rows: list<array<string, mixed>>}
     */
    public function report(int $versionId): array
    {
        $rows = $this->app->db()->all(
            "SELECT a.*, e.employee_code, e.name_th, e.name_en, e.preferred_name, e.status AS emp_status FROM dc_acks a JOIN employees e ON e.id = a.employee_id
              WHERE a.version_id = ? ORDER BY FIELD(a.status, 'pending', 'exception', 'acknowledged'), e.employee_code",
            [$versionId]
        );
        $c = ['acknowledged' => 0, 'pending' => 0, 'exception' => 0];
        $out = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'pending' && $r['emp_status'] === 'former') {
                continue;
            }
            $c[(string) $r['status']]++;
            $out[] = $r + ['display' => $r['employee_code'] . ' · ' . $this->emp()->displayName($r)];
        }
        $total = array_sum($c);
        return ['total' => $total, 'acked' => $c['acknowledged'], 'pending' => $c['pending'], 'exception' => $c['exception'], 'pct' => $total > 0 ? round($c['acknowledged'] * 100 / $total, 1) : 0.0, 'rows' => $out];
    }

    public function exception(Authorizer $az, string $docPid, int $ackId, string $reason): void
    {
        $d = $this->managed($az, $docPid);
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['exception_reason' => 'doccontrol.error.exception_reason']);
        }
        $n = $this->app->db()->run(
            "UPDATE dc_acks SET status = 'exception', exception_reason = ?, decided_by = ? WHERE id = ? AND version_id = ? AND status = 'pending'",
            [mb_substr(trim($reason), 0, 300), $az->userId, $ackId, $d['current_version_id']]
        )->rowCount();
        if ($n !== 1) {
            throw new BusinessException('doccontrol.error.state', [], 409);
        }
        $this->app->audit()->record('doccontrol.exception', 'success', 'dc_document', $docPid, (int) $d['legal_entity_id'], trim($reason), ['ack' => $ackId]);
    }

    public function remind(Authorizer $az, string $docPid): int
    {
        $d = $this->managed($az, $docPid);
        $day = $this->today();
        $n = 0;
        foreach ($this->app->db()->all("SELECT a.id, a.user_id FROM dc_acks a JOIN employees e ON e.id = a.employee_id WHERE a.version_id = ? AND a.status = 'pending' AND a.user_id IS NOT NULL AND e.status <> 'former'", [$d['current_version_id']]) as $a) {
            $this->app->notifier()->notify((int) $a['user_id'], 'doccontrol.reminder', 'doccontrol.notify.reminder', ['title' => (string) $d['title']], '/policies/d/' . $docPid, 'dc.remind:' . $a['id'] . ':' . $day);
            $this->app->db()->run('UPDATE dc_acks SET reminded_at = ? WHERE id = ?', [$this->now(), $a['id']]);
            $n++;
        }
        $this->app->audit()->record('doccontrol.reminded', 'success', 'dc_document', $docPid, (int) $d['legal_entity_id'], null, ['people' => $n]);
        return $n;
    }

    // ---- employees ----------------------------------------------------------------------------------------------------

    /** @return array{pending: list<array<string, mixed>>, documents: list<array<string, mixed>>} */
    public function mine(Authorizer $az, string $q = ''): array
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null || $me['job'] === null) {
            return ['pending' => [], 'documents' => []];
        }
        $db = $this->app->db();
        $pending = $db->all(
            "SELECT d.public_id, d.code, d.title, d.doc_type, v.version_no, v.effective_from, a.assigned_at FROM dc_acks a JOIN dc_versions v ON v.id = a.version_id JOIN dc_documents d ON d.id = v.document_id
              WHERE a.employee_id = ? AND a.status = 'pending' AND v.status = 'effective' AND d.status = 'active' ORDER BY a.assigned_at",
            [$me['id']]
        );
        $sql = "SELECT d.*, v.version_no, v.effective_from, v.requires_ack, (SELECT a.status FROM dc_acks a WHERE a.version_id = v.id AND a.employee_id = ?) AS my_status
                  FROM dc_documents d JOIN dc_versions v ON v.id = d.current_version_id WHERE d.legal_entity_id = ? AND d.status = 'active' AND v.status = 'effective'";
        $params = [$me['id'], $me['job']['legal_entity_id']];
        if ($q !== '') {
            $sql .= ' AND (d.title LIKE ? OR d.code LIKE ?)';
            array_push($params, '%' . $q . '%', '%' . $q . '%');
        }
        $docs = array_values(array_filter($db->all($sql . ' ORDER BY d.doc_type, d.code', $params), fn (array $d): bool => Audience::matches($this->app, (int) $d['legal_entity_id'], Audience::decode((string) $d['audience_json']), $me['job'])));
        return ['pending' => $pending, 'documents' => $docs];
    }

    /**
     * One document for its reader (current effective version only) or its manager (+ history).
     *
     * @return array<string, mixed>
     */
    public function view(Authorizer $az, string $docPid): array
    {
        $db = $this->app->db();
        $d = $db->one('SELECT d.*, u.display_name AS owner_name FROM dc_documents d LEFT JOIN users u ON u.id = d.owner_user_id WHERE d.public_id = ?', [$docPid]);
        if ($d === null) {
            throw HttpException::notFound();
        }
        $manage = $az->scope('doccontrol.manage')->coversLegalEntity((int) $d['legal_entity_id']);
        $v = $d['current_version_id'] !== null ? $db->one('SELECT * FROM dc_versions WHERE id = ?', [$d['current_version_id']]) : null;
        $me = $this->emp()->forUser($az->userId);
        $reader = $v !== null && $d['status'] === 'active' && $me !== null && $me['status'] !== 'former'
            && Audience::matches($this->app, (int) $d['legal_entity_id'], Audience::decode((string) $d['audience_json']), $me['job']);
        if (!$manage && !$reader) {
            throw HttpException::notFound();
        }
        $d['version'] = $v;
        $d['can_manage'] = $manage;
        $d['my_ack'] = $v !== null && $me !== null ? $db->one('SELECT * FROM dc_acks WHERE version_id = ? AND employee_id = ?', [$v['id'], $me['id']]) : null;
        return $d;
    }

    /**
     * The PDF: readers get the current version (and their "opened" time is noted); managers any version.
     *
     * @return array{bytes: string, mime: string, name: string}
     */
    public function file(Authorizer $az, string $docPid, ?string $versionPid): array
    {
        $d = $this->view($az, $docPid);
        $db = $this->app->db();
        if ($versionPid !== null && $versionPid !== ($d['version']['public_id'] ?? '')) {
            if (!$d['can_manage']) {
                throw HttpException::notFound();
            }
            $v = $db->one('SELECT * FROM dc_versions WHERE public_id = ? AND document_id = ?', [$versionPid, $d['id']]);
        } else {
            $v = $d['version'];
        }
        if ($v === null) {
            throw HttpException::notFound();
        }
        if ($d['my_ack'] !== null && (int) $d['my_ack']['version_id'] === (int) $v['id'] && $d['my_ack']['opened_at'] === null) {
            $db->run('UPDATE dc_acks SET opened_at = ? WHERE id = ?', [$this->now(), $d['my_ack']['id']]);
        }
        $f = $this->app->documents()->moduleRead((string) $v['document_pid'], 'doccontrol', $az->userId, 'controlled document', false);
        return ['bytes' => $f['bytes'], 'mime' => $f['mime'], 'name' => $d['code'] . '_v' . $v['version_no'] . '.pdf'];
    }

    public function acknowledge(Authorizer $az, string $docPid, bool $confirmed, string $ip, string $ua): void
    {
        $d = $this->view($az, $docPid);
        $a = $d['my_ack'];
        if ($a === null || $a['status'] !== 'pending' || $d['version'] === null) {
            throw new BusinessException('doccontrol.error.state', [], 409);
        }
        if ($a['opened_at'] === null) {
            throw new BusinessException('doccontrol.error.open_first', [], 422);
        }
        if (!$confirmed) {
            throw new ValidationException(['confirm' => 'doccontrol.error.confirm']);
        }
        $statement = $this->app->translator()->get('doccontrol.statement', ['code' => (string) $d['code'], 'n' => (string) $d['version']['version_no']]);
        $this->app->db()->run(
            "UPDATE dc_acks SET status = 'acknowledged', acknowledged_at = ?, statement = ?, file_sha256 = ?, ip = ?, user_agent = ? WHERE id = ? AND status = 'pending'",
            [$this->now(), mb_substr($statement, 0, 500), $d['version']['sha256'], mb_substr($ip, 0, 45), mb_substr($ua, 0, 200), $a['id']]
        );
        $this->app->audit()->record('doccontrol.acknowledged', 'success', 'dc_version', (string) $d['version']['public_id'], (int) $d['legal_entity_id'], null, ['version' => (int) $d['version']['version_no']]);
    }
}
