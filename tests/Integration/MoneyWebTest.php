<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Files\Csv;
use Blue\Tests\Support\TestApp;

/** Phase 3C screens through the HTTP kernel: rendering + translations, rights, re-authentication for Restricted files, forms. */
final class MoneyWebTest extends TestCase
{
    private TestApp $t;

    private const UNTRANSLATED = '/(?<![.\w\/{:-])(finance|payment|backup|integration|nav|common|form|status)\.[a-z_]+\.?[a-z_.]*\b(?![^<]*>)/';

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function assertPages(string $user, array $paths): void
    {
        $b = $this->t->browser();
        $b->login($user);
        foreach ($paths as $p) {
            [$path, $qs] = array_pad(explode('?', $p, 2), 2, '');
            parse_str($qs, $q);
            $res = $b->get($path, $q);
            self::assertSame(200, $res->status, $user . ' ' . $p . ' ' . mb_substr(strip_tags($res->body), 0, 300));
            $text = (string) preg_replace('/<(script|style)\b.*?<\/\1>/s', '', $res->body);
            $text = str_replace(['finance.maker', 'finance.checker', 'payment.claim.paid', 'payment.ap.paid', 'payment.payroll.paid'], '', strip_tags($text)); // data, not keys
            self::assertDoesNotMatchRegularExpression(self::UNTRANSLATED, $text, "untranslated key on {$p} for {$user}");
        }
    }

    public function testPagesRenderForEachRole(): void
    {
        $app = $this->t->app;
        $le = (int) $app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $this->assertPages('finance.maker', ['/finance', '/finance/journals', '/finance/journals/new', '/finance/requests', '/finance/accounts', '/finance/rules', '/finance/periods',
            '/finance/trial-balance', '/payments', '/payments/new', '/finance?le=' . $le]);
        $this->assertPages('finance.checker', ['/finance', '/finance/periods', '/payments']);
        $this->assertPages('auditor.demo', ['/finance', '/payments', '/admin/backups', '/admin/integrations']);
        $this->assertPages(TestApp::ADMIN, ['/admin/backups', '/admin/integrations', '/admin/integrations/new',
            '/admin/integrations/' . $app->integrations()->byCode('blue.scheduler')['public_id']]);
        $home = $this->t->browser();
        $home->login('finance.maker');
        $body = $home->get('/')->body;
        self::assertStringContainsString('href="/finance"', $body);
        self::assertStringContainsString('href="/payments"', $body);
        self::assertStringNotContainsString('href="/admin/backups"', $body);
    }

    public function testRightsOnScreens(): void
    {
        $emp = $this->t->browser();
        $emp->login('employee.demo1');
        foreach (['/finance', '/payments', '/admin/backups', '/admin/integrations', '/finance/journals/new'] as $p) {
            self::assertSame(403, $emp->get($p)->status, $p);
        }
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        self::assertSame(403, $sys->get('/finance')->status, 'system admin gets no finance data (6.4)');
        self::assertSame(403, $sys->get('/payments')->status);
        $le2 = (int) $this->t->app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO2'");
        $fm = $this->t->browser();
        $fm->login('finance.maker');
        self::assertSame(404, $fm->get('/finance/accounts', ['le' => (string) $le2])->status, 'other company not even confirmed');
    }

