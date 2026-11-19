<?php

declare(strict_types=1);

namespace Blue\Modules\Safety;

use DateTimeImmutable;
use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\Ulid;

/**
 * The company's safety policy (R7 · 0.45.0 · DEC-114; answers Q-14 "AI drafts the standards and forms" and Q-26 "AI sets
 * the default severity levels and alert chain").
 *
 *  levels      what levels 1–4 mean, with examples (shown on the report form and the incident)
 *  chain       per level: who is alerted when a report arrives — the safety team always, plus the reporter's line manager,
 *              HR (safety.medical holders), executives (role EXE) as the company chooses, plus the named people kept in the
 *              settings — and, when nobody has triaged it within N minutes, who the escalation goes to
 *  likelihood / impact   the meaning of 1–5 on each scale
 *  bands       risk bands on likelihood × impact (low / medium / high / very high, by upper score) with the expected action
 *
 * Versions: draft → published (the previous one superseded). A draft is made from the AI template (ai_draft = 1, the label
 * "ร่างโดย AI — ต้องตรวจก่อนใช้" stays until published) or from the policy in use; the publisher (safety.approve) must not be
 * the person who created or last edited it, and records who reviewed it and when. Nothing here is a legal requirement: the
 * AI text sets no legal deadline, rate or official form, and the escalation minutes are internal response targets.
 * Until a policy is published the module works as before (level notes and named people from the settings).
 */
final class SafetyPolicy
{
    public const LEVELS = [1, 2, 3, 4];
    public const SCALE = [1, 2, 3, 4, 5];
    public const BANDS = ['low', 'medium', 'high', 'very_high'];
    public const WHO = ['manager', 'hr', 'exec'];

