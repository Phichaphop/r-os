<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\Http\Request;

/** Install locations: domain root, public/ as web root, sub-folder on XAMPP, Thai folder names. */
final class RequestBaseTest extends TestCase
{
    /** @return array<string, array{0:string,1:string,2:string,3:string}> */
    public static function cases(): array
    {
        return [
            'web root = public/' => ['/index.php', '/install', '', '/install'],
            'root .htaccess → public/' => ['/public/index.php', '/system/status', '', '/system/status'],
            'XAMPP sub-folder via root .htaccess' => ['/blue/public/index.php', '/blue/', '/blue', '/'],
            'XAMPP sub-folder deep path' => ['/blue/public/index.php', '/blue/system/ui-showcase?lang=en', '/blue', '/system/ui-showcase'],
            'XAMPP sub-folder opened at public/' => ['/blue/public/index.php', '/blue/public/install', '/blue/public', '/install'],
            'nested folders' => ['/work/apps/blue/public/index.php', '/work/apps/blue/install', '/work/apps/blue', '/install'],
            'Thai folder name (percent-encoded URI)' => ['/ระบบ/public/index.php', '/' . rawurlencode('ระบบ') . '/install', '/ระบบ', '/install'],
            'folder with space' => ['/my app/public/index.php', '/my%20app/', '/my app', '/'],
            'prefix that only looks similar' => ['/blue/public/index.php', '/blue2/install', '', '/blue2/install'],
            'Windows backslashes' => ['\\blue\\public\\index.php', '/blue/install', '/blue', '/install'],
        ];
    }

    /** @dataProvider cases */
    public function testResolvesBaseAndPath(string $script, string $uri, string $base, string $path): void
    {
        self::assertSame([$base, $path], Request::resolveBase($script, $uri));
    }
}
