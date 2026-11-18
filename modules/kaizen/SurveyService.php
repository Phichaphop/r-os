<?php

declare(strict_types=1);

namespace Blue\Modules\Kaizen;

use DateTimeImmutable;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;
use Blue\Modules\Employees\Audience;
use Blue\Modules\Employees\EmployeeService;

/**
 * M41 surveys (9D-1 · DEC-97): engagement, eNPS, satisfaction or short pulse surveys by HR (kaizen.survey).
 *
 * A survey has a target group (the shared audience), an answering window, questions (1–5 scale, eNPS 0–10, one choice,
 * several choices, free text) and the smallest number of answers before any result is shown (set per survey — no default).
 * Each person answers once. An anonymous survey stores the answers without the person; who has answered is kept in a
 * separate list with no time and no order (so the two cannot be matched) and answers carry the day only. Questions are
 * fixed once the survey opens.
 *
 * Statuses: draft → open → closed (end of window or early) → analyzed (summary written; the audience can read it).
 */
final class SurveyService
{
    public const KINDS = ['scale', 'enps', 'choice', 'multi', 'text'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function kz(): KaizenService
    {
        return KaizenService::of($this->app);
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function require(Authorizer $az, int $le): void
    {
        if (!$this->kz()->can($az, 'kaizen.survey', $le)) {
            throw HttpException::forbidden();
        }
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM kz_surveys WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed>|null my employee row with today's job */
    private function me(Authorizer $az): ?array
    {
        $me = EmployeeService::of($this->app)->forUser($az->userId);
        return $me === null || $me['status'] === 'former' || $me['job'] === null ? null : $me;
    }

    /** @param array<string, mixed> $s */
    private function inAudience(Authorizer $az, array $s): bool
    {
        $me = $this->me($az);
        return $me !== null && Audience::matches($this->app, (int) $s['legal_entity_id'], Audience::decode((string) $s['audience_json']), $me['job']);
    }

    // ---- for everyone ----------------------------------------------------------------------------------------------------------

    /**
     * Surveys of my company that include me: open ones to answer, and analyzed ones whose summary I can read.
     *
     * @return list<array<string, mixed>>
     */
    public function forMe(Authorizer $az): array
    {
        $me = $this->me($az);
        if ($me === null) {
            return [];
        }
        $le = (int) $me['job']['legal_entity_id'];
        $today = $this->kz()->today();
        $out = [];
        foreach ($this->app->db()->all("SELECT * FROM kz_surveys WHERE legal_entity_id = ? AND status IN ('open','analyzed') ORDER BY status = 'open' DESC, closes_on DESC LIMIT 100", [$le]) as $s) {
            if (!Audience::matches($this->app, $le, Audience::decode((string) $s['audience_json']), $me['job'])) {
                continue;
            }
            if ($s['status'] === 'open' && ((string) $s['opens_on'] > $today || (string) $s['closes_on'] < $today)) {
                continue;
            }
            $s['answered'] = $this->app->db()->scalar('SELECT 1 FROM kz_respondents WHERE survey_id = ? AND user_id = ?', [$s['id'], $az->userId]) !== null;
            $out[] = $s;
        }
        return $out;
    }

    /**
     * A survey to answer (or its published summary).
     *
     * @return array<string, mixed>
     */
    public function forAnswer(Authorizer $az, string $pid): array
    {
        $s = $this->row($pid);
        if (!in_array($s['status'], ['open', 'analyzed'], true) || !$this->inAudience($az, $s)) {
            throw HttpException::notFound();
        }
        $s['questions'] = $this->questions((int) $s['id']);
        $s['answered'] = $this->app->db()->scalar('SELECT 1 FROM kz_respondents WHERE survey_id = ? AND user_id = ?', [$s['id'], $az->userId]) !== null;
        $today = $this->kz()->today();
        $s['answerable'] = $s['status'] === 'open' && !$s['answered'] && (string) $s['opens_on'] <= $today && (string) $s['closes_on'] >= $today;
        return $s;
    }

    /** @return list<array<string, mixed>> */
    public function questions(int $surveyId): array
    {
        $out = [];
        foreach ($this->app->db()->all('SELECT * FROM kz_questions WHERE survey_id = ? ORDER BY sort, id', [$surveyId]) as $q) {
            $q['choices'] = $q['options'] === null ? [] : array_values(array_filter(array_map('trim', explode("\n", (string) $q['options'])), static fn (string $x): bool => $x !== ''));
            $out[] = $q;
        }
        return $out;
    }

    /**
     * Sends my answers (once).
     *
     * @param array<string, mixed> $in q<id> => value (multi: list)
     */
    public function answer(Authorizer $az, string $pid, array $in): void
    {
        $s = $this->forAnswer($az, $pid);
        if (!$s['answerable']) {
            throw new BusinessException($s['answered'] ? 'kaizen.error.answered' : 'kaizen.error.survey_closed', [], 409);
        }
        $answers = [];
        $e = [];
        foreach ($s['questions'] as $q) {
            $key = 'q' . $q['id'];
            $v = $in[$key] ?? null;
            $req = (int) $q['required'] === 1;
            switch ($q['kind']) {
                case 'scale':
                case 'enps':
                    $max = $q['kind'] === 'scale' ? 5 : 10;
                    $min = $q['kind'] === 'scale' ? 1 : 0;
                    $v = trim((string) $v);
                    if ($v === '') {
                        if ($req) {
                            $e[$key] = 'kaizen.error.answer_required';
                        }
                        break;
                    }
                    if (preg_match('/^\d{1,2}$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
                        $e[$key] = 'kaizen.error.choice';
                        break;
                    }
                    $answers[(string) $q['id']] = (int) $v;
                    break;
                case 'choice':
                    $v = trim((string) $v);
                    if ($v === '') {
                        if ($req) {
                            $e[$key] = 'kaizen.error.answer_required';
                        }
                        break;
                    }
                    if (!in_array($v, $q['choices'], true)) {
                        $e[$key] = 'kaizen.error.choice';
                        break;
                    }
                    $answers[(string) $q['id']] = $v;
                    break;
                case 'multi':
                    $picked = array_values(array_unique(array_map('strval', is_array($v) ? $v : [])));
                    if ($picked === []) {
                        if ($req) {
                            $e[$key] = 'kaizen.error.answer_required';
                        }
                        break;
                    }
                    if (array_diff($picked, $q['choices']) !== []) {
                        $e[$key] = 'kaizen.error.choice';
                        break;
                    }
                    $answers[(string) $q['id']] = $picked;
                    break;
                default:
                    $v = trim((string) $v);
                    if ($v === '') {
                        if ($req) {
                            $e[$key] = 'kaizen.error.answer_required';
                        }
                        break;
                    }
                    if (mb_strlen($v) > 2000) {
                        $e[$key] = 'kaizen.error.too_long';
                        break;
                    }
                    $answers[(string) $q['id']] = $v;
            }
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $db = $this->app->db();
        $anon = (int) $s['anonymous'] === 1;
        $db->transactional(function () use ($db, $s, $az, $answers, $anon): void {
            if ($db->run('INSERT IGNORE INTO kz_respondents (survey_id, user_id) VALUES (?, ?)', [$s['id'], $az->userId])->rowCount() === 0) {
                throw new BusinessException('kaizen.error.answered', [], 409);
            }
            $db->run('INSERT INTO kz_responses (survey_id, user_id, submitted_on, answers_json) VALUES (?, ?, ?, ?)', [$s['id'], $anon ? null : $az->userId, $this->kz()->today(), json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        });
    }

    // ---- HR ----------------------------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function adminList(Authorizer $az, int $le): array
    {
        $this->require($az, $le);
        return $this->app->db()->all(
            "SELECT s.*, (SELECT COUNT(*) FROM kz_respondents r WHERE r.survey_id = s.id) AS answers, (SELECT COUNT(*) FROM kz_questions q WHERE q.survey_id = s.id) AS question_count
               FROM kz_surveys s WHERE s.legal_entity_id = ? ORDER BY FIELD(s.status, 'draft','open','closed','analyzed'), s.closes_on DESC, s.id DESC LIMIT 300",
            [$le]
        );
    }

    /** @return array<string, mixed> survey + questions + audience text (HR view) */
    public function manage(Authorizer $az, string $pid): array
    {
        $s = $this->row($pid);
        $this->require($az, (int) $s['legal_entity_id']);
        $s['questions'] = $this->questions((int) $s['id']);
        $s['answers'] = (int) $this->app->db()->scalar('SELECT COUNT(*) FROM kz_respondents WHERE survey_id = ?', [$s['id']]);
        $aud = Audience::decode((string) $s['audience_json']);
        $s['audience_text'] = Audience::describe($this->app, $aud);
        $s['audience_in'] = Audience::toInput($this->app, $aud);
        $s['audience_size'] = count(array_filter(Audience::members($this->app, (int) $s['legal_entity_id'], $aud, $this->kz()->today()), static fn (?int $u): bool => $u !== null));
        return $s;
    }

    /**
     * Creates or edits a draft survey.
     *
     * @param array<string, mixed> $in title, intro, sites[], departments[], groups, opens_on, closes_on, anonymous, min_results
     */
    public function save(Authorizer $az, int $le, array $in, ?string $pid = null): string
    {
        $this->require($az, $le);
        $db = $this->app->db();
        if ($pid !== null) {
            $s = $this->row($pid);
            if ((int) $s['legal_entity_id'] !== $le) {
                throw HttpException::notFound();
            }
            if ($s['status'] !== 'draft') {
                throw new BusinessException('kaizen.error.not_draft', [], 409);
            }
        }
        $e = [];
        $title = trim((string) ($in['title'] ?? ''));
        $intro = trim((string) ($in['intro'] ?? ''));
        $from = trim((string) ($in['opens_on'] ?? ''));
        $to = trim((string) ($in['closes_on'] ?? ''));
        $min = trim((string) ($in['min_results'] ?? ''));
        if (mb_strlen($title) < 5 || mb_strlen($title) > 160) {
            $e['title'] = 'kaizen.error.title';
        }
        if (mb_strlen($intro) > 2000) {
            $e['intro'] = 'kaizen.error.too_long';
        }
        $ok = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && DateTimeImmutable::createFromFormat('!Y-m-d', $d) !== false;
        if (!$ok($from) || !$ok($to) || $to < $from) {
            $e['opens_on'] = 'kaizen.error.window';
        } elseif ((DateTimeImmutable::createFromFormat('!Y-m-d', $from) ?: throw new ValidationException(['opens_on' => 'kaizen.error.window']))->modify('+366 days')->format('Y-m-d') < $to) {
            $e['opens_on'] = 'kaizen.error.window_long';
        }
        if (preg_match('/^\d{1,3}$/', $min) !== 1 || (int) $min < 1 || (int) $min > 500) {
            $e['min_results'] = 'kaizen.error.min_results';
        }
        try {
            $aud = Audience::fromInput($this->app, $le, $in);
        } catch (ValidationException $x) {
            $e += $x->errors;
            $aud = Audience::empty();
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $vals = [$title, $intro ?: null, Audience::encode($aud), $from, $to, !empty($in['anonymous']) ? 1 : 0, (int) $min];
        $now = $this->stamp();
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO kz_surveys (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, title, intro, audience_json, opens_on, closes_on, anonymous, min_results) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, ...$vals]
            );
        } else {
            $db->run('UPDATE kz_surveys SET title = ?, intro = ?, audience_json = ?, opens_on = ?, closes_on = ?, anonymous = ?, min_results = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE public_id = ?', [...$vals, $now, $az->userId, $pid]);
        }
        $this->app->audit()->record('kaizen.survey_saved', 'success', 'kz_survey', $pid, $le);
        return $pid;
    }

    /**
     * Adds a question to a draft, or removes one.
     *
     * @param array<string, mixed> $in kind, text, options, required · or remove = question id
     */
    public function question(Authorizer $az, string $pid, array $in): void
    {
        $s = $this->row($pid);
        $this->require($az, (int) $s['legal_entity_id']);
        if ($s['status'] !== 'draft') {
            throw new BusinessException('kaizen.error.not_draft', [], 409);
        }
        $db = $this->app->db();
        if (($in['remove'] ?? '') !== '') {
            $db->run('DELETE FROM kz_questions WHERE id = ? AND survey_id = ?', [(int) $in['remove'], $s['id']]);
            return;
        }
        $kind = (string) ($in['kind'] ?? '');
        $text = trim((string) ($in['text'] ?? ''));
        $opts = array_values(array_unique(array_filter(array_map(static fn (string $x): string => mb_substr(trim($x), 0, 120), preg_split('/\R/', (string) ($in['options'] ?? '')) ?: []), static fn (string $x): bool => $x !== '')));
        $e = [];
        if (!in_array($kind, self::KINDS, true)) {
            $e['kind'] = 'kaizen.error.choice';
        }
        if (mb_strlen($text) < 3 || mb_strlen($text) > 500) {
            $e['text'] = 'kaizen.error.question';
        }
        if (in_array($kind, ['choice', 'multi'], true) && (count($opts) < 2 || count($opts) > 20)) {
            $e['options'] = 'kaizen.error.options';
        }
        if ((int) $db->scalar('SELECT COUNT(*) FROM kz_questions WHERE survey_id = ?', [$s['id']]) >= 50) {
            $e['text'] = 'kaizen.error.too_many_questions';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $sort = (int) $db->scalar('SELECT COALESCE(MAX(sort), 0) + 1 FROM kz_questions WHERE survey_id = ?', [$s['id']]);
        $db->run('INSERT INTO kz_questions (survey_id, sort, kind, text, options, required) VALUES (?, ?, ?, ?, ?, ?)', [$s['id'], $sort, $kind, $text, in_array($kind, ['choice', 'multi'], true) ? implode("\n", $opts) : null, !empty($in['required']) ? 1 : 0]);
    }

    /** Opens a draft (questions are fixed from now on) · closes an open one early · writes the summary of a closed one. */
    public function transition(Authorizer $az, string $pid, string $to, string $summary = ''): void
    {
        $s = $this->row($pid);
        $le = (int) $s['legal_entity_id'];
        $this->require($az, $le);
        $db = $this->app->db();
        $today = $this->kz()->today();
        switch ($to) {
            case 'open':
                if ($s['status'] !== 'draft') {
                    throw new BusinessException('kaizen.error.not_draft', [], 409);
                }
                if ((int) $db->scalar('SELECT COUNT(*) FROM kz_questions WHERE survey_id = ?', [$s['id']]) === 0) {
                    throw new BusinessException('kaizen.error.no_questions', [], 409);
                }
                if ((string) $s['closes_on'] < $today) {
                    throw new BusinessException('kaizen.error.window_past', [], 409);
                }
                $db->run("UPDATE kz_surveys SET status = 'open', updated_at = ?, updated_by = ? WHERE id = ?", [$this->stamp(), $az->userId, $s['id']]);
                $this->announce($pid);
                break;
            case 'closed':
                if ($s['status'] !== 'open') {
                    throw new BusinessException('kaizen.error.not_open', [], 409);
                }
                $db->run("UPDATE kz_surveys SET status = 'closed', updated_at = ?, updated_by = ? WHERE id = ?", [$this->stamp(), $az->userId, $s['id']]);
                break;
            case 'analyzed':
                if ($s['status'] !== 'closed') {
                    throw new BusinessException('kaizen.error.not_closed', [], 409);
                }
                $summary = trim($summary);
                if (mb_strlen($summary) < 10 || mb_strlen($summary) > 4000) {
                    throw new ValidationException(['summary' => 'kaizen.error.summary']);
                }
                $db->run("UPDATE kz_surveys SET status = 'analyzed', summary = ?, updated_at = ?, updated_by = ? WHERE id = ?", [$summary, $this->stamp(), $az->userId, $s['id']]);
                break;
            default:
                throw HttpException::notFound();
        }
        $this->app->audit()->record('kaizen.survey_' . $to, 'success', 'kz_survey', $pid, $le);
    }

    /** Tells the audience once the survey is open and its window has started. */
    private function announce(string $pid): void
    {
        $s = $this->row($pid);
        if ($s['status'] !== 'open' || (int) $s['notified'] === 1 || (string) $s['opens_on'] > $this->kz()->today()) {
            return;
        }
        $this->app->db()->run('UPDATE kz_surveys SET notified = 1 WHERE id = ?', [$s['id']]);
        foreach (Audience::members($this->app, (int) $s['legal_entity_id'], Audience::decode((string) $s['audience_json']), $this->kz()->today()) as $u) {
            if ($u !== null) {
                $this->app->notifier()->notify($u, 'kaizen.survey', 'kaizen.notify.survey', ['title' => (string) $s['title'], 'until' => (string) $s['closes_on']], '/kaizen/surveys/s/' . $pid, 'kz.sv:' . $pid . ':' . $u);
            }
        }
    }

    // ---- results -----------------------------------------------------------------------------------------------------------------

    /**
     * Aggregated results. Nothing but the count is shown below the survey's minimum number of answers.
     *
     * @return array<string, mixed>
     */
    public function results(Authorizer $az, string $pid): array
    {
        $s = $this->manage($az, $pid);
        $rows = array_map(static fn (array $r): array => (array) json_decode((string) $r['answers_json'], true), $this->app->db()->all('SELECT answers_json FROM kz_responses WHERE survey_id = ?', [$s['id']]));
        $n = count($rows);
        $s['n'] = $n;
        $s['rate'] = $s['audience_size'] > 0 ? round($n * 100 / $s['audience_size'], 1) : null;
        $s['shown'] = $n >= (int) $s['min_results'];
        $s['results'] = [];
        if (!$s['shown']) {
            return $s;
        }
        foreach ($s['questions'] as $q) {
            $vals = [];
            foreach ($rows as $r) {
                if (array_key_exists((string) $q['id'], $r)) {
                    $vals[] = $r[(string) $q['id']];
                }
            }
            $res = ['question' => $q, 'answered' => count($vals)];
            switch ($q['kind']) {
                case 'scale':
                case 'enps':
                    $dist = [];
                    for ($k = $q['kind'] === 'scale' ? 1 : 0; $k <= ($q['kind'] === 'scale' ? 5 : 10); $k++) {
                        $dist[$k] = 0;
                    }
                    foreach ($vals as $v) {
                        if (isset($dist[(int) $v])) {
                            $dist[(int) $v]++;
                        }
                    }
                    $res['dist'] = $dist;
                    $res['avg'] = $vals === [] ? null : round(array_sum(array_map('intval', $vals)) / count($vals), 2);
                    if ($q['kind'] === 'enps' && $vals !== []) {
                        $pro = count(array_filter($vals, static fn ($v): bool => (int) $v >= 9));
                        $det = count(array_filter($vals, static fn ($v): bool => (int) $v <= 6));
                        $res['enps'] = round(($pro - $det) * 100 / count($vals), 1);
                        $res['promoters'] = $pro;
                        $res['detractors'] = $det;
                    }
                    break;
                case 'choice':
                case 'multi':
                    $dist = array_fill_keys($q['choices'], 0);
                    foreach ($vals as $v) {
                        foreach ((array) $v as $c) {
                            if (isset($dist[(string) $c])) {
                                $dist[(string) $c]++;
                            }
                        }
                    }
                    $res['dist'] = $dist;
                    break;
                default:
                    $texts = array_map('strval', $vals);
                    sort($texts); // no submission order
                    $res['texts'] = $texts;
            }
            $s['results'][] = $res;
        }
        return $s;
    }

    /** CSV of the answers (one row per answer, sorted — not in submission order); only once results can be shown. */
    public function exportCsv(Authorizer $az, string $pid): string
    {
        $s = $this->results($az, $pid);
        if (!$s['shown']) {
            throw new BusinessException('kaizen.error.below_minimum', ['n' => (string) $s['min_results']], 409);
        }
        $anon = (int) $s['anonymous'] === 1;
        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            throw new \RuntimeException('temp stream');
        }
        fwrite($out, "\xEF\xBB\xBF");
        $head = $anon ? [] : ['person'];
        foreach ($s['questions'] as $q) {
            $head[] = (string) $q['text'];
        }
        fputcsv($out, $head, ',', '"', '');
        $lines = [];
        foreach ($this->app->db()->all('SELECT r.answers_json, u.display_name FROM kz_responses r LEFT JOIN users u ON u.id = r.user_id WHERE r.survey_id = ?', [$s['id']]) as $r) {
            $a = (array) json_decode((string) $r['answers_json'], true);
            $line = $anon ? [] : [(string) ($r['display_name'] ?? '')];
            foreach ($s['questions'] as $q) {
                $v = $a[(string) $q['id']] ?? '';
                $line[] = is_array($v) ? implode('; ', array_map('strval', $v)) : (string) $v;
            }
            $lines[] = $line;
        }
        sort($lines);
        foreach ($lines as $line) {
            // a free-text answer never starts a spreadsheet formula when the CSV is opened
            fputcsv($out, array_map(static fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'" . $v : $v, $line), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        $this->app->audit()->record('kaizen.survey_exported', 'success', 'kz_survey', $pid, (int) $s['legal_entity_id'], null, ['rows' => count($lines)]);
        return $csv;
    }

    /** Hourly: open surveys past their window close; surveys whose window starts today are announced. */
    public function tick(): int
    {
        $db = $this->app->db();
        $today = $this->kz()->today();
        $n = $db->run("UPDATE kz_surveys SET status = 'closed', updated_at = ? WHERE status = 'open' AND closes_on < ?", [$this->stamp(), $today])->rowCount();
        foreach ($db->all("SELECT public_id FROM kz_surveys WHERE status = 'open' AND notified = 0 AND opens_on <= ? LIMIT 100", [$today]) as $s) {
            $this->announce((string) $s['public_id']);
            $n++;
        }
        return $n + $this->kz()->tick();
    }

    public static function tickJob(App $app): string
    {
        return 'kaizen: ' . self::of($app)->tick();
    }
}
