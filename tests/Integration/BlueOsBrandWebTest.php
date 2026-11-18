<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Http\Kernel;
use Blue\Core\Http\Request;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * 0.37.0 (DEC-102): the system is called Blue OS; an organization can hide the system name (the logo grows in the
 * side bar and on the sign-in page) and paint an uploaded logo white / light accent on the navy areas; the "use the chosen
 * font" button is primary; recruitment has its own menu section (job openings + résumé builder moved there); the safety
 * officer role (SFO) holds safety.manage instead of WPA.
 */
final class BlueOsBrandWebTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this, 'prototype', false);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function as(string $user): TestBrowser
    {
        $b = $this->t->browser();
        $b->login($user);
        return $b;
    }

    /** @param array<string, string> $extra */
    private function look(TestBrowser $b, array $extra): int
    {
        $this->t->clock->advance('+1 minute'); // a new version of the same key needs a later effective time
        $b->get('/admin/settings/appearance');
        return $b->post('/admin/settings/appearance', $extra + ['app_name' => 'Blue OS', 'tagline' => '', 'preset' => 'steel', 'primary' => '', 'menu' => '', 'accent' => '', 'background' => '', 'mode' => 'light'])->status;
    }

    private function themeCss(): string
    {
        return (new Kernel($this->t->app))->handle(new Request('GET', '/theme.css', [], [], [], [], '127.0.0.1'))->body;
    }

    public function testBlueOsNameLogoOnlyModeAndLogoTint(): void
    {
        $sys = $this->as(TestApp::ADMIN);
        $home = $sys->get('/admin/users')->body;
        self::assertStringContainsString('<span class="sidebar__brand-name">Blue OS</span>', $home, 'default system name');
        self::assertMatchesRegularExpression('#<title>[^<]*Blue OS</title>#', $home);
        $login0 = $this->t->browser()->get('/login')->body;
        self::assertStringContainsString('<p class="auth-split__name">Blue OS</p>', $login0, 'split sign-in page shows the name by default');
        self::assertStringContainsString('auth-split__lead', $login0);
        self::assertStringContainsString('class="auth-split"', $this->t->browser()->get('/password/forgot')->body, 'forgot-password uses the same layout');

        // the form: name switch (hidden 0 + checkbox 1), three tints, the font button is primary
        $form = $sys->get('/admin/settings/appearance')->body;
        self::assertStringContainsString('<input type="hidden" name="show_name" value="0">', $form);
        self::assertStringContainsString('name="show_name" value="1" checked', $form);
        foreach (['original', 'light', 'accent'] as $tint) {
            self::assertStringContainsString('name="logo_tint" value="' . $tint . '"', $form);
        }
        self::assertMatchesRegularExpression('#<button[^>]*class="btn btn--primary[^"]*"[^>]*>(?:(?!</button>).)*ใช้ฟอนต์ที่เลือก#su', $form, 'font button uses the primary colour');

        // no uploaded logo: the Blue logo shipped with the system (Main_logo_blue, transparent) — served at /brand/logo,
        // the star mark as the tab icon; hide the name + white tint apply to it too
        $logoBlue = (string) file_get_contents(dirname(__DIR__, 2) . '/ui/icons/logo-blue.png');
        $guest = $this->t->browser();
        $logo = $guest->get('/brand/logo');
        self::assertSame(['image/png', $logoBlue], [$logo->headers['Content-Type'], $logo->body]);
        self::assertSame((string) file_get_contents(dirname(__DIR__, 2) . '/ui/icons/mark-blue.png'), $guest->get('/brand/favicon')->body);
        self::assertStringContainsString('<link rel="icon" href="/assets/ui/icons/mark-blue.png', $guest->get('/login')->body);
        self::assertSame(303, $this->look($sys, ['show_name' => '0', 'logo_tint' => 'light']));
        $this->t->app->appearance()->reset();
        self::assertSame('hide', $this->t->app->registry()->resolveOrNull('brand.show_name')?->value);
        self::assertSame('light', $this->t->app->registry()->resolveOrNull('brand.logo_tint')?->value);
        $page = $sys->get('/admin/users')->body;
        self::assertStringNotContainsString('sidebar__brand-name', $page, 'name hidden');
        self::assertStringContainsString('sidebar__brand sidebar__brand--logo', $page);
        self::assertMatchesRegularExpression('#class="brand-logo brand-logo--large brand-logo--tint" src="/brand/logo\?v=[0-9a-f]{12}" alt="Blue OS"#', $page, 'the logo carries the name');
        self::assertStringContainsString('brand-logo--tint', $this->themeCss());
        // the alternate built-in logo (Main_logo_blue_os) replaces it at once
        self::assertSame(303, $this->look($sys, ['show_name' => '0', 'logo_tint' => 'original', 'builtin_logo' => 'blue_os']));
        $this->t->app->appearance()->reset();
        self::assertSame((string) file_get_contents(dirname(__DIR__, 2) . '/ui/icons/logo-blue-os.png'), $this->t->browser()->get('/brand/logo')->body);
        self::assertStringContainsString('name="builtin_logo" value="blue_os" checked', $sys->get('/admin/settings/appearance')->body);
        self::assertSame(303, $this->look($sys, ['show_name' => '0', 'logo_tint' => 'light', 'builtin_logo' => 'blue']));
        $this->t->app->appearance()->reset();

        // uploaded logo + white tint: silhouette class in the shell and on the sign-in page, mask in theme.css
        $sys->post('/admin/settings/appearance/brand', ['kind' => 'logo'], ['file' => ['logo.png', (string) base64_decode(self::PNG)]]);
        $this->t->app->appearance()->reset();
        $page = $sys->get('/admin/users')->body;
        self::assertMatchesRegularExpression('#class="brand-logo brand-logo--large brand-logo--tint" src="/brand/logo\?v=[0-9a-f]{12}"#', $page);
        $login = $this->t->browser()->get('/login')->body;
        // sign-in page = reversed split screen: branding (logo, no name when hidden) left, the form in the right half
        self::assertMatchesRegularExpression('#<div class="auth-split">\s*<aside class="auth-split__brand navy-dots on-navy"[^>]*>.*?</aside>\s*<main id="main" class="auth-split__form">.*?<form method="post" action="/login"#s', $login);
        self::assertStringContainsString('brand-logo brand-logo--hero brand-logo--tint', $login);
        self::assertStringNotContainsString('auth-split__name', $login, 'name hidden');
        self::assertMatchesRegularExpression('#<link rel="stylesheet" id="theme-css" href="/theme\.css\?v=[0-9a-f]{12}">#', $login, 'the tint needs theme.css even on the default colours');
        $css = $this->themeCss();
        self::assertMatchesRegularExpression('#\.brand-logo--tint \{ object-position: -99999px 0; background-color: var\(--color-on-navy\); -webkit-mask: url\("/brand/logo\?v=[0-9a-f]{12}"\)#', $css);
        self::assertSame(303, $this->look($sys, ['logo_tint' => 'accent']));
        $this->t->app->appearance()->reset();
        self::assertStringContainsString('background-color: var(--color-navy-accent)', $this->themeCss());
        self::assertStringContainsString('<span class="sidebar__brand-name">Blue OS</span>', $sys->get('/admin/users')->body, 'a form without the switch keeps the name shown');

        // original colours: no tint class, no mask; reset brings everything back
        self::assertSame(303, $this->look($sys, ['logo_tint' => 'original', 'builtin_logo' => 'blue_os']));
        $this->t->app->appearance()->reset();
        self::assertStringNotContainsString('brand-logo--tint', $sys->get('/admin/users')->body);
        self::assertStringNotContainsString('brand-logo--tint', $this->themeCss());
        $this->t->clock->advance('+1 minute');
        $sys->get('/admin/settings/appearance');
        $sys->post('/admin/settings/appearance', ['reset' => '1']);
        $this->t->app->appearance()->reset();
        self::assertSame('show', $this->t->app->registry()->resolveOrNull('brand.show_name')?->value);
        self::assertSame('original', $this->t->app->registry()->resolveOrNull('brand.logo_tint')?->value);
        self::assertSame('blue', $this->t->app->registry()->resolveOrNull('brand.builtin_logo')?->value);
    }

    public function testRecruitmentSectionAndSafetyOfficerRole(): void
    {
        // recruitment: job openings and the résumé builder sit in their own section, no longer under tools
        $emp = $this->as('employee.demo1');
        preg_match('#<nav class="sidebar.*?</nav>#s', $emp->get('/')->body, $nav);
        self::assertMatchesRegularExpression('#data-navgroup="sec_recruitment".*?href="/recruit/jobs"#s', $nav[0]);
        self::assertMatchesRegularExpression('#data-navgroup="sec_recruitment".*?href="/talent/builder"#s', $nav[0]);
        preg_match('#data-navgroup="tools".*?(?=data-navgroup="|<div class="sidebar__footer")#s', $nav[0], $tools);
        self::assertStringNotContainsString('href="/recruit/jobs"', $tools[0] ?? '');
        self::assertStringNotContainsString('href="/talent/builder"', $tools[0] ?? '');
        self::assertStringContainsString('สรรหาว่าจ้าง', $nav[0]);
        $hr = $this->as('hr.demo1');
        preg_match('#data-navgroup="sec_recruitment".*?(?=<div class="sidebar__group[ "]|<div class="sidebar__footer")#s', $hr->get('/')->body, $sec);
        self::assertStringContainsString('href="/recruit"', $sec[0] ?? '', 'the whole recruitment module moved with it');

        // safety officer: SFO holds safety.manage, WPA no longer does; the demo workplace admin also got SFO
        $db = $this->t->app->db();
        $perm = static fn (string $role): array => array_column($db->all('SELECT rp.permission_code FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.code = ?', [$role]), 'permission_code');
        self::assertContains('safety.manage', $perm('SFO'));
        self::assertNotContains('safety.manage', $perm('WPA'));
        self::assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM user_role_assignments a JOIN roles r ON r.id = a.role_id JOIN users u ON u.id = a.user_id WHERE r.code = 'SFO' AND u.username = 'ga.demo1' AND a.status = 'active'"));
        self::assertSame(['projects.oversee'], array_values(array_intersect(['projects.oversee'], $perm('AUD'))));
        self::assertContains('projects.oversee', $perm('SYS'));
    }
}
