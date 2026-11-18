<?php

declare(strict_types=1);

namespace Blue\Modules\Draw;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\Csv;
use Blue\Core\Files\FileType;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Files\Xlsx;
use Blue\Core\Support\Ulid;

/**
 * M29 Lucky draw: Draft → Ready (list locked, commitment shown) → Drawing → Results Confirmed (seed revealed) → Archived.
 * Rounds are never overwritten: a redraw voids one winner with a reason and adds a new round.
 * 0.6.1: events are private to their organizer (nobody else sees them); "reset" starts a new generation (earlier rounds are
 * kept, the old seed is revealed in the log); prize_mode "sequence" draws an order (lucky no. 1, 2, 3 …) without prizes.
 */
final class DrawService
{
    public const MAX_PARTICIPANTS = 5000;
    public const MAX_PRIZES = 50;

    public function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- reading -------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> events the person organizes — only their own (0.6.1: draws are private) */
    public function mine(Authorizer $az): array
    {
        if (!$az->can('draw.use')) {
            return [];
        }
        return $this->app->db()->all(
            "SELECT e.*, (SELECT COUNT(*) FROM draw_participants p WHERE p.event_id = e.id) AS participants FROM draw_events e
              WHERE e.owner_user_id = ? ORDER BY e.status = 'archived', e.id DESC LIMIT 200",
            [$az->userId]
        );
    }

    /** @return array<string, mixed> an event the person organizes */
    public function event(Authorizer $az, string $pid): array
    {
        $e = $this->row($pid);
        if (!$this->canOrganize($az, $e)) {
            throw HttpException::notFound();
        }
        return $e;
    }

    /** Only the organizer — not even draw.manage opens someone else's draw (0.6.1: "see only my own draws"). */
    /** @param array<string, mixed> $e */
    public function canOrganize(Authorizer $az, array $e): bool
    {
        return $az->can('draw.use') && (int) $e['owner_user_id'] === $az->userId;
    }

    /** @return list<array<string, mixed>> */
    public function participants(int $eventId, int $limit = 0): array
    {
        return $this->app->db()->all('SELECT * FROM draw_participants WHERE event_id = ? ORDER BY position' . ($limit > 0 ? ' LIMIT ' . $limit : ''), [$eventId]);
    }

    public function participantCount(int $eventId): int
    {
        return (int) $this->app->db()->scalar('SELECT COUNT(*) FROM draw_participants WHERE event_id = ?', [$eventId]);
    }

    /** @return list<array<string, mixed>> prizes with how many are still to draw */
    public function prizes(int $eventId): array
    {
        return $this->app->db()->all(
            "SELECT z.*, (SELECT COUNT(*) FROM draw_winners w JOIN draw_events e ON e.id = w.event_id WHERE w.prize_id = z.id AND w.status = 'active' AND w.generation = e.generation) AS won
               FROM draw_prizes z WHERE z.event_id = ? AND z.retired = 0 ORDER BY z.sort, z.id",
            [$eventId]
        );
    }

    /** @return list<array<string, mixed>> winners of the current generation with names, rounds and reasons (voided kept) */
    public function winners(int $eventId): array
    {
        return $this->app->db()->all(
            'SELECT w.*, p.name, p.code, p.grp, p.position, r.round_no, r.reason AS round_reason, r.created_at AS drawn_at, z.name AS prize_name, z.sort AS prize_sort
               FROM draw_winners w JOIN draw_participants p ON p.id = w.participant_id JOIN draw_rounds r ON r.id = w.round_id JOIN draw_prizes z ON z.id = w.prize_id
              WHERE w.event_id = ? AND w.generation = (SELECT generation FROM draw_events WHERE id = w.event_id)
              ORDER BY z.sort, z.id, COALESCE(w.seq_no, 0), r.round_no, w.pick_no',
            [$eventId]
        );
    }

    /**
     * Names shown on the idle wheel / start line of the draw screen (presentation only — never the source of randomness):
     * up to 60, in random order; names that can no longer be drawn are left out.
     *
     * @return list<string>
     */
    public function showNames(int $eventId): array
    {
        $e = $this->app->db()->one('SELECT * FROM draw_events WHERE id = ?', [$eventId]) ?? [];
        $skipWon = $e !== [] && (int) $e['no_repeat'] === 1 && !$this->keepsDrawn($e);
        return array_values(array_map('strval', array_column($this->app->db()->all(
            'SELECT p.name FROM draw_participants p WHERE p.event_id = ?'
            . ($skipWon ? " AND NOT EXISTS (SELECT 1 FROM draw_winners w WHERE w.participant_id = p.id AND w.status = 'active' AND w.generation = ?)" : '')
            . ' ORDER BY RAND() LIMIT 60',
            $skipWon ? [$eventId, (int) $e['generation']] : [$eventId]
        ), 'name')));
    }

