<?php

declare(strict_types=1);

namespace Blue\Core\Search;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;

/**
 * Global search that checks rights BEFORE searching (P0-07 AIP-04, 04 §6.17.8, 6.30.14): every source applies the
 * same permission and company/site scope as its own list page, so a result never reveals a title the user could not
 * open. Searches names, codes, numbers and titles — not file contents (no OCR in rollout 1). Also the base for
 * permission-first retrieval when AI search arrives (S02 6.22.5).
 */
final class SearchService
{
    public const MIN_LENGTH = 2;
    public const PER_SOURCE = 8;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param list<array{label: string, href: string, icon: string}> $menu items already filtered for the user (App Shell)
     * @return array<string, list<array{title: string, subtitle: string, href: string, icon: string}>> source => hits
     */
    public function search(Authorizer $az, string $q, array $menu, string $locale): array
    {
        $q = trim(mb_substr($q, 0, 100));
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return [];
        }
        $db = $this->app->db();
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $t = $this->app->translator();
        $out = [];

        $hits = [];
        foreach ($menu as $m) {
            if (mb_stripos($m['label'], $q) !== false) {
                $hits[] = ['title' => $m['label'], 'subtitle' => $t->get('search.source.menu'), 'href' => $m['href'], 'icon' => $m['icon']];
            }
        }
        $out['menu'] = array_slice($hits, 0, self::PER_SOURCE);

        // documents: same visibility rule as the document register
        [$vis, $params] = $this->app->documents()->visibleSql($az);
        $rows = $db->all(
            "SELECT d.public_id, d.title, d.document_number, d.document_class, d.lifecycle_status FROM documents d
              WHERE {$vis} AND d.document_class <> 'core.brand_asset' AND d.lifecycle_status <> 'disposed' AND (d.title LIKE ? OR d.document_number LIKE ?)
              ORDER BY d.id DESC LIMIT " . self::PER_SOURCE,
            [...$params, $like, $like]
        );
        $out['documents'] = array_map(static fn (array $r): array => [
            'title' => (string) $r['title'], 'subtitle' => trim(($r['document_number'] ?? '') . ' · ' . $t->get('document.class.' . $r['document_class']), ' ·'),
            'href' => '/documents/' . $r['public_id'], 'icon' => 'file-text',
        ], $rows);

        // users: the user list needs organization-wide core.user.view (same as /admin/users)
        if ($az->scope('core.user.view')->all) {
            $rows = $db->all("SELECT public_id, username, display_name FROM users WHERE username LIKE ? OR display_name LIKE ? OR email LIKE ? ORDER BY display_name LIMIT " . self::PER_SOURCE, [$like, $like, $like]);
            $out['users'] = array_map(static fn (array $r): array => ['title' => (string) $r['display_name'], 'subtitle' => (string) $r['username'], 'href' => '/admin/users/' . $r['public_id'], 'icon' => 'user'], $rows);
        }

        // requests the user made or may decide
        $rows = $db->all(
            "SELECT DISTINCT i.public_id, i.title, i.status, i.requester_id FROM workflow_instances i LEFT JOIN workflow_tasks k ON k.instance_id = i.id AND k.assignee_id = ?
              WHERE (i.requester_id = ? OR k.id IS NOT NULL) AND (i.title LIKE ? OR i.public_id = ?) ORDER BY i.id DESC LIMIT " . self::PER_SOURCE,
            [$az->userId, $az->userId, $like, strtoupper($q)]
        );
        $out['requests'] = array_map(static fn (array $r): array => [
            'title' => (string) $r['title'], 'subtitle' => $t->get('workflow.status.' . $r['status']),
            'href' => ((int) $r['requester_id'] === $az->userId ? '/requests/' : '/approvals/') . $r['public_id'], 'icon' => 'send',
        ], $rows);

        if (!$az->scope('core.config.view')->isEmpty()) {
            $rows = $db->all("SELECT config_key FROM config_definitions WHERE status = 'active' AND config_key LIKE ? ORDER BY config_key LIMIT " . self::PER_SOURCE, [$like]);
            $out['config'] = array_map(static fn (array $r): array => ['title' => (string) $r['config_key'], 'subtitle' => $t->get('config.desc.' . $r['config_key']), 'href' => '/admin/config/key/' . $r['config_key'], 'icon' => 'settings'], $rows);
        }

        $cal = $this->app->calendars();
        $hits = [];
        foreach ($cal->list($az) as $c) {
            if (mb_stripos((string) $c['name'], $q) !== false || mb_stripos((string) $c['code'], $q) !== false) {
                $hits[] = ['title' => (string) $c['name'], 'subtitle' => (string) $c['code'], 'href' => '/calendars/' . $c['public_id'], 'icon' => 'calendar-event'];
            }
            foreach ($db->all("SELECT day_date, name FROM calendar_days WHERE calendar_id = ? AND name LIKE ? ORDER BY day_date DESC LIMIT 3", [$c['id'], $like]) as $d) {
                $hits[] = ['title' => (string) $d['name'], 'subtitle' => \Blue\Core\I18n\DateFormat::day((string) $d['day_date'], $locale) . ' · ' . $c['code'],
                    'href' => '/calendars/' . $c['public_id'] . '?month=' . substr((string) $d['day_date'], 0, 7), 'icon' => 'calendar-event'];
            }
        }
        $out['calendars'] = array_slice($hits, 0, self::PER_SOURCE);

        if ($az->can('core.template.issue') || $az->scope('core.template.manage')->all) {
            $rows = $db->all("SELECT public_id, code, name FROM document_templates WHERE status = 'active' AND (name LIKE ? OR code LIKE ?) ORDER BY code LIMIT " . self::PER_SOURCE, [$like, $like]);
            $out['templates'] = array_map(static fn (array $r): array => ['title' => (string) $r['name'], 'subtitle' => (string) $r['code'], 'href' => '/templates/' . $r['public_id'], 'icon' => 'file-text'], $rows);
        }

        return array_filter($out, static fn (array $h): bool => $h !== []);
    }
}
