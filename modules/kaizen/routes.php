<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Http\Router;
use Blue\Modules\Kaizen\KaizenController;

/** M41 routes — ideas (send, track, board, vote, handle, report, criteria) and surveys (answer, manage, results). */

return static function (Router $r, App $app): void {
    $id = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $c = new KaizenController($app);
    $r->get('/kaizen', [$c, 'index'], 'kaizen.index');
    $r->post('/kaizen', [$c, 'submit']);
    $r->get('/kaizen/track', [$c, 'track'], 'kaizen.track');
    $r->post('/kaizen/track', [$c, 'trackPost']);
    $r->get('/kaizen/i/' . $id, [$c, 'idea']);
    $r->get('/kaizen/i/' . $id . '/photo', [$c, 'photo']);
    $r->post('/kaizen/i/' . $id . '/vote', [$c, 'vote']);
    $r->post('/kaizen/i/' . $id . '/{action:screen|score|accept|reject|start|result|verify|close|reward|reward_ok|reward_no|note}', [$c, 'act']);
    $r->get('/kaizen/admin', [$c, 'admin'], 'kaizen.admin');
    $r->get('/kaizen/report', [$c, 'report'], 'kaizen.report');
    $r->get('/kaizen/settings', [$c, 'settings'], 'kaizen.settings');
    $r->post('/kaizen/settings', [$c, 'criterionPost']);
    $r->post('/kaizen/settings/' . $id, [$c, 'criterionPost']);
    $r->get('/kaizen/surveys', [$c, 'surveys'], 'kaizen.surveys');
    $r->get('/kaizen/surveys/s/' . $id, [$c, 'survey']);
    $r->post('/kaizen/surveys/s/' . $id, [$c, 'answer']);
    $r->get('/kaizen/surveys/admin', [$c, 'surveyAdmin'], 'kaizen.survey_admin');
    $r->post('/kaizen/surveys/admin', [$c, 'surveyCreate']);
    $r->get('/kaizen/surveys/m/' . $id, [$c, 'surveyManage']);
    $r->post('/kaizen/surveys/m/' . $id . '/{what:edit|question|open|close|analyze}', [$c, 'surveyPost']);
    $r->get('/kaizen/surveys/m/' . $id . '/results', [$c, 'results']);
    $r->get('/kaizen/surveys/m/' . $id . '/export', [$c, 'export']);
};
