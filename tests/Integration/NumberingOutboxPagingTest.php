<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Blue\Core\Error\BusinessException;
use Blue\Core\Http\Request;
use Blue\Core\Numbering\NumberingService;
use Blue\Core\Ui\Paging;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestDb;

/** 6.30.10 numbering, outbox / idempotent consumers (6.30.7), TC-PAGE (6.10.1). */
final class NumberingOutboxPagingTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function le(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    public function testNumbersAreSequentialPerScopeNeverReusedAndVoidable(): void
    {
        $n = $this->t->app->numbering();
        $le1 = $this->le('DEMO1');
        $pattern = 'DMO-{YYYY}{MM}-{SEQ:4}';
        self::assertSame('DMO-202611-0001', $n->next($le1, null, 'demo.doc', '202611', $pattern));
        self::assertSame('DMO-202611-0002', $n->next($le1, null, 'demo.doc', '202611', $pattern));
        self::assertSame('DMO-202612-0001', $n->next($le1, null, 'demo.doc', '202612', $pattern), 'new period restarts');
        self::assertSame('DMO-202611-0001', $n->next($this->le('DEMO2'), null, 'demo.doc', '202611', $pattern), 'per company');
        $n->void($le1, 'DMO-202611-0002', 'cancelled document');
        self::assertSame('DMO-202611-0003', $n->next($le1, null, 'demo.doc', '202611', $pattern), 'a voided number is never given again');
        self::assertSame('BR1-26-00007', NumberingService::format('{SITE}-{YY}-{SEQ:5}', 7, '2026', 'BR1'));
        $this->expectException(BusinessException::class);
        $n->next($le1, null, 'demo.doc', '202611', 'NO-SEQUENCE-TOKEN');
    }

    public function testTheSequenceRowIsLockedWhileANumberIsTaken(): void
    {
        $n = $this->t->app->numbering();
        $le1 = $this->le('DEMO1');
        $n->next($le1, null, 'demo.lock', '2026', '{SEQ:3}');
        // connection A takes the row lock and keeps its transaction open …
        $db = $this->t->app->db();
        $db->pdo()->beginTransaction();
        $db->run("SELECT next_value FROM number_sequences WHERE doc_type = 'demo.lock' FOR UPDATE");
        // … so connection B (another request) must wait instead of reading the same number
        $c = TestDb::creds();
        $other = new PDO("mysql:host={$c['DB_HOST']};port={$c['DB_PORT']};dbname={$c['DB_NAME']};charset=utf8mb4", $c['DB_USER'], $c['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET innodb_lock_wait_timeout = 1');
        $other->beginTransaction();
        try {
            $other->query("SELECT next_value FROM number_sequences WHERE doc_type = 'demo.lock' FOR UPDATE");
            self::fail('second request read the sequence while it was locked');
        } catch (\PDOException $e) {
            self::assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->rollBack();
            $db->pdo()->rollBack();
        }
    }

    public function testOutboxDeliversOncePerConsumerAndBacksOffOnFailure(): void
    {
        $ob = $this->t->app->outbox();
        $seen = [];
        $failing = 0;
        $ob->subscribe('test.audit', 'demo.*', function (array $e) use (&$seen): void {
            $seen[] = $e['event_id'];
        });
        $ob->subscribe('test.flaky', 'demo.created', function () use (&$failing): void {
            if (++$failing < 3) {
                throw new \RuntimeException('temporary');
            }
        });
        $id = $ob->record('demo.created', ['ref' => 'X1'], 'demo:X1');
        self::assertSame($id, $ob->record('demo.created', ['ref' => 'X1'], 'demo:X1'), 'idempotency key → same event');
        self::assertSame(['delivered' => 0, 'failed' => 1, 'dead' => 0], $ob->dispatch());
        self::assertSame(['delivered' => 0, 'failed' => 0, 'dead' => 0], $ob->dispatch(), 'waits for its back-off');
        $this->t->clock->advance('+2 minutes');
        $ob->dispatch();
        $this->t->clock->advance('+3 minutes');
        self::assertSame(['delivered' => 1, 'failed' => 0, 'dead' => 0], $ob->dispatch());
        self::assertSame([$id], $seen, 'the healthy consumer ran exactly once despite three deliveries');
        self::assertSame(2, (int) $this->t->app->db()->scalar('SELECT COUNT(*) FROM processed_events WHERE event_id = ?', [$id]));

        $ob->subscribe('test.broken', 'demo.broken', function (): void {
            throw new \RuntimeException('always');
        });
        $dead = $ob->record('demo.broken', [], 'demo:broken');
        for ($i = 0; $i < 10; $i++) {
            $ob->dispatch();
            $this->t->clock->advance('+61 minutes');
        }
        self::assertSame('dead', $this->t->app->db()->scalar('SELECT status FROM outbox_events WHERE event_id = ?', [$dead]));
        self::assertTrue($ob->requeue($dead));
    }

    public function testPageSizeRulesAndRememberedChoice(): void
    {
        $req = static fn (array $q): Request => new Request('GET', '/x', $q, [], [], [], '127.0.0.1');
        foreach (Paging::SIZES as $s) {
            self::assertSame($s, Paging::from($req(['per' => (string) $s]))->per);
        }
        foreach (['7', '0', '-20', '1000', 'abc', '20.5', ''] as $bad) {
            self::assertSame(20, Paging::from($req(['per' => $bad]))->per, "per={$bad}");
        }
        self::assertSame(1, Paging::from($req(['page' => '-3']))->page);
        $prefs = $this->t->app->preferences();
        $uid = (int) $this->t->user(TestApp::ADMIN)['id'];
        Paging::from($req(['per' => '50']), $prefs, $uid, 'users');
        self::assertSame(50, Paging::from($req([]), $prefs, $uid, 'users')->per, 'remembered per list');
        self::assertSame(20, Paging::from($req([]), $prefs, $uid, 'audit')->per, 'other lists keep the default');
        $p = new Paging(9, 20);
        self::assertTrue($p->clamp(41));
        self::assertSame(3, $p->page);
        $p0 = new Paging(4, 20);
        $p0->clamp(0);
        self::assertSame(1, $p0->page);
        self::assertSame([1, null, 4, 5, 6, null, 20], Paging::window(5, 20));
        self::assertSame([1, 2, 3], Paging::window(2, 3));
    }

    public function testUserListUsesTheSharedPagination(): void
    {
        $b = $this->t->browser();
        $b->login(TestApp::ADMIN);
        $page = $b->get('/admin/users', ['per' => '3', 'page' => '1']);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('class="pagination"', $page->body); // per=3 is not allowed → default 20 → one page
        $ten = $b->get('/admin/users', ['per' => '10']);
        self::assertMatchesRegularExpression('/<option value="10" selected>/', $ten->body);
        $past = $b->get('/admin/users', ['per' => '10', 'page' => '99']);
        self::assertSame(200, $past->status);
        $last = (string) $this->t->app->db()->scalar('SELECT username FROM users ORDER BY username DESC LIMIT 1'); // the user count grows with demo data
        self::assertStringContainsString($last, $past->body, 'past the end → last page that has rows, not an empty table');
    }
}
