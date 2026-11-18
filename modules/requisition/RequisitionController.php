<?php

declare(strict_types=1);

namespace Blue\Modules\Requisition;

use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Error\ValidationException;
use Blue\Core\Files\UploadedFile;
use Blue\Core\Http\Controller;
use Blue\Core\Http\Request;
use Blue\Core\Http\Response;
use Blue\Core\Records\DocumentsController;
use Blue\Core\Support\Barcode\Code128;
use Blue\Core\Support\Qr\QrEncoder;
use Blue\Core\Support\Qr\QrRender;

/** Office supply screens (M01 · 9B-2). */
final class RequisitionController extends Controller
{
    private function svc(): RequisitionService
    {
        return RequisitionService::of($this->app);
    }

    private function t(string $k): string
    {
        return $this->app->translator()->get($k);
    }

    /** @return array{0: array<string, string>, 1: string, 2: int} */
    private function company(Request $r, string $perm = 'requisition.use', ?string $want = null): array
    {
        $ids = $this->svc()->companies($this->ctx($r)->az(), $perm);
        if ($ids === []) {
            throw HttpException::forbidden();
        }
        $companies = [];
        foreach ($this->app->db()->all('SELECT public_id, code FROM legal_entities WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY code') as $row) {
            $companies[(string) $row['public_id']] = (string) $row['code'];
        }
        $pid = $want ?? (string) ($r->query['le'] ?? $r->body['le'] ?? '');
        if (!isset($companies[$pid])) {
            $pid = (string) array_key_first($companies);
        }
        return [$companies, $pid, (int) $this->app->db()->scalar('SELECT id FROM legal_entities WHERE public_id = ?', [$pid])];
    }

    private function file(Request $r, string $name): ?UploadedFile
    {
        $f = ($r->files[$name] ?? [])[0] ?? null;
        return $f instanceof UploadedFile && $f->error !== UPLOAD_ERR_NO_FILE ? $f : null;
    }

    private function back(Request $r, string $to, BusinessException $e): Response
    {
        if ($e instanceof HttpException) {
            throw $e;
        }
        $this->flash($r, 'danger', $e->messageKey, $e->params);
        return $this->redirect($r, $to);
    }

    private function fresh(Response $res): Response
    {
        return $res->withHeader('Cache-Control', 'no-store, max-age=0');
    }

    private function origin(Request $r): string
    {
        $configured = rtrim($this->app->config->string('APP_URL'), '/');
        return $configured !== '' ? $configured : ($r->secure ? 'https' : 'http') . '://' . $r->host . $r->basePath();
    }

    /** @return array<string, bool> which section links the person sees */
    private function roles(Request $r): array
    {
        $az = $this->ctx($r)->az();
        return ['canIssue' => $this->svc()->companies($az, 'requisition.issue') !== [], 'canManage' => $this->svc()->companies($az, 'requisition.manage') !== []];
    }

    /**
     * The site whose stock the catalogue shows: ?site=, else my cart's site, else my own site, else the first.
     *
     * @param ?array<string, mixed> $cart
     */
    private function site(Request $r, int $le, ?array $cart): int
    {
        $sites = $this->svc()->sites($le);
        $want = (string) ($r->query['site'] ?? '');
        foreach ($sites as $s) {
            if ($s['public_id'] === $want) {
                return (int) $s['id'];
            }
        }
        if ($cart !== null) {
            return (int) $cart['site_id'];
        }
        $mine = $this->svc()->job($this->ctx($r)->az()->userId)['site'];
        foreach ($sites as $s) {
            if ((int) $s['id'] === $mine) {
                return $mine;
            }
        }
        return (int) ($sites[0]['id'] ?? 0);
    }

    // ---- catalogue and cart ---------------------------------------------------------------------------------------------

