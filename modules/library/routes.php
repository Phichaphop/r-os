<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Library\LibraryController;

/** M03 routes — search, item, reader, file, page text, bookmarks, reservations; librarians: items, files, copies, loans, desk, categories. */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new LibraryController($app);
    $r->get('/library', [$c, 'index'], 'library.index');
    $r->get('/library/loans', [$c, 'loans'], 'library.loans');
    $r->post('/library/l/' . $id . '/cancel', [$c, 'cancelMine']);
    $r->get('/library/i/' . $id, [$c, 'item']);
    $r->get('/library/i/' . $id . '/read', [$c, 'read']);
    $r->get('/library/i/' . $id . '/{mode:view|download}', [$c, 'file']);
    $r->get('/library/i/' . $id . '/text', [$c, 'text']);
    $r->post('/library/i/' . $id . '/{action:progress|bookmark|unbookmark|reserve}', [$c, 'itemPost']);
    $r->get('/library/manage', [$c, 'manageList'], 'library.manage');
    $r->get('/library/manage/new', [$c, 'newForm']);
    $r->post('/library/manage/new', [$c, 'create']);
    $r->post('/library/manage/category', [$c, 'categoryPost']);
    $r->post('/library/manage/category/' . $id, [$c, 'categoryPost']);
    $r->get('/library/desk', [$c, 'desk'], 'library.desk');
    $r->get('/library/m/' . $id, [$c, 'manage']);
    $r->get('/library/m/' . $id . '/v/{no:[0-9]{1,6}}', [$c, 'version']);
    $r->post('/library/m/' . $id . '/{action:save|upload|publish|archive|restore|copy|lend|return}', [$c, 'managePost']);
    $r->post('/library/copy/' . $id . '/withdraw', [$c, 'copyPost']);
    $r->post('/library/loan/' . $id . '/{action:lost|cancel}', [$c, 'loanPost']);
};