    /** @return list<array<string, mixed>> */
    public function rounds(int $eventId): array
    {
        return $this->app->db()->all(
            'SELECT r.*, z.name AS prize_name FROM draw_rounds r JOIN draw_prizes z ON z.id = r.prize_id
              WHERE r.event_id = ? AND r.generation = (SELECT generation FROM draw_events WHERE id = r.event_id) ORDER BY r.round_no',
            [$eventId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function log(int $eventId): array
    {
        return $this->app->db()->all('SELECT l.*, u.display_name AS actor_name FROM draw_log l LEFT JOIN users u ON u.id = l.actor_id WHERE l.event_id = ? ORDER BY l.id DESC LIMIT 200', [$eventId]);
    }

    /**
     * Results page — the organizer only (0.6.1: draws are private; publishing to everyone was removed).
     *
     * @return array<string, mixed>
     */
    public function results(Authorizer $az, string $pid): array
    {
        $e = $this->event($az, $pid);
        $e['organizer'] = true;
        return $e;
    }

    /**
     * Seed (hex) — only after results are confirmed.
     *
     * @param array<string, mixed> $e
     */
    public function revealedSeed(array $e): ?string
    {
        if (!in_array($e['status'], ['confirmed', 'archived'], true) || $e['confirmed_at'] === null || $e['seed_enc'] === null) {
            return null;
        }
        return bin2hex($this->app->crypto()->decrypt((string) base64_decode((string) $e['seed_enc'], true)));
    }

    /**
     * Recomputes every round from the revealed seed and the snapshot; true when every round gives the recorded winners.
     *
     * @param array<string, mixed> $e
     * @return array{ok: bool, rounds: list<array{round: int, ok: bool}>, snapshot_ok: bool, commitment_ok: bool}|null
     */
    public function verify(array $e): ?array
    {
        $hex = $this->revealedSeed($e);
        if ($hex === null) {
            return null;
        }
        $seed = (string) hex2bin($hex);
        $parts = $this->participants((int) $e['id']);
        $byId = [];
        foreach ($parts as $p) {
            $byId[(int) $p['id']] = (int) $p['position'];
        }
        $snapshot = DrawEngine::snapshotHash(
            array_map(static fn (array $p): array => ['position' => (int) $p['position'], 'code' => $p['code'], 'name' => (string) $p['name'], 'grp' => $p['grp']], $parts),
            array_map(static fn (array $z): array => ['sort' => (int) $z['sort'], 'name' => (string) $z['name'], 'quantity' => (int) $z['quantity']], $this->prizes((int) $e['id'])),
            (int) $e['no_repeat'] === 1
        );
        $out = [];
        $ok = true;
        $taken = [];
        foreach ($this->rounds((int) $e['id']) as $r) {
            $winners = $this->app->db()->all('SELECT participant_id, prize_id FROM draw_winners WHERE round_id = ? ORDER BY pick_no', [$r['id']]);
            $cands = $r['candidate_list'] !== null // simple draws (0.11.2) keep the exact list of each spin
                ? array_values(array_map('intval', (array) json_decode((string) $r['candidate_list'], true)))
                : $this->eligible($parts, $taken, (int) $r['prize_id'], (int) $e['no_repeat'] === 1, $this->keepsDrawn($e), $this->replacedPosition($r, $byId));
            $picked = DrawEngine::pick($seed, (int) $r['round_no'], $cands, (int) $r['draw_count']);
            $recorded = array_map(static fn (array $w): int => $byId[(int) $w['participant_id']] ?? -1, $winners);
            $roundOk = $picked === $recorded && DrawEngine::candidatesHash($cands) === $r['candidates_hash'];
            $ok = $ok && $roundOk;
            $out[] = ['round' => (int) $r['round_no'], 'ok' => $roundOk];
            foreach ($winners as $w) {
                $taken[] = ['position' => $byId[(int) $w['participant_id']] ?? -1, 'prize' => (int) $w['prize_id']];
            }
        }
        $commitOk = DrawEngine::commitment($seed) === $e['commitment'];
        $snapOk = ($e['style'] ?? 'classic') === 'simple' ? true : $snapshot === $e['snapshot_hash']; // a simple draw has no locked list — each spin keeps its own
        return ['ok' => $ok && $commitOk && $snapOk, 'rounds' => $out, 'snapshot_ok' => $snapOk, 'commitment_ok' => $commitOk];
    }

    // ---- drafting ---------------------------------------------------------------------------------

    /** @param array<string, string> $in */
    public function create(Authorizer $az, array $in, ?int $legalEntityId): string
    {
        $this->organizer($az);
        $d = $this->validate($in);
        $pid = Ulid::generate($this->app->clock);
        $now = $this->now();
        $this->app->db()->run(
            'INSERT INTO draw_events (public_id, created_at, created_by, updated_at, updated_by, owner_user_id, legal_entity_id, name, description, mode, prize_mode, seq_count, no_repeat, event_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $now, $az->userId, $now, $az->userId, $az->userId, $legalEntityId, $d['name'], $d['description'], $d['mode'], $d['prize_mode'], $d['seq_count'], $d['no_repeat'], $d['event_at']]
        );
        $id = (int) $this->app->db()->scalar('SELECT id FROM draw_events WHERE public_id = ?', [$pid]);
        $this->logIt($id, 'created', ['name' => $d['name']], $az->userId);
        $this->app->audit()->record('draw.created', 'success', 'draw_event', $pid, $legalEntityId);
        return $pid;
    }

    /** @param array<string, string> $in */
    public function update(Authorizer $az, string $pid, array $in): void
    {
        $e = $this->draft($az, $pid);
        $d = $this->validate($in);
        $this->app->db()->run(
            'UPDATE draw_events SET name = ?, description = ?, mode = ?, prize_mode = ?, seq_count = ?, no_repeat = ?, event_at = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
            [$d['name'], $d['description'], $d['mode'], $d['prize_mode'], $d['seq_count'], $d['no_repeat'], $d['event_at'], $this->now(), $az->userId, $e['id']]
        );
        $this->logIt((int) $e['id'], 'edited', [], $az->userId);
    }

    /** Presentation mode can be switched any time before confirming (it does not affect the result). */
    public function setMode(Authorizer $az, string $pid, string $mode): void
    {
        $e = $this->event($az, $pid);
        if (in_array($mode, ['wheel', 'race'], true) && in_array($e['status'], ['draft', 'ready', 'drawing'], true)) {
            $this->app->db()->run('UPDATE draw_events SET mode = ?, updated_at = ? WHERE id = ?', [$mode, $this->now(), $e['id']]);
        }
    }

    /**
     * Imports participants from pasted text or a CSV/XLSX file. Columns: name · code, name · code, name, group.
     *
     * @return array{added: int, duplicates: list<int>, invalid: list<array{0: int, 1: string}>, total: int}
     */
    public function import(Authorizer $az, string $pid, string $text, ?UploadedFile $file, bool $replace): array
    {
        $e = $this->draft($az, $pid);
        $rows = [];
        if ($file !== null && $file->error !== UPLOAD_ERR_NO_FILE) {
            if (!$file->ok()) {
                throw new ValidationException(['file' => $file->errorKey()]);
            }
            $bytes = $file->bytes();
            $type = FileType::sniff($bytes, $file->safeName())['type'];
            try {
                $rows = match ($type) {
                    'xlsx' => Xlsx::read($bytes, null, self::MAX_PARTICIPANTS + 2),
                    'csv', 'txt' => Csv::read($bytes, self::MAX_PARTICIPANTS + 2),
                    default => throw new ValidationException(['file' => 'draw.error.file_type']),
                };
            } catch (ValidationException $ex) {
                throw $ex;
            } catch (\Throwable) {
                throw new ValidationException(['file' => 'draw.error.file_read']);
            }
        } else {
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $rows[] = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line, ',', '"', '');
            }
        }
        $rows = array_values(array_map(static fn (array $r): array => array_map(static fn (mixed $c): string => trim((string) $c), $r), $rows));
        if ($rows !== [] && preg_match('/^(name|ชื่อ|code|รหัส)/iu', implode(' ', $rows[0])) === 1) {
            array_shift($rows); // header row
            $offset = 2;
        } else {
            $offset = 1;
        }
        $existing = $replace ? [] : $this->participants((int) $e['id']);
        $seen = [];
        foreach ($existing as $p) {
            $seen[$this->key($p['code'], (string) $p['name'])] = true;
        }
        $add = [];
        $dups = [];
        $invalid = [];
        foreach ($rows as $i => $r) {
            $r = array_values(array_filter($r, static fn (string $c): bool => $c !== '')) === [] ? [] : $r;
            if ($r === []) {
                continue;
            }
            [$code, $name, $grp] = match (count($r)) {
                1 => [null, $r[0], null],
                2 => [$r[0] !== '' ? $r[0] : null, $r[1], null],
                default => [$r[0] !== '' ? $r[0] : null, $r[1], $r[2] !== '' ? $r[2] : null],
            };
            $row = $i + $offset;
            if ($name === '' || mb_strlen($name) > 120 || mb_strlen((string) $code) > 40 || mb_strlen((string) $grp) > 80 || preg_match('/[\x00-\x1F]/', $name . $code . $grp) === 1) {
                $invalid[] = [$row, $name === '' ? 'draw.import.no_name' : 'draw.import.too_long'];
                continue;
            }
            $k = $this->key($code, $name);
            if (isset($seen[$k])) {
                $dups[] = $row;
                continue;
            }
            $seen[$k] = true;
            $add[] = [$code, $name, $grp];
        }
        if (count($existing) + count($add) > self::MAX_PARTICIPANTS) {
            throw new ValidationException(['file' => 'draw.error.too_many']);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $e, $replace, $add, $existing): void {
            if ($replace) {
                $db->run('DELETE FROM draw_participants WHERE event_id = ?', [$e['id']]);
            }
            $pos = $replace ? 0 : count($existing);
            foreach ($add as [$code, $name, $grp]) {
                $db->run('INSERT INTO draw_participants (event_id, position, code, name, grp) VALUES (?, ?, ?, ?, ?)', [$e['id'], ++$pos, $code, $name, $grp]);
            }
        });
        $this->logIt((int) $e['id'], 'imported', ['added' => count($add), 'duplicates' => count($dups), 'invalid' => count($invalid), 'replace' => $replace], $az->userId);
        return ['added' => count($add), 'duplicates' => $dups, 'invalid' => $invalid, 'total' => $this->participantCount((int) $e['id'])];
    }

