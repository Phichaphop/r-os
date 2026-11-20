<?php

declare(strict_types=1);

use Blue\Core\App;
use Blue\Core\Audit\AuditController;
use Blue\Core\Auth\AuthController;
use Blue\Core\Auth\ProfileController;
use Blue\Core\Auth\SecurityController;
use Blue\Core\Authz\UsersController;
use Blue\Core\Http\Router;
use Blue\Core\Org\OrgController;
use Blue\Core\System\InstallController;
use Blue\Core\System\SystemController;
use Blue\Core\System\UpdateController;
use Blue\Core\Notify\NotificationsController;
use Blue\Core\Registry\ConfigController;
use Blue\Core\Registry\SettingsController;
use Blue\Core\Workflow\ApprovalsController;
use Blue\Core\Workflow\WorkflowAdminController;
use Blue\Core\Calendar\CalendarController;
use Blue\Core\Import\ImportController;
use Blue\Core\Records\DocumentsController;
use Blue\Core\Records\RecordsController;
use Blue\Core\Records\TemplatesController;
use Blue\Core\Search\SearchController;
use Blue\Core\Backup\BackupController;
use Blue\Core\Finance\FinanceController;
use Blue\Core\Integrations\IntegrationsController;
use Blue\Core\Payments\PaymentsController;
use Blue\Core\Privacy\PrivacyController;
use Blue\Core\Modules\ModulesController;

/**
 * Core routes. Module routes are registered from each module's manifest (module registry, phase 3).
 * Access: everything requires sign-in except the paths listed in Authenticate::PUBLIC;
 * each action then checks its permission + data scope.
 */

