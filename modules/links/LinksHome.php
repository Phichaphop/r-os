<?php

declare(strict_types=1);

namespace Blue\Modules\Links;

use Blue\Core\App;
use Blue\Core\Authz\Authorizer;

/** M31 on the home page: "External links" — the published links this person may see, grouped by category. */
final class LinksHome
{
    /** Cards shown on the home page before "see all". */
    public const LIMIT = 12;

    /**
     * Shown on Home as an app group like "Tools" (0.6.2): one tile per link, through the counted redirect.
     *
     * @return array{key: string, title: string, items: list<array{href: string, label: string, summary: string, icon: string, new_tab: bool}>, empty?: string, more_href?: string}|null
     */
    public static function section(App $app, Authorizer $az): ?array
    {
        if (!$az->can('links.use')) {
            return null;
        }
        $rows = LinksService::of($app)->visible($az);
        if ($rows === [] && !$az->can('links.manage')) {
            return null;
        }
        $t = $app->translator();
        $en = $t->locale() === 'en';
        $items = [];
        foreach (array_slice($rows, 0, self::LIMIT) as $l) {
            $items[] = [
                'href' => '/links/go/' . $l['public_id'],
                'label' => $en && !empty($l['name_en']) ? (string) $l['name_en'] : (string) $l['name_th'],
                'summary' => !empty($l['description']) ? (string) $l['description'] : (string) $l['host'],
                'icon' => (string) $l['icon'],
                'new_tab' => (int) $l['open_new_tab'] === 1,
            ];
        }
        $out = ['key' => 'links', 'title' => $t->get('links.home_title'), 'items' => $items];
        if ($rows === []) {
            $out['empty'] = $t->get('links.home_empty_admin');
        }
        if (count($rows) > self::LIMIT) {
            $out['more_href'] = '/links';
        }
        return $out;
    }
}
