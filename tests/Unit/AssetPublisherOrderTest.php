<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use Blue\Core\Ui\AssetPublisher;
use PHPUnit\Framework\TestCase;

/**
 * 0.37.0: the system's own components (ui/components/blue) are bundled after the generic components so they can
 * refine them — the rename ros → blue moved the folder first in alphabetical order and broke the home search padding.
 */
final class AssetPublisherOrderTest extends TestCase
{
    public function testSystemComponentsComeLast(): void
    {
        $tmp = sys_get_temp_dir() . '/blue-assets-' . bin2hex(random_bytes(4));
        foreach (['tokens', 'base', 'components/blue', 'components/field', 'components/table', 'patterns/x', 'js', 'icons', 'fonts'] as $d) {
            mkdir($tmp . '/ui/' . $d, 0777, true);
        }
        foreach (['tokens/primitives.css', 'tokens/semantic-light.css', 'tokens/semantic-dark.css', 'tokens/density.css', 'base/fonts.css', 'base/reset.css', 'base/typography.css', 'base/layout.css', 'base/a11y.css', 'tokens/theme-swatches.css'] as $f) {
            file_put_contents($tmp . '/ui/' . $f, '');
        }
        file_put_contents($tmp . '/ui/components/blue/blue.css', '.own{}');
        file_put_contents($tmp . '/ui/components/field/field.css', '.field{}');
        file_put_contents($tmp . '/ui/components/table/table.css', '.table{}');
        file_put_contents($tmp . '/ui/patterns/x/x.css', '.pattern{}');
        mkdir($tmp . '/public', 0777, true);
        (new AssetPublisher($tmp))->publish();
        $css = (string) file_get_contents($tmp . '/public/assets/ui/app.css');
        self::assertTrue(strpos($css, '.field{}') < strpos($css, '.table{}'));
        self::assertTrue(strpos($css, '.table{}') < strpos($css, '.own{}'), 'components/blue after the generic components');
        self::assertTrue(strpos($css, '.own{}') < strpos($css, '.pattern{}'), 'patterns still last');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($tmp);
    }
}
