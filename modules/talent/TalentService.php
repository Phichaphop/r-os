<?php

declare(strict_types=1);

namespace Blue\Modules\Talent;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\EmployeeService;
use Blue\Modules\Recruit\Lists;
use Blue\Modules\Recruit\RecruitService;

/**
 * M49 Talent pool (phase 5E, DEC-76).
 *
 *  profile    a person kept for future openings, from an application (only with the applicant's talent-pool consent),
 *             a CV upload, a walk-in or a referral: source, consent date and basis, positions of interest, skills, years,
 *             education, CV (encrypted document). Never the ID number; looked up by internal id only.
 *  repeats    the same e-mail or phone in the same company is the same person: a new CV / application is added to the
 *             existing profile instead of a second one (keyed hashes of the e-mail and phone).
 *  status     new → reviewed → pool / linked to vacancy → contacted → hired / inactive, starred, contact history.
 *  vacancy    putting someone forward makes an application in M08 (source talent_pool) pointing back to the profile.
 *  matches    when a posting is published, recruiters are told how many profiles match its title (positions/skills).
 *  retention  retention date = consent date + the APPROVED applicant_unsuccessful rule (no approved rule = none, and the
 *             screen says so); past it the profile is anonymised. Erasing on request does the same now, with a reason.
 */