    public function clearParticipants(Authorizer $az, string $pid): void
    {
        $e = $this->draft($az, $pid);
        $this->app->db()->run('DELETE FROM draw_participants WHERE event_id = ?', [$e['id']]);
        $this->logIt((int) $e['id'], 'cleared', [], $az->userId);
    }

    /** @param list<array{name: string, quantity: string}> $rows */
    public function savePrizes(Authorizer $az, string $pid, array $rows): void
    {
        $e = $this->draft($az, $pid);
        $clean = [];
        foreach ($rows as $r) {
            $name = mb_substr(trim((string) $r['name']), 0, 120);
            $q = trim((string) $r['quantity']);
            if ($name === '' && $q === '') {
                continue;
            }
            if ($name === '' || !ctype_digit($q) || (int) $q < 1 || (int) $q > 1000) {
                throw new ValidationException(['prizes' => 'draw.error.prize']);
            }
            $clean[] = [$name, (int) $q];
        }
        if (count($clean) > self::MAX_PRIZES) {
            throw new ValidationException(['prizes' => 'draw.error.too_many_prizes']);
        }
        $db = $this->app->db();
        $db->transactional(function () use ($db, $e, $clean): void {
            $this->retirePrizes((int) $e['id']);
            foreach ($clean as $i => [$name, $q]) {
                $db->run('INSERT INTO draw_prizes (event_id, sort, name, quantity) VALUES (?, ?, ?, ?)', [$e['id'], $i + 1, $name, $q]);
            }
        });
        $this->logIt((int) $e['id'], 'prizes', ['count' => count($clean)], $az->userId);
    }

    // ---- lock / draw / confirm ------------------------------------------------------------------------

    public function lock(Authorizer $az, string $pid, bool $lawAck): void
    {
        $e = $this->draft($az, $pid);
        $n = $this->participantCount((int) $e['id']);
        if ($e['prize_mode'] === 'sequence' && $n > 0) {
            // no prizes: one implicit "lucky order" prize as long as the list (or the number asked for).
            // 0.11.1: the organizer chooses whether a name drawn leaves the pool (no_repeat = 1) or stays in it (0 — may come up again)
            $q = $e['seq_count'] !== null ? ($this->keepsDrawn($e) ? (int) $e['seq_count'] : min((int) $e['seq_count'], $n)) : $n;
            $this->app->db()->transactional(function () use ($e, $q): void {
                $this->retirePrizes((int) $e['id']);
                $this->app->db()->run('INSERT INTO draw_prizes (event_id, sort, name, quantity) VALUES (?, 1, ?, ?)', [$e['id'], $this->app->translator()->get('draw.seq.prize_name'), $q]);
            });
        }
        $prizes = $this->prizes((int) $e['id']);
        $total = array_sum(array_map(static fn (array $z): int => (int) $z['quantity'], $prizes));
        $errors = [];
        if ($n === 0) {
            $errors['lock'] = 'draw.error.no_participants';
        } elseif ($prizes === []) {
            $errors['lock'] = 'draw.error.no_prizes';
        } elseif (!$this->keepsDrawn($e) && ((int) $e['no_repeat'] === 1 ? $total > $n : max(array_map(static fn (array $z): int => (int) $z['quantity'], $prizes)) > $n)) {
            $errors['lock'] = 'draw.error.more_winners';
        } elseif (!$lawAck) {
            $errors['law_ack'] = 'draw.error.law_ack';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $seed = DrawEngine::newSeed();
        $parts = $this->participants((int) $e['id']);
        $snapshot = DrawEngine::snapshotHash(
            array_map(static fn (array $p): array => ['position' => (int) $p['position'], 'code' => $p['code'], 'name' => (string) $p['name'], 'grp' => $p['grp']], $parts),
            array_map(static fn (array $z): array => ['sort' => (int) $z['sort'], 'name' => (string) $z['name'], 'quantity' => (int) $z['quantity']], $prizes),
            (int) $e['no_repeat'] === 1
        );
        $now = $this->now();
        $this->app->db()->run(
            "UPDATE draw_events SET status = 'ready', law_ack = 1, seed_enc = ?, commitment = ?, snapshot_hash = ?, locked_at = ?, locked_by = ?, updated_at = ?, updated_by = ?,
                row_version = row_version + 1 WHERE id = ? AND status = 'draft'",
            [base64_encode($this->app->crypto()->encrypt($seed)), DrawEngine::commitment($seed), $snapshot, $now, $az->userId, $now, $az->userId, $e['id']]
        );
        $this->logIt((int) $e['id'], 'locked', ['participants' => $n, 'prizes' => count($prizes), 'commitment' => DrawEngine::commitment($seed), 'snapshot' => $snapshot], $az->userId);
        $this->app->audit()->record('draw.locked', 'success', 'draw_event', $pid, $this->le($e), null, ['commitment' => DrawEngine::commitment($seed), 'snapshot' => $snapshot]);
    }

    /** Back to draft before any draw (e.g. a name was missing) — the old commitment is logged and a new one made on the next lock. */
    public function unlock(Authorizer $az, string $pid, string $reason): void
    {
        $e = $this->event($az, $pid);
        if ($e['status'] !== 'ready') {
            throw new BusinessException('draw.error.status', [], 409);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'iam.error.reason_required']);
        }
        $this->app->db()->run("UPDATE draw_events SET status = 'draft', seed_enc = NULL, commitment = NULL, snapshot_hash = NULL, locked_at = NULL, locked_by = NULL, updated_at = ?,
            row_version = row_version + 1 WHERE id = ? AND status = 'ready' AND NOT EXISTS (SELECT 1 FROM draw_rounds r WHERE r.event_id = draw_events.id AND r.generation = draw_events.generation)", [$this->now(), $e['id']]);
        $this->logIt((int) $e['id'], 'unlocked', ['reason' => mb_substr(trim($reason), 0, 300), 'old_commitment' => $e['commitment']], $az->userId);
        $this->app->audit()->record('draw.unlocked', 'success', 'draw_event', $pid, $this->le($e), mb_substr(trim($reason), 0, 200));
    }

