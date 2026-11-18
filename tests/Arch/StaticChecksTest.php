<?php

declare(strict_types=1);

namespace Blue\Tests\Arch;

use PHPUnit\Framework\TestCase;
use Blue\Core\Arch\AiOutboundCheck;
use Blue\Core\Arch\ArchitectureCheck;
use Blue\Core\Arch\SecretScan;
use Blue\Core\Arch\TokenLint;
use Blue\Tests\Support\TempDir;

/**
 * TC-AI-00 (AIP-06), "no secret in repo", token rules (P0-05 §8) and layering (P0-02 §4).
 * Each check must pass on this repository AND must catch a planted violation (so a silent no-op cannot pass).
 */
final class StaticChecksTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::make('blue-arch');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    private function plant(string $rel, string $content): void
    {
        @mkdir(dirname($this->tmp . '/' . $rel), 0777, true);
        file_put_contents($this->tmp . '/' . $rel, $content);
    }

    public function testRepositoryPassesAllChecks(): void
    {
        self::assertSame([], (new AiOutboundCheck(self::ROOT))->run(), 'TC-AI-00');
        self::assertSame([], (new SecretScan(self::ROOT))->run(), 'secret scan');
        self::assertSame([], (new TokenLint(self::ROOT))->run(), 'token lint');
        self::assertSame([], (new ArchitectureCheck(self::ROOT))->run(), 'architecture');
    }

    public function testAiCheckCatchesProviderCallsAndExtraProviders(): void
    {
        $this->plant('core/AI/DisabledProvider.php', '<?php final class DisabledProvider implements ProviderInterface {}');
        $this->plant('modules/leave/Application/Summarise.php', '<?php $x = file_get_contents("https://api.openai.com/v1/chat/completions");');
        $this->plant('core/AI/FreeLlm.php', '<?php final class FreeLlm implements ProviderInterface {}');
        $problems = implode("\n", (new AiOutboundCheck($this->tmp))->run());
        self::assertStringContainsString('api.openai.com', $problems);
        self::assertStringContainsString('core/AI/FreeLlm.php', $problems);
    }

    public function testSecretScanCatchesCommittedSecrets(): void
    {
        $this->plant('config/prod.php', "<?php return ['DB_PASS' => 'Sup3rS3cret!'];");
        $this->plant('notes.txt', 'token ghp_' . str_repeat('a', 36));
        $problems = implode("\n", (new SecretScan($this->tmp))->run());
        self::assertStringContainsString('DB password', $problems);
        self::assertStringContainsString('GitHub token', $problems);
        self::assertSame([], (new SecretScan(self::ROOT))->run());
    }

    public function testTokenLintCatchesRawColoursAndInlineStyles(): void
    {
        $this->plant('ui/components/x/x.css', '.x { color: #ff0000; }');
        $this->plant('ui/views/system/x.php', '<div style="margin:0">x</div><script>alert(1)</script>');
        $this->plant('ui/tokens/primitives.css', ':root { --a: #123456; }');
        $problems = implode("\n", (new TokenLint($this->tmp))->run());
        self::assertStringContainsString('x.css:1 raw colour', $problems);
        self::assertStringContainsString('inline style', $problems);
        self::assertStringContainsString('without nonce', $problems);
        self::assertStringNotContainsString('primitives.css', $problems, 'tokens folder is the one allowed place');
    }

    public function testArchitectureCheckCatchesLayerViolations(): void
    {
        $this->plant('modules/leave/Views/list.php', '<?php $rows = $app->db()->all("SELECT * FROM lv_requests");');
        $this->plant('modules/leave/Domain/Rule.php', "<?php\nuse Blue\\Modules\\Leave\\Infrastructure\\Repo;\n");
        $this->plant('modules/payroll/Application/Run.php', "<?php\nuse Blue\\Modules\\Leave\\Infrastructure\\LeaveRepo;\n\$now = new \\DateTimeImmutable();\n");
        $this->plant('modules/payroll/Infrastructure/Db.php', '<?php $p = new PDO("mysql:");');
        $problems = implode("\n", (new ArchitectureCheck($this->tmp))->run());
        self::assertStringContainsString('(rule 1)', $problems);
        self::assertStringContainsString('(rule 2)', $problems);
        self::assertStringContainsString('(rule 3)', $problems);
        self::assertStringContainsString('(rule 4)', $problems);
        self::assertStringContainsString('(rule 5)', $problems);
    }

    public function testArchitectureCheckCatchesCaseOnlyPathClashes(): void
    {
        // Real bug found on XAMPP/Windows: core/Migrations (classes) and core/migrations (files) are one folder there.
        $this->plant('core/Migrations/Runner.php', '<?php');
        $this->plant('core/migrations/202611020001_x.php', '<?php');
        $problems = implode("\n", (new ArchitectureCheck($this->tmp))->run());
        self::assertStringContainsString('(rule 6)', $problems);
    }
}
