<?php

declare(strict_types=1);

namespace Blue\Modules\Announcements;

use DateTimeImmutable;
use DateTimeZone;
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
 * M19 Announcements (phase 5D, DEC-72).
 *
 *  write     a publisher (announcements.publish on the company) writes Thai (+ optional English) text, category,
 *            priority, pin, publish window, target group (Audience), read receipt, login pop-up (5–10 s), e-mail,
 *            one video and one link (shown as buttons — nothing is embedded, nothing is fetched from outside).
 *  approve   normal = the publisher schedules it; important / critical = another person with announcements.approve
 *            must approve (also after editing a published one).
 *  publish   at the start time (job every 15 min) → in-app notice to the target group (+ e-mail when the mail
 *            channel is set up) — then expired at the end time. Each publication stores a revision snapshot; a
 *            read receipt counts only for the current revision.
 *  measure   views (first/last/how many), unique readers, acknowledgements %, reactions, link clicks, who has not
 *            read / acknowledged, remind them (once a day).
 */
final class AnnouncementService
{
    public const PRIORITIES = ['normal', 'important', 'critical'];
    public const REACTIONS = ['like', 'thanks', 'celebrate'];

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

    public function tz(): DateTimeZone
    {
        return new DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function nowUtc(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** "2026-11-09T08:30" (local) → UTC "Y-m-d H:i:s", or null */
    public function utc(string $local): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $this->tz());
        return $d === false || $d->format('Y-m-d\TH:i') !== $local ? null : $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC "Y-m-d H:i:s" → local "Y-m-d\TH:i" */
    public function local(?string $utc): string
    {
        if ($utc === null) {
            return '';
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        return $d === false ? '' : $d->setTimezone($this->tz())->format('Y-m-d\TH:i');
    }

    // ---- writing ------------------------------------------------------------------------------------------------------

    /** @return list<int> */
    public function companies(Authorizer $az): array
    {
        $scope = $az->scope('announcements.publish');
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    /** @return array<string, mixed> */
    public function managed(Authorizer $az, string $pid): array
    {
        $p = $this->app->db()->one('SELECT * FROM ann_posts WHERE public_id = ?', [$pid]);
        if ($p === null || !$az->scope('announcements.publish')->coversLegalEntity((int) $p['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $p;
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed> validated columns
     */
    private function validate(int $le, array $in): array
    {
        $errors = [];
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'announcements.error.title';
        }
        $body = trim((string) ($in['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 20000) {
            $errors['body'] = 'announcements.error.body';
        }
        $titleEn = trim((string) ($in['title_en'] ?? ''));
        $bodyEn = trim((string) ($in['body_en'] ?? ''));
        if (mb_strlen($titleEn) > 200 || mb_strlen($bodyEn) > 20000 || (($titleEn === '') !== ($bodyEn === ''))) {
            $errors['title_en'] = 'announcements.error.english';
        }
        $cat = trim((string) ($in['category'] ?? ''));
        if ($cat === '' || mb_strlen($cat) > 60) {
            $errors['category'] = 'announcements.error.category';
        }
        $prio = in_array($in['priority'] ?? '', self::PRIORITIES, true) ? (string) $in['priority'] : 'normal';
        $starts = $this->utc((string) ($in['starts_at'] ?? ''));
        if ($starts === null) {
            $errors['starts_at'] = 'announcements.error.datetime';
        }
        $ends = null;
        if (trim((string) ($in['ends_at'] ?? '')) !== '') {
            $ends = $this->utc((string) $in['ends_at']);
            if ($ends === null) {
                $errors['ends_at'] = 'announcements.error.datetime';
            } elseif ($starts !== null && $ends <= $starts) {
                $errors['ends_at'] = 'announcements.error.order';
            }
        }
        $popup = (int) ($in['popup_seconds'] ?? 0);
        if ($popup !== 0 && ($popup < 5 || $popup > 10)) {
            $errors['popup_seconds'] = 'announcements.error.popup';
        }
        $urls = [];
        foreach (['video_url', 'link_url'] as $k) {
            $u = trim((string) ($in[$k] ?? ''));
            if ($u !== '' && (mb_strlen($u) > 500 || filter_var($u, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($u), 'https://'))) {
                $errors[$k] = 'announcements.error.url';
            }
            $urls[$k] = $u === '' ? null : $u;
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
        return ['title' => $title, 'body' => $body, 'title_en' => $titleEn === '' ? null : $titleEn, 'body_en' => $bodyEn === '' ? null : $bodyEn, 'category' => $cat, 'priority' => $prio,
            'pinned' => ($in['pinned'] ?? '') === '1' ? 1 : 0, 'starts_at' => $starts, 'ends_at' => $ends, 'audience_json' => Audience::encode($aud), 'requires_ack' => ($in['requires_ack'] ?? '') === '1' ? 1 : 0,
            'popup_seconds' => $popup, 'send_email' => ($in['send_email'] ?? '') === '1' ? 1 : 0, 'video_url' => $urls['video_url'], 'link_url' => $urls['link_url']];
    }

    /**
     * New draft (pid null) or change one. A published announcement may be edited: a new revision is published at
     * once for normal priority; important / critical go back to approval (the published text stays until then).
     *
     * @param array<string, mixed> $in
     */
    public function save(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        if (!$az->scope('announcements.publish')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $db = $this->app->db();
        $cols = $this->validate($le, $in);
        $now = $this->now();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $names = array_keys($cols);
            $db->run(
                'INSERT INTO ann_posts (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, ' . implode(', ', $names) . ", status) VALUES (?, ?, ?, ?, ?, ?, " . implode(', ', array_fill(0, count($names), '?')) . ", 'draft')",
                array_merge([$pid, $now, $az->userId, $now, $az->userId, $le], array_values($cols))
            );
            $this->app->audit()->record('announcements.created', 'success', 'announcement', $pid, $le, null, ['title' => $cols['title'], 'priority' => $cols['priority']]);
            return $pid;
        }
        $p = $this->managed($az, $pid);
        if (!in_array($p['status'], ['draft', 'rejected', 'scheduled', 'published'], true)) {
            throw new BusinessException('announcements.error.state', [], 409);
        }
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($cols)));
        $db->run("UPDATE ann_posts SET {$set}, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?", array_merge(array_values($cols), [$now, $az->userId, $p['id']]));
        $this->app->audit()->record('announcements.changed', 'success', 'announcement', $pid, $le, null, ['title' => $cols['title'], 'status' => $p['status']]);
        if (in_array($p['status'], ['published', 'scheduled'], true)) {
            if ($cols['priority'] === 'normal') {
                $this->activate($pid, $az->userId, false); // new revision now (or keep scheduled)
            } else {
                $db->run("UPDATE ann_posts SET status = 'draft' WHERE id = ? AND status = 'scheduled'", [$p['id']]);
                $this->startApproval($az, $this->managed($az, $pid));
            }
        } elseif ($p['status'] === 'rejected') {
            $db->run("UPDATE ann_posts SET status = 'draft' WHERE id = ?", [$p['id']]);
        }
        return $pid;
    }

    public function attach(Authorizer $az, string $pid, ?UploadedFile $file): void
    {
        $p = $this->managed($az, $pid);
        if ($file === null || !$file->ok() || $file->size === 0) {
            throw new ValidationException(['attachment' => 'file.error.missing']);
        }
        if ((int) $this->app->db()->scalar('SELECT COUNT(*) FROM ann_attachments WHERE post_id = ?', [$p['id']]) >= 10) {
            throw new ValidationException(['attachment' => 'announcements.error.too_many_files']);
        }
        try {
            $stored = $this->app->documents()->storeGenerated($az->userId, 'announcements.file', $file->bytes(), $file->safeName(), [
                'title' => 'Announcement ' . $p['public_id'], 'legal_entity_id' => (int) $p['legal_entity_id'],
            ], 'upload', true);
        } catch (ValidationException $ex) {
            throw new ValidationException(['attachment' => array_values($ex->errors)[0] ?? 'file.error.type_not_allowed']);
        }
        $mime = (string) ($this->app->db()->scalar('SELECT v.mime_type FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.public_id = ? ORDER BY v.version_no DESC LIMIT 1', [(string) $stored['document']]) ?? 'application/octet-stream');
        $this->app->db()->run('INSERT INTO ann_attachments (post_id, document_pid, file_name, mime, created_at) VALUES (?, ?, ?, ?, ?)', [$p['id'], (string) $stored['document'], mb_substr($file->safeName(), 0, 200), $mime, $this->now()]);
        $this->app->audit()->record('announcements.attached', 'success', 'announcement', $pid, (int) $p['legal_entity_id'], null, ['file' => $file->safeName()]);
    }

    public function detach(Authorizer $az, string $pid, string $docPid): void
    {
        $p = $this->managed($az, $pid);
        $this->app->db()->run('DELETE FROM ann_attachments WHERE post_id = ? AND document_pid = ?', [$p['id'], $docPid]);
        $this->app->audit()->record('announcements.detached', 'success', 'announcement', $pid, (int) $p['legal_entity_id'], null, ['document' => $docPid]);
    }

    /** Draft → scheduled / published (normal) or → review (important / critical). */
    public function submit(Authorizer $az, string $pid): void
    {
        $p = $this->managed($az, $pid);
        if ($p['status'] !== 'draft') {
            throw new BusinessException('announcements.error.state', [], 409);
        }
        if ($p['ends_at'] !== null && (string) $p['ends_at'] <= $this->nowUtc()) {
            throw new ValidationException(['ends_at' => 'announcements.error.past']);
        }
        if ($p['priority'] === 'normal') {
            $this->activate($pid, $az->userId, true);
            return;
        }
        $this->startApproval($az, $p);
    }

    /** @param array<string, mixed> $p */
    private function startApproval(Authorizer $az, array $p): void
    {
        $db = $this->app->db();
        if ($db->scalar("SELECT id FROM workflow_instances WHERE id = ? AND status IN ('pending','returned')", [$p['workflow_instance_id']]) !== null) {
            return; // already waiting
        }
        $title = $this->app->translator()->get('announcements.title_line', ['title' => (string) $p['title'], 'priority' => $this->app->translator()->get('announcements.priority.' . $p['priority'])]);
        $inst = $this->app->workflow()->start('announcements.publish', (string) $p['public_id'], $title, (int) $p['legal_entity_id'], $az->userId);
        $db->run("UPDATE ann_posts SET status = IF(status = 'published', status, 'review'), workflow_instance_id = (SELECT id FROM workflow_instances WHERE public_id = ?), updated_at = ? WHERE id = ?", [$inst, $this->now(), $p['id']]);
        $this->app->audit()->record('announcements.submitted', 'success', 'announcement', (string) $p['public_id'], (int) $p['legal_entity_id']);
    }

    public function onApproved(string $pid, int $approverId): void
    {
        $this->app->db()->run('UPDATE ann_posts SET approved_by = ? WHERE public_id = ?', [$approverId, $pid]);
        $this->activate($pid, $approverId, true);
    }

    public function onClosed(string $pid, string $outcome): void
    {
        $status = $outcome === 'rejected' ? 'rejected' : 'draft';
        $this->app->db()->run("UPDATE ann_posts SET status = ?, updated_at = ? WHERE public_id = ? AND status = 'review'", [$status, $this->now(), $pid]);
    }

    /**
     * Publish now (start reached) with a new revision snapshot, or keep it scheduled. First publication notifies.
     */
    public function activate(string $pid, ?int $actorId, bool $fromDraft): void
    {
        $db = $this->app->db();
        $p = $db->one('SELECT * FROM ann_posts WHERE public_id = ?', [$pid]);
        if ($p === null) {
            return;
        }
        if ((string) $p['starts_at'] > $this->nowUtc()) {
            $db->run("UPDATE ann_posts SET status = 'scheduled', updated_at = ? WHERE id = ?", [$this->now(), $p['id']]);
            $this->app->audit()->record('announcements.scheduled', 'success', 'announcement', $pid, (int) $p['legal_entity_id'], null, ['starts_at' => $p['starts_at']], $actorId);
            return;
        }
        $rev = (int) $p['revision'] + 1;
        $atts = $db->all('SELECT document_pid, file_name, mime FROM ann_attachments WHERE post_id = ? ORDER BY id', [$p['id']]);
        $snap = array_intersect_key($p, array_flip(['title', 'body', 'title_en', 'body_en', 'category', 'priority', 'pinned', 'starts_at', 'ends_at', 'audience_json', 'requires_ack', 'video_url', 'link_url'])) + ['attachments' => $atts];
        $db->transactional(function () use ($db, $p, $rev, $snap, $actorId): void {
            $db->run('INSERT INTO ann_revisions (post_id, revision, snapshot_json, created_by, created_at) VALUES (?, ?, ?, ?, ?)', [$p['id'], $rev, json_encode($snap, JSON_UNESCAPED_UNICODE), $actorId, $this->now()]);
            $db->run("UPDATE ann_posts SET status = 'published', revision = ?, published_at = COALESCE(published_at, ?), updated_at = ? WHERE id = ?", [$rev, $this->now(), $this->now(), $p['id']]);
        });
        $this->app->audit()->record('announcements.published', 'success', 'announcement', $pid, (int) $p['legal_entity_id'], null, ['revision' => $rev], $actorId);
        if ($p['notified_at'] === null) {
            $this->notifyAudience($p);
        }
        unset($fromDraft);
    }

    /** @param array<string, mixed> $p */
    private function notifyAudience(array $p): void
    {
        $db = $this->app->db();
        $members = Audience::members($this->app, (int) $p['legal_entity_id'], Audience::decode((string) $p['audience_json']), $this->emp()->today());
        $mailer = $this->app->mailer();
        $mailed = 0;
        foreach (array_filter($members) as $uid) {
            $this->app->notifier()->notify((int) $uid, 'announcements.published', 'announcements.notify.published', ['title' => (string) $p['title']], '/announcements/p/' . $p['public_id'], 'ann:' . $p['public_id'] . ':' . $uid);
            if ((int) $p['send_email'] === 1 && $mailer->available()) {
                $to = (string) ($db->scalar('SELECT email FROM users WHERE id = ?', [$uid]) ?? '');
                if ($to !== '' && $mailer->send($to, (string) $p['title'], mb_substr((string) $p['body'], 0, 4000))) {
                    $mailed++;
                }
            }
        }
        $db->run('UPDATE ann_posts SET notified_at = ? WHERE id = ?', [$this->now(), $p['id']]);
        $this->app->audit()->record('announcements.notified', 'success', 'announcement', (string) $p['public_id'], (int) $p['legal_entity_id'], null, ['people' => count($members), 'emails' => $mailed]);
    }

    public function withdraw(Authorizer $az, string $pid, string $reason): void
    {
        $p = $this->managed($az, $pid);
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        if (!in_array($p['status'], ['scheduled', 'published', 'review'], true)) {
            throw new BusinessException('announcements.error.state', [], 409);
        }
        $this->app->db()->run("UPDATE ann_posts SET status = 'withdrawn', updated_at = ?, updated_by = ? WHERE id = ?", [$this->now(), $az->userId, $p['id']]);
        $this->app->audit()->record('announcements.withdrawn', 'success', 'announcement', $pid, (int) $p['legal_entity_id'], trim($reason));
    }

    public static function scheduleJob(App $app): int
    {
        return self::of($app)->tick();
    }

    /** Scheduled → published at the start; published → expired at the end. */
    public function tick(): int
    {
        $db = $this->app->db();
        $now = $this->nowUtc();
        $n = 0;
        foreach ($db->all("SELECT public_id FROM ann_posts WHERE status = 'scheduled' AND starts_at <= ?", [$now]) as $p) {
            $this->activate((string) $p['public_id'], null, false);
            $n++;
        }
        $n += $db->run("UPDATE ann_posts SET status = 'expired', updated_at = ? WHERE status = 'published' AND ends_at IS NOT NULL AND ends_at <= ?", [$this->now(), $now])->rowCount();
        return $n;
    }

    /** @return list<array<string, mixed>> */
    public function adminList(int $le): array
    {
        return $this->app->db()->all(
            "SELECT p.*, u.display_name AS created_name, (SELECT COUNT(*) FROM ann_reads r WHERE r.post_id = p.id) AS readers
               FROM ann_posts p LEFT JOIN users u ON u.id = p.created_by WHERE p.legal_entity_id = ? ORDER BY FIELD(p.status, 'review','draft','scheduled','published','expired','rejected','withdrawn','archived'), p.starts_at DESC LIMIT 300",
            [$le]
        );
    }

    // ---- reading ------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $p */
    public function visibleTo(Authorizer $az, array $p, bool $archive = false): bool
    {
        if ($az->scope('announcements.publish')->coversLegalEntity((int) $p['legal_entity_id'])) {
            return true;
        }
        if (!($p['status'] === 'published' || ($archive && $p['status'] === 'expired'))) {
            return false;
        }
        $me = $this->emp()->forUser($az->userId);
        return $me !== null && $me['status'] !== 'former' && Audience::matches($this->app, (int) $p['legal_entity_id'], Audience::decode((string) $p['audience_json']), $me['job']);
    }

    /**
     * The board: published (or expired = archive) announcements for this person, pinned first.
     *
     * @return list<array<string, mixed>>
     */
    public function board(Authorizer $az, string $q = '', string $cat = '', string $view = 'all'): array
    {
        $me = $this->emp()->forUser($az->userId);
        if ($me === null || $me['job'] === null) {
            return [];
        }
        $archive = $view === 'archive';
        $sql = 'SELECT p.*, r.first_seen_at, r.views, r.acked_at, r.acked_revision FROM ann_posts p LEFT JOIN ann_reads r ON r.post_id = p.id AND r.user_id = ? WHERE p.legal_entity_id = ? AND p.status = ?';
        $params = [$az->userId, $me['job']['legal_entity_id'], $archive ? 'expired' : 'published'];
        if ($q !== '') {
            $sql .= ' AND (p.title LIKE ? OR p.body LIKE ? OR p.title_en LIKE ?)';
            array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
        }
        if ($cat !== '') {
            $sql .= ' AND p.category = ?';
            $params[] = $cat;
        }
        $rows = array_values(array_filter($this->app->db()->all($sql . ' ORDER BY p.pinned DESC, p.starts_at DESC LIMIT 300', $params), function (array $p) use ($az, $archive): bool {
            $me = $this->emp()->forUser($az->userId);
            return $me !== null && Audience::matches($this->app, (int) $p['legal_entity_id'], Audience::decode((string) $p['audience_json']), $me['job']) || ($archive && $this->visibleTo($az, $p, true));
        }));
        if ($view === 'unread') {
            $rows = array_values(array_filter($rows, static fn (array $p): bool => (int) ($p['views'] ?? 0) === 0 || ((int) $p['requires_ack'] === 1 && (int) ($p['acked_revision'] ?? 0) !== (int) $p['revision'])));
        }
        return $rows;
    }

    /** @return list<string> */
    public function categories(Authorizer $az): array
    {
        $me = $this->emp()->forUser($az->userId);
        return $me === null || $me['job'] === null ? [] : array_map('strval', array_column($this->app->db()->all(
            "SELECT DISTINCT category FROM ann_posts WHERE legal_entity_id = ? AND status IN ('published','expired') ORDER BY category",
            [$me['job']['legal_entity_id']]
        ), 'category'));
    }

    /**
     * Open one announcement (counts a view for readers).
     *
     * @return array<string, mixed>
     */
    public function view(Authorizer $az, string $pid, bool $count = true): array
    {
        $db = $this->app->db();
        $p = $db->one('SELECT p.*, u.display_name AS created_name FROM ann_posts p LEFT JOIN users u ON u.id = p.created_by WHERE p.public_id = ?', [$pid]);
        if ($p === null || !$this->visibleTo($az, $p, true)) {
            throw HttpException::notFound();
        }
        $p['can_manage'] = $az->scope('announcements.publish')->coversLegalEntity((int) $p['legal_entity_id']);
        if ($count && in_array($p['status'], ['published', 'expired'], true)) {
            $now = $this->now();
            $db->run(
                'INSERT INTO ann_reads (post_id, user_id, first_seen_at, last_seen_at, views) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), views = views + 1',
                [$p['id'], $az->userId, $now, $now]
            );
        }
        $p['mine'] = $db->one('SELECT * FROM ann_reads WHERE post_id = ? AND user_id = ?', [$p['id'], $az->userId]);
        $p['attachments'] = $db->all('SELECT * FROM ann_attachments WHERE post_id = ? ORDER BY id', [$p['id']]);
        $p['reactions'] = [];
        foreach ($db->all('SELECT reaction, COUNT(*) AS n FROM ann_reads WHERE post_id = ? AND reaction IS NOT NULL GROUP BY reaction', [$p['id']]) as $r) {
            $p['reactions'][(string) $r['reaction']] = (int) $r['n'];
        }
        return $p;
    }

    public function acknowledge(Authorizer $az, string $pid): void
    {
        $p = $this->view($az, $pid, true);
        if ((int) $p['requires_ack'] !== 1 || $p['status'] !== 'published') {
            throw new BusinessException('announcements.error.state', [], 409);
        }
        $this->app->db()->run('UPDATE ann_reads SET acked_at = ?, acked_revision = ? WHERE post_id = ? AND user_id = ?', [$this->now(), $p['revision'], $p['id'], $az->userId]);
        $this->app->audit()->record('announcements.acknowledged', 'success', 'announcement', $pid, (int) $p['legal_entity_id'], null, ['revision' => (int) $p['revision']]);
    }

    public function react(Authorizer $az, string $pid, string $reaction): void
    {
        $p = $this->view($az, $pid, true);
        $value = in_array($reaction, self::REACTIONS, true) && ($p['mine']['reaction'] ?? null) !== $reaction ? $reaction : null; // same again = remove
        $this->app->db()->run('UPDATE ann_reads SET reaction = ? WHERE post_id = ? AND user_id = ?', [$value, $p['id'], $az->userId]);
    }

    /** Count a click on the video / link button and return where to go. */
    public function click(Authorizer $az, string $pid, string $which): string
    {
        $p = $this->view($az, $pid, true);
        $url = $which === 'video' ? $p['video_url'] : $p['link_url'];
        if ($url === null) {
            throw HttpException::notFound();
        }
        $this->app->db()->run('UPDATE ann_reads SET clicks = clicks + 1 WHERE post_id = ? AND user_id = ?', [$p['id'], $az->userId]);
        return (string) $url;
    }

    /** @return array{bytes: string, mime: string, name: string} */
    public function attachment(Authorizer $az, string $pid, string $docPid): array
    {
        $p = $this->view($az, $pid, false);
        $a = $this->app->db()->one('SELECT * FROM ann_attachments WHERE post_id = ? AND document_pid = ?', [$p['id'], $docPid]);
        if ($a === null) {
            throw HttpException::notFound();
        }
        $d = $this->app->documents()->moduleRead($docPid, 'announcements', $az->userId, 'announcement attachment', false);
        return ['bytes' => $d['bytes'], 'mime' => $d['mime'], 'name' => (string) $a['file_name']];
    }

    // ---- engagement -----------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> audience size, readers, acknowledged (current revision), %, reactions, clicks, who has not read / acknowledged */
    public function stats(Authorizer $az, string $pid): array
    {
        $p = $this->managed($az, $pid);
        $db = $this->app->db();
        $members = Audience::members($this->app, (int) $p['legal_entity_id'], Audience::decode((string) $p['audience_json']), $this->emp()->today());
        $reads = [];
        foreach ($db->all('SELECT * FROM ann_reads WHERE post_id = ?', [$p['id']]) as $r) {
            $reads[(int) $r['user_id']] = $r;
        }
        $notRead = [];
        $notAcked = [];
        $read = 0;
        $acked = 0;
        foreach ($members as $empId => $uid) {
            $r = $uid !== null ? ($reads[$uid] ?? null) : null;
            if ($r !== null && (int) $r['views'] > 0) {
                $read++;
            } else {
                $notRead[] = $empId;
            }
            if ((int) $p['requires_ack'] === 1) {
                if ($r !== null && (int) ($r['acked_revision'] ?? 0) === (int) $p['revision']) {
                    $acked++;
                } else {
                    $notAcked[] = $empId;
                }
            }
        }
        $names = function (array $ids) use ($db): array {
            if ($ids === []) {
                return [];
            }
            return array_map(fn (array $e): string => $e['employee_code'] . ' · ' . $this->emp()->displayName($e), $db->all(
                'SELECT employee_code, name_th, name_en, preferred_name FROM employees WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY employee_code LIMIT 500',
                array_values($ids)
            ));
        };
        $reactions = array_fill_keys(self::REACTIONS, 0);
        $views = 0;
        $clicks = 0;
        foreach ($reads as $r) {
            if ($r['reaction'] !== null) {
                $reactions[(string) $r['reaction']]++;
            }
            $views += (int) $r['views'];
            $clicks += (int) $r['clicks'];
        }
        $n = count($members);
        return ['post' => $p, 'audience' => $n, 'read' => $read, 'read_pct' => $n > 0 ? round($read * 100 / $n, 1) : 0.0, 'acked' => $acked,
            'acked_pct' => $n > 0 && (int) $p['requires_ack'] === 1 ? round($acked * 100 / $n, 1) : null, 'views' => $views, 'clicks' => $clicks, 'reactions' => $reactions,
            'not_read' => $names($notRead), 'not_acked' => $names($notAcked), 'not_read_count' => count($notRead), 'not_acked_count' => count($notAcked)];
    }

    /** Remind people who have not read (or not acknowledged) — once a day per person. */
    public function remind(Authorizer $az, string $pid): int
    {
        $p = $this->managed($az, $pid);
        if ($p['status'] !== 'published') {
            throw new BusinessException('announcements.error.state', [], 409);
        }
        $db = $this->app->db();
        $n = 0;
        $day = $this->emp()->today();
        foreach (Audience::members($this->app, (int) $p['legal_entity_id'], Audience::decode((string) $p['audience_json']), $day) as $uid) {
            if ($uid === null) {
                continue;
            }
            $r = $db->one('SELECT * FROM ann_reads WHERE post_id = ? AND user_id = ?', [$p['id'], $uid]);
            $pending = $r === null || (int) $r['views'] === 0 || ((int) $p['requires_ack'] === 1 && (int) ($r['acked_revision'] ?? 0) !== (int) $p['revision']);
            if ($pending) {
                $this->app->notifier()->notify($uid, 'announcements.reminder', 'announcements.notify.reminder', ['title' => (string) $p['title']], '/announcements/p/' . $pid, 'ann.remind:' . $pid . ':' . $uid . ':' . $day);
                $n++;
            }
        }
        $this->app->audit()->record('announcements.reminded', 'success', 'announcement', $pid, (int) $p['legal_entity_id'], null, ['people' => $n]);
        return $n;
    }

    /**
     * Home page: pinned and unread announcements, and at most one login pop-up (shown once per person).
     *
     * @return array{items: list<array<string, mixed>>, popup: array<string, mixed>|null}
     */
    public function forHome(Authorizer $az): array
    {
        $rows = $this->board($az);
        $items = array_slice(array_values(array_filter($rows, static fn (array $p): bool => (int) $p['pinned'] === 1 || (int) ($p['views'] ?? 0) === 0)), 0, 6);
        $popup = null;
        foreach ($rows as $p) {
            if ((int) $p['popup_seconds'] > 0 && (int) ($p['views'] ?? 0) === 0) {
                $seen = $this->app->db()->scalar('SELECT popup_at FROM ann_reads WHERE post_id = ? AND user_id = ?', [$p['id'], $az->userId]);
                if ($seen !== null) {
                    continue;
                }
                $now = $this->now();
                $this->app->db()->run(
                    'INSERT INTO ann_reads (post_id, user_id, first_seen_at, last_seen_at, views, popup_at) VALUES (?, ?, ?, ?, 0, ?) ON DUPLICATE KEY UPDATE popup_at = VALUES(popup_at)',
                    [$p['id'], $az->userId, $now, $now, $now]
                );
                $popup = $p;
                break;
            }
        }
        return ['items' => $items, 'popup' => $popup];
    }
}
