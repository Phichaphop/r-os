<?php

declare(strict_types=1);

namespace Blue\Core\Search;

use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;

/** Global search page (topbar search box, Ctrl+K). Results are filtered by rights before they are looked up. */
final class SearchController extends Controller
{
    public function index(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $q = $this->str($r, 'q', 100);
        $shell = (array) $this->app->view()->global('shell', []);
        $menu = [];
        foreach ((array) ($shell['groups'] ?? []) as $g) {
            foreach ((array) $g['items'] as $i) {
                $menu[] = ['label' => (string) $i['label'], 'href' => (string) $i['href'], 'icon' => (string) $i['icon']];
            }
        }
        $this->app->view()->share('search_q', $q);
        $results = $this->app->search()->search($ctx->az(), $q, $menu, $this->app->translator()->locale());
        return $this->page('search/index', [
            'title' => $this->app->translator()->get('search.title'), 'q' => $q, 'results' => $results,
            'tooShort' => $q !== '' && mb_strlen($q) < SearchService::MIN_LENGTH,
        ]);
    }
}