    /**
     * Draws $count winners of a prize. Same $token again (double click / refresh / retry) → the same round, never a new one.
     *
     * @return array<string, mixed> round, winners, remaining, names for the animation
     */
    public function draw(Authorizer $az, string $pid, int $prizeId, int $count, string $token, ?int $replaceWinnerId = null, string $reason = ''): array
    {
        $e = $this->event($az, $pid);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new ValidationException(['token' => 'draw.error.token']);
        }
        $db = $this->app->db();
        return $db->transactional(function () use ($db, $e, $az, $prizeId, $count, $token, $replaceWinnerId, $reason, $pid): array {
            $ev = $db->one('SELECT * FROM draw_events WHERE id = ? FOR UPDATE', [$e['id']]) ?? throw HttpException::notFound();
            $done = $db->one('SELECT * FROM draw_rounds WHERE event_id = ? AND request_token = ?', [$ev['id'], $token]);
            if ($done !== null) {
                return $this->roundView($ev, $done);
            }
            if (!in_array($ev['status'], ['ready', 'drawing'], true)) {
                throw new BusinessException('draw.error.status', [], 409);
            }
            $prize = $db->one('SELECT * FROM draw_prizes WHERE id = ? AND event_id = ?', [$prizeId, $ev['id']]) ?? throw new ValidationException(['prize' => 'draw.error.prize_pick']);
            if ($replaceWinnerId !== null) {
                $w = $db->one("SELECT * FROM draw_winners WHERE id = ? AND event_id = ? AND prize_id = ? AND status = 'active' AND generation = ?", [$replaceWinnerId, $ev['id'], $prizeId, $ev['generation']]);
                if ($w === null) {
                    throw new ValidationException(['winner' => 'draw.error.winner']);
                }
                if (mb_strlen(trim($reason)) < 3) {
                    throw new ValidationException(['reason' => 'draw.error.redraw_reason']);
                }
                $db->run("UPDATE draw_winners SET status = 'void', void_reason = ?, voided_by = ?, voided_at = ? WHERE id = ?", [mb_substr(trim($reason), 0, 300), $az->userId, $this->now(), $w['id']]);
                $count = 1;
            }
            $gen = (int) $ev['generation'];
            $won = (int) $db->scalar("SELECT COUNT(*) FROM draw_winners WHERE prize_id = ? AND status = 'active' AND generation = ?", [$prizeId, $gen]);
            $remaining = (int) $prize['quantity'] - $won;
            if ($count < 1 || $count > $remaining) {
                throw new ValidationException(['count' => 'draw.error.count']);
            }
            $parts = $this->participants((int) $ev['id']);
            $taken = array_map(
                static fn (array $w): array => ['position' => (int) $w['position'], 'prize' => (int) $w['prize_id']],
                $db->all('SELECT p.position, w.prize_id FROM draw_winners w JOIN draw_participants p ON p.id = w.participant_id WHERE w.event_id = ? AND w.generation = ?', [$ev['id'], $gen])
            );
            $replacedPos = null;
            if (isset($w) && $replaceWinnerId !== null) {
                $replacedPos = (int) $db->scalar('SELECT position FROM draw_participants WHERE id = ?', [$w['participant_id']]);
            }
            $cands = $this->eligible($parts, $taken, $prizeId, (int) $ev['no_repeat'] === 1, $this->keepsDrawn($ev), $replacedPos);
            if (count($cands) < $count) {
                throw new ValidationException(['count' => 'draw.error.no_candidates']);
            }
            $roundNo = (int) $db->scalar('SELECT COALESCE(MAX(round_no), 0) + 1 FROM draw_rounds WHERE event_id = ? AND generation = ?', [$ev['id'], $gen]);
            $seed = $this->app->crypto()->decrypt((string) base64_decode((string) $ev['seed_enc'], true));
            $picked = DrawEngine::pick($seed, $roundNo, $cands, $count);
            $db->run(
                'INSERT INTO draw_rounds (event_id, generation, prize_id, round_no, draw_count, candidates, candidates_hash, reason, replaces_winner_id, request_token, created_by, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$ev['id'], $gen, $prizeId, $roundNo, $count, count($cands), DrawEngine::candidatesHash($cands), $replaceWinnerId !== null ? mb_substr(trim($reason), 0, 300) : null,
                $replaceWinnerId,
                $token,
                $az->userId,
                $this->now()]
            );
            $roundId = (int) $db->scalar('SELECT id FROM draw_rounds WHERE event_id = ? AND generation = ? AND round_no = ?', [$ev['id'], $gen, $roundNo]);
            $byPos = [];
            foreach ($parts as $p) {
                $byPos[(int) $p['position']] = (int) $p['id'];
            }
            // sequence mode: places 1, 2, 3 … in draw order; a redraw takes over the place of the winner it replaces
            $seq = null;
            if ($ev['prize_mode'] === 'sequence') {
                $seq = isset($w) && $replaceWinnerId !== null && $w['seq_no'] !== null ? (int) $w['seq_no']
                    : (int) $db->scalar('SELECT COALESCE(MAX(seq_no), 0) FROM draw_winners WHERE event_id = ? AND generation = ?', [$ev['id'], $gen]) + 1;
            }
            foreach ($picked as $k => $pos) {
                $db->run(
                    'INSERT INTO draw_winners (event_id, generation, round_id, prize_id, participant_id, pick_no, seq_no) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$ev['id'], $gen, $roundId, $prizeId, $byPos[$pos], $k + 1, $seq !== null ? ($replaceWinnerId !== null ? $seq : $seq + $k) : null]
                );
            }
            $db->run("UPDATE draw_events SET status = 'drawing', updated_at = ? WHERE id = ?", [$this->now(), $ev['id']]);
            $this->logIt((int) $ev['id'], $replaceWinnerId !== null ? 'redrawn' : 'drawn', ['round' => $roundNo, 'prize' => $prize['name'], 'count' => $count, 'candidates' => count($cands),
                'reason' => $replaceWinnerId !== null ? mb_substr(trim($reason), 0, 300) : null], $az->userId);
            $this->app->audit()->record($replaceWinnerId !== null ? 'draw.redrawn' : 'draw.drawn', 'success', 'draw_event', $pid, $this->le($ev), null, ['round' => $roundNo, 'count' => $count]);
            $round = $db->one('SELECT * FROM draw_rounds WHERE id = ?', [$roundId]) ?? [];
            return $this->roundView($ev, $round);
        });
    }

    public const SIMPLE_MAX_NAMES = 2000;
    public const SIMPLE_MAX_WINNERS = 200;

    /**
     * Simple saved draw (0.11.2, DEC-74): like the quick draw — the names on screen, spin — but on the server and saved.
     * The first spin creates the draw (secret seed + commitment); each spin records the exact list it drew from, so it can
     * be replayed from the seed once the results are confirmed. Spins are never deleted. Same $token again = same spin.
     *
     * @param list<string> $names the names on screen now (repeats and blank lines are dropped)
     * @return array<string, mixed> event, round, winners (name, seq), prize, candidates
     */
    public function spin(Authorizer $az, ?string $pid, string $title, array $names, int $count, string $prize, string $token, string $mode, bool $takeOut, ?int $legalEntityId): array
    {
        $this->organizer($az);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new ValidationException(['token' => 'draw.error.token']);
        }
        $list = [];
        foreach ($names as $n) {
            $n = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $n)), 0, 120);
            if ($n !== '') {
                $list[mb_strtolower($n)] ??= $n;
            }
        }
        if ($list === []) {
            throw new ValidationException(['names' => 'draw.quick.error.names']);
        }
        if (count($list) > self::SIMPLE_MAX_NAMES) {
            throw new ValidationException(['names' => 'draw.quick.error.too_many']);
        }
        if ($count < 1 || $count > self::SIMPLE_MAX_WINNERS || $count > count($list)) {
            throw new ValidationException(['count' => 'draw.quick.error.count']);
        }
        $title = mb_substr(trim($title), 0, 120);
        $prize = mb_substr(trim($prize), 0, 120);
        $mode = $mode === 'race' ? 'race' : 'wheel';
        $db = $this->app->db();
        return $db->transactional(function () use ($db, $az, $pid, $title, $list, $count, $prize, $token, $mode, $takeOut, $legalEntityId): array {
            $now = $this->now();
            if ($pid === null || $pid === '') {
                $seed = DrawEngine::newSeed();
                $pid = Ulid::generate($this->app->clock);
                $name = mb_strlen($title) >= 2 ? $title : $this->app->translator()->get('draw.simple.default_name', ['at' => $this->app->clock->now()->setTimezone(new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->format('d/m/Y H:i')]);
                $db->run(
                    "INSERT INTO draw_events (public_id, created_at, created_by, updated_at, updated_by, owner_user_id, legal_entity_id, name, mode, style, prize_mode, no_repeat, status, seed_enc, commitment, locked_at, locked_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'simple', 'prizes', ?, 'drawing', ?, ?, ?, ?)",
                    [$pid, $now, $az->userId, $now, $az->userId, $az->userId, $legalEntityId, $name, $mode, $takeOut ? 1 : 0, base64_encode($this->app->crypto()->encrypt($seed)), DrawEngine::commitment($seed), $now, $az->userId]
                );
                $id = (int) $db->scalar('SELECT id FROM draw_events WHERE public_id = ?', [$pid]);
                $this->logIt($id, 'created', ['name' => $name, 'commitment' => DrawEngine::commitment($seed)], $az->userId);
                $this->app->audit()->record('draw.created', 'success', 'draw_event', $pid, $legalEntityId, null, ['commitment' => DrawEngine::commitment($seed)]);
            }
            $ev = $db->one('SELECT * FROM draw_events WHERE public_id = ? FOR UPDATE', [$pid]) ?? throw HttpException::notFound();
            if (!$this->canOrganize($az, $ev) || ($ev['style'] ?? 'classic') !== 'simple') {
                throw HttpException::notFound();
            }
            $done = $db->one('SELECT * FROM draw_rounds WHERE event_id = ? AND request_token = ?', [$ev['id'], $token]);
            if ($done !== null) {
                return $this->spinView($ev, $done);
            }
            if ($ev['status'] !== 'drawing') {
                throw new BusinessException('draw.error.status', [], 409);
            }
            // names: existing ones keep their position, new ones are appended (the list only ever grows)
            $pos = [];
            foreach ($db->all('SELECT position, name FROM draw_participants WHERE event_id = ?', [$ev['id']]) as $p) {
                $pos[mb_strtolower((string) $p['name'])] = (int) $p['position'];
            }
            $next = $pos === [] ? 1 : max($pos) + 1;
            if (count($pos) + count(array_diff_key($list, $pos)) > self::MAX_PARTICIPANTS) {
                throw new ValidationException(['names' => 'draw.error.too_many']);
            }
            foreach ($list as $k => $n) {
                if (!isset($pos[$k])) {
                    $db->run('INSERT INTO draw_participants (event_id, position, name) VALUES (?, ?, ?)', [$ev['id'], $next, $n]);
                    $pos[$k] = $next++;
                }
            }
            $cands = array_values(array_map(static fn (string $k): int => $pos[$k], array_keys($list)));
            sort($cands);
            $gen = (int) $ev['generation'];
            $roundNo = (int) $db->scalar('SELECT COALESCE(MAX(round_no), 0) + 1 FROM draw_rounds WHERE event_id = ? AND generation = ?', [$ev['id'], $gen]);
            $label = $prize !== '' ? $prize : $this->app->translator()->get('draw.simple.default_prize');
            $z = $db->one('SELECT * FROM draw_prizes WHERE event_id = ? AND name = ? AND retired = 0', [$ev['id'], $label]);
            if ($z === null) {
                $db->run('INSERT INTO draw_prizes (event_id, sort, name, quantity) VALUES (?, ?, ?, ?)', [$ev['id'], (int) $db->scalar('SELECT COALESCE(MAX(sort), 0) + 1 FROM draw_prizes WHERE event_id = ?', [$ev['id']]), $label, $count]);
                $prizeId = (int) $db->scalar('SELECT id FROM draw_prizes WHERE event_id = ? AND name = ? AND retired = 0', [$ev['id'], $label]);
            } else {
                $prizeId = (int) $z['id'];
                $db->run('UPDATE draw_prizes SET quantity = quantity + ? WHERE id = ?', [$count, $prizeId]);
            }
            $seed = $this->app->crypto()->decrypt((string) base64_decode((string) $ev['seed_enc'], true));
            $picked = DrawEngine::pick($seed, $roundNo, $cands, $count);
            $db->run(
                'INSERT INTO draw_rounds (event_id, generation, prize_id, round_no, draw_count, candidates, candidates_hash, candidate_list, request_token, created_by, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$ev['id'], $gen, $prizeId, $roundNo, $count, count($cands), DrawEngine::candidatesHash($cands), json_encode($cands), $token, $az->userId, $now]
            );
            $roundId = (int) $db->scalar('SELECT id FROM draw_rounds WHERE event_id = ? AND generation = ? AND round_no = ?', [$ev['id'], $gen, $roundNo]);
            $byPos = array_column($db->all('SELECT id, position FROM draw_participants WHERE event_id = ?', [$ev['id']]), 'id', 'position');
            $seq = (int) $db->scalar('SELECT COALESCE(MAX(seq_no), 0) FROM draw_winners WHERE event_id = ? AND generation = ?', [$ev['id'], $gen]);
            foreach ($picked as $k => $p) {
                $db->run('INSERT INTO draw_winners (event_id, generation, round_id, prize_id, participant_id, pick_no, seq_no) VALUES (?, ?, ?, ?, ?, ?, ?)', [$ev['id'], $gen, $roundId, $prizeId, (int) $byPos[$p], $k + 1, $seq + $k + 1]);
            }
            $db->run(
                'UPDATE draw_events SET name = ?, mode = ?, no_repeat = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?',
                [mb_strlen($title) >= 2 ? $title : $ev['name'], $mode, $takeOut ? 1 : 0, $now, $az->userId, $ev['id']]
            );
            $this->logIt((int) $ev['id'], 'drawn', ['round' => $roundNo, 'prize' => $label, 'count' => $count, 'candidates' => count($cands)], $az->userId);
            $this->app->audit()->record('draw.drawn', 'success', 'draw_event', (string) $ev['public_id'], $this->le($ev), null, ['round' => $roundNo, 'count' => $count, 'candidates' => count($cands)]);
            return $this->spinView($ev, $db->one('SELECT * FROM draw_rounds WHERE id = ?', [$roundId]) ?? []);
        });
    }

    /**
     * The names on screen when a simple draw is opened again: the list of its last spin.
     *
     * @param array<string, mixed> $e
     * @return list<string>
     */
    public function lastNames(array $e): array
    {
        $last = $this->app->db()->one('SELECT id, candidate_list FROM draw_rounds WHERE event_id = ? AND generation = ? ORDER BY round_no DESC LIMIT 1', [$e['id'], $e['generation']]);
        $positions = $last !== null ? array_map('intval', (array) json_decode((string) $last['candidate_list'], true)) : [];
        if ($positions === []) {
            return [];
        }
        if ((int) $e['no_repeat'] === 1) { // "take winners out" was on: the last winners already left the list on screen
            $won = array_map('intval', array_column($this->app->db()->all('SELECT p.position FROM draw_winners w JOIN draw_participants p ON p.id = w.participant_id WHERE w.round_id = ?', [$last['id']]), 'position'));
            $positions = array_values(array_diff($positions, $won));
        }
        $names = array_column($this->app->db()->all('SELECT position, name FROM draw_participants WHERE event_id = ?', [$e['id']]), 'name', 'position');
        return array_values(array_map(static fn (int $p): string => (string) ($names[$p] ?? ''), $positions));
    }

    /**
     * @param array<string, mixed> $ev
     * @param array<string, mixed> $round
     * @return array<string, mixed>
     */
    private function spinView(array $ev, array $round): array
    {
        $db = $this->app->db();
        $winners = $db->all('SELECT w.seq_no, p.name FROM draw_winners w JOIN draw_participants p ON p.id = w.participant_id WHERE w.round_id = ? ORDER BY w.pick_no', [$round['id']]);
        return ['event' => (string) $ev['public_id'], 'round' => (int) $round['round_no'], 'candidates' => (int) $round['candidates'],
            'prize' => (string) $db->scalar('SELECT name FROM draw_prizes WHERE id = ?', [$round['prize_id']]),
            'winners' => array_map(static fn (array $w): array => ['name' => (string) $w['name'], 'seq' => (int) $w['seq_no']], $winners)];
    }

    /** Renames a simple draw (title field on its page). */
    public function rename(Authorizer $az, string $pid, string $title): void
    {
        $e = $this->event($az, $pid);
        $title = mb_substr(trim($title), 0, 120);
        if (mb_strlen($title) >= 2 && $title !== $e['name']) {
            $this->app->db()->run('UPDATE draw_events SET name = ?, updated_at = ?, updated_by = ? WHERE id = ?', [$title, $this->now(), $az->userId, $e['id']]);
        }
    }

    public function confirm(Authorizer $az, string $pid, string $witness): void
    {
        $e = $this->event($az, $pid);
        if ($e['status'] !== 'drawing') {
            throw new BusinessException('draw.error.status', [], 409);
        }
        $now = $this->now();
        $this->app->db()->run(
            "UPDATE draw_events SET status = 'confirmed', confirmed_at = ?, confirmed_by = ?, witness = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ? AND status = 'drawing'",
            [$now, $az->userId, trim($witness) !== '' ? mb_substr(trim($witness), 0, 120) : null, $now, $e['id']]
        );
        $this->logIt((int) $e['id'], 'confirmed', ['witness' => trim($witness) !== '' ? mb_substr(trim($witness), 0, 120) : null], $az->userId);
        $this->app->audit()->record('draw.confirmed', 'success', 'draw_event', $pid, $this->le($e));
    }

    public function publish(Authorizer $az, string $pid, bool $on): void
    {
        $e = $this->event($az, $pid);
        if ($e['status'] !== 'confirmed') {
            throw new BusinessException('draw.error.status', [], 409);
        }
        $this->app->db()->run('UPDATE draw_events SET published = ?, updated_at = ? WHERE id = ?', [$on ? 1 : 0, $this->now(), $e['id']]);
        $this->logIt((int) $e['id'], $on ? 'published' : 'unpublished', [], $az->userId);
        $this->app->audit()->record($on ? 'draw.published' : 'draw.unpublished', 'success', 'draw_event', $pid, $this->le($e));
    }

    /**
     * "Reset the draw": back to draft with a new generation. Earlier rounds and winners stay in the database (shown
     * no more); the old commitment AND the old seed go to the log so the earlier draws can still be checked.
     */
    public function reset(Authorizer $az, string $pid, string $reason): void
    {
        $e = $this->event($az, $pid);
        if (!in_array($e['status'], ['ready', 'drawing', 'confirmed'], true)) {
            throw new BusinessException('draw.error.status', [], 409);
        }
        $reason = mb_substr(trim($reason), 0, 300);
        if (mb_strlen($reason) < 3) {
            throw new ValidationException(['reset_reason' => 'draw.error.reset_reason']);
        }
        $oldSeed = $e['seed_enc'] !== null ? bin2hex($this->app->crypto()->decrypt((string) base64_decode((string) $e['seed_enc'], true))) : null;
        $rounds = (int) $this->app->db()->scalar('SELECT COUNT(*) FROM draw_rounds WHERE event_id = ? AND generation = ?', [$e['id'], $e['generation']]);
        $this->app->db()->run(
            "UPDATE draw_events SET status = 'draft', generation = generation + 1, seed_enc = NULL, commitment = NULL, snapshot_hash = NULL, locked_at = NULL, locked_by = NULL,
                confirmed_at = NULL, confirmed_by = NULL, witness = NULL, published = 0, law_ack = 0, updated_at = ?, updated_by = ?, row_version = row_version + 1
              WHERE id = ? AND generation = ?",
            [$this->now(), $az->userId, $e['id'], $e['generation']]
        );
        $this->logIt((int) $e['id'], 'reset', ['reason' => $reason, 'generation' => (int) $e['generation'], 'rounds' => $rounds,
            'old_commitment' => $e['commitment'], 'old_seed' => $oldSeed], $az->userId);
        $this->app->audit()->record('draw.reset', 'success', 'draw_event', $pid, $this->le($e), mb_substr($reason, 0, 200), ['generation' => (int) $e['generation'], 'rounds' => $rounds]);
    }

    /**
     * The participant template (Excel): code · name · group headers only (so no example can slip into a real draw), plus
     * a second sheet that explains the columns with an example.
     */
    public function template(): string
    {
        $t = $this->app->translator();
        return Xlsx::write([
            $t->get('draw.template.sheet') => ['headers' => [$t->get('draw.col.code'), $t->get('draw.col.name'), $t->get('draw.col.group')], 'rows' => [], 'widths' => [16, 36, 24]],
            $t->get('draw.template.help_sheet') => ['headers' => [$t->get('draw.template.help_col'), $t->get('draw.template.help_text')], 'freeze' => false, 'widths' => [22, 80],
                'bold_rows' => [5], 'rows' => [
                    [$t->get('draw.col.code'), $t->get('draw.template.help_code')],
                    [$t->get('draw.col.name'), $t->get('draw.template.help_name')],
                    [$t->get('draw.col.group'), $t->get('draw.template.help_group')],
                    ['', $t->get('draw.template.help_limit', ['n' => (string) self::MAX_PARTICIPANTS])],
                    ['', ''],
                    [$t->get('draw.template.example_title'), ''],
                    ['E001 | ' . $t->get('draw.template.example1') . ' | ' . $t->get('draw.template.example_group'), $t->get('draw.template.example_note1')],
                    [' | ' . $t->get('draw.template.example2') . ' | ', $t->get('draw.template.example_note2')],
                ]],
        ], 'Blue OS lucky draw template');
    }

    public function archive(Authorizer $az, string $pid): void
    {
        $e = $this->event($az, $pid);
        if (!in_array($e['status'], ['draft', 'confirmed'], true)) {
            throw new BusinessException('draw.error.status', [], 409);
        }
        $this->app->db()->run("UPDATE draw_events SET status = 'archived', published = 0, updated_at = ? WHERE id = ?", [$this->now(), $e['id']]);
        $this->logIt((int) $e['id'], 'archived', [], $az->userId);
    }

    /**
     * @param array<string, mixed> $e
     * @return array{0: list<string>, 1: list<list<string>>} export headers + rows
     */
    public function exportRows(array $e): array
    {
        $t = $this->app->translator();
        $rows = [];
        foreach ($this->winners((int) $e['id']) as $w) {
            $rows[] = [(string) $w['prize_name'], (string) $w['round_no'], (string) $w['pick_no'], (string) ($w['code'] ?? ''), (string) $w['name'], (string) ($w['grp'] ?? ''),
                $t->get('draw.winner.' . $w['status']), (string) ($w['void_reason'] ?? ''), (string) ($w['round_reason'] ?? ''), (string) $w['drawn_at']];
        }
        return [[$t->get('draw.col.prize'), $t->get('draw.col.round'), $t->get('draw.col.pick'), $t->get('draw.col.code'), $t->get('draw.col.name'), $t->get('draw.col.group'),
            $t->get('draw.col.status'), $t->get('draw.col.void_reason'), $t->get('draw.col.round_reason'), $t->get('draw.col.drawn_at')], $rows];
    }

    // ---- helpers ------------------------------------------------------------------------------------------

    /**
     * Candidates (snapshot positions) for a prize: everyone not already drawn — for any prize when "no repeat", else for this prize.
     * Sequence mode that keeps drawn names (0.11.1): everyone, every round — except, on a redraw, the person being replaced.
     *
     * @param list<array<string, mixed>> $parts
     * @param list<array{position: int, prize: int}> $taken
     * @return list<int>
     */
    private function eligible(array $parts, array $taken, int $prizeId, bool $noRepeat, bool $keepDrawn = false, ?int $replaced = null): array
    {
        $out = [];
        $skip = [];
        foreach ($keepDrawn ? [] : $taken as $t) {
            if ($noRepeat || $t['prize'] === $prizeId) {
                $skip[$t['position']] = true;
            }
        }
        if ($replaced !== null) {
            $skip[$replaced] = true; // a redraw never gives the place back to the person it replaces
        }
        foreach ($parts as $p) {
            if (!isset($skip[(int) $p['position']])) {
                $out[] = (int) $p['position'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $ev
     * @param array<string, mixed> $round
     * @return array<string, mixed>
     */
    private function roundView(array $ev, array $round): array
    {
        $db = $this->app->db();
        $winners = $db->all('SELECT w.id, w.pick_no, w.seq_no, p.name, p.grp FROM draw_winners w JOIN draw_participants p ON p.id = w.participant_id WHERE w.round_id = ? ORDER BY w.pick_no', [$round['id']]);
        $prize = $db->one('SELECT * FROM draw_prizes WHERE id = ?', [$round['prize_id']]) ?? [];
        $won = (int) $db->scalar("SELECT COUNT(*) FROM draw_winners WHERE prize_id = ? AND status = 'active' AND generation = ?", [$round['prize_id'], $round['generation'] ?? $ev['generation']]);
        // names for the animation only (presentation, not the source of randomness)
        $names = array_column($db->all('SELECT name FROM draw_participants WHERE event_id = ? ORDER BY RAND() LIMIT 40', [$ev['id']]), 'name');
        return ['round' => (int) $round['round_no'], 'prize' => ['id' => (int) $prize['id'], 'name' => (string) $prize['name'], 'remaining' => (int) $prize['quantity'] - $won],
            'winners' => array_map(static fn (array $w): array => ['id' => (int) $w['id'], 'name' => (string) $w['name'], 'group' => (string) ($w['grp'] ?? ''),
                'seq' => $w['seq_no'] !== null ? (int) $w['seq_no'] : null], $winners),
            'names' => array_values(array_map('strval', $names)), 'reason' => $round['reason']];
    }

    /** @param array<string, mixed> $e sequence mode where a drawn name stays in the pool (0.11.1) */
    private function keepsDrawn(array $e): bool
    {
        return ($e['prize_mode'] ?? 'prizes') === 'sequence' && (int) $e['no_repeat'] === 0;
    }

    /**
     * Snapshot position of the winner a redraw round replaced (verification replays the same exclusion).
     *
     * @param array<string, mixed> $round
     * @param array<int, int> $byId participant id → position
     */
    private function replacedPosition(array $round, array $byId): ?int
    {
        if ($round['replaces_winner_id'] === null) {
            return null;
        }
        $pid = $this->app->db()->scalar('SELECT participant_id FROM draw_winners WHERE id = ?', [$round['replaces_winner_id']]);
        return $pid !== null ? ($byId[(int) $pid] ?? null) : null;
    }

    /** Prizes nobody has won (in any generation) are deleted; the others stay, retired, for the history. */
    private function retirePrizes(int $eventId): void
    {
        $db = $this->app->db();
        $db->run('DELETE FROM draw_prizes WHERE event_id = ? AND NOT EXISTS (SELECT 1 FROM draw_winners w WHERE w.prize_id = draw_prizes.id)', [$eventId]);
        $db->run('UPDATE draw_prizes SET retired = 1 WHERE event_id = ?', [$eventId]);
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->app->db()->one('SELECT * FROM draw_events WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> */
    private function draft(Authorizer $az, string $pid): array
    {
        $e = $this->event($az, $pid);
        if ($e['status'] !== 'draft') {
            throw new BusinessException('draw.error.locked', [], 409);
        }
        return $e;
    }

    private function organizer(Authorizer $az): void
    {
        if (!$az->can('draw.use')) {
            throw HttpException::forbidden();
        }
    }

    /**
     * @param array<string, string> $in
     * @return array{name: string, description: ?string, mode: string, no_repeat: int, event_at: ?string, prize_mode: string, seq_count: ?int}
     */
    private function validate(array $in): array
    {
        $name = mb_substr(trim($in['name'] ?? ''), 0, 120);
        if (mb_strlen($name) < 2) {
            throw new ValidationException(['name' => 'draw.error.name']);
        }
        $at = null;
        if (trim($in['event_date'] ?? '') !== '') {
            $tz = new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($in['event_date']) . ' ' . (trim($in['event_time'] ?? '') ?: '00:00'), $tz);
            if ($d === false) {
                throw new ValidationException(['event_date' => 'draw.error.date']);
            }
            $at = $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }
        $desc = trim($in['description'] ?? '');
        $prizeMode = ($in['prize_mode'] ?? '') === 'sequence' ? 'sequence' : 'prizes';
        $seq = trim($in['seq_count'] ?? '');
        if ($seq !== '' && (!ctype_digit($seq) || (int) $seq < 1 || (int) $seq > self::MAX_PARTICIPANTS)) {
            throw new ValidationException(['seq_count' => 'draw.error.seq_count']);
        }
        return ['name' => $name, 'description' => $desc !== '' ? mb_substr($desc, 0, 1000) : null, 'mode' => ($in['mode'] ?? '') === 'race' ? 'race' : 'wheel',
            // the form always sends no_repeat (hidden 0 + checkbox 1). Sequence mode (0.11.1): 1 = a name drawn leaves the pool, 0 = it stays and may come up again
            'no_repeat' => ($in['no_repeat'] ?? '1') === '1' ? 1 : 0, 'event_at' => $at,
            'prize_mode' => $prizeMode, 'seq_count' => $prizeMode === 'sequence' && $seq !== '' ? (int) $seq : null];
    }

    private function key(?string $code, string $name): string
    {
        return $code !== null && $code !== '' ? 'c:' . mb_strtolower($code) : 'n:' . mb_strtolower((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** @param array<string, mixed> $detail */
    private function logIt(int $eventId, string $action, array $detail, int $actor): void
    {
        $this->app->db()->run(
            'INSERT INTO draw_log (event_id, action, detail_json, actor_id, occurred_at) VALUES (?, ?, ?, ?, ?)',
            [$eventId, $action, $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE), $actor, $this->now()]
        );
    }

    /** @param array<string, mixed> $e */
    private function le(array $e): ?int
    {
        return $e['legal_entity_id'] !== null ? (int) $e['legal_entity_id'] : null;
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }
}
