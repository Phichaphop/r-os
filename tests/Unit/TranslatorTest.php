<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Blue\Core\I18n\DateFormat;
use Blue\Core\I18n\Translator;

/** DEC-15 + P0-05 §6 */
final class TranslatorTest extends TestCase
{
    private const SEED = __DIR__ . '/../../core/I18n/seed';

    public function testThaiAndFallbackToEnglish(): void
    {
        $dir = sys_get_temp_dir() . '/blue-i18n-' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents("{$dir}/en.php", "<?php return ['a' => 'Hello {name}', 'only_en' => 'English only'];");
        file_put_contents("{$dir}/th.php", "<?php return ['a' => 'สวัสดี {name}'];");
        $t = new Translator([$dir], 'en', 'th');
        self::assertSame('สวัสดี พิชาภพ', $t->get('a', ['name' => 'พิชาภพ']));
        self::assertSame('English only', $t->get('only_en'), 'missing TH falls back to EN');
        self::assertSame('missing.key', $t->get('missing.key'), 'unknown key is visible, never a crash');
        $t->setLocale('../../etc');
        self::assertSame('en', $t->locale(), 'invalid locale falls back');
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);
    }

    public function testCoreSeedsHaveTheSameKeysInThaiAndEnglish(): void
    {
        $en = Translator::seedMessages([self::SEED], 'en');
        $th = Translator::seedMessages([self::SEED], 'th');
        self::assertSame([], array_values(array_diff(array_keys($en), array_keys($th))), 'keys missing in th.php');
        self::assertSame([], array_values(array_diff(array_keys($th), array_keys($en))), 'keys missing in en.php');
    }

    public function testEveryKeyUsedInViewsExists(): void
    {
        $en = Translator::seedMessages([self::SEED], 'en');
        $missing = [];
        $files = array_merge(
            glob(__DIR__ . '/../../ui/views/*/*.php') ?: [],
            glob(__DIR__ . '/../../ui/components/*/*.php') ?: [],
            glob(__DIR__ . '/../../core/*/*.php') ?: []
        );
        foreach ($files as $f) {
            preg_match_all("/(?:\\\$t|->get)\('([a-z0-9_]+\.[a-z0-9_.]+)'/", (string) file_get_contents($f), $m);
            foreach ($m[1] as $key) {
                if (str_ends_with($key, '.')) {
                    continue; // prefix of a dynamic key, e.g. $t('iam.status.' . $status)
                }
                if (!isset($en[$key])) {
                    $missing[] = basename($f) . ': ' . $key;
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }

    public function testDateDisplayUsesBuddhistEraForThai(): void
    {
        self::assertSame('14 ต.ค. 2569 07:15', DateFormat::display('2026-10-14 00:15:00', 'th'));
        self::assertSame('14 Oct 2026 07:15', DateFormat::display('2026-10-14 00:15:00', 'en'));
        self::assertSame('1 ม.ค. 2570', DateFormat::display('2026-12-31 17:00:00', 'th', 'Asia/Bangkok', false), 'timezone shifts the date');
    }
}
