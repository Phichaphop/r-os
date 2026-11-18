<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Blue\Core\Modules\ModuleRegistry;
use Blue\Tests\Support\TestApp;
use Blue\Tests\Support\TestBrowser;

/**
 * 0.27.1 (DEC-92): the side menu is foldable groups. Module "work" items are filed into sections (HR split into
 * people / time & leave / pay & benefits), a module's 2nd+ item becomes a sub-item, self-service (/me/...) stays in
 * "my work", module settings get their own group. A long menu (administrators) starts with only "my work" and the
 * current page's group open and offers a menu filter; a short menu (14 items or fewer) starts fully open.
 */
final class SidebarGroupsWebTest extends TestCase
{
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

    /** @return array<string, array{open: bool, current: bool, html: string}> */
    private function groups(string $html): array
    {
        preg_match('#<nav class="sidebar.*?</nav>#s', $html, $nav);
        $out = [];
        preg_match_all('#<div class="sidebar__group( is-open)?" data-navgroup="([a-z_0-9]+)"( data-navgroup-current)?[^>]*>(.*?)(?=<div class="sidebar__group[ "]|<div class="sidebar__footer")#s', $nav[0] ?? '', $m, PREG_SET_ORDER);
        foreach ($m as $g) {
            $out[$g[2]] = ['open' => $g[1] !== '', 'current' => $g[3] !== '', 'html' => $g[4]];
        }
        return $out;
    }

    public function testAdministratorMenuIsFoldableSections(): void
    {
        $admin = $this->as('hr.demo1');
        $home = $admin->get('/')->body;
        $g = $this->groups($home);
        self::assertArrayHasKey('system', $this->groups($this->as(TestApp::ADMIN)->get('/')->body));
        foreach (['work', 'sec_core_plus', 'sec_hr_people', 'sec_hr_time', 'sec_hr_pay', 'sec_workplace', 'tools', 'admin', 'settings', 'module_settings'] as $k) {
            self::assertArrayHasKey($k, $g, "group {$k}");
        }
        // long menu: only "my work" starts open; the rest folded (hidden body, aria-expanded=false)
        self::assertTrue($g['work']['open']);
        self::assertFalse($g['sec_hr_people']['open']);
        self::assertMatchesRegularExpression('#aria-expanded="false" aria-controls="ng-sec_hr_time"#', $home);
        self::assertStringContainsString('<div class="sidebar__group-items" id="ng-sec_hr_time" hidden>', $home);
        self::assertStringContainsString('data-navfilter hidden', $home, 'menu filter for a long menu');
        self::assertStringContainsString('ค้นหาเมนู', $home);
        // sections hold the right modules; HR split three ways
        self::assertStringContainsString('href="/leave"', $g['sec_hr_time']['html']);
        self::assertStringContainsString('href="/attendance"', $g['sec_hr_time']['html']);
        self::assertStringContainsString('href="/welfare/admin"', $g['sec_hr_pay']['html']);
        self::assertStringContainsString('href="/employees"', $g['sec_hr_people']['html']);
        self::assertStringContainsString('href="/helpdesk"', $g['sec_workplace']['html']);
        self::assertStringContainsString('href="/announcements"', $g['sec_core_plus']['html']);
        // self-service stays in "my work"; module settings in their own group, not in system settings
        self::assertStringContainsString('href="/me/profile"', $g['work']['html']);
        self::assertStringNotContainsString('href="/me/profile"', $g['sec_hr_people']['html']);
        self::assertStringContainsString('href="/leave/admin"', $g['module_settings']['html']);
        self::assertStringNotContainsString('href="/leave/admin"', $g['settings']['html']);
        // a module's second item is a folded sub-item of its first
        self::assertMatchesRegularExpression('#data-subnav="/employees".*?sidebar__link--sub" href="/employees/report"#s', $g['sec_hr_people']['html']);
        // top-level items in the biggest section stay manageable
        self::assertLessThanOrEqual(10, preg_match_all('#<a class="sidebar__link" #', $g['sec_hr_people']['html']));
        // the group holding the current page opens itself
        $leave = $admin->get('/leave/admin')->body;
        $lg = $this->groups($leave);
        self::assertTrue($lg['module_settings']['open'] && $lg['module_settings']['current']);
        self::assertFalse($lg['sec_hr_time']['open']);
        self::assertTrue($this->groups($admin->get('/helpdesk')->body)['sec_workplace']['open']);
    }

    public function testEmployeeMenuAndManifestSectionCheck(): void
    {
        $emp = $this->as('employee.demo1');
        $home = $emp->get('/')->body;
        $g = $this->groups($home);
        self::assertNotSame([], $g);
        // an employee sees many self-service apps and tools too: same folding, "my work" open, approvals count kept
        self::assertTrue($g['work']['open']);
        self::assertFalse($g['tools']['open']);
        self::assertStringContainsString('href="/me/profile"', $g['work']['html']);
        self::assertStringContainsString('data-navfilter hidden', $home);
        self::assertArrayNotHasKey('module_settings', $g, 'no settings for an employee');
        self::assertArrayNotHasKey('admin', $g);
        // manifest check: an unknown nav_section is a problem
        $problems = ModuleRegistry::validate(['code' => 'demo_x', 'version' => '1', 'pack' => 'hr', 'name' => ['th' => 'x', 'en' => 'x'], 'nav_section' => 'nope'], 'demo_x');
        self::assertContains('nav_section must be one of ' . implode('|', ModuleRegistry::NAV_SECTIONS), $problems);
        self::assertNotContains('nav_section must be one of ' . implode('|', ModuleRegistry::NAV_SECTIONS), ModuleRegistry::validate(['code' => 'demo_x', 'version' => '1', 'pack' => 'hr', 'name' => ['th' => 'x', 'en' => 'x'], 'nav_section' => 'hr_time'], 'demo_x'));
    }
}