    private function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    private function stamp(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    // ---- the AI template ---------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the AI-drafted starting policy in the given language (th / en) */
    public static function template(string $lang = 'th'): array
    {
        $th = $lang !== 'en';
        $lv = $th ? [
            1 => ['เล็กน้อย', 'ไม่มีผู้บาดเจ็บ หรือปฐมพยาบาลแล้วทำงานต่อได้ทันที · ความเสียหายเล็กน้อยที่ไม่กระทบงาน · จุดเสี่ยงที่แก้ได้ทันที', 'มีดบาดเล็กน้อย ทำแผลแล้วทำงานต่อ · ป้ายเตือนหลุด · พื้นเปียกเล็กน้อยและเช็ดแล้ว'],
            2 => ['ปานกลาง', 'ต้องรับการรักษาจากแพทย์หรือเปลี่ยนงานชั่วคราว · ความเสียหายที่ทำให้งานหยุดชั่วคราว · เกือบเกิดเหตุที่อาจทำให้บาดเจ็บปานกลาง', 'ข้อเท้าพลิกต้องไปพบแพทย์ · รถยกชนชั้นวางสินค้าเสียหาย · ของตกจากที่สูงไม่โดนใคร'],
            3 => ['ร้ายแรง', 'บาดเจ็บจนต้องหยุดงานหรือนอนโรงพยาบาล · ความเสียหายมากหรือสายการผลิตหยุด · เกือบเกิดเหตุที่อาจทำให้บาดเจ็บสาหัสหรือเสียชีวิต · สารเคมีหกในพื้นที่', 'กระดูกหัก · นิ้วเข้าเครื่องจักร · ไฟไหม้ที่ดับได้ในพื้นที่ · คนเกือบตกจากที่สูง'],
            4 => ['วิกฤต', 'เสียชีวิต พิการ สูญเสียอวัยวะ หรือผู้บาดเจ็บหลายคน · ไฟไหม้ ระเบิด หรือสารเคมีรั่วไหลจนต้องอพยพหรือกระทบนอกพื้นที่ · เหตุที่บุคคลภายนอกหรือสื่อเกี่ยวข้อง', 'ผู้เสียชีวิตในพื้นที่ · อพยพทั้งอาคาร · แก๊สรั่วออกนอกโรงงาน'],
        ] : [
            1 => ['Minor', 'No injury, or first aid and back to work at once · minor damage that does not affect work · a hazard fixed on the spot', 'Small cut dressed on site · a warning sign fell off · a small wet patch wiped up'],
            2 => ['Moderate', 'Needs a doctor or a temporary change of duty · damage that stops work for a while · a near miss that could have caused a moderate injury', 'Sprained ankle seen by a doctor · forklift hit a rack · an object fell from height and hit no one'],
            3 => ['Serious', 'Injury causing time off work or a hospital stay · heavy damage or a stopped line · a near miss that could have caused serious injury or death · a chemical spill in the area', 'Broken bone · finger caught in a machine · a fire put out in the area · someone nearly fell from height'],
            4 => ['Critical', 'Death, disability, loss of a body part or several people hurt · fire, explosion or a chemical release needing evacuation or reaching outside · an event involving outsiders or the media', 'A fatality on site · whole building evacuated · gas release beyond the fence'],
        ];
        $levels = [];
        foreach ($lv as $l => [$name, $meaning, $examples]) {
            $levels[$l] = ['name' => $name, 'meaning' => $meaning, 'examples' => $examples];
        }
        return [
            'levels' => $levels,
            // alerted at once (the safety team always) and escalated when nobody has triaged within N minutes — internal targets
            'chain' => [
                1 => ['manager' => false, 'hr' => false, 'exec' => false, 'after_min' => null, 'then' => []],
                2 => ['manager' => true, 'hr' => false, 'exec' => false, 'after_min' => 1440, 'then' => ['manager']],
                3 => ['manager' => true, 'hr' => true, 'exec' => false, 'after_min' => 60, 'then' => ['exec']],
                4 => ['manager' => true, 'hr' => true, 'exec' => true, 'after_min' => 30, 'then' => ['exec', 'hr']],
            ],
            'likelihood' => $th
                ? [1 => 'แทบไม่เกิดขึ้น', 2 => 'ไม่น่าเกิด แต่เป็นไปได้', 3 => 'อาจเกิดขึ้นได้บางครั้ง', 4 => 'น่าจะเกิดขึ้น', 5 => 'เกิดขึ้นบ่อยหรือแทบแน่นอน']
                : [1 => 'Almost never', 2 => 'Unlikely but possible', 3 => 'Could happen at times', 4 => 'Likely', 5 => 'Frequent or almost certain'],
            'impact' => $th
                ? [1 => 'ปฐมพยาบาล / เสียหายเล็กน้อย', 2 => 'รักษาโดยแพทย์ / งานหยุดชั่วคราว', 3 => 'บาดเจ็บจนหยุดงาน / เสียหายมาก', 4 => 'ทุพพลภาพ สูญเสียอวัยวะ / เสียหายรุนแรง', 5 => 'เสียชีวิตหรือหลายคน / หายนะ']
                : [1 => 'First aid / minor damage', 2 => 'Medical treatment / work stops for a while', 3 => 'Lost-time injury / heavy damage', 4 => 'Disability or loss of a body part / severe damage', 5 => 'Death or many people / disaster'],
            'bands' => [
                ['key' => 'low', 'max' => 4, 'action' => $th ? 'ควบคุมตามขั้นตอนปกติ และทบทวนตามรอบ' : 'Control with the usual procedures and review periodically'],
                ['key' => 'medium', 'max' => 9, 'action' => $th ? 'กำหนดมาตรการลดความเสี่ยง ผู้รับผิดชอบ และวันเสร็จ' : 'Set risk-reduction actions with an owner and a due date'],
                ['key' => 'high', 'max' => 16, 'action' => $th ? 'ต้องมีมาตรการลดความเสี่ยงก่อนทำงานนั้นต่อ และให้ผู้บริหารรับทราบ' : 'Reduce the risk before the work continues, and tell management'],
                ['key' => 'very_high', 'max' => 25, 'action' => $th ? 'หยุดงานนั้นทันทีจนกว่าจะควบคุมความเสี่ยงได้' : 'Stop the work at once until the risk is controlled'],
            ],
        ];
    }

    // ---- reading -----------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function row(int $le, string $status): ?array
    {
        return $this->app->db()->one('SELECT * FROM sf_policies WHERE legal_entity_id = ? AND status = ? ORDER BY version DESC LIMIT 1', [$le, $status]);
    }

    /** @return array<string, mixed>|null the published policy's content (with its version), or null */
    public function published(int $le): ?array
    {
        if (!$this->app->db()->tableExists('sf_policies')) {
            return null;
        }
        $r = $this->row($le, 'published');
        if ($r === null) {
            return null;
        }
        $c = json_decode((string) $r['content_json'], true);
        return is_array($c) ? $c + ['_version' => (int) $r['version'], '_reviewer' => $r['reviewer'], '_reviewed_on' => $r['reviewed_on'], '_published_at' => $r['published_at']] : null;
    }

    /** @return array{published: array<string, mixed>|null, draft: array<string, mixed>|null, history: list<array<string, mixed>>} */
    public function overview(int $le): array
    {
        $db = $this->app->db();
        $draft = $this->row($le, 'draft');
        if ($draft !== null) {
            $draft['content'] = json_decode((string) $draft['content_json'], true);
            $draft['created_name'] = $db->scalar('SELECT username FROM users WHERE id = ?', [$draft['created_by']]);
            $draft['updated_name'] = $db->scalar('SELECT username FROM users WHERE id = ?', [$draft['updated_by']]);
        }
        $pub = $this->row($le, 'published');
        if ($pub !== null) {
            $pub['content'] = json_decode((string) $pub['content_json'], true);
            $pub['published_name'] = $db->scalar('SELECT username FROM users WHERE id = ?', [$pub['published_by']]);
        }
        $hist = $db->all('SELECT p.version, p.status, p.ai_draft, p.reviewer, p.reviewed_on, p.published_at, u.username AS published_name FROM sf_policies p LEFT JOIN users u ON u.id = p.published_by
            WHERE p.legal_entity_id = ? AND p.status <> ? ORDER BY p.version DESC LIMIT 20', [$le, 'draft']);
        return ['published' => $pub, 'draft' => $draft, 'history' => $hist];
    }

    /**
     * The risk band of a score under a policy (or the template when none is published).
     * @param array<string, mixed>|null $policy
     * @return array{key: string, action: string}|null
     */
    public static function band(?array $policy, ?int $likelihood, ?int $impact): ?array
    {
        if ($likelihood === null || $impact === null || $policy === null) {
            return null;
        }
        $score = $likelihood * $impact;
        foreach ((array) $policy['bands'] as $b) {
            if ($score <= (int) $b['max']) {
                return ['key' => (string) $b['key'], 'action' => (string) $b['action']];
            }
        }
        return null;
    }

    // ---- editing -----------------------------------------------------------------------------------------------------------

    private function guard(Authorizer $az, int $le, string ...$perms): void
    {
        foreach ($perms as $p) {
            if ($az->scope($p)->coversLegalEntity($le)) {
                return;
            }
        }
        throw HttpException::forbidden();
    }

    /** Start a draft from the AI template ('ai') or from the policy in use ('current'). */
    public function startDraft(Authorizer $az, int $le, string $from): string
    {
        $this->guard($az, $le, 'safety.manage');
        $db = $this->app->db();
        if ($this->row($le, 'draft') !== null) {
            throw new BusinessException('safety.policy.error.draft_exists');
        }
        $pub = $this->published($le);
        if ($from === 'current' && $pub === null) {
            throw new BusinessException('safety.policy.error.none_published');
        }
        $content = $from === 'current' && $pub !== null ? array_diff_key($pub, array_flip(['_version', '_reviewer', '_reviewed_on', '_published_at'])) : self::template($this->app->translator()->locale());
        $pid = Ulid::generate($this->app->clock);
        $now = $this->stamp();
        $db->transactional(function () use ($db, $le, $pid, $now, $az, $content, $from): void {
            $db->one('SELECT id FROM legal_entities WHERE id = ? FOR UPDATE', [$le]);
            $v = (int) $db->scalar('SELECT COALESCE(MAX(version), 0) + 1 FROM sf_policies WHERE legal_entity_id = ?', [$le]);
            $db->run(
                'INSERT INTO sf_policies (public_id, created_at, created_by, updated_at, updated_by, legal_entity_id, version, status, ai_draft, content_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pid, $now, $az->userId, $now, $az->userId, $le, $v, 'draft', $from === 'ai' ? 1 : 0, json_encode($content, JSON_UNESCAPED_UNICODE)]
            );
        });
        $this->app->audit()->record('safety.policy_drafted', 'success', 'sf_policy', $pid, $le, null, ['from' => $from]);
        return $pid;
    }

    /** @param array<string, mixed> $in the policy form */
    public function saveDraft(Authorizer $az, int $le, array $in): void
    {
        $this->guard($az, $le, 'safety.manage');
        $d = $this->row($le, 'draft') ?? throw new BusinessException('safety.policy.error.no_draft');
        $content = $this->validate($in);
        $this->app->db()->run('UPDATE sf_policies SET content_json = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [json_encode($content, JSON_UNESCAPED_UNICODE), $this->stamp(), $az->userId, $d['id']]);
        $this->app->audit()->record('safety.policy_edited', 'success', 'sf_policy', (string) $d['public_id'], $le);
    }

    public function discard(Authorizer $az, int $le): void
    {
        $this->guard($az, $le, 'safety.manage');
        $d = $this->row($le, 'draft') ?? throw new BusinessException('safety.policy.error.no_draft');
        $this->app->db()->run('DELETE FROM sf_policies WHERE id = ?', [$d['id']]);
        $this->app->audit()->record('safety.policy_discarded', 'success', 'sf_policy', (string) $d['public_id'], $le);
    }

    /** @param array<string, mixed> $in reviewed (checkbox), reviewer, reviewed_on */
    public function publish(Authorizer $az, int $le, array $in): void
    {
        $this->guard($az, $le, 'safety.approve');
        $db = $this->app->db();
        $d = $this->row($le, 'draft') ?? throw new BusinessException('safety.policy.error.no_draft');
        if ((int) $d['created_by'] === $az->userId || (int) $d['updated_by'] === $az->userId) {
            throw new BusinessException('safety.policy.error.same_person');
        }
        $e = [];
        if (empty($in['reviewed'])) {
            $e['reviewed'] = 'safety.policy.error.reviewed';
        }
        $who = trim((string) ($in['reviewer'] ?? ''));
        if (mb_strlen($who) < 3 || mb_strlen($who) > 160) {
            $e['reviewer'] = 'safety.policy.error.reviewer';
        }
        $on = (string) ($in['reviewed_on'] ?? '');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        $today = SafetyService::of($this->app)->today();
        if ($dt === false || $dt->format('Y-m-d') !== $on || $on > $today) {
            $e['reviewed_on'] = 'safety.policy.error.date';
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        $now = $this->stamp();
        $db->transactional(function () use ($db, $le, $d, $az, $who, $on, $now): void {
            $db->run("UPDATE sf_policies SET status = 'superseded', updated_at = ? WHERE legal_entity_id = ? AND status = 'published'", [$now, $le]);
            $db->run(
                "UPDATE sf_policies SET status = 'published', ai_draft = 0, reviewer = ?, reviewed_on = ?, published_by = ?, published_at = ?, updated_at = ?, row_version = row_version + 1 WHERE id = ?",
                [$who, $on, $az->userId, $now, $now, $d['id']]
            );
        });
        $this->app->audit()->record('safety.policy_published', 'success', 'sf_policy', (string) $d['public_id'], $le, null, ['version' => (int) $d['version'], 'ai_draft' => (int) $d['ai_draft']]);
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function validate(array $in): array
    {
        $e = [];
        $txt = static function (string $k, int $min, int $max) use ($in, &$e): string {
            $v = trim(str_replace("\r\n", "\n", (string) ($in[$k] ?? '')));
            if (mb_strlen($v) < $min || mb_strlen($v) > $max) {
                $e[$k] = $min > 0 ? 'safety.policy.error.text' : 'safety.error.too_long';
            }
            return $v;
        };
        $out = ['levels' => [], 'chain' => [], 'likelihood' => [], 'impact' => [], 'bands' => []];
        foreach (self::LEVELS as $l) {
            $out['levels'][$l] = ['name' => $txt('name_' . $l, 2, 40), 'meaning' => $txt('meaning_' . $l, 5, 400), 'examples' => $txt('examples_' . $l, 0, 400)];
            $after = trim((string) ($in['after_' . $l] ?? ''));
            $min = null;
            if ($after !== '') {
                if (preg_match('/^\d{1,5}$/', $after) !== 1 || (int) $after < 5 || (int) $after > 10080) {
                    $e['after_' . $l] = 'safety.policy.error.after';
                } else {
                    $min = (int) $after;
                }
            }
            $then = array_values(array_intersect(self::WHO, (array) ($in['then_' . $l] ?? [])));
            if ($min !== null && $then === []) {
                $e['then_' . $l] = 'safety.policy.error.then';
            }
            $out['chain'][$l] = ['manager' => !empty($in['manager_' . $l]), 'hr' => !empty($in['hr_' . $l]), 'exec' => !empty($in['exec_' . $l]), 'after_min' => $min, 'then' => $min === null ? [] : $then];
        }
        foreach (self::SCALE as $s) {
            $out['likelihood'][$s] = $txt('likelihood_' . $s, 2, 80);
            $out['impact'][$s] = $txt('impact_' . $s, 2, 80);
        }
        $prev = 0;
        foreach (self::BANDS as $i => $b) {
            $max = $b === 'very_high' ? 25 : (int) ($in['band_max_' . $b] ?? 0);
            if ($b !== 'very_high' && ($max <= $prev || $max >= 25)) {
                $e['band_max_' . $b] = 'safety.policy.error.band';
            }
            $out['bands'][$i] = ['key' => $b, 'max' => $max, 'action' => $txt('band_action_' . $b, 5, 300)];
            $prev = max($prev, $max);
        }
        if ($e !== []) {
            throw new ValidationException($e);
        }
        return $out;
    }
}
