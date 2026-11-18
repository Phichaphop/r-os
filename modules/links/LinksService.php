<?php

declare(strict_types=1);

namespace Blue\Modules\Links;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Support\UrlSafety;
use Blue\Core\Support\Ulid;

/**
 * M31 External links. A link is DRAFT until someone with links.manage reviews the risk and publishes it;
 * a later edit of a published link waits in draft_json (the live link keeps working) until it is published as a new
 * revision (7B item 3). People see only published links for their role/company; clicks are counted per day without
 * user ids, and the redirect sends no referrer so nothing internal leaks to the other site (7B item 2).
 */
final class LinksService
{
    public const CATEGORIES = ['ai', 'email', 'translate', 'design', 'documents', 'maps', 'hr', 'finance', 'other'];
    public const ICONS = ['external-link', 'language', 'map-pin', 'send', 'palette', 'file-type-pdf', 'robot-off', 'calculator', 'calendar-event',
        'briefcase', 'users', 'building', 'report-money', 'cash', 'help-circle', 'tool', 'inbox', 'clipboard-check', 'qrcode', 'typography'];
    private const EDITABLE = ['name_th', 'name_en', 'description', 'url', 'category', 'icon', 'open_new_tab', 'audience_roles', 'legal_entity_id', 'sort_order'];

    public function __construct(private readonly App $app)
    {
    }

    public static function of(App $app): self
    {
        return $app->service(self::class, static fn (App $a): self => new self($a));
    }

    // ---- people ------------------------------------------------------------------------------------