return static function (Router $r, App $app): void {
    $ulid = '{id:[0-9A-HJKMNP-TV-Z]{26}}';
    $system = new SystemController($app);
    $install = new InstallController($app);
    $auth = new AuthController($app);
    $me = new ProfileController($app);
    $users = new UsersController($app);
    $org = new OrgController($app);
    $audit = new AuditController($app);
    $security = new SecurityController($app);
    $settings = new SettingsController($app);
    $config = new ConfigController($app);
    $approvals = new ApprovalsController($app);
    $workflows = new WorkflowAdminController($app);
    $notifications = new NotificationsController($app);
    $update = new UpdateController($app);
    $documents = new DocumentsController($app);
    $templates = new TemplatesController($app);
    $records = new RecordsController($app);
    $calendars = new CalendarController($app);
    $imports = new ImportController($app);
    $search = new SearchController($app);

    $r->get('/', [$system, 'home'], 'home');
    $r->get('/health', [$system, 'health'], 'health');
    $r->get('/system/status', [$system, 'status'], 'system.status');
    $r->get('/system/ui-showcase', [$system, 'showcase'], 'system.showcase');
    $r->get('/system/ui-showcase/error-demo', [$system, 'errorDemo'], 'system.showcase.error');
    $r->get('/system/mailbox', [$system, 'mailbox'], 'system.mailbox');

    $r->get('/install', [$install, 'show'], 'install');
    $r->post('/install', [$install, 'submit'], 'install.submit');

    // Authentication (P0-04 §3)
    $r->get('/login', [$auth, 'loginForm'], 'login');
    $r->post('/login', [$auth, 'login']);
    $r->post('/logout', [$auth, 'logout'], 'logout');
    $r->get('/activate/{token:[A-Za-z0-9_-]{20,100}}', [$auth, 'linkForm'], 'activate');
    $r->post('/activate/{token:[A-Za-z0-9_-]{20,100}}', [$auth, 'linkSubmit']);
    $r->get('/password/link/{token:[A-Za-z0-9_-]{20,100}}', [$auth, 'linkForm'], 'password.link');
    $r->post('/password/link/{token:[A-Za-z0-9_-]{20,100}}', [$auth, 'linkSubmit']);
    $r->get('/password/forgot', [$auth, 'forgotForm'], 'password.forgot');
    $r->post('/password/forgot', [$auth, 'forgot']);
    $r->get('/password/reset', [$auth, 'resetForm'], 'password.reset');
    $r->post('/password/reset', [$auth, 'reset']);
    $r->get('/mfa/setup', [$auth, 'mfaSetup'], 'mfa.setup');
    $r->post('/mfa/setup', [$auth, 'mfaSetupConfirm']);
    $r->get('/mfa/verify', [$auth, 'mfaVerifyForm'], 'mfa.verify');
    $r->post('/mfa/verify', [$auth, 'mfaVerify']);
    $r->get('/reauth', [$auth, 'reauthForm'], 'reauth');
    $r->post('/reauth', [$auth, 'reauth']);

    // My account
    $r->get('/me', [$me, 'show'], 'me');
    $r->get('/me/password', [$me, 'passwordForm'], 'me.password');
    $r->get('/me/appearance', [$me, 'appearance'], 'me.appearance');
    $r->post('/me/appearance', [$me, 'saveAppearance']);
    $r->get('/me/theme.css', [$me, 'themeCss'], 'me.theme');
    $r->get('/me/theme-preview.css', [$me, 'previewCss']);
    $r->post('/me/password', [$me, 'changePassword']);
    $r->post('/me/sessions/' . $ulid . '/revoke', [$me, 'revokeSession']);
    $r->post('/me/sessions/revoke-others', [$me, 'revokeOthers']);
    $r->post('/context', [$me, 'switchContext'], 'context');

    // Administration — users, roles, scopes (P0-04 §4)
    $r->get('/admin/users', [$users, 'index'], 'admin.users');
    $r->get('/admin/users/new', [$users, 'createForm'], 'admin.users.new');
    $r->post('/admin/users/new', [$users, 'create']);
    $r->get('/admin/users/' . $ulid, [$users, 'show'], 'admin.users.show');
    $r->post('/admin/users/' . $ulid . '/status/{action:suspend|reactivate|unlock|offboard|deactivate}', [$users, 'status']);
    $r->post('/admin/users/' . $ulid . '/reset-link', [$users, 'resetLink']);
    $r->post('/admin/users/' . $ulid . '/mfa-reset', [$users, 'mfaReset']);
    $r->post('/admin/users/' . $ulid . '/grant', [$users, 'grant']);
    $r->post('/admin/assignments/{aid:[0-9A-HJKMNP-TV-Z]{26}}/revoke', [$users, 'revoke']);
    $r->post('/admin/assignments/{aid:[0-9A-HJKMNP-TV-Z]{26}}/{decision:approve|reject}', [$users, 'decide']);
    $r->get('/admin/roles', [$users, 'roles'], 'admin.roles');
    $r->get('/admin/security', [$security, 'show'], 'admin.security');
    $r->post('/admin/security', [$security, 'save']);

    // Organization structure (03A)
    $r->get('/admin/org', [$org, 'index'], 'admin.org');
    $r->get('/admin/org/{kind:legal-entity}/new', [$org, 'form']);
    $r->post('/admin/org/{kind:legal-entity}/new', [$org, 'save']);
    $r->get('/admin/org/{kind:site|department}/' . $ulid . '/new', [$org, 'form']);
    $r->post('/admin/org/{kind:site|department}/' . $ulid . '/new', [$org, 'save']);
    $r->get('/admin/org/{kind:legal-entity|site|department}/' . $ulid, [$org, 'form']);
    $r->post('/admin/org/{kind:legal-entity|site|department}/' . $ulid, [$org, 'save']);

    // Phase 3A — shared services
    $r->get('/theme.css', [$settings, 'themeCss'], 'theme.css');
    $r->get('/admin/settings/appearance', [$settings, 'appearance'], 'admin.appearance');
    $r->get('/admin/settings/guest', [$settings, 'guest'], 'admin.guest');
    $r->post('/admin/settings/guest', [$settings, 'saveGuest']);
    $r->post('/admin/settings/appearance', [$settings, 'save']);
    $r->get('/admin/settings/appearance/preview.css', [$settings, 'previewCss']);
    $r->get('/admin/settings/appearance/check', [$settings, 'previewCheck']);

    $key = '{key:[a-z0-9_.]{3,100}}';
    $r->get('/admin/config', [$config, 'index'], 'admin.config');
    $r->get('/admin/config/key/' . $key, [$config, 'show']);
    $r->post('/admin/config/key/' . $key . '/propose', [$config, 'propose']);
    $r->get('/admin/config/version/' . $ulid, [$config, 'version']);
    $r->post('/admin/config/version/' . $ulid . '/submit', [$config, 'submit']);
    $r->post('/admin/config/version/' . $ulid . '/cancel', [$config, 'cancel']);

    $r->get('/approvals', [$approvals, 'inbox'], 'approvals');
    $r->get('/approvals/delegate', [$approvals, 'delegatePage'], 'approvals.delegate');
    $r->post('/approvals/delegations', [$approvals, 'delegate']);
    $r->post('/approvals/delegations/' . $ulid . '/revoke', [$approvals, 'revokeDelegation']);
    $r->get('/approvals/' . $ulid, [$approvals, 'show']);
    $r->post('/approvals/' . $ulid . '/decide', [$approvals, 'decide']);
    $r->post('/approvals/' . $ulid . '/retry', [$approvals, 'retry']);
    $r->get('/requests', [$approvals, 'mine'], 'requests');
    $r->get('/requests/' . $ulid, [$approvals, 'show']);
    $r->post('/requests/' . $ulid . '/cancel', [$approvals, 'cancel']);
    $r->post('/requests/' . $ulid . '/resubmit', [$approvals, 'resubmit']);
    $r->get('/me/access', [$me, 'accessForm'], 'me.access');
    // phase 5 gate (DEC-82): my data + data-subject requests (P0-04 P-04/P-05)
    $privacy = new PrivacyController($app);
    $r->get('/me/data', [$privacy, 'mine'], 'me.data');
    $r->get('/me/data/export', [$privacy, 'export']);
    $r->post('/me/privacy', [$privacy, 'submit']);
    $r->get('/me/privacy/' . $ulid, [$privacy, 'myRequest']);
    $r->get('/privacy/requests', [$privacy, 'desk'], 'privacy.desk');
    $r->get('/privacy/requests/' . $ulid, [$privacy, 'show']);
    $r->post('/privacy/requests/' . $ulid . '/{action:verify|prepare|approve|close}', [$privacy, 'act']);
    $r->get('/privacy/requests/' . $ulid . '/package', [$privacy, 'package']);
    $r->post('/me/access', [$me, 'accessSubmit']);

    $r->get('/admin/workflows', [$workflows, 'index'], 'admin.workflows');
    $r->post('/admin/workflows/{code:[a-z0-9_.]{3,60}}', [$workflows, 'publish']);

    $r->get('/notifications', [$notifications, 'index'], 'notifications');
    $r->post('/notifications/read-all', [$notifications, 'readAll']);
    $r->get('/notifications/' . $ulid, [$notifications, 'open']);

    $r->get('/system/update', [$update, 'show'], 'system.update');
    $r->post('/system/update', [$update, 'run']);

    // Phase 3B — documents & records, templates, calendars, import/export, search, brand files
    $r->get('/brand/logo', [$settings, 'brandFile'], 'brand.logo');
    $r->get('/brand/favicon', [$settings, 'brandFile'], 'brand.favicon');
    $r->post('/admin/settings/appearance/brand', [$settings, 'brand']);
    $r->post('/admin/settings/font', [$settings, 'font']);
    $r->get('/brand/font/{file:[a-f0-9]{64}\.(?:woff2|woff|ttf|otf)}', [$settings, 'fontFile']);

    $r->get('/documents', [$documents, 'index'], 'documents');
    $r->get('/documents/export', [$documents, 'export']);
    $r->get('/documents/new', [$documents, 'createForm']);
    $r->post('/documents/new', [$documents, 'create']);
    $r->get('/documents/' . $ulid, [$documents, 'show']);
    $r->get('/documents/' . $ulid . '/download', [$documents, 'download']);
    $r->get('/documents/' . $ulid . '/view', [$documents, 'view']);
    $r->post('/documents/' . $ulid . '/version', [$documents, 'newVersion']);
    $r->post('/documents/' . $ulid . '/finalize', [$documents, 'finalize']);
    $r->post('/documents/' . $ulid . '/supersede', [$documents, 'supersede']);
    $r->post('/documents/' . $ulid . '/verify', [$documents, 'verify']);
    $r->post('/documents/' . $ulid . '/hold', [$documents, 'hold']);

    $r->get('/templates', [$templates, 'index'], 'templates');
    $r->get('/templates/new', [$templates, 'createForm']);
    $r->post('/templates/new', [$templates, 'create']);
    $r->get('/templates/' . $ulid, [$templates, 'show']);
    $r->post('/templates/' . $ulid . '/draft', [$templates, 'saveDraft']);
    $r->post('/templates/' . $ulid . '/publish', [$templates, 'publish']);
    $r->post('/templates/' . $ulid . '/discard', [$templates, 'discard']);
    $r->post('/templates/' . $ulid . '/issue', [$templates, 'issue']);
    $r->get('/templates/' . $ulid . '/compare', [$templates, 'compare']);
    $r->get('/templates/versions/' . $ulid . '/preview', [$templates, 'preview']);

    $r->get('/admin/records', [$records, 'index'], 'admin.records');
    $r->get('/admin/records/rules/{class:[a-z_]{3,60}}', [$records, 'ruleClass']);
    $r->post('/admin/records/rules/{class:[a-z_]{3,60}}', [$records, 'propose']);
    $r->get('/admin/records/rule/' . $ulid, [$records, 'rule']);
    $r->post('/admin/records/rule/' . $ulid . '/submit', [$records, 'submit']);
    $r->get('/admin/records/holds', [$records, 'holds']);
    $r->post('/admin/records/holds', [$records, 'placeHold']);
    $r->post('/admin/records/holds/' . $ulid . '/release', [$records, 'releaseHold']);
    $r->get('/admin/records/disposition', [$records, 'disposition']);
    $r->post('/admin/records/disposition', [$records, 'requestDisposition']);
    $r->get('/admin/records/disposition/' . $ulid, [$records, 'batch']);

    $r->get('/calendars', [$calendars, 'index'], 'calendars');
    $r->get('/calendars/' . $ulid, [$calendars, 'legacy']);
    // managing calendars is administration (0.6.1); everyone else sees their own calendar at /calendars
    $r->get('/admin/calendars', [$calendars, 'admin'], 'calendars.admin');
    $r->post('/admin/calendars', [$calendars, 'create']);
    $r->get('/admin/calendars/' . $ulid, [$calendars, 'show']);
    $r->post('/admin/calendars/' . $ulid, [$calendars, 'update']);
    $r->post('/admin/calendars/' . $ulid . '/days', [$calendars, 'setDay']);
    $r->post('/admin/calendars/' . $ulid . '/days/{date:\d{4}-\d{2}-\d{2}}/delete', [$calendars, 'removeDay']);
    $r->get('/calendars/' . $ulid . '/export', [$calendars, 'export']);
    $r->get('/api/calendar/working-day', [$calendars, 'api']);

    $imp = '{code:[a-z0-9_.]{3,60}}';
    $r->get('/import/batch/' . $ulid, [$imports, 'batch']);
    $r->post('/import/batch/' . $ulid . '/confirm', [$imports, 'confirm']);
    $r->post('/import/batch/' . $ulid . '/cancel', [$imports, 'cancel']);
    $r->get('/import/batch/' . $ulid . '/errors', [$imports, 'errorReport']);
    $r->get('/import/' . $imp . '/template', [$imports, 'template']);
    $r->get('/import/' . $imp, [$imports, 'form']);
    $r->post('/import/' . $imp, [$imports, 'upload']);

    $r->get('/search', [$search, 'index'], 'search');
    $r->get('/admin/users/export', [$users, 'export']);
    $r->get('/admin/config/export', [$config, 'export']);

    // Phase 3C — finance core (minimal) + posting interface, payments / bank files, backup & restore, integrations
    $fin = new FinanceController($app);
    $r->get('/finance', [$fin, 'index'], 'finance');
    $r->get('/finance/journals', [$fin, 'journals']);
    $r->get('/finance/journals/new', [$fin, 'journalForm']);
    $r->post('/finance/journals/new', [$fin, 'createJournal']);
    $r->get('/finance/journals/' . $ulid, [$fin, 'journal']);
    $r->get('/finance/journals/' . $ulid . '/edit', [$fin, 'journalForm']);
    $r->post('/finance/journals/' . $ulid . '/edit', [$fin, 'updateJournal']);
    $r->post('/finance/journals/' . $ulid . '/submit', [$fin, 'submitJournal']);
    $r->post('/finance/journals/' . $ulid . '/resubmit', [$fin, 'resubmitJournal']);
    $r->post('/finance/journals/' . $ulid . '/cancel', [$fin, 'cancelJournal']);
    $r->post('/finance/journals/' . $ulid . '/reverse', [$fin, 'reverseJournal']);
    $r->get('/finance/requests', [$fin, 'requests']);
    $r->get('/finance/requests/' . $ulid, [$fin, 'request']);
    $r->post('/finance/requests/' . $ulid . '/retry', [$fin, 'retryRequest']);
    $r->post('/finance/requests/' . $ulid . '/reject', [$fin, 'rejectRequest']);
    $r->get('/finance/accounts', [$fin, 'accounts']);
    $r->post('/finance/accounts', [$fin, 'createAccount']);
    $r->post('/finance/accounts/' . $ulid . '/status', [$fin, 'accountStatus']);
    $r->get('/finance/rules', [$fin, 'rules']);
    $r->post('/finance/rules', [$fin, 'proposeRule']);
    $r->get('/finance/rules/' . $ulid, [$fin, 'rule']);
    $r->post('/finance/rules/' . $ulid . '/submit', [$fin, 'submitRule']);
    $r->post('/finance/rules/' . $ulid . '/cancel', [$fin, 'cancelRule']);
    $r->get('/finance/periods', [$fin, 'periods']);
    $r->post('/finance/periods', [$fin, 'createYear']);
    $r->post('/finance/periods/close', [$fin, 'closePeriod']);
    $r->get('/finance/trial-balance', [$fin, 'trialBalance']);

    $pay = new PaymentsController($app);
    $r->get('/payments', [$pay, 'index'], 'payments');
    $bf = new \Blue\Core\Payments\BankFormatsController($app);
    $r->get('/payments/formats', [$bf, 'index']);
    $r->post('/payments/formats/new', [$bf, 'create']);
    $r->get('/payments/formats/' . $ulid, [$bf, 'show']);
    foreach (['save', 'activate', 'copy', 'discard', 'retire'] as $act) {
        $r->post('/payments/formats/' . $ulid . '/' . $act, [$bf, $act]);
    }
    $r->get('/payments/new', [$pay, 'createForm']);
    $r->post('/payments/new', [$pay, 'create']);
    $r->get('/payments/' . $ulid, [$pay, 'show']);
    $r->get('/payments/' . $ulid . '/file', [$pay, 'file']);
    foreach (['submit', 'resubmit', 'cancel', 'clear', 'generate', 'supersede', 'sent', 'reissue'] as $act) {
        $r->post('/payments/' . $ulid . '/' . $act, [$pay, $act]);
    }

    $bk = new BackupController($app);
    $r->get('/admin/backups', [$bk, 'index'], 'admin.backups');
    $r->post('/admin/backups/run', [$bk, 'run']);
    $r->post('/admin/backups/upload', [$bk, 'upload']);
    $r->get('/admin/backups/drills/' . $ulid, [$bk, 'drillReport']);
    $r->get('/admin/backups/restore/' . $ulid, [$bk, 'restore']);
    $r->post('/admin/backups/restore/' . $ulid . '/execute', [$bk, 'execute']);
    $r->get('/admin/backups/' . $ulid, [$bk, 'show']);
    $r->get('/admin/backups/' . $ulid . '/download', [$bk, 'download']);
    $r->post('/admin/backups/' . $ulid . '/step', [$bk, 'step']);
    $r->post('/admin/backups/' . $ulid . '/recheck', [$bk, 'recheck']);
    $r->post('/admin/backups/' . $ulid . '/drill', [$bk, 'drill']);
    $r->post('/admin/backups/' . $ulid . '/delete', [$bk, 'delete']);
    $r->post('/admin/backups/' . $ulid . '/restore', [$bk, 'requestRestore']);

    $int = new IntegrationsController($app);
    $r->get('/admin/integrations', [$int, 'index'], 'admin.integrations');
    $r->get('/admin/integrations/new', [$int, 'form']);
    $r->post('/admin/integrations/new', [$int, 'save']);
    $r->get('/admin/integrations/' . $ulid, [$int, 'show']);
    $r->get('/admin/integrations/' . $ulid . '/edit', [$int, 'form']);
    $r->post('/admin/integrations/' . $ulid . '/edit', [$int, 'save']);
    $r->post('/admin/integrations/' . $ulid . '/keys', [$int, 'issueKey']);
    $r->post('/admin/integrations/' . $ulid . '/keys/{key:[0-9A-HJKMNP-TV-Z]{26}}/revoke', [$int, 'revokeKey']);
    $r->post('/m2m/scheduler/run', [$int, 'schedulerRun']);

    // Audit trail (P0-04 §7)
    $r->get('/admin/audit', [$audit, 'index'], 'admin.audit');
    $r->get('/admin/audit/export', [$audit, 'export'], 'admin.audit.export');

    // phase 3U — module registry
    $mods = new ModulesController($app);
    $r->get('/admin/modules', [$mods, 'index'], 'admin.modules');
    $r->post('/admin/modules/{code:[a-z][a-z0-9_]{1,30}}/toggle', [$mods, 'toggle']);
};