final class TalentService
{
    public const SOURCES = ['upload', 'walk_in', 'referral'];
    public const STATUSES = ['new', 'reviewed', 'pool', 'linked', 'contacted', 'hired', 'inactive'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    public static function housekeepingTask(App $app): int
    {
        $s = self::of($app);
        return $s->retentionJob() + $s->matchJob();
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    public function today(): string
    {
        return EmployeeService::of($this->app)->today();
    }

    /** @return list<int> */
    public function companies(Authorizer $az, string $permission = 'talent.use'): array
    {
        $scope = $az->scope($permission);
        return array_values(array_filter(array_map('intval', array_column($this->app->db()->all('SELECT id FROM legal_entities ORDER BY code'), 'id')), static fn (int $id): bool => $scope->coversLegalEntity($id)));
    }

    /** Retention date for a consent given on $consent (null when no approved rule). */
    public function retentionUntil(string $consent): ?string
    {
        $rule = $this->app->retention()->current('applicant_unsuccessful');
        if ($rule === null || (int) $rule['duration_months'] < 1) {
            return null;
        }
        return EmployeeService::ymd($consent)->modify('+' . (int) $rule['duration_months'] . ' months')->format('Y-m-d');
    }

    private function dupKey(?string $v): ?string
    {
        $v = $v !== null ? mb_strtolower(trim($v)) : '';
        return $v !== '' ? $this->app->crypto()->keyedHash('talent|' . $v) : null;
    }

    private static function phone(string $v): string
    {
        return (string) preg_replace('/[^0-9+]/', '', $v);
    }

    /** @return array<string, mixed>|null a live profile of the company with the same e-mail or phone */
    public function duplicate(int $le, ?string $email, ?string $phone, ?int $exceptId = null): ?array
    {
        $e = $this->dupKey($email);
        $p = $this->dupKey($phone !== null ? self::phone($phone) : null);
        if ($e === null && $p === null) {
            return null;
        }
        return $this->app->db()->one(
            'SELECT * FROM tal_profiles WHERE legal_entity_id = ? AND anonymized_at IS NULL AND id <> ? AND ((? IS NOT NULL AND dup_email = ?) OR (? IS NOT NULL AND dup_phone = ?)) LIMIT 1',
            [$le, $exceptId ?? 0, $e, $e, $p, $p]
        );
    }

    /**
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validate(array $in, bool $new): array
    {
        $s = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $errors = [];
        $name = $s('name', 160);
        if (mb_strlen($name) < 3) {
            $errors['name'] = 'talent.error.name';
        }
        $email = $s('email', 160);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'talent.error.email';
        }
        $phone = self::phone($s('phone', 30));
        if ($phone !== '' && preg_match('/^\+?[0-9]{9,15}$/', $phone) !== 1) {
            $errors['phone'] = 'talent.error.phone';
        }
        if ($email === '' && $phone === '') {
            $errors['phone'] = 'talent.error.contact';
        }
        $years = trim((string) ($in['experience_years'] ?? ''));
        if ($years !== '' && preg_match('/^\d{1,2}(\.\d)?$/', $years) !== 1) {
            $errors['experience_years'] = 'talent.error.years';
        }
        $edu = (string) ($in['education_level'] ?? '');
        $out = ['name' => $name, 'email' => $email !== '' ? $email : null, 'phone' => $phone !== '' ? $phone : null, 'positions' => $s('positions', 300), 'skills' => $s('skills', 1000),
            'experience_years' => $years !== '' ? (float) $years : null, 'education_level' => in_array($edu, Lists::EDU_LEVELS, true) ? $edu : null, 'notes' => $s('notes', 2000)];
        if ($new) {
            $source = in_array($in['source'] ?? '', self::SOURCES, true) ? (string) $in['source'] : 'upload';
            $consent = $s('consent_at', 10);
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $consent);
            if ($d === false || $d->format('Y-m-d') !== $consent || $consent > $this->today()) {
                $errors['consent_at'] = 'talent.error.consent_at';
            }
            $basis = $s('consent_basis', 300);
            if (mb_strlen($basis) < 5) {
                $errors['consent_basis'] = 'talent.error.consent_basis';
            }
            $out += ['source' => $source, 'referred_by' => $source === 'referral' ? ($s('referred_by', 160) ?: null) : null, 'consent_at' => $consent, 'consent_basis' => $basis];
            if ($source === 'referral' && $out['referred_by'] === null) {
                $errors['referred_by'] = 'talent.error.referred_by';
            }
        }
        return [$out, $errors];
    }

    /**
     * New profile (upload / walk-in / referral). The same e-mail or phone in the company → the CV and a note are added
     * to that profile instead (returns its id).
     *
     * @param array<string, mixed> $in
     * @return array{profile: string, merged: bool}
     */
    public function create(Authorizer $az, int $le, array $in, ?UploadedFile $cv): array
    {
        if (!$az->scope('talent.use')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        [$d, $errors] = $this->validate($in, true);
        if ($cv !== null && $cv->error !== UPLOAD_ERR_NO_FILE && (!$cv->ok() || $cv->size > 5 * 1048576)) {
            $errors['cv'] = 'talent.error.cv';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $dup = $this->duplicate($le, $d['email'], $d['phone']);
        if ($dup !== null) {
            $this->attachCv($az, (int) $dup['id'], $cv, (string) $dup['name']);
            $this->contact((int) $dup['id'], 'merged', $this->app->translator()->get('talent.source.' . $d['source']) . ($d['notes'] !== '' ? ' · ' . $d['notes'] : ''), $az->userId);
            return ['profile' => (string) $dup['public_id'], 'merged' => true];
        }
        $pid = $this->insert($az, $le, $d, null);
        $id = (int) $this->app->db()->scalar('SELECT id FROM tal_profiles WHERE public_id = ?', [$pid]);
        $this->attachCv($az, $id, $cv, $d['name']);
        return ['profile' => $pid, 'merged' => false];
    }

    /** @param array<string, mixed> $d */
    private function insert(Authorizer $az, int $le, array $d, ?int $candidateId): string
    {
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $this->app->db()->run(
            'INSERT INTO tal_profiles (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, name, email, phone, dup_email, dup_phone, positions, skills, experience_years,
                education_level, source, referred_by, candidate_id, consent_at, consent_basis, retention_until, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $le, $d['name'], $d['email'], $d['phone'], $this->dupKey($d['email']), $this->dupKey($d['phone']), $d['positions'], $d['skills'],
                $d['experience_years'], $d['education_level'], $d['source'], $d['referred_by'], $candidateId, $d['consent_at'], $d['consent_basis'], $this->retentionUntil((string) $d['consent_at']),
                $d['notes'] !== '' ? $d['notes'] : null]
        );
        $id = (int) $this->app->db()->scalar('SELECT id FROM tal_profiles WHERE public_id = ?', [$pid]);
        $this->contact($id, 'created', $d['source'], $az->userId);
        $this->app->audit()->record('talent.created', 'success', 'tal_profile', $pid, $le, null, ['source' => $d['source']]);
        return $pid;
    }

    private function attachCv(Authorizer $az, int $profileId, ?UploadedFile $cv, string $name): void
    {
        if ($cv === null || $cv->error === UPLOAD_ERR_NO_FILE) {
            return;
        }
        $stored = $this->app->documents()->storeGenerated($az->userId, 'talent.cv', (string) file_get_contents($cv->path), $cv->clientName, ['title' => mb_substr('CV · ' . $name, 0, 200)], 'upload', true);
        $this->app->db()->run('UPDATE tal_profiles SET cv_document_pid = ?, cv_filename = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$stored['document'], mb_substr($cv->clientName, 0, 200), $this->now(), $az->userId, $profileId]);
        $this->contact($profileId, 'cv', mb_substr($cv->clientName, 0, 200), $az->userId);
    }

    /**
     * From an application whose applicant agreed to the talent pool (M08). A profile with the same contacts gets the
     * application linked instead.
     *
     * @return array{profile: string, merged: bool}
     */
    public function fromCandidate(Authorizer $az, string $candidatePid): array
    {
        $c = RecruitService::of($this->app)->forTalent($az, $candidatePid);
        if ($c === null) {
            throw new BusinessException('talent.error.no_consent', [], 409);
        }
        $le = (int) $c['legal_entity_id'];
        if (!$az->scope('talent.use')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $pr = (array) $c['profile'];
        $levels = array_flip(Lists::EDU_LEVELS);
        $edu = null;
        foreach ((array) ($pr['education'] ?? []) as $row) {
            if (($row['level'] ?? '') !== '' && ($edu === null || $levels[$row['level']] > $levels[$edu])) {
                $edu = (string) $row['level'];
            }
        }
        $months = 0;
        foreach ((array) ($pr['work'] ?? []) as $row) {
            if (preg_match('/^(\d{4})-(\d{2})$/', (string) ($row['from'] ?? ''), $a) === 1) {
                $to = preg_match('/^(\d{4})-(\d{2})$/', (string) ($row['to'] ?? ''), $b) === 1 ? $b : [0, substr($this->today(), 0, 4), substr($this->today(), 5, 2)];
                $months += max(0, ((int) $to[1] * 12 + (int) $to[2]) - ((int) $a[1] * 12 + (int) $a[2]) + 1);
            }
        }
        $dup = $this->duplicate($le, $c['email'] !== null ? (string) $c['email'] : null, (string) $c['phone']);
        if ($dup !== null) {
            $this->app->db()->run('UPDATE tal_profiles SET candidate_id = COALESCE(candidate_id, ?), updated_at = ? WHERE id = ?', [$c['id'], $this->now(), $dup['id']]);
            $this->contact((int) $dup['id'], 'merged', (string) $c['posting_title'], $az->userId);
            return ['profile' => (string) $dup['public_id'], 'merged' => true];
        }
        $pid = $this->insert($az, $le, [
            'name' => (string) $c['name'], 'email' => $c['email'] !== null ? (string) $c['email'] : null, 'phone' => (string) $c['phone'] !== '' ? (string) $c['phone'] : null,
            'positions' => mb_substr((string) $c['posting_title'], 0, 300), 'skills' => mb_substr((string) ($pr['skills'] ?? ''), 0, 1000),
            'experience_years' => $months > 0 ? round($months / 12, 1) : null, 'education_level' => $edu, 'source' => 'applicant', 'referred_by' => null,
            'consent_at' => substr((string) $c['consent_at'], 0, 10), 'consent_basis' => $this->app->translator()->get('talent.basis_applicant', ['n' => (string) $c['notice_version']]), 'notes' => '',
        ], (int) $c['id']);
        return ['profile' => $pid, 'merged' => false];
    }

    /**
     * @param array<string, string> $f q, status, edu, years (minimum), starred, source
     * @return list<array<string, mixed>>
     */
    public function search(Authorizer $az, int $le, array $f): array
    {
        if (!$az->scope('talent.use')->coversLegalEntity($le)) {
            throw HttpException::forbidden();
        }
        $where = ['p.legal_entity_id = ?', 'p.anonymized_at IS NULL'];
        $args = [$le];
        if (($f['q'] ?? '') !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(p.name LIKE ? OR p.positions LIKE ? OR p.skills LIKE ? OR p.email LIKE ? OR p.phone LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        if (in_array($f['status'] ?? '', self::STATUSES, true)) {
            $where[] = 'p.status = ?';
            $args[] = $f['status'];
        }
        if (in_array($f['edu'] ?? '', Lists::EDU_LEVELS, true)) {
            $where[] = 'p.education_level = ?';
            $args[] = $f['edu'];
        }
        if (preg_match('/^\d{1,2}$/', (string) ($f['years'] ?? '')) === 1) {
            $where[] = 'p.experience_years >= ?';
            $args[] = (int) $f['years'];
        }
        if (($f['starred'] ?? '') === '1') {
            $where[] = 'p.starred = 1';
        }
        if (in_array($f['source'] ?? '', ['applicant', 'upload', 'walk_in', 'referral'], true)) {
            $where[] = 'p.source = ?';
            $args[] = $f['source'];
        }
        return $this->app->db()->all('SELECT p.* FROM tal_profiles p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.starred DESC, p.updated_at DESC LIMIT 300', $args);
    }

    /** @return array<string, mixed> a profile of a company in the person's scope */
    public function profile(Authorizer $az, string $pid): array
    {
        $p = $this->app->db()->one('SELECT * FROM tal_profiles WHERE public_id = ?', [$pid]);
        if ($p === null || !$az->scope('talent.use')->coversLegalEntity((int) $p['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        return $p;
    }

    /** @return array<string, mixed> */
    private function live(Authorizer $az, string $pid): array
    {
        $p = $this->profile($az, $pid);
        if ($p['anonymized_at'] !== null) {
            throw new BusinessException('talent.error.anonymized', [], 409);
        }
        return $p;
    }

    /** @param array<string, mixed> $in */
    public function update(Authorizer $az, string $pid, array $in): void
    {
        $p = $this->live($az, $pid);
        [$d, $errors] = $this->validate($in, false);
        if ($errors === [] && $this->duplicate((int) $p['legal_entity_id'], $d['email'], $d['phone'], (int) $p['id']) !== null) {
            $errors['email'] = 'talent.error.duplicate';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->app->db()->run(
            'UPDATE tal_profiles SET name = ?, email = ?, phone = ?, dup_email = ?, dup_phone = ?, positions = ?, skills = ?, experience_years = ?, education_level = ?, notes = ?,
                status = IF(status = \'new\', \'reviewed\', status), updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$d['name'], $d['email'], $d['phone'], $this->dupKey($d['email']), $this->dupKey($d['phone']), $d['positions'], $d['skills'], $d['experience_years'], $d['education_level'],
                $d['notes'] !== '' ? $d['notes'] : null, $this->now(), $az->userId, $p['id']]
        );
    }

    public function setStatus(Authorizer $az, string $pid, string $status): void
    {
        $p = $this->live($az, $pid);
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException(['status' => 'form.invalid_choice']);
        }
        $this->app->db()->run('UPDATE tal_profiles SET status = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$status, $this->now(), $az->userId, $p['id']]);
        $this->contact((int) $p['id'], 'status', $status, $az->userId);
    }

    public function star(Authorizer $az, string $pid, bool $on): void
    {
        $p = $this->live($az, $pid);
        $this->app->db()->run('UPDATE tal_profiles SET starred = ?, updated_at = ? WHERE id = ?', [$on ? 1 : 0, $this->now(), $p['id']]);
    }

    public function logContact(Authorizer $az, string $pid, string $channel, string $note): void
    {
        $p = $this->live($az, $pid);
        $note = mb_substr(trim($note), 0, 1000);
        if (!in_array($channel, ['phone', 'email', 'meeting', 'message', 'other'], true) || mb_strlen($note) < 2) {
            throw new ValidationException(['note' => 'talent.error.note']);
        }
        $this->contact((int) $p['id'], $channel, $note, $az->userId);
        if (in_array($p['status'], ['new', 'reviewed', 'pool', 'linked'], true)) {
            $this->app->db()->run("UPDATE tal_profiles SET status = 'contacted', updated_at = ? WHERE id = ?", [$this->now(), $p['id']]);
        }
    }

    public function uploadCv(Authorizer $az, string $pid, ?UploadedFile $cv): void
    {
        $p = $this->live($az, $pid);
        if ($cv === null || !$cv->ok() || $cv->size === 0 || $cv->size > 5 * 1048576) {
            throw new ValidationException(['cv' => 'talent.error.cv']);
        }
        $this->attachCv($az, (int) $p['id'], $cv, (string) $p['name']);
    }

    /** @return array<string, mixed> the CV (audited) */
    public function cv(Authorizer $az, string $pid): array
    {
        $p = $this->live($az, $pid);
        if ($p['cv_document_pid'] === null) {
            throw HttpException::notFound();
        }
        return $this->app->documents()->moduleRead((string) $p['cv_document_pid'], 'talent', $az->userId, 'download', true) + ['name' => (string) $p['cv_filename']];
    }

    /** Puts the person forward for a posting: an application in M08 with source talent_pool. */
    public function link(Authorizer $az, string $pid, string $postingPid): string
    {
        $p = $this->live($az, $pid);
        $cand = RecruitService::of($this->app)->addFromTalent($az, $postingPid, ['name' => (string) $p['name'], 'email' => $p['email'], 'phone' => $p['phone']], (int) $p['id']);
        $this->app->db()->run("UPDATE tal_profiles SET status = 'linked', updated_at = ? WHERE id = ?", [$this->now(), $p['id']]);
        $this->contact((int) $p['id'], 'linked', (string) $this->app->db()->scalar('SELECT title FROM rec_postings WHERE public_id = ?', [$postingPid]), $az->userId);
        return $cand;
    }

    /**
     * @param array<string, mixed> $p
     * @return list<array<string, mixed>> applications made from or linked to this profile
     */
    public function applications(array $p): array
    {
        return $this->app->db()->all(
            'SELECT c.public_id, c.stage, c.created_at, po.title FROM rec_candidates c JOIN rec_postings po ON po.id = c.posting_id WHERE c.talent_id = ? OR c.id = ? ORDER BY c.id DESC',
            [$p['id'], (int) ($p['candidate_id'] ?? 0)]
        );
    }

    /** @return list<array<string, mixed>> */
    public function contacts(int $profileId): array
    {
        return $this->app->db()->all('SELECT c.*, u.display_name AS actor FROM tal_contacts c LEFT JOIN users u ON u.id = c.actor_id WHERE c.profile_id = ? ORDER BY c.id DESC', [$profileId]);
    }

    /**
     * Open postings whose title shares a word (3+ letters) with the person's positions or skills.
     *
     * @param array<string, mixed> $p
     * @return list<array<string, mixed>>
     */
    public function matchingPostings(array $p): array
    {
        $words = array_values(array_filter(preg_split('/[\s,\/·]+/u', mb_strtolower((string) $p['positions'] . ' ' . (string) $p['skills'])) ?: [], static fn (string $w): bool => mb_strlen($w) >= 3));
        if ($words === []) {
            return [];
        }
        $out = [];
        foreach ($this->app->db()->all("SELECT public_id, title FROM rec_postings WHERE legal_entity_id = ? AND status = 'published'", [$p['legal_entity_id']]) as $po) {
            $title = mb_strtolower((string) $po['title']);
            foreach ($words as $w) {
                if (str_contains($title, $w)) {
                    $out[] = $po;
                    break;
                }
            }
        }
        return $out;
    }

    /** Hourly: for each published posting, tell recruiters once how many pool profiles match it. */
    public function matchJob(): int
    {
        $db = $this->app->db();
        $sent = 0;
        foreach ($db->all("SELECT id, public_id, title, legal_entity_id FROM rec_postings WHERE status = 'published'") as $po) {
            $n = 0;
            foreach ($db->all("SELECT * FROM tal_profiles WHERE legal_entity_id = ? AND anonymized_at IS NULL AND status IN ('new','reviewed','pool','contacted')", [$po['legal_entity_id']]) as $p) {
                foreach ($this->matchingPostings($p) as $m) {
                    if ($m['public_id'] === $po['public_id']) {
                        $n++;
                    }
                }
            }
            if ($n === 0) {
                continue;
            }
            foreach (RecruitService::of($this->app)->holders('talent.use', (int) $po['legal_entity_id']) as $uid) {
                $this->app->notifier()->notify($uid, 'talent.match', 'talent.notify.match', ['n' => (string) $n, 'title' => (string) $po['title']], '/talent?q=' . rawurlencode((string) $po['title']), 'tal.match:' . $po['public_id'] . ':' . $uid);
            }
            $sent++;
        }
        return $sent;
    }

    /** Hourly (idempotent): profiles past their retention date are anonymised. */
    public function retentionJob(): int
    {
        $ids = array_map('intval', array_column($this->app->db()->all('SELECT id FROM tal_profiles WHERE retention_until IS NOT NULL AND retention_until < ? AND anonymized_at IS NULL LIMIT 500', [$this->today()]), 'id'));
        foreach ($ids as $id) {
            $this->anonymize($id, null, 'retention');
        }
        if ($ids !== []) {
            $this->app->audit()->record('talent.anonymized', 'success', 'tal_profile', null, null, null, ['profiles' => count($ids)]);
        }
        return count($ids);
    }

    public function erase(Authorizer $az, string $pid, string $reason): void
    {
        $p = $this->live($az, $pid);
        if (!$az->scope('talent.manage')->coversLegalEntity((int) $p['legal_entity_id'])) {
            throw HttpException::forbidden();
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new ValidationException(['reason' => 'talent.error.reason']);
        }
        $this->anonymize((int) $p['id'], $az->userId, 'request: ' . mb_substr(trim($reason), 0, 200));
        $this->app->audit()->record('talent.erased', 'success', 'tal_profile', $pid, (int) $p['legal_entity_id'], mb_substr(trim($reason), 0, 200));
    }

    private function anonymize(int $id, ?int $actor, string $why): void
    {
        $now = $this->now();
        $this->app->db()->run("UPDATE tal_profiles SET name = '—', email = NULL, phone = NULL, dup_email = NULL, dup_phone = NULL, skills = '', notes = NULL, referred_by = NULL,
            cv_document_pid = NULL, cv_filename = NULL, status = 'inactive', starred = 0, anonymized_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $id]);
        $this->app->db()->run('UPDATE tal_contacts SET note = NULL WHERE profile_id = ?', [$id]);
        $this->contact($id, 'anonymized', $why, $actor);
    }

    private function contact(int $profileId, string $kind, ?string $note, ?int $actor): void
    {
        $this->app->db()->run('INSERT INTO tal_contacts (profile_id, kind, note, actor_id, occurred_at) VALUES (?, ?, ?, ?, ?)', [$profileId, $kind, $note, $actor, $this->now()]);
    }
}
