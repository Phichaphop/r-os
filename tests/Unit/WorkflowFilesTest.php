<?php

declare(strict_types=1);

namespace Blue\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 0.50.0 (R9, DEC-119 · Q-16/Q-17): the CI keeps a leg on the Staging notebook's stack (XAMPP 8.2.12 = PHP 8.2 +
 * MariaDB 10.4) and a tag builds the release zip the same way the deliveries always have. The workflows themselves
 * are checked with actionlint before a release; this guards the decisions against a later edit.
 */
final class WorkflowFilesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../.github/workflows';

    public function testCiRunsTheStagingStack(): void
    {
        $ci = (string) file_get_contents(self::DIR . '/ci.yml');
        self::assertMatchesRegularExpression("/php: '8\\.2', db: 'mariadb:10\\.4'/", $ci, 'XAMPP leg');
        self::assertMatchesRegularExpression("/php: '8\\.1', db: 'mariadb:10\\.11'/", $ci);
        self::assertStringContainsString('image: ${{ matrix.db }}', $ci);
        self::assertStringContainsString('vendor/bin/phpunit', $ci);
        self::assertStringContainsString('php bin/blue check:all', $ci);
    }

    public function testReleaseBuildsTheZipFromTrackedFilesWithAChecksum(): void
    {
        $rel = (string) file_get_contents(self::DIR . '/release.yml');
        self::assertStringContainsString("tags: ['v*.*.*']", $rel);
        self::assertStringContainsString('uses: ./.github/workflows/ci.yml', $rel, 'release only after the full CI');
        self::assertStringContainsString('needs: ci', $rel);
        self::assertStringContainsString('git archive --format=zip --prefix=blue/', $rel);
        self::assertStringContainsString('sha256sum', $rel);
        self::assertStringContainsString('config/env', $rel, 'the zip is checked for secrets');
        self::assertStringContainsString('public const RELEASE', $rel, 'the version comes from App::RELEASE');
        self::assertStringContainsString('workflow_run.conclusion == \'success\'', $rel, 'automatic release only after a green CI on main');
        self::assertStringContainsString('gh release view', $rel, 'a version is released once');
        foreach (glob(self::DIR . '/*.yml') ?: [] as $f) {
            preg_match_all('/secrets\.([A-Z_]+)/', (string) file_get_contents($f), $m);
            self::assertSame([], array_values(array_diff(array_unique($m[1]), ['GITHUB_TOKEN', 'FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD', 'FTP_SERVER_DIR'])), basename($f) . ': no new secret');
        }
    }
}