    public function catalog(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        $cart = $this->svc()->cart($az, $le);
        $site = $this->site($r, $le, $cart);
        $f = ['q' => mb_substr(trim((string) ($r->query['q'] ?? '')), 0, 60), 'category' => (string) ($r->query['category'] ?? ''), 'fav' => (string) ($r->query['fav'] ?? '')];
        $items = $this->svc()->items($le, $site, $f, $az->userId);
        $job = $this->svc()->job($az->userId);
        return $this->fresh($this->page('@requisition/catalog', ['title' => $this->t('requisition.title'), 'companies' => $companies, 'lePid' => $lePid, 'sites' => $this->svc()->sites($le), 'siteId' => $site,
            'categories' => $this->svc()->categories($le, true), 'items' => $items, 'f' => $f, 'cart' => $cart, 'closed' => $this->svc()->closedReason($le),
            'quota' => $this->svc()->quotaLeft($job['department'], array_map(static fn (array $i): int => (int) $i['id'], $items)), 'here' => '/requisition'] + $this->roles($r)));
    }

    public function add(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r);
        $back = '/requisition?le=' . rawurlencode($lePid) . (isset($r->body['site']) ? '&site=' . rawurlencode((string) $r->body['site']) : '');
        try {
            $this->svc()->addToCart($this->ctx($r)->az(), $le, $this->str($r, 'item', 26), $this->str($r, 'qty', 6), $this->str($r, 'site', 26));
        } catch (ValidationException $e) {
            $this->flash($r, 'danger', (string) (array_values($e->errors)[0] ?? 'requisition.error.qty'));
            return $this->redirect($r, $back);
        } catch (BusinessException $e) {
            return $this->back($r, $back, $e);
        }
        $this->flash($r, 'success', 'requisition.flash.added');
        return $this->redirect($r, $back);
    }

    public function favorite(Request $r): Response
    {
        $on = $this->svc()->toggleFavorite($this->ctx($r)->az(), (string) $r->params['id']);
        $this->flash($r, 'success', $on ? 'requisition.flash.fav_on' : 'requisition.flash.fav_off');
        $back = (string) ($r->body['back'] ?? '');
        return $this->redirect($r, str_starts_with($back, '/requisition') && !str_starts_with($back, '//') ? $back : '/requisition');
    }

    public function photo(Request $r): Response
    {
        $p = $this->svc()->photo($this->ctx($r)->az(), (string) $r->params['id']);
        return DocumentsController::fileResponse($p['bytes'], $p['mime'], 'item', true)->withHeader('Cache-Control', 'private, max-age=3600');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function cart(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        [$companies, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        $cart = $this->svc()->cart($az, $le);
        $job = $this->svc()->job($az->userId);
        return $this->fresh($this->page('@requisition/cart', ['title' => $this->t('requisition.cart_title'), 'companies' => $companies, 'lePid' => $lePid, 'cart' => $cart, 'sites' => $this->svc()->sites($le),
            'closed' => $this->svc()->closedReason($le), 'limit' => $this->svc()->limitReached($le, $az->userId, (int) ($cart['id'] ?? 0)), 'policy' => $this->svc()->policy($le),
            'quota' => $cart === null ? [] : $this->svc()->quotaLeft($job['department'], array_map(static fn (array $l): int => (int) $l['item_id'], $cart['lines'])),
            'errors' => $errors, 'old' => $old, 'here' => '/requisition/cart'] + $this->roles($r), $status));
    }

    public function cartPost(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r);
        $az = $this->ctx($r)->az();
        $cart = $this->svc()->cart($az, $le) ?? throw HttpException::notFound();
        $pid = (string) $cart['public_id'];
        try {
            $this->svc()->updateCart($az, $pid, $r->body);
            if ((string) $r->params['action'] === 'submit') {
                $res = $this->svc()->submit($az, $pid);
                $this->flash($r, $res['over_quota'] ? 'warning' : 'success', $res['special'] ? 'requisition.flash.sent_special' : ($res['over_quota'] ? 'requisition.flash.sent_over' : 'requisition.flash.sent'));
                return $this->redirect($r, '/requisition/r/' . $pid);
            }
        } catch (ValidationException $e) {
            return $this->cart($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->back($r, '/requisition/cart?le=' . rawurlencode($lePid), $e);
        }
        $this->flash($r, 'success', 'requisition.flash.saved_cart');
        return $this->redirect($r, '/requisition/cart?le=' . rawurlencode($lePid));
    }

    public function mine(Request $r): Response
    {
        $az = $this->ctx($r)->az();
        return $this->fresh($this->page('@requisition/mine', ['title' => $this->t('requisition.mine_title'), 'rows' => $this->svc()->mine($az), 'here' => '/requisition/mine'] + $this->roles($r)));
    }

    // ---- one request ------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function request(Request $r, array $errors = [], array $old = [], int $status = 200): Response
    {
        $rq = $this->svc()->request($this->ctx($r)->az(), (string) $r->params['id']);
        $qr = null;
        $link = null;
        $pickup = false;
        $barcode = null;
        if ($rq['status'] !== 'draft' && $rq['ref'] !== null) {
            // 0.38.0 (10.1): every submitted request carries a QR + a Code 128 barcode of its number — the desk scans
            // instead of typing; the requester's approved request carries the pick-up code in the QR (proof of holding it)
            $pickup = $rq['mine'] && $rq['status'] === 'approved' && $rq['pickup_code'] !== null;
            $link = rtrim($this->origin($r), '/') . '/requisition/issue?code=' . rawurlencode($pickup ? (string) $rq['pickup_code'] : (string) $rq['ref']);
            $qr = QrRender::svg(QrEncoder::encode($link, 'M')['modules'], 6, null, (string) $rq['ref']);
            $barcode = Code128::svg((string) $rq['ref'], 2, 56, true, $this->app->translator()->get('requisition.barcode_alt', ['ref' => (string) $rq['ref']]));
        }
        return $this->fresh($this->page('@requisition/request', ['title' => (string) ($rq['ref'] ?? $this->t('requisition.cart_title')), 'rq' => $rq, 'qr' => $qr, 'qrLink' => $link,
            'pickup' => $pickup, 'barcode' => $barcode,
            'errors' => $errors, 'old' => $old, 'here' => ''] + $this->roles($r), $status));
    }

    public function act(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $az = $this->ctx($r)->az();
        $action = (string) $r->params['action'];
        $s = $this->svc();
        try {
            if ($action === 'cancel') {
                $s->cancel($az, $pid, $this->str($r, 'cancel_reason', 500));
            } elseif ($action === 'receive') {
                $s->confirmReceipt($az, $pid);
            } elseif ($action === 'repeat') {
                $s->repeat($az, $pid);
                $this->flash($r, 'success', 'requisition.flash.repeat');
                return $this->redirect($r, '/requisition/cart');
            } elseif ($action === 'issue') {
                $status = $s->issue($az, $pid, (array) ($r->body['qty'] ?? []), $this->str($r, 'issue_note', 500));
                $this->flash($r, 'success', 'requisition.flash.' . $status);
                return $this->redirect($r, '/requisition/issue');
            } else {
                $s->refuse($az, $pid, $this->str($r, 'refuse_reason', 500));
                $this->flash($r, 'success', 'requisition.flash.refused');
                return $this->redirect($r, '/requisition/issue');
            }
        } catch (ValidationException $e) {
            if (in_array($action, ['issue', 'refuse'], true)) {
                return $this->desk($r, $this->errors($e), $r->body, 422, $pid);
            }
            return $this->request($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->back($r, in_array($action, ['issue', 'refuse'], true) ? '/requisition/issue' : '/requisition/r/' . $pid, $e);
        }
        $this->flash($r, 'success', 'requisition.flash.' . $action);
        return $this->redirect($r, '/requisition/r/' . $pid);
    }

    // ---- issue desk --------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function desk(Request $r, array $errors = [], array $old = [], int $status = 200, ?string $pid = null): Response
    {
        $az = $this->ctx($r)->az();
        if ($this->svc()->companies($az, 'requisition.issue') === []) {
            throw HttpException::forbidden();
        }
        $code = mb_substr(trim((string) ($r->query['code'] ?? '')), 0, 200);
        $found = $pid ?? ($code !== '' ? $this->svc()->find($az, $code) : null);
        $rq = $found !== null ? $this->svc()->request($az, $found) : null;
        return $this->fresh($this->page('@requisition/issue', ['title' => $this->t('requisition.issue_title'), 'code' => $code, 'rq' => $rq, 'notFound' => $code !== '' && $found === null,
            'waiting' => $this->svc()->waiting($az), 'errors' => $errors, 'old' => $old, 'here' => '/requisition/issue'] + $this->roles($r), $status));
    }

    // ---- administration ------------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function admin(Request $r, array $errors = [], array $old = [], string $form = '', int $status = 200, ?string $want = null): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'requisition.manage', $want);
        $az = $this->ctx($r)->az();
        $depts = [];
        foreach ($this->app->db()->all("SELECT id, public_id, code FROM departments WHERE legal_entity_id = ? AND status = 'active' ORDER BY code", [$le]) as $d) {
            $depts[(string) $d['public_id']] = (string) ($this->app->org()->scopeOptions($az->reachableScope(), $this->app->translator()->locale())['department'][(int) $d['id']] ?? $d['code']);
        }
        $edit = (string) ($r->query['item'] ?? $old['_item'] ?? '');
        return $this->page('@requisition/admin', ['title' => $this->t('requisition.admin_title'), 'companies' => $companies, 'lePid' => $lePid, 'categories' => $this->svc()->categories($le),
            'items' => $this->svc()->items($le, null, [], null, false), 'edit' => $edit !== '' ? $this->svc()->item($edit) : null, 'policy' => $this->svc()->policy($le), 'quotas' => $this->svc()->quotas($le),
            'depts' => $depts, 'today' => $this->svc()->today(), 'errors' => $errors, 'old' => $old, 'form' => $form, 'here' => '/requisition/admin'] + $this->roles($r), $status);
    }

    public function adminPost(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r, 'requisition.manage');
        $az = $this->ctx($r)->az();
        $what = (string) $r->params['what'];
        try {
            match ($what) {
                'category' => $this->svc()->saveCategory($az, $le, $r->body),
                'item' => $this->svc()->saveItem($az, $le, $r->body, $this->file($r, 'photo')),
                'quota' => $this->svc()->saveQuota($az, $le, $r->body),
                default => $this->svc()->savePolicy($az, $le, $r->body),
            };
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $r->body, $what, 422);
        }
        $this->flash($r, 'success', 'requisition.flash.saved_' . $what);
        return $this->redirect($r, '/requisition/admin?le=' . rawurlencode($lePid) . '#ra-' . $what);
    }

    public function categoryPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $c = $this->app->db()->one('SELECT legal_entity_id FROM rq_categories WHERE public_id = ?', [$pid]) ?? throw HttpException::notFound();
        $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$c['legal_entity_id']]);
        try {
            $this->svc()->saveCategory($this->ctx($r)->az(), (int) $c['legal_entity_id'], $r->body, $pid);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $r->body, 'cat-' . $pid, 422, $lePid);
        }
        $this->flash($r, 'success', 'requisition.flash.saved_category');
        return $this->redirect($r, '/requisition/admin?le=' . rawurlencode($lePid) . '#ra-category');
    }

    public function itemPost(Request $r): Response
    {
        $pid = (string) $r->params['id'];
        $it = $this->svc()->item($pid);
        $lePid = (string) $this->app->db()->scalar('SELECT public_id FROM legal_entities WHERE id = ?', [$it['legal_entity_id']]);
        try {
            $this->svc()->saveItem($this->ctx($r)->az(), (int) $it['legal_entity_id'], $r->body, $this->file($r, 'photo'), $pid);
        } catch (ValidationException $e) {
            return $this->admin($r, $this->errors($e), $r->body + ['_item' => $pid], 'item', 422, $lePid);
        }
        $this->flash($r, 'success', 'requisition.flash.saved_item');
        return $this->redirect($r, '/requisition/admin?le=' . rawurlencode($lePid) . '#ra-items');
    }

    public function quotaEnd(Request $r): Response
    {
        $this->svc()->endQuota($this->ctx($r)->az(), (string) $r->params['id']);
        $this->flash($r, 'success', 'requisition.flash.quota_ended');
        return $this->redirect($r, '/requisition/admin#ra-quota');
    }

    // ---- stock and report ------------------------------------------------------------------------------------------------------

    private function siteOf(Request $r, int $le): ?int
    {
        $want = (string) ($r->query['site'] ?? $r->body['site'] ?? '');
        foreach ($this->svc()->sites($le) as $s) {
            if ($s['public_id'] === $want) {
                return (int) $s['id'];
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     * @param list<string> $importErrors
     */
    public function stock(Request $r, array $errors = [], array $old = [], int $status = 200, array $importErrors = []): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'requisition.manage');
        $sites = $this->svc()->sites($le);
        $site = $this->siteOf($r, $le) ?? (int) ($sites[0]['id'] ?? 0);
        $sitePid = '';
        foreach ($sites as $s) {
            if ((int) $s['id'] === $site) {
                $sitePid = (string) $s['public_id'];
            }
        }
        return $this->page('@requisition/stock', ['title' => $this->t('requisition.stock_title'), 'companies' => $companies, 'lePid' => $lePid, 'sites' => $sites, 'siteId' => $site, 'sitePid' => $sitePid,
            'items' => $this->svc()->items($le, $site, [], null, false), 'ledger' => $this->svc()->ledger($le, $site, null, 30), 'errors' => $errors, 'old' => $old, 'importErrors' => $importErrors,
            'here' => '/requisition/stock'] + $this->roles($r), $status);
    }

    public function stockPost(Request $r): Response
    {
        [, $lePid, $le] = $this->company($r, 'requisition.manage');
        $az = $this->ctx($r)->az();
        $sitePid = $this->str($r, 'site', 26);
        $back = '/requisition/stock?le=' . rawurlencode($lePid) . '&site=' . rawurlencode($sitePid);
        try {
            if ((string) $r->params['what'] === 'adjust') {
                $kind = $this->str($r, 'kind', 10) === 'count' ? 'count' : 'receive';
                $this->svc()->adjust($az, $le, $sitePid, $this->str($r, 'item', 40), $kind, $this->str($r, 'qty', 8), $this->str($r, 'note', 300));
                $this->flash($r, 'success', $kind === 'count' ? 'requisition.flash.count' : 'requisition.flash.receive_stock');
            } else {
                $res = $this->svc()->import($az, $le, $sitePid, $this->str($r, 'import_kind', 10) === 'count' ? 'count' : 'receive', $this->file($r, 'file'));
                if ($res['errors'] !== []) {
                    return $this->stock($r, ['file' => 'requisition.error.import_rows'], $r->body, 422, $res['errors']);
                }
                $this->flash($r, 'success', 'requisition.flash.imported', ['n' => (string) $res['done']]);
            }
        } catch (ValidationException $e) {
            return $this->stock($r, $this->errors($e), $r->body, 422);
        } catch (BusinessException $e) {
            return $this->back($r, $back, $e);
        }
        return $this->redirect($r, $back);
    }

    private function month(Request $r): string
    {
        $m = (string) ($r->query['month'] ?? '');
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1 ? $m : $this->svc()->nowLocal()->format('Y-m');
    }

    public function report(Request $r): Response
    {
        [$companies, $lePid, $le] = $this->company($r, 'requisition.manage');
        $month = $this->month($r);
        $site = $this->siteOf($r, $le);
        return $this->page('@requisition/report', ['title' => $this->t('requisition.report_title'), 'companies' => $companies, 'lePid' => $lePid, 'month' => $month, 'sites' => $this->svc()->sites($le),
            'siteId' => $site, 'report' => $this->svc()->report($this->ctx($r)->az(), $le, $site, $month), 'here' => '/requisition/report'] + $this->roles($r));
    }

    public function export(Request $r): Response
    {
        [, , $le] = $this->company($r, 'requisition.manage');
        $f = $this->svc()->export($this->ctx($r)->az(), $le, $this->siteOf($r, $le), $this->month($r));
        return DocumentsController::fileResponse($f['bytes'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $f['name'], false)->withHeader('Cache-Control', 'no-store, max-age=0');
    }
}
