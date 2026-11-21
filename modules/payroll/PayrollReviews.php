<?php

declare(strict_types=1);

namespace Blue\Modules\Payroll;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;

/**
 * 0.49.0 (R8-4, DEC-118 · Q-11 "แต่งตั้ง Payroll Checker ภายหลัง เก็บประวัติแยกรายงวด"): the Payroll Checker (role PYC,
 * permission payroll.review) reviews approved payroll runs afterwards — one run at a time; regular, off-cycle and
 * correction runs are reviewed separately and listed by pay period. Until someone is appointed, approved runs wait here.
 *
 * Rules: only approved runs · the reviewer is not the person who created, calculated, submitted or approved the run
 * (maker ≠ checker) · "issues" needs a note · every review is kept (append-only), the latest counts · the review records
 * the hash of the result it looked at · audit and run history carry the result only — never amounts or the note.
 */
final class PayrollReviews
{
    public const RESULTS = ['ok', 'issues'];
    public const STATES = ['waiting', 'ok', 'issues'];
    private const NOTE_MAX = 1000;

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    /**
     * Approved runs of a company with their latest review, grouped by pay period (newest first).
     *
     * @return list<array{start: string, end: string, runs: list<array<string, mixed>>}>
     */
    public function periods(int $le, string $state = ''): array
    {
        $rows = $this->app->db()->all(
            "SELECT r.id, r.public_id, r.run_no, r.run_type, r.label, r.period_start, r.period_end, r.pay_date, r.approved_at, r.employee_count,
                    v.result AS review_result, v.reviewed_at, v.calc_hash AS review_hash, r.calc_hash, u.display_name AS reviewer,
                    (SELECT COUNT(*) FROM pay_run_reviews x WHERE x.run_id = r.id) AS review_count
               FROM pay_runs r
               LEFT JOIN pay_run_reviews v ON v.id = (SELECT MAX(y.id) FROM pay_run_reviews y WHERE y.run_id = r.id)
               LEFT JOIN users u ON u.id = v.reviewer_id
              WHERE r.legal_entity_id = ? AND r.status = 'approved'
              ORDER BY r.period_end DESC, r.period_start DESC, r.run_no",
            [$le]
        );
        $out = [];
        foreach ($rows as $r) {
            $r['state'] = $r['review_result'] === null ? 'waiting' : (string) $r['review_result'];
            if ($state !== '' && $r['state'] !== $state) {
                continue;
            }
            $key = $r['period_start'] . '|' . $r['period_end'];
            $out[$key] ??= ['start' => (string) $r['period_start'], 'end' => (string) $r['period_end'], 'runs' => []];
            $out[$key]['runs'][] = $r;
        }
        return array_values($out);
    }

    /** @return array{waiting: int, ok: int, issues: int} */
    public function counts(int $le): array
    {
        $c = ['waiting' => 0, 'ok' => 0, 'issues' => 0];
        foreach ($this->periods($le) as $p) {
            foreach ($p['runs'] as $r) {
                $c[$r['state']]++;
            }
        }
        return $c;
    }

    /** Is anyone appointed as Payroll Checker for this company (an active PYC assignment covering it)? */
    public function appointed(int $le): bool
    {
        $today = $this->app->clock->now()->format('Y-m-d');
        return (int) $this->app->db()->scalar(
            "SELECT COUNT(*) FROM user_role_assignments a JOIN roles o ON o.id = a.role_id JOIN access_scopes s ON s.assignment_id = a.id
              WHERE o.code = 'PYC' AND a.status = 'active' AND a.valid_from <= ? AND (a.valid_to IS NULL OR a.valid_to >= ?)
                AND ((s.scope_level = 'legal_entity' AND s.scope_id = ?) OR s.scope_level = 'organization')",
            [$today, $today, $le]
        ) > 0;
    }

    /** @return list<array<string, mixed>> reviews of a run, newest first */
    public function history(int $runId): array
    {
        return $this->app->db()->all(
            'SELECT v.public_id, v.result, v.note, v.calc_hash, v.reviewed_at, u.display_name AS reviewer FROM pay_run_reviews v JOIN users u ON u.id = v.reviewer_id WHERE v.run_id = ? ORDER BY v.id DESC',
            [$runId]
        );
    }

    /**
     * Why this person may not review the run ('' = may): not approved · no permission · took part in the run.
     *
     * @param array<string, mixed> $run
     */
    public function blocker(Authorizer $az, array $run): string
    {
        if (!$az->scope('payroll.review')->coversLegalEntity((int) $run['legal_entity_id'])) {
            return 'no_permission';
        }
        if ($run['status'] !== 'approved') {
            return 'not_approved';
        }
        foreach (['created_by', 'calculated_by', 'submitted_by', 'approved_by'] as $f) {
            if ($run[$f] !== null && (int) $run[$f] === $az->userId) {
                return 'took_part';
            }
        }
        return '';
    }

    /**
     * Records a review of an approved run.
     *
     * @return string public id of the review
     */
    public function review(Authorizer $az, string $runPid, string $result, string $note): string
    {
        $run = $this->app->db()->one('SELECT * FROM pay_runs WHERE public_id = ?', [$runPid]);
        if ($run === null || !$az->scope('payroll.view')->coversLegalEntity((int) $run['legal_entity_id'])) {
            throw HttpException::notFound();
        }
        $why = $this->blocker($az, $run);
        if ($why === 'no_permission') {
            throw HttpException::forbidden();
        }
        if ($why !== '') {
            throw new BusinessException('payroll.review.error.' . $why, [], 409);
        }
        $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
        $errors = [];
        if (!in_array($result, self::RESULTS, true)) {
            $errors['result'] = 'payroll.review.error.result';
        }
        if ($result === 'issues' && mb_strlen($note) < 5) {
            $errors['note'] = 'payroll.review.error.note';
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            $errors['note'] = 'payroll.review.error.note_long';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $db = $this->app->db();
        $db->transactional(function () use ($db, $pid, $run, $az, $result, $note, $now): void {
            $db->run(
                'INSERT INTO pay_run_reviews (public_id, run_id, legal_entity_id, reviewer_id, result, note, calc_hash, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $run['id'], $run['legal_entity_id'], $az->userId, $result, $note === '' ? null : $note, $run['calc_hash'], $now]
            );
            $db->run('INSERT INTO pay_run_events (run_id, action, actor_id, note, occurred_at) VALUES (?, ?, ?, ?, ?)', [$run['id'], 'reviewed:' . $result, $az->userId, null, $now]);
        });
        $this->app->audit()->record('payroll.run_reviewed', 'success', 'pay_run', $runPid, (int) $run['legal_entity_id'], null, ['result' => $result, 'review' => $pid]);
        return $pid;
    }
}
