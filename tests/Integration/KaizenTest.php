<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Modules\Kaizen\KaizenService;
use Blue\Modules\Kaizen\SurveyService;
use Blue\Tests\Support\TestApp;

/**
 * Phase 9D-1 (DEC-97): M41 suggestions / Kaizen and surveys. Clock: Mon 2026-11-09 09:00 (Asia/Bangkok). Demo: DEMO1
 * criteria ผลกระทบ ×40 · ความคุ้มค่า ×30 · ทำได้จริง ×30, one named idea from employee.demo1 (KZyymm-0001), one draft pulse
 * survey. ga.demo1 (WPA) handles ideas but is no employee; hr.demo1 / hr.head1 (HRA) run surveys and decide rewards.
 */
final class KaizenTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(kaizen|form)\.[a-z_]+(\.[a-z_]+)*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function kz(): KaizenService
    {
        return KaizenService::of($this->t->app);
    }

    private function sv(): SurveyService
    {
        return SurveyService::of($this->t->app);
    }

    private function az(string $user): Authorizer
    {
        return new Authorizer($this->t->app->db(), $this->t->clock, (int) $this->t->user($user)['id']);
    }

    private function le(): int
    {
        return (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
    }

    /** @return array<string, mixed> */
    private function row(string $pid): array
    {
        return $this->t->app->db()->one('SELECT * FROM kz_ideas WHERE public_id = ?', [$pid]) ?? [];
    }

    private function idea(string $user, string $title, bool $anon = false, ?UploadedFile $photo = null): array
    {
        return $this->kz()->submit($this->az($user), ['title' => $title, 'problem' => 'ปัญหาที่เกิดซ้ำทุกสัปดาห์ในแผนก', 'proposal' => 'เสนอให้ปรับขั้นตอนตามนี้แล้ววัดผล', 'benefit' => 'ลดเวลา', 'anonymous' => $anon ? '1' : ''], $photo);
    }

    /** @return array<string, string> criterion public id => score (ผลกระทบ ×40, ความคุ้มค่า ×30, ทำได้จริง ×30) */
    private function scores(int $impact, int $value, int $doable): array
    {
        $out = [];
        foreach ($this->kz()->criteria($this->le(), true) as $cr) {
            $name = (string) $cr['name'];
            $out[(string) $cr['public_id']] = (string) (str_starts_with($name, 'ผลกระทบ') ? $impact : (str_starts_with($name, 'ความคุ้มค่า') ? $value : $doable));
        }
        return $out;
    }

    /**
     * @param callable(): mixed $fn
     * @return array<string, string>
     */
    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        self::fail('expected a refusal');
    }

    /** @param callable(): mixed $fn */
    private function refused(callable $fn): string
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return 'http' . $e->httpStatus;
        } catch (BusinessException $e) {
            return $e->messageKey;
        }
        self::fail('expected a refusal');
    }

    public function testIdeaLifecycleAnonymityVotesAndReward(): void
    {
        $k = $this->kz();
        $db = $this->t->app->db();
        $ga = $this->az('ga.demo1');
        $owner = $this->az('manager.demo1');
        // G9D1-01: validation · an account with no employee cannot send · anonymous with a file is refused
        self::assertSame(['title' => 'kaizen.error.title', 'problem' => 'kaizen.error.long_text', 'proposal' => 'kaizen.error.long_text'], $this->errors(fn () => $k->submit($this->az('employee.demo1'), ['title' => 'x'], null)));
        self::assertSame('kaizen.error.no_employee', $this->refused(fn () => $this->idea('ga.demo1', 'ผู้ดูแลส่งข้อเสนอ')));
        $png = UploadedFile::fromBytes('p.png', (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/alpha.png'), $this->t->tmp);
        self::assertSame('kaizen.error.anonymous_photo', $this->errors(fn () => $this->idea('manager.demo1', 'ไฟบันไดหนีไฟดับบ่อย', true, $png))['photo']);
        // named idea with a photo
        $named = $this->idea('employee.demo1', 'ลดขั้นตอนเบิกกระดาษ', false, UploadedFile::fromBytes('p.png', (string) file_get_contents(dirname(__DIR__) . '/fixtures/pdf/alpha.png'), $this->t->tmp));
        self::assertNull($named['code']);
        self::assertMatchesRegularExpression('/^KZ2611-000\d$/', $named['ref']);
        $n = $this->row($named['pid']);
        self::assertSame([(int) $this->t->user('employee.demo1')['id'], 0], [(int) $n['submitter_id'], (int) $n['anonymous']]);
        self::assertNotNull($n['photo_pid']);
        // G9D1-02: anonymous — no sender, day-only time, only a hash of the code, no audit naming anyone
        $anon = $this->idea('manager.demo1', 'ไฟบันไดหนีไฟดับบ่อย', true);
        $a = $this->row($anon['pid']);
        self::assertNotNull($anon['code']);
        self::assertSame([null, null, 1], [$a['submitter_id'], $a['created_by'], (int) $a['anonymous']]);
        self::assertSame('00:00:00', substr((string) $a['created_at'], 11, 8));
        self::assertSame(hash('sha256', (string) $anon['code']), $a['track_hash']);
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM audit_events WHERE target_id = ?", [$anon['pid']]));
        self::assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM kz_events WHERE idea_id = ? AND actor_id IS NOT NULL', [$a['id']]));
        self::assertSame($anon['ref'], $k->track(strtolower((string) $anon['code']))['ref']);
        self::assertNull($k->track('NOPE-000000'));
        self::assertSame([], array_filter($k->mine($this->az('manager.demo1')), static fn (array $r): bool => $r['public_id'] === $anon['pid']), 'not in "my ideas"');
        // G9D1-03: not on the board before screening; colleagues cannot open it; managers get a daily digest only
        self::assertSame('http404', $this->refused(fn () => $k->idea($owner, $named['pid'])));
        self::assertSame([], $k->board($owner, $this->le()));
        self::assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'kaizen.idea'"));
        $k->tick();
        $k->tick();
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'kaizen.idea' AND user_id = ?", [$this->t->user('ga.demo1')['id']]), 'one digest a day');
        // G9D1-04: screen (owner manager.demo1) → board; votes: not on my own idea, once each, toggles
        self::assertSame('http404', $this->refused(fn () => $k->act($owner, $named['pid'], 'screen', ['owner' => 'manager.demo1'])), 'a colleague cannot even see an unscreened idea');
        self::assertSame('kaizen.error.person', $this->errors(fn () => $k->act($ga, $named['pid'], 'screen', ['owner' => 'nobody']))['owner']);
        $k->act($ga, $named['pid'], 'screen', ['owner' => 'manager.demo1', 'note' => 'รับพิจารณา']);
        self::assertSame(['screening', (int) $this->t->user('manager.demo1')['id']], [$this->row($named['pid'])['status'], (int) $this->row($named['pid'])['owner_id']]);
        self::assertCount(1, $k->board($this->az('hr.head1'), $this->le()));
        self::assertSame('kaizen.error.cannot_vote', $this->refused(fn () => $k->vote($this->az('employee.demo1'), $named['pid'])));
        self::assertTrue($k->vote($this->az('hr.head1'), $named['pid']));
        self::assertTrue($k->vote($owner, $named['pid']));
        self::assertFalse($k->vote($owner, $named['pid']));
        self::assertSame(1, $k->idea($this->az('employee.demo1'), $named['pid'])['votes']);
        self::assertSame('kaizen.error.cannot_vote', $this->refused(fn () => $k->vote($this->az('ga.demo1'), $named['pid'])), 'no employee → no vote');
        // G9D1-05: score = Σ(s×w)/Σw — 5×40 + 3×30 + 4×30 = 410 / 100 = 4.10; only after scoring can it be accepted
        self::assertSame('kaizen.error.score_first', $this->refused(fn () => $k->act($ga, $named['pid'], 'accept', [])));
        self::assertSame('kaizen.error.score', array_values($this->errors(fn () => $k->act($owner, $named['pid'], 'score', ['scores' => []])))[0]);
        $k->act($owner, $named['pid'], 'score', ['scores' => $this->scores(5, 3, 4)]);
        self::assertSame(['evaluation', '4.10'], [$this->row($named['pid'])['status'], (string) $this->row($named['pid'])['score']]);
        self::assertArrayNotHasKey('score', $k->idea($this->az('employee.demo1'), $named['pid']), 'the sender does not see the score');
        self::assertSame('kaizen.error.manager_only', $this->refused(fn () => $k->act($owner, $named['pid'], 'accept', [])));
        $k->act($ga, $named['pid'], 'accept', ['note' => 'ทำได้ทันที']);
        // G9D1-06: plan → result → a manager who is not the owner verifies
        self::assertSame(['kaizen.error.plan', 'kaizen.error.due'], array_values($this->errors(fn () => $k->act($owner, $named['pid'], 'start', ['plan' => '', 'due_date' => '2026-11-01']))));
        $k->act($owner, $named['pid'], 'start', ['plan' => 'ทำแบบฟอร์มใหม่และอบรม', 'due_date' => '2026-11-30']);
        self::assertSame('kaizen.error.result_first', $this->refused(fn () => $k->act($ga, $named['pid'], 'verify', [])));
        $k->act($owner, $named['pid'], 'result', ['result' => 'เวลาเบิกลดจาก 2 วันเหลือ 0.5 วัน']);
        self::assertSame('kaizen.error.manager_only', $this->refused(fn () => $k->act($owner, $named['pid'], 'verify', [])));
        $k->act($ga, $named['pid'], 'verify', ['note' => 'ตรวจข้อมูลก่อน/หลังแล้ว']);
        self::assertSame('verified', $this->row($named['pid'])['status']);
        // G9D1-07: reward — proposed by a manager, decided by someone else with kaizen.reward; not for anonymous ideas
        $k->act($ga, $named['pid'], 'reward', ['reward' => 'บัตรของขวัญตามระเบียบบริษัท']);
        self::assertSame('kaizen.error.reward_self', $this->refused(fn () => $k->act($ga, $named['pid'], 'reward_ok', [])));
        self::assertSame('kaizen.error.reward_self', $this->refused(fn () => $k->act($this->az('employee.demo1'), $named['pid'], 'reward_ok', [])));
        $k->act($this->az('hr.head1'), $named['pid'], 'reward_ok', []);
        self::assertSame('approved', $this->row($named['pid'])['reward_status']);
        self::assertSame(4, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'kaizen.idea'", [$this->t->user('employee.demo1')['id']]));
        // the anonymous idea: rejection needs a reason; the tracking view shows it; no reward possible
        self::assertSame('kaizen.error.reason', $this->errors(fn () => $k->act($ga, $anon['pid'], 'reject', ['note' => '']))['note']);
        $k->act($ga, $anon['pid'], 'screen', ['owner' => 'hr.head1']);
        $k->act($this->az('hr.head1'), $anon['pid'], 'score', ['scores' => $this->scores(5, 5, 5)]);
        $k->act($ga, $anon['pid'], 'accept', []);
        $k->act($ga, $anon['pid'], 'start', ['plan' => 'เปลี่ยนหลอดไฟและตรวจทุกเดือน', 'due_date' => '2026-11-09']);
        $this->t->clock->advance('+1 day');
        $k->tick();
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE dedupe_key = ?", ['kz.due:' . $anon['pid']]));
        $k->act($ga, $anon['pid'], 'result', ['result' => 'เปลี่ยนหลอดครบทุกชั้นแล้ว']);
        $k->act($this->az('hr.demo1'), $anon['pid'], 'verify', []);
        self::assertSame('kaizen.error.anonymous_reward', $this->refused(fn () => $k->act($ga, $anon['pid'], 'reward', ['reward' => 'ของรางวัล'])));
        self::assertSame('verified', $k->track((string) $anon['code'])['status']);
        // internal notes stay inside
        $k->act($ga, $named['pid'], 'note', ['note' => 'บันทึกภายในสำหรับทีม', 'internal' => '1']);
        $events = array_column($k->idea($this->az('employee.demo1'), $named['pid'])['events'], 'event');
        self::assertNotContains('note', $events);
        self::assertNotContains('scored', $events);
        // report: 3 ideas (demo + 2), accept rate 2 / 2 decided = 100%
        $rep = $k->report($ga, $this->le());
        self::assertSame(3, $rep['total']);
        self::assertSame(100.0, $rep['accept_rate']);
        self::assertSame(2, array_sum(array_column($rep['months'], 'verified')));
    }

    public function testSurveysAnonymityThresholdAndScreens(): void
    {
        $s = $this->sv();
        $db = $this->t->app->db();
        $hr = $this->az('hr.demo1');
        // G9D1-08: validation · questions only on drafts · cannot open without questions
        $e = $this->errors(fn () => $s->save($hr, $this->le(), ['title' => 'x', 'opens_on' => '2026-11-12', 'closes_on' => '2026-11-10', 'min_results' => '0']));
        self::assertSame(['kaizen.error.title', 'kaizen.error.window', 'kaizen.error.min_results'], [$e['title'], $e['opens_on'], $e['min_results']]);
        $pid = $s->save($hr, $this->le(), ['title' => 'สำรวจความผูกพัน Q4', 'opens_on' => '2026-11-09', 'closes_on' => '2026-11-13', 'min_results' => '2', 'anonymous' => '1']);
        self::assertSame('kaizen.error.no_questions', $this->refused(fn () => $s->transition($hr, $pid, 'open')));
        self::assertSame('kaizen.error.options', $this->errors(fn () => $s->question($hr, $pid, ['kind' => 'choice', 'text' => 'ทำงานที่ไหน', 'options' => 'ออฟฟิศ']))['options']);
        $s->question($hr, $pid, ['kind' => 'scale', 'text' => 'พอใจกับงานแค่ไหน', 'required' => '1']);
        $s->question($hr, $pid, ['kind' => 'enps', 'text' => 'แนะนำบริษัทให้เพื่อนไหม', 'required' => '1']);
        $s->question($hr, $pid, ['kind' => 'choice', 'text' => 'ทำงานที่ไหน', 'options' => "ออฟฟิศ\nบ้าน\nผสม", 'required' => '1']);
        $s->question($hr, $pid, ['kind' => 'multi', 'text' => 'อยากได้สวัสดิการอะไร', 'options' => "อาหาร\nรถรับส่ง\nฟิตเนส"]);
        $s->question($hr, $pid, ['kind' => 'text', 'text' => 'ข้อเสนอแนะอื่น']);
        $q = array_column($s->questions((int) $db->scalar('SELECT id FROM kz_surveys WHERE public_id = ?', [$pid])), 'id', 'kind');
        self::assertSame('http404', $this->refused(fn () => $s->forAnswer($this->az('employee.demo1'), $pid)), 'draft is not visible');
        $s->transition($hr, $pid, 'open');
        self::assertSame('kaizen.error.not_draft', $this->refused(fn () => $s->question($hr, $pid, ['kind' => 'text', 'text' => 'เพิ่มทีหลัง'])));
        self::assertGreaterThanOrEqual(3, (int) $db->scalar("SELECT COUNT(*) FROM notifications WHERE event_type = 'kaizen.survey'"));
        // G9D1-09: answer once; required questions; anonymous storage
        $emp = $this->az('employee.demo1');
        self::assertCount(1, array_filter($s->forMe($emp), static fn (array $r): bool => $r['public_id'] === $pid));
        $e = $this->errors(fn () => $s->answer($emp, $pid, ['q' . $q['scale'] => '7']));
        self::assertSame(['kaizen.error.choice', 'kaizen.error.answer_required', 'kaizen.error.answer_required'], [$e['q' . $q['scale']], $e['q' . $q['enps']], $e['q' . $q['choice']]]);
        $s->answer($emp, $pid, ['q' . $q['scale'] => '4', 'q' . $q['enps'] => '9', 'q' . $q['choice'] => 'บ้าน', 'q' . $q['multi'] => ['อาหาร', 'ฟิตเนส'], 'q' . $q['text'] => 'อยากให้มีมุมพักผ่อน']);
        self::assertSame('kaizen.error.answered', $this->refused(fn () => $s->answer($emp, $pid, ['q' . $q['scale'] => '4', 'q' . $q['enps'] => '9', 'q' . $q['choice'] => 'บ้าน'])));
        $sid = (int) $db->scalar('SELECT id FROM kz_surveys WHERE public_id = ?', [$pid]);
        self::assertSame([1, 0], [(int) $db->scalar('SELECT COUNT(*) FROM kz_responses WHERE survey_id = ?', [$sid]), (int) $db->scalar('SELECT COUNT(*) FROM kz_responses WHERE survey_id = ? AND user_id IS NOT NULL', [$sid])]);
        // G9D1-10: below the minimum only the count is shown; export refused
        $r = $s->results($hr, $pid);
        self::assertSame([1, false, []], [$r['n'], $r['shown'], $r['results']]);
        self::assertSame('kaizen.error.below_minimum', $this->refused(fn () => $s->exportCsv($hr, $pid)));
        $s->answer($this->az('manager.demo1'), $pid, ['q' . $q['scale'] => '2', 'q' . $q['enps'] => '5', 'q' . $q['choice'] => 'ออฟฟิศ']);
        $s->answer($this->az('hr.head1'), $pid, ['q' . $q['scale'] => '3', 'q' . $q['enps'] => '10', 'q' . $q['choice'] => 'บ้าน', 'q' . $q['multi'] => ['อาหาร']]);
        // G9D1-11: avg (4+2+3)/3 = 3.00 · eNPS: promoters 9,10 = 2, detractors 5 = 1 → (2−1)/3 = 33.3 · บ้าน 2 · อาหาร 2
        $r = $s->results($hr, $pid);
        self::assertTrue($r['shown']);
        $byKind = array_column(array_map(static fn (array $x): array => $x + ['kind' => $x['question']['kind']], $r['results']), null, 'kind');
        self::assertSame(3.0, $byKind['scale']['avg']);
        self::assertSame(33.3, $byKind['enps']['enps']);
        self::assertSame(['ออฟฟิศ' => 1, 'บ้าน' => 2, 'ผสม' => 0], $byKind['choice']['dist']);
        self::assertSame(['อาหาร' => 2, 'รถรับส่ง' => 0, 'ฟิตเนส' => 1], $byKind['multi']['dist']);
        self::assertSame(['อยากให้มีมุมพักผ่อน'], $byKind['text']['texts']);
        $csv = $s->exportCsv($hr, $pid);
        self::assertStringNotContainsString('พนักงาน', explode("\n", $csv)[0], 'no person column in an anonymous survey');
        self::assertCount(4, array_filter(explode("\n", trim($csv))));
        self::assertSame('http403', $this->refused(fn () => $s->results($this->az('employee.demo1'), $pid)));
        // G9D1-12: the window ends → closed by the tick; summary → analyzed and readable by the audience
        $this->t->clock->advance('+5 days');
        $s->tick();
        self::assertSame('closed', (string) $db->scalar('SELECT status FROM kz_surveys WHERE id = ?', [$sid]));
        self::assertSame('kaizen.error.summary', $this->errors(fn () => $s->transition($hr, $pid, 'analyzed', 'สั้น'))['summary']);
        $s->transition($hr, $pid, 'analyzed', 'คะแนนความพึงพอใจเฉลี่ย 3.0 — จะจัดมุมพักผ่อนและทบทวนอาหาร');
        self::assertSame('analyzed', $s->forAnswer($emp, $pid)['status']);
        // a named survey keeps who answered
        $p2 = $s->save($hr, $this->le(), ['title' => 'ลงชื่อร่วมกิจกรรมปีใหม่', 'opens_on' => '2026-11-14', 'closes_on' => '2026-11-20', 'min_results' => '1']);
        $s->question($hr, $p2, ['kind' => 'choice', 'text' => 'เข้าร่วมไหม', 'options' => "ร่วม\nไม่ร่วม", 'required' => '1']);
        $s->transition($hr, $p2, 'open');
        $qid = (int) $db->scalar('SELECT q.id FROM kz_questions q JOIN kz_surveys s ON s.id = q.survey_id WHERE s.public_id = ?', [$p2]);
        $s->answer($emp, $p2, ['q' . $qid => 'ร่วม']);
        self::assertSame((int) $this->t->user('employee.demo1')['id'], (int) $db->scalar('SELECT r.user_id FROM kz_responses r JOIN kz_surveys s ON s.id = r.survey_id WHERE s.public_id = ?', [$p2]));
        // screens
        $g = $this->t->browser();
        $g->login('hr.demo1');
        $idea = (string) $db->scalar('SELECT public_id FROM kz_ideas ORDER BY id LIMIT 1');
        foreach (['/kaizen', '/kaizen/track', '/kaizen/i/' . $idea, '/kaizen/admin', '/kaizen/report', '/kaizen/settings', '/kaizen/surveys', '/kaizen/surveys/s/' . $pid, '/kaizen/surveys/admin', '/kaizen/surveys/m/' . $pid, '/kaizen/surveys/m/' . $pid . '/results', '/kaizen/surveys/m/' . $p2] as $path) {
            $res = $g->get($path);
            self::assertSame(200, $res->status, $path);
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body)), $path);
        }
        self::assertSame(200, $g->get('/kaizen/surveys/m/' . $pid . '/export')->status);
        self::assertSame(303, $g->post('/kaizen/settings', ['name' => 'ความปลอดภัย', 'weight' => '20'])->status);
        self::assertSame(422, $g->post('/kaizen/settings', ['name' => 'ความปลอดภัย', 'weight' => '0'])->status);
        self::assertSame(303, $g->post('/kaizen/surveys/admin', ['title' => 'สำรวจอาหารกลางวัน', 'opens_on' => '2026-11-14', 'closes_on' => '2026-11-21', 'min_results' => '5', 'anonymous' => '1'])->status);
        $e1 = $this->t->browser();
        $e1->login('employee.demo1');
        $sent = $e1->post('/kaizen', ['title' => 'เพิ่มที่จอดจักรยาน', 'problem' => 'จักรยานจอดเกะกะทางเดินหน้าตึก', 'proposal' => 'ทำราวจอดจักรยานข้างลานจอดรถ', 'anonymous' => '1']);
        self::assertSame(200, $sent->status);
        self::assertMatchesRegularExpression('/class="kz-code"><strong>[0-9A-Z]{4}-[0-9A-F]{6}</', $sent->body);
        self::assertSame(200, $e1->post('/kaizen/track', ['code' => 'WRONG'])->status);
        self::assertSame(403, $e1->get('/kaizen/admin')->status);
        self::assertSame(403, $e1->get('/kaizen/surveys/admin')->status);
    }
}