    /**
     * Published links this person may see.
     *
     * @return list<array<string, mixed>>
     */
    public function visible(Authorizer $az, string $q = '', string $category = ''): array
    {
        if (!$az->can('links.use')) {
            throw HttpException::forbidden();
        }
        $rows = $this->app->db()->all(
            "SELECT l.*, (SELECT COALESCE(SUM(c.clicks), 0) FROM link_clicks c WHERE c.link_id = l.id AND c.day >= ?) AS clicks_30d
               FROM link_items l WHERE l.status = 'published' ORDER BY l.category, l.sort_order, l.name_th",
            [$this->day('-30 days')]
        );
        $q = mb_strtolower(trim($q));
        $out = [];
        foreach ($rows as $r) {
            if (!$this->mayView($az, $r)) {
                continue;
            }
            if ($category !== '' && $r['category'] !== $category) {
                continue;
            }
            if ($q !== '' && !str_contains(mb_strtolower($r['name_th'] . ' ' . $r['name_en'] . ' ' . $r['description'] . ' ' . $r['host']), $q)) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function mayView(Authorizer $az, array $row): bool
    {
        $roles = array_filter(explode(',', (string) ($row['audience_roles'] ?? '')));
        if ($roles !== [] && array_intersect($roles, $az->roleCodes()) === []) {
            return false;
        }
        $le = $row['legal_entity_id'] !== null ? (int) $row['legal_entity_id'] : null;
        return $le === null || $az->reachableScope()->coversLegalEntity($le);
    }

    /** Counts the click and returns where to go (404 when the link is not visible to this person). */
    public function go(Authorizer $az, string $pid): string
    {
        $row = $this->app->db()->one("SELECT * FROM link_items WHERE public_id = ? AND status = 'published'", [$pid]);
        if ($row === null || !$az->can('links.use') || !$this->mayView($az, $row)) {
            throw HttpException::notFound();
        }
        $this->app->db()->run(
            'INSERT INTO link_clicks (link_id, day, clicks) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE clicks = clicks + 1',
            [$row['id'], $this->day()]
        );
        return (string) $row['url'];
    }

    // ---- administrators ----------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function all(Authorizer $az): array
    {
        $this->guard($az);
        return $this->app->db()->all(
            "SELECT l.*, (SELECT COALESCE(SUM(c.clicks), 0) FROM link_clicks c WHERE c.link_id = l.id AND c.day >= ?) AS clicks_30d,
                    u.display_name AS reviewer_name
               FROM link_items l LEFT JOIN users u ON u.id = l.reviewed_by
              ORDER BY FIELD(l.status, 'draft', 'published', 'disabled', 'archived'), l.category, l.sort_order, l.name_th",
            [$this->day('-30 days')]
        );
    }

    /** @return array<string, mixed> */
    public function get(Authorizer $az, string $pid): array
    {
        $this->guard($az);
        $row = $this->app->db()->one('SELECT * FROM link_items WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $row['pending'] = $row['draft_json'] !== null ? (array) json_decode((string) $row['draft_json'], true) : null;
        $row['revisions'] = $this->app->db()->all(
            'SELECT r.*, u.display_name FROM link_revisions r LEFT JOIN users u ON u.id = r.changed_by WHERE r.link_id = ? ORDER BY r.id DESC LIMIT 50',
            [$row['id']]
        );
        return $row;
    }

    /**
     * Validates input; returns [clean values, warnings].
     *
     * @param array<string, string|list<string>> $in
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function validate(array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['name_th'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name_th'] = 'links.error.name';
        }
        $url = trim((string) ($in['url'] ?? ''));
        $check = UrlSafety::check($url);
        if ($check['errors'] !== []) {
            $errors['url'] = $check['errors'][0];
        }
        $cat = (string) ($in['category'] ?? '');
        if (!in_array($cat, self::CATEGORIES, true)) {
            $errors['category'] = 'links.error.category';
        }
        $icon = (string) ($in['icon'] ?? 'external-link');
        $roles = array_values(array_intersect((array) ($in['audience_roles'] ?? []), array_keys(\Blue\Core\Authz\Catalog::ROLES)));
        $le = (string) ($in['legal_entity_id'] ?? '');
        $leId = null;
        if ($le !== '') {
            $leId = (int) ($this->app->db()->scalar('SELECT id FROM legal_entities WHERE id = ?', [(int) $le]) ?? 0) ?: null;
            if ($leId === null) {
                $errors['legal_entity_id'] = 'links.error.company';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [[
            'name_th' => $name,
            'name_en' => mb_substr(trim((string) ($in['name_en'] ?? '')), 0, 120) ?: null,
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 300) ?: null,
            'url' => $url,
            'host' => $check['host'],
            'category' => $cat,
            'icon' => in_array($icon, self::ICONS, true) ? $icon : 'external-link',
            'open_new_tab' => ($in['open_new_tab'] ?? '') === '1' ? 1 : 0,
            'audience_roles' => $roles === [] ? null : implode(',', $roles),
            'legal_entity_id' => $leId,
            'sort_order' => max(0, min(9999, (int) ($in['sort_order'] ?? 100))),
        ], $check['warnings']];
    }

    /**
     * Creates a draft, or stores a pending edit. Editing a draft changes it directly; editing a published link
     * keeps the live values until the edit is published (new revision).
     *
     * @param array<string, string|list<string>> $in
     * @return array{pid: string, warnings: list<string>, pending: bool}
     */
    public function save(Authorizer $az, ?string $pid, array $in): array
    {
        $this->guard($az);
        [$v, $warnings] = $this->validate($in);
        $db = $this->app->db();
        $now = $this->now();
        $uid = $az->userId;
        if ($pid === null) {
            $pid = Ulid::generate($this->app->clock);
            $db->run(
                'INSERT INTO link_items (public_id, created_at, created_by, updated_at, updated_by, name_th, name_en, description, url, host, category, icon,
                    open_new_tab, audience_roles, legal_entity_id, owner_user_id, status, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?)',
                [$pid, $now, $uid, $now, $uid, $v['name_th'], $v['name_en'], $v['description'], $v['url'], $v['host'], $v['category'], $v['icon'],
                    $v['open_new_tab'], $v['audience_roles'], $v['legal_entity_id'], $uid, $v['sort_order']]
            );
            $this->app->audit()->record('links.created', 'success', 'link', $pid);
            return ['pid' => $pid, 'warnings' => $warnings, 'pending' => false];
        }
        $row = $db->one('SELECT * FROM link_items WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        if ($row['status'] === 'archived') {
            throw new ValidationException(['url' => 'links.error.archived']);
        }
        if ($row['status'] === 'draft' && (int) $row['revision'] === 0) {
            $sets = [];
            $params = [];
            foreach (self::EDITABLE as $f) {
                $sets[] = "{$f} = ?";
                $params[] = $v[$f];
            }
            $db->run('UPDATE link_items SET ' . implode(', ', $sets) . ', host = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...$params, $v['host'], $now, $uid, $row['id']]);
            return ['pid' => $pid, 'warnings' => $warnings, 'pending' => false];
        }
        $db->run('UPDATE link_items SET draft_json = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [json_encode($v, JSON_UNESCAPED_UNICODE), $now, $uid, $row['id']]);
        $this->app->audit()->record('links.edit_pending', 'success', 'link', $pid);
        return ['pid' => $pid, 'warnings' => $warnings, 'pending' => true];
    }

    /**
     * Risk review + publish (7C: purpose, risk review/approval, validation status, change history).
     * The URL is checked again at publish time.
     */
    public function publish(Authorizer $az, string $pid, string $purpose, string $riskNote, bool $confirmed): void
    {
        $this->guard($az);
        $db = $this->app->db();
        $row = $db->one('SELECT * FROM link_items WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $errors = [];
        if (mb_strlen(trim($purpose)) < 5) {
            $errors['purpose'] = 'links.error.purpose';
        }
        if (!$confirmed) {
            $errors['confirm'] = 'links.error.confirm';
        }
        if ($row['status'] === 'archived') {
            $errors['purpose'] = 'links.error.archived';
        }
        $values = $row['draft_json'] !== null ? (array) json_decode((string) $row['draft_json'], true) : null;
        $url = (string) ($values['url'] ?? $row['url']);
        $check = UrlSafety::check($url);
        if ($check['errors'] !== []) {
            $errors['url'] = $check['errors'][0];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $now = $this->now();
        $db->transactional(function () use ($db, $row, $values, $purpose, $riskNote, $az, $now): void {
            $sets = ['status = \'published\'', 'draft_json = NULL', 'purpose = ?', 'risk_note = ?', 'reviewed_by = ?', 'reviewed_at = ?', 'published_at = ?',
                'revision = revision + 1', 'updated_at = ?', 'updated_by = ?', 'row_version = row_version + 1'];
            $params = [mb_substr(trim($purpose), 0, 300), mb_substr(trim($riskNote), 0, 500) ?: null, $az->userId, $now, $now, $now, $az->userId];
            if ($values !== null) {
                foreach (self::EDITABLE as $f) {
                    $sets[] = "{$f} = ?";
                    $params[] = $values[$f] ?? $row[$f];
                }
                $sets[] = 'host = ?';
                $params[] = $values['host'] ?? $row['host'];
            }
            $db->run('UPDATE link_items SET ' . implode(', ', $sets) . ' WHERE id = ?', [...$params, $row['id']]);
            $this->revision((int) $row['id'], 'published', $az->userId, $purpose);
        });
        $this->app->audit()->record('links.published', 'success', 'link', $pid, null, mb_substr($purpose, 0, 200));
    }

    /** published → disabled, disabled → published (the reviewed values), any → archived (final). */
    public function setStatus(Authorizer $az, string $pid, string $to, string $reason): void
    {
        $this->guard($az);
        $db = $this->app->db();
        $row = $db->one('SELECT * FROM link_items WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $allowed = ['disabled' => ['published'], 'published' => ['disabled'], 'archived' => ['draft', 'published', 'disabled']];
        if (!in_array($row['status'], $allowed[$to] ?? [], true)) {
            throw new ValidationException(['status' => 'links.error.transition']);
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new ValidationException(['reason' => 'links.error.reason']);
        }
        $db->run('UPDATE link_items SET status = ?, updated_at = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [$to, $this->now(), $az->userId, $row['id']]);
        $this->revision((int) $row['id'], $to, $az->userId, $reason);
        $this->app->audit()->record('links.' . $to, 'success', 'link', $pid, null, mb_substr($reason, 0, 200));
    }

    public function discardPending(Authorizer $az, string $pid): void
    {
        $this->guard($az);
        $this->app->db()->run('UPDATE link_items SET draft_json = NULL, updated_at = ?, updated_by = ? WHERE public_id = ?', [$this->now(), $az->userId, $pid]);
    }

    private function revision(int $linkId, string $action, int $userId, string $reason): void
    {
        $db = $this->app->db();
        $row = $db->one('SELECT name_th, name_en, description, url, category, icon, open_new_tab, audience_roles, legal_entity_id, purpose, risk_note, status, revision FROM link_items WHERE id = ?', [$linkId]);
        $db->run(
            'INSERT INTO link_revisions (link_id, revision, action, snapshot_json, reason, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$linkId, (int) ($row['revision'] ?? 0), $action, json_encode($row, JSON_UNESCAPED_UNICODE), mb_substr(trim($reason), 0, 300) ?: null, $userId, $this->now()]
        );
    }

    private function guard(Authorizer $az): void
    {
        if (!$az->can('links.manage')) {
            throw HttpException::forbidden();
        }
    }

    private function now(): string
    {
        return $this->app->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function day(string $modify = 'now'): string
    {
        return $this->app->clock->now()->setTimezone(new \DateTimeZone($this->app->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok')))->modify($modify)->format('Y-m-d');
    }
}
