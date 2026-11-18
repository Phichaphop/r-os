<?php

declare(strict_types=1);

namespace Blue\Core\Notify;

use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Ui\Paging;

/** In-app notifications (6.12 bell): list, open (marks read, then goes to the target which re-checks access), mark all read. */
final class NotificationsController extends Controller
{
    public function index(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $paging = Paging::from($r, $this->app->preferences(), $ctx->userId(), 'notifications');
        $n = $this->app->notifier();
        $list = $n->list($ctx->userId(), $paging->page, $paging->per);
        if ($paging->clamp($list['total'])) {
            $list = $n->list($ctx->userId(), $paging->page, $paging->per);
        }
        return $this->page('notifications/index', [
            'title' => $this->app->translator()->get('notify.title'),
            'list' => $list, 'page' => $paging->page, 'per' => $paging->per,
        ]);
    }

    public function open(Request $r): Response
    {
        $link = $this->app->notifier()->markRead($this->ctx($r)->userId(), (string) $r->params['id']);
        return $this->redirect($r, $this->safeNext($link ?? '/notifications'));
    }

    public function readAll(Request $r): Response
    {
        $this->app->notifier()->markRead($this->ctx($r)->userId());
        $this->flash($r, 'success', 'notify.all_read');
        return $this->redirect($r, '/notifications');
    }
}
