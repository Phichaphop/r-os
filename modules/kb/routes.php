<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Kb\KbController;

/** M55 routes — read, search, feedback, ask, suggestions for the help desk form · authors: articles, revisions, questions · settings. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new KbController($app);
    $r->get('/kb', [$c, 'index'], 'kb.index');
    $r->post('/kb/ask', [$c, 'ask']);
    $r->get('/kb/suggest', [$c, 'suggest'], 'kb.suggest');
    $r->get('/kb/a/' . $id, [$c, 'article']);
    $r->post('/kb/a/' . $id . '/feedback', [$c, 'feedback']);
    $r->get('/kb/a/' . $id . '/f/{fid:[0-9]{1,12}}', [$c, 'file']);
    $r->get('/kb/manage', [$c, 'manage'], 'kb.manage');
    $r->get('/kb/manage/new', [$c, 'newForm']);
    $r->post('/kb/manage/new', [$c, 'create']);
    $r->get('/kb/manage/a/' . $id, [$c, 'manageArticle']);
    $r->post('/kb/manage/a/' . $id . '/{action:edit|submit|approve|return|withdraw|owner|attach}', [$c, 'articlePost']);
    $r->post('/kb/manage/q/' . $id . '/decline', [$c, 'decline']);
    $r->get('/kb/settings', [$c, 'settings'], 'kb.settings');
    $r->post('/kb/settings/category', [$c, 'categoryPost']);
    $r->post('/kb/settings/category/' . $id, [$c, 'categoryPost']);
    $r->post('/kb/settings/synonyms', [$c, 'synonymsPost']);
};
