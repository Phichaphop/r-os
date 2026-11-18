<?php

declare(strict_types=1);

namespace Blue\Modules\Announcements;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;

/** M19 on the home page: pinned + unread announcements as tiles, and the login pop-up (once per person). */
final class AnnouncementHome
{
    /** @return array<string, mixed>|null */
    public static function section(App $app, Authorizer $az): ?array
    {
        if (!$az->can('announcements.read')) {
            return null;
        }
        $h = AnnouncementService::of($app)->forHome($az);
        if ($h['items'] === [] && $h['popup'] === null) {
            return null;
        }
        $t = $app->translator();
        $items = [];
        foreach ($h['items'] as $p) {
            $items[] = ['href' => '/announcements/p/' . $p['public_id'], 'label' => (string) $p['title'], 'summary' => mb_substr(preg_replace('/\s+/', ' ', (string) $p['body']) ?? '', 0, 90),
                'icon' => (int) $p['pinned'] === 1 ? 'bell' : 'info-circle', 'new_tab' => false];
        }
        $out = ['key' => 'announcements', 'title' => $t->get('announcements.home_title'), 'items' => $items, 'more_href' => '/announcements'];
        if ($h['popup'] !== null) {
            $p = $h['popup'];
            $out['popup'] = ['title' => (string) $p['title'], 'text' => mb_substr((string) $p['body'], 0, 400), 'href' => '/announcements/p/' . $p['public_id'], 'seconds' => (int) $p['popup_seconds'],
                'label' => $t->get('announcements.read_more'), 'close' => $t->get('announcements.close'), 'kicker' => $t->get('announcements.priority.' . $p['priority'])];
        }
        return $out;
    }
}
