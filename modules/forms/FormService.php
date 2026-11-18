<?php

declare(strict_types=1);

namespace Blue\Modules\Forms;

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
 * M02 Central forms (phase 5D, DEC-72).
 *
 *  form      a company's official form: code, title, category, language, owner, target group (Audience), dates.
 *  version   every file is a new version (Documents class forms.file — type checked, private storage): draft →
 *            review → another person with forms.approve publishes it; the former version becomes superseded and
 *            stays downloadable for form managers only. A published version is never replaced in place.
 *  users     see published forms of their company that target them and are within their dates; search, category,
 *            starred, recently used; preview (PDF/images) or download — every one is logged and counted, and the
 *            file is served through the app (no file URL). Starred users are told about a new version.
 *  sign      "send to sign online" opens M20 with the current PDF (0.17.0) — off, with the reason, when M20 is off, the file is not a PDF or the person may not send.
 */
final class FormService
{
    public const PREVIEW = ['application/pdf', 'image/png', 'image/jpeg'];

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

    /** @return list<int> companies where the user manages forms */
    public function companies(Authorizer $az, string $permission = 'forms.manage'): array
    {
        $scope = $az->scope($permission);
        $out = [];
        foreach ($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code') as $l) {
            if ($scope->coversLegalEntity((int) $l['id'])) {
                $out[] = (int) $l['id'];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> form row the manager may change */
    public function managed(Authorizer $az, string $pid): array
    {
        $f = $this->app->db()->one('SELECT * FROM frm_forms WHERE public_id = ?', [$pid]);
        if ($f === null || !$az->scope('forms.manage')->coversLegalEntity((int) $f['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $f;
    }

    /**
     * Create (pid null) or change a form's details.
     *
     * @param array<string, mixed> $in code, title, description, category, language, starts_on, ends_on + audience fields
     */
    public function save(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$az->scope('forms.manage')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $old = $pid !== null ? $this->managed($az, $pid) : null;
        $errors = [];
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9_.-]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'forms.error.code';
        } elseif ($db->scalar('SELECT id FROM frm_forms WHERE legal_entity_id = ? AND code = ?' . ($old !== null ? ' AND id <> ?' : ''), $old !== null ? [$le, $code, $old['id']] : [$le, $code]) !== null) {
            $errors['code'] = 'forms.error.code_taken';
        }
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'forms.error.title';
        }
        $cat = trim((string) ($in['category'] ?? ''));
        if ($cat === '' || mb_strlen($cat) > 60) {
            $errors['category'] = 'forms.error.category';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) > 500) {
            $errors['description'] = 'forms.error.description';
        }
        $lang = in_array($in['language'] ?? '', ['th', 'en', 'both'], true) ? (string) $in['language'] : 'th';
        $dates = [];
        foreach (['starts_on', 'ends_on'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && !self::isDate($v)) {
                $errors[$k] = 'forms.error.date';
            }
            $dates[$k] = $v === '' ? null : $v;
        }
        if ($dates['starts_on'] !== null && $dates['ends_on'] !== null && $dates['ends_on'] < $dates['starts_on']) {
            $errors['ends_on'] = 'forms.error.date_order';
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
                "INSERT INTO frm_forms (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, code, title, description, category, language, owner_user_id, audience_json, starts_on, ends_on, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
                [$pid, $now, $az->userId, $now, $az->userId, $le, $code, $title, $desc === '' ? null : $desc, $cat, $lang, $az->userId, Audience::encode($aud), $dates['starts_on'], $dates['ends_on']]
            );
            $this->app->audit()->record('forms.created', 'success', 'form', $pid, $le, null, ['code' => $code]);
            return $pid;
        }
        $db->run(
            'UPDATE frm_forms SET code = ?, title = ?, description = ?, category = ?, language = ?, audience_json = ?, starts_on = ?, ends_on = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$code, $title, $desc === '' ? null : $desc, $cat, $lang, Audience::encode($aud), $dates['starts_on'], $dates['ends_on'], $now, $az->userId, $old['id']]
        );
        $this->app->audit()->record('forms.changed', 'success', 'form', (string) $pid, $le, null, ['code' => $code, 'audience' => $aud]);
        return (string) $pid;
    }

    /** Upload a new version (draft) — the file is checked and stored privately. */
    public function upload(Authorizer $az, string $formPid, ?UploadedFile $file, string $note): string
    {
        $f = $this->managed($az, $formPid);
        if ($f['status'] === 'archived') {
            throw new BusinessException('forms.error.archived', [], 409);
        }
        if ($file === null || !$file->ok() || $file->size === 0) {
            throw new ValidationException(['file' => 'file.error.missing']);
        }
        if (mb_strlen(trim($note)) > 500) {
            throw new ValidationException(['change_note' => 'forms.error.description']);
        }
        $db = $this->app->db();
        if ($db->scalar("SELECT id FROM frm_versions WHERE form_id = ? AND status IN ('draft','review')", [$f['id']]) !== null) {
            throw new BusinessException('forms.error.open_version', [], 409);
        }
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'forms.file', $file->bytes(), $file->safeName(), [
                'title' => 'Form ' . $f['code'], 'legal_entity_id' => (int) $f['legal_entity_id'],
            ], 'upload', true);
        } catch (ValidationException $ex) {
            throw new ValidationException(['file' => array_values($ex->errors)[0] ?? 'file.error.type_not_allowed']);
        }
        $meta = $db->one(
            'SELECT v.mime_type, v.size_bytes, v.sha256 FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.public_id = ? ORDER BY v.version_no DESC LIMIT 1',
            [(string) $stored['document']]
        ) ?? ['mime_type' => 'application/octet-stream', 'size_bytes' => $file->size, 'sha256' => hash('sha256', $file->bytes())];
        $no = (int) $db->scalar('SELECT COALESCE(MAX(version_no), 0) + 1 FROM frm_versions WHERE form_id = ?', [$f['id']]);
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db->run(
            "INSERT INTO frm_versions (public_id, created_at, created_by, updated_at, updated_by, form_id, version_no, document_pid, file_name, mime, size_bytes, sha256, change_note, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
            [$pid, $now, $az->userId, $now, $az->userId, $f['id'], $no, (string) $stored['document'], mb_substr($file->safeName(), 0, 200), (string) $meta['mime_type'], (int) $meta['size_bytes'], (string) $meta['sha256'],
                trim($note) === '' ? null : trim($note)]
        );
        $this->app->audit()->record('forms.version_uploaded', 'success', 'form_version', $pid, (int) $f['legal_entity_id'], null, ['form' => $f['code'], 'version' => $no, 'sha256' => $meta['sha256']]);
        return $pid;
    }

    /** Send a draft version for approval (another person with forms.approve publishes it). */
    public function submit(Authorizer $az, string $versionPid): void
    {
        $db = $this->app->db();
        $v = $db->one('SELECT v.*, f.public_id AS form_pid FROM frm_versions v JOIN frm_forms f ON f.id = v.form_id WHERE v.public_id = ?', [$versionPid]);
        if ($v === null) {
            throw HttpException::notFound();
        }
        $f = $this->managed($az, (string) $v['form_pid']);
        if ($v['status'] !== 'draft') {
            throw new BusinessException('forms.error.state', [], 409);
        }
        $db->transactional(function () use ($db, $az, $v, $f): void {
            $title = $this->app->translator()->get('forms.title_line', ['code' => (string) $f['code'], 'n' => (string) $v['version_no'], 'title' => (string) $f['title']]);
            $inst = $this->app->workflow()->start('forms.publish', (string) $v['public_id'], $title, (int) $f['legal_entity_id'], $az->userId);
            $db->run("UPDATE frm_versions SET status = 'review', workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?), updated_at = ? WHERE id = ?", [$inst, $this->now(), $v['id']]);
        });
        $this->app->audit()->record('forms.version_submitted', 'success', 'form_version', (string) $v['public_id'], (int) $f['legal_entity_id']);
    }

    public function discard(Authorizer $az, string $versionPid): void
    {
        $v = $this->app->db()->one('SELECT v.*, f.public_id AS form_pid FROM frm_versions v JOIN frm_forms f ON f.id = v.form_id WHERE v.public_id = ?', [$versionPid]);
        if ($v === null) {
            throw HttpException::notFound();
        }
        $f = $this->managed($az, (string) $v['form_pid']);
        if ($v['status'] !== 'draft') {
            throw new BusinessException('forms.error.state', [], 409);
        }
        $this->app->db()->run("UPDATE frm_versions SET status = 'rejected', updated_at = ? WHERE id = ?", [$this->now(), $v['id']]);
        $this->app->audit()->record('forms.version_discarded', 'success', 'form_version', $versionPid, (int) $f['legal_entity_id']);
    }

    /** Approved: publish this version, supersede the former one, tell people who starred the form. */
    public function onApproved(string $versionPid, int $approverId): void
    {
        $db = $this->app->db();
        $v = $db->one("SELECT * FROM frm_versions WHERE public_id = ? AND status = 'review'", [$versionPid]);
        if ($v === null) {
            return;
        }
        $f = $db->one('SELECT * FROM frm_forms WHERE id = ?', [$v['form_id']]);
        $db->transactional(function () use ($db, $v, $approverId): void {
            $now = $this->now();
            $db->run("UPDATE frm_versions SET status = 'superseded', updated_at = ? WHERE form_id = ? AND status = 'published'", [$now, $v['form_id']]);
            $db->run("UPDATE frm_versions SET status = 'published', approved_by = ?, published_at = ?, updated_at = ? WHERE id = ?", [$approverId, $now, $now, $v['id']]);
            $db->run("UPDATE frm_forms SET status = 'published', current_version_id = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$v['id'], $now, $v['form_id']]);
        });
        $this->app->audit()->record('forms.published', 'success', 'form_version', $versionPid, (int) ($f['legal_entity_id'] ?? 0), null, ['version' => $v['version_no']], $approverId);
        if ((int) $v['version_no'] > 1 && $f !== null) {
            foreach ($db->all('SELECT user_id FROM frm_stars WHERE form_id = ?', [$v['form_id']]) as $s) {
                $this->app->notifier()->notify((int) $s['user_id'], 'forms.new_version', 'forms.notify.new_version', ['code' => (string) $f['code'], 'n' => (string) $v['version_no']], '/forms/f/' . $f['public_id'], 'forms.v:' . $v['public_id'] . ':' . $s['user_id']);
            }
        }
    }

    public function onClosed(string $versionPid, string $outcome): void
    {
        $status = $outcome === 'returned' ? 'draft' : 'rejected';
        $this->app->db()->run("UPDATE frm_versions SET status = ?, updated_at = ? WHERE public_id = ? AND status = 'review'", [$status, $this->now(), $versionPid]);
    }

    public function archive(Authorizer $az, string $formPid, string $reason): void
    {
        $f = $this->managed($az, $formPid);
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $this->app->db()->run("UPDATE frm_forms SET status = 'archived', updated_at = ?, row_version = row_version + 1 WHERE id = ?", [$this->now(), $f['id']]);
        $this->app->audit()->record('forms.archived', 'success', 'form', $formPid, (int) $f['legal_entity_id'], trim($reason));
    }

    /** @return list<array<string, mixed>> */
    public function adminList(int $le): array
    {
        return $this->app->db()->all(
            "SELECT f.*, v.version_no AS current_no, (SELECT COUNT(*) FROM frm_versions x WHERE x.form_id = f.id AND x.status IN ('draft','review')) AS open_versions
               FROM frm_forms f LEFT JOIN frm_versions v ON v.id = f.current_version_id WHERE f.legal_entity_id = ? ORDER BY f.category, f.code",
            [$le]
        );
    }

    /** @return list<array<string, mixed>> */
    public function versions(int $formId): array
    {
        return $this->app->db()->all(
            'SELECT v.*, u.display_name AS created_name, a.display_name AS approved_name, i.public_id AS instance_pid FROM frm_versions v LEFT JOIN users u ON u.id = v.created_by
               LEFT JOIN users a ON a.id = v.approved_by LEFT JOIN workflow_instances i ON i.id = v.workflow_instance_id WHERE v.form_id = ? ORDER BY v.version_no DESC',
            [$formId]
        );
    }

    // ---- users ---------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $f form row */
    public function visibleTo(Authorizer $az, array $f): bool
    {
        if ($az->scope('forms.manage')->coversLegalEntity((int) $f['legal_entity_id'])) {
            return true;
        }
        if ($f['status'] !== 'published' || $f['current_version_id'] === null) {
            return false;
        }
        $today = $this->today();
        if (($f['starts_on'] !== null && (string) $f['starts_on'] > $today) || ($f['ends_on'] !== null && (string) $f['ends_on'] < $today)) {
            return false;
        }
        $me = $this->emp()->forUser($az->userId);
        return $me !== null && $me['status'] !== 'former' && Audience::matches($this->app, (int) $f['legal_entity_id'], Audience::decode((string) $f['audience_json']), $me['job']);
    }

    /**
     * Published forms for the user: search, category, starred / recent.
     *
     * @return list<array<string, mixed>>
     */
    public function browse(Authorizer $az, string $q = '', string $category = '', string $view = 'all'): array
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null || $me['job'] === null) {
            return [];
        }
        $le = (int) $me['job']['legal_entity_id'];
        $sql = "SELECT f.*, v.version_no AS current_no, v.mime, v.size_bytes, v.published_at, v.public_id AS version_pid,
                       (SELECT 1 FROM frm_stars s WHERE s.form_id = f.id AND s.user_id = ?) AS starred,
                       (SELECT MAX(e.created_at) FROM frm_events e WHERE e.form_id = f.id AND e.user_id = ?) AS last_used
                  FROM frm_forms f JOIN frm_versions v ON v.id = f.current_version_id WHERE f.legal_entity_id = ? AND f.status = 'published'";
        $params = [$az->userId, $az->userId, $le];
        if ($q !== '') {
            $sql .= ' AND (f.title LIKE ? OR f.code LIKE ? OR f.description LIKE ?)';
            array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        if ($category !== '') {
            $sql .= ' AND f.category = ?';
            $params[] = $category;
        }
        $rows = array_values(array_filter($this->app->db()->all($sql . ' ORDER BY f.category, f.title LIMIT 500', $params), fn (array $f): bool => $this->visibleTo($az, $f)));
        if ($view === 'starred') {
            $rows = array_values(array_filter($rows, static fn (array $f): bool => (int) $f['starred'] === 1));
        } elseif ($view === 'recent') {
            $rows = array_values(array_filter($rows, static fn (array $f): bool => $f['last_used'] !== null));
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['last_used'], (string) $a['last_used']));
        }
        return $rows;
    }

    /** @return list<string> categories of the user's company */
    public function categories(Authorizer $az): array
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null || $me['job'] === null) {
            return [];
        }
        return array_map('strval', array_column($this->app->db()->all("SELECT DISTINCT category FROM frm_forms WHERE legal_entity_id = ? AND status = 'published' ORDER BY category", [$me['job']['legal_entity_id']]), 'category'));
    }

    /** @return array<string, mixed> form + current version for the user (404 when not theirs to see) */
    public function form(Authorizer $az, string $pid): array
    {
        $f = $this->app->db()->one(
            'SELECT f.*, v.version_no AS current_no, v.mime, v.size_bytes, v.published_at, v.public_id AS version_pid, v.change_note, u.display_name AS owner_name,
                    (SELECT 1 FROM frm_stars s WHERE s.form_id = f.id AND s.user_id = ?) AS starred
               FROM frm_forms f LEFT JOIN frm_versions v ON v.id = f.current_version_id LEFT JOIN users u ON u.id = f.owner_user_id WHERE f.public_id = ?',
            [$az->userId, $pid]
        );
        if ($f === null || !$this->visibleTo($az, $f)) {
            throw HttpException::notFound();
        }
        $f['can_manage'] = $az->scope('forms.manage')->coversLegalEntity((int) $f['legal_entity_id']);
        return $f;
    }

    /**
     * The current version's file (users) or any version (form managers), logged.
     *
     * @return array{bytes: string, mime: string, name: string, event: string}
     */
    public function file(Authorizer $az, string $formPid, ?string $versionPid, string $event): array
    {
        $f = $this->form($az, $formPid);
        $db = $this->app->db();
        if ($versionPid !== null) {
            if (!$f['can_manage']) {
                throw HttpException::notFound(); // only the current version for users (DEC-72)
            }
            $v = $db->one('SELECT * FROM frm_versions WHERE public_id = ? AND form_id = ?', [$versionPid, $f['id']]);
        } else {
            $v = $f['current_version_id'] !== null ? $db->one('SELECT * FROM frm_versions WHERE id = ?', [$f['current_version_id']]) : null;
        }
        if ($v === null) {
            throw HttpException::notFound();
        }
        $event = $event === 'preview' && in_array($v['mime'], self::PREVIEW, true) ? 'preview' : 'download';
        $d = $this->app->documents()->moduleRead((string) $v['document_pid'], 'forms', $az->userId, 'form ' . $event, false);
        $db->run('INSERT INTO frm_events (form_id, version_id, user_id, event, created_at) VALUES (?, ?, ?, ?, ?)', [$f['id'], $v['id'], $az->userId, $event, $this->now()]);
        if ($event === 'download') {
            $db->run('UPDATE frm_forms SET download_count = download_count + 1 WHERE id = ?', [$f['id']]);
        }
        return ['bytes' => $d['bytes'], 'mime' => $d['mime'], 'name' => $f['code'] . '_v' . $v['version_no'] . '.' . pathinfo((string) $v['file_name'], PATHINFO_EXTENSION), 'event' => $event];
    }

    public function star(Authorizer $az, string $formPid, bool $on): void
    {
        $f = $this->form($az, $formPid);
        if ($on) {
            $this->app->db()->run('INSERT IGNORE INTO frm_stars (user_id, form_id, created_at) VALUES (?, ?, ?)', [$az->userId, $f['id'], $this->now()]);
        } else {
            $this->app->db()->run('DELETE FROM frm_stars WHERE user_id = ? AND form_id = ?', [$az->userId, $f['id']]);
        }
    }

    /** @return array{downloads: int, previews: int, people: int} */
    public function stats(int $formId): array
    {
        $r = $this->app->db()->one(
            "SELECT SUM(event = 'download') AS d, SUM(event = 'preview') AS p, COUNT(DISTINCT user_id) AS u FROM frm_events WHERE form_id = ?",
            [$formId]
        );
        return ['downloads' => (int) ($r['d'] ?? 0), 'previews' => (int) ($r['p'] ?? 0), 'people' => (int) ($r['u'] ?? 0)];
    }

    public static function isDate(string $d): bool
    {
        $x = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $x !== false && $x->format('Y-m-d') === $d;
    }
}