    public function testJournalAndPaymentFlowThroughTheWeb(): void
    {
        $app = $this->t->app;
        $le = (int) $app->db()->scalar("SELECT id FROM legal_entities WHERE code = 'DEMO1'");
        $fm = $this->t->browser();
        $fm->login('finance.maker');
        $fm->get('/finance/journals/new', ['le' => (string) $le]);
        $bad = $fm->post('/finance/journals/new', ['le' => (string) $le, 'date' => '2026-11-09', 'description' => 'web', 'account' => ['5130', '1110'], 'debit' => ['10', ''], 'credit' => ['', '9'],
            'line_description' => ['', ''], 'site' => ['', '']]);
        self::assertSame(422, $bad->status);
        $ok = $fm->post('/finance/journals/new', ['le' => (string) $le, 'date' => '2026-11-09', 'description' => 'web', 'account' => ['5130', '1110', ''], 'debit' => ['10', '', ''], 'credit' => ['', '10', ''],
            'line_description' => ['', '', ''], 'site' => ['', '', ''], 'submit_now' => '1']);
        self::assertSame(303, $ok->status, $ok->body);
        self::assertSame('in_review', $app->db()->scalar("SELECT status FROM fin_journals WHERE description = 'web'"));

        // payments: create, import (masked preview), submit
        $pm = $this->t->browser();
        $pm->login('payroll.maker');
        $pm->get('/payments/new');
        $res = $pm->post('/payments/new', ['legal_entity' => (string) $le, 'type' => 'claim', 'pay_date' => '2026-11-25', 'period' => '2026-11', 'note' => '']);
        self::assertSame(303, $res->status, $res->body);
        $batch = basename((string) $res->headers['Location']);
        $pm->get('/import/payment.items', ['batch' => $batch]);
        $up = $pm->post('/import/payment.items', ['batch' => $batch], ['file' => ['items.csv', Csv::write(
            ['payee_type', 'payee_code', 'payee_name', 'site', 'bank_code', 'account_no', 'amount'],
            [['employee', 'E9', 'Web (DEMO)', 'HQ', 'BANK-A', '0012345678', '100.00']]
        )]]);
        self::assertSame(303, $up->status, $up->body);
        $preview = $pm->get((string) parse_url((string) $up->headers['Location'], PHP_URL_PATH));
        self::assertStringNotContainsString('0012345678', $preview->body, 'account masked on the preview');
        self::assertStringContainsString('••••5678', $preview->body);

        // Restricted file needs the password again
        $imp = basename((string) $up->headers['Location']);
        $pm->post('/import/batch/' . $imp . '/confirm', []);
        $pm->post('/payments/' . $batch . '/submit', []);
        $inst = (string) $app->db()->scalar("SELECT public_id FROM workflow_instances WHERE subject_type = 'payment_batch' AND subject_id = ?", [$batch]);
        $app->workflow()->decide($inst, (int) $this->t->user('payroll.checker')['id'], 'approve');
        $pc = $this->t->browser();
        $pc->login('payroll.checker');
        $pc->get('/payments/' . $batch);
        $gen = $pc->post('/payments/' . $batch . '/generate', ['_back' => '/payments/' . $batch]);
        self::assertStringContainsString('/reauth', (string) $gen->headers['Location']);
        $pc->get('/reauth');
        $pc->post('/reauth', ['password' => $this->t->demoPassword, 'next' => '/payments/' . $batch]);
        $pc->get('/payments/' . $batch);
        self::assertSame(303, $pc->post('/payments/' . $batch . '/generate', [])->status);
        $file = $pc->get('/payments/' . $batch . '/file');
        self::assertSame(200, $file->status);
        self::assertStringContainsString('attachment', $file->headers['Content-Disposition']);
        self::assertStringStartsWith('PK', $file->body);
        self::assertSame(1, (int) $app->db()->scalar('SELECT download_count FROM pmt_files'));
    }

    public function testBackupDownloadNeedsReauthAndStreamsTheEncryptedFile(): void
    {
        $app = $this->t->app;
        $sys = $this->t->browser();
        $sys->login(TestApp::ADMIN);
        $sys->get('/admin/backups');
        $run = $sys->post('/admin/backups/run', ['include_files' => '1']);
        self::assertSame(303, $run->status);
        $pid = basename((string) $run->headers['Location']);
        for ($i = 0; $i < 20 && $app->backups()->row($pid)['status'] === 'running'; $i++) {
            $sys->post('/admin/backups/' . $pid . '/step', []);
        }
        self::assertSame('verified', $app->backups()->row($pid)['status']);
        self::assertSame(200, $sys->get('/admin/backups/' . $pid)->status);
        $dl = $sys->get('/admin/backups/' . $pid . '/download');
        self::assertStringContainsString('/reauth', (string) ($dl->headers['Location'] ?? ''));
        $sys->get('/reauth');
        $sys->post('/reauth', ['password' => TestApp::ADMIN_PASSWORD, 'next' => '/admin/backups']);
        $dl = $sys->get('/admin/backups/' . $pid . '/download');
        self::assertSame(200, $dl->status);
        self::assertNotNull($dl->file);
        self::assertStringStartsWith("ROSBAK1\n", (string) file_get_contents((string) $dl->file));
        $aud = $this->t->browser();
        $aud->login('auditor.demo');
        self::assertSame(200, $aud->get('/admin/backups/' . $pid)->status);
        self::assertSame(403, $aud->get('/admin/backups/' . $pid . '/download')->status, 'auditor sees the catalogue, not the data');
        // restore maintenance flag blocks every page
        $app->install->setRestoring(true);
        self::assertSame(503, $aud->get('/finance')->status);
        $app->install->setRestoring(false);
    }
}
