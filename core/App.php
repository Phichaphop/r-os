<?php

declare(strict_types=1);

namespace Blue\Core;

use Blue\Core\AI\AiGateway;
use Blue\Core\Audit\AuditContext;
use Blue\Core\Audit\AuditLogger;
use Blue\Core\Auth\AuthService;
use Blue\Core\Auth\AuthSettings;
use Blue\Core\Auth\LoginThrottle;
use Blue\Core\Auth\MfaService;
use Blue\Core\Auth\PasswordPolicy;
use Blue\Core\Auth\SecurityPolicy;
use Blue\Core\Auth\SessionManager;
use Blue\Core\Authz\AccessRequests;
use Blue\Core\Authz\IamService;
use Blue\Core\Amend\AmendmentService;
use Blue\Core\Events\Outbox;
use Blue\Core\Jobs\CallbackJob;
use Blue\Core\Ledger\CalculationLedger;
use Blue\Core\Locks\LockService;
use Blue\Core\Notify\Notifier;
use Blue\Core\Numbering\NumberingService;
use Blue\Core\Registry\ConfigRegistry;
use Blue\Core\Calendar\WorkCalendar;
use Blue\Core\Import\CalendarDaysImport;
use Blue\Core\Import\ImportService;
use Blue\Core\Records\Disposition;
use Blue\Core\Records\DocumentService;
use Blue\Core\Records\DocumentStorage;
use Blue\Core\Records\LegalHolds;
use Blue\Core\Records\RetentionRules;
use Blue\Core\Records\TemplateService;
use Blue\Core\Search\SearchService;
use Blue\Core\Backup\BackupJob;
use Blue\Core\Backup\BackupService;
use Blue\Core\Backup\RestoreService;
use Blue\Core\Finance\Accounts;
use Blue\Core\Finance\Journals;
use Blue\Core\Finance\PostingRules;
use Blue\Core\Finance\PostingService;
use Blue\Core\Integrations\IntegrationAuth;
use Blue\Core\Integrations\IntegrationRegistry;
use Blue\Core\Integrations\CurlTransport;
use Blue\Core\Integrations\HttpTransport;
use Blue\Core\Payments\PaymentBatches;
use Blue\Core\Payments\PaymentItemsImport;
use Blue\Core\Payments\PaymentResultsImport;
use Blue\Core\Ui\Appearance;
use Blue\Core\Ui\Preferences;
use Blue\Core\Workflow\WorkflowEngine;
use Blue\Core\Mail\Mailer;
use Blue\Core\Org\OrgService;
use Blue\Core\Support\Crypto;
use Blue\Core\Config\AppConfig;
use Blue\Core\Database\Connection;
use Blue\Core\I18n\Translator;
use Blue\Core\Install\InstallState;
use Blue\Core\Jobs\HeartbeatJob;
use Blue\Core\Jobs\JobRegistry;
use Blue\Core\Jobs\Scheduler;
use Blue\Core\Log\Logger;
use Blue\Core\Support\Clock;
use Blue\Core\Support\SystemClock;
use Blue\Core\Ui\View;

/**
 * Composition root: builds shared services once per request/CLI run.
 * Deliberately small — no magic container; every dependency is visible here.
 */
final class App
{
    /** Release shown in backups and on the status page. */
    public const RELEASE = '0.41.0 (R5: chat)';

    private ?Connection $db = null;
    private ?Translator $translator = null;
    private ?View $view = null;
    private ?JobRegistry $jobs = null;
    private ?Scheduler $scheduler = null;
    private ?AiGateway $ai = null;
    private ?AuditContext $auditContext = null;
    private ?AuditLogger $audit = null;
    private ?Crypto $crypto = null;
    private ?AuthSettings $authSettings = null;
    private ?SessionManager $sessions = null;
    private ?LoginThrottle $throttle = null;
    private ?AuthService $auth = null;
    private ?MfaService $mfa = null;
    private ?SecurityPolicy $security = null;
    private ?IamService $iam = null;
    private ?OrgService $org = null;
    private ?Notifier $notifier = null;
    private ?WorkflowEngine $workflow = null;
    private ?ConfigRegistry $registry = null;
    private ?CalculationLedger $ledger = null;
    private ?LockService $locks = null;
    private ?AmendmentService $amendments = null;
    private ?AccessRequests $accessRequests = null;
    private ?Outbox $outbox = null;
    private ?NumberingService $numbering = null;
    private ?Preferences $preferences = null;
    private ?Appearance $appearance = null;
    private ?Mailer $mailer = null;
    private ?DocumentService $documents = null;
    private ?TemplateService $templates = null;
    private ?RetentionRules $retention = null;
    private ?LegalHolds $holds = null;
    private ?Disposition $disposition = null;
    private ?WorkCalendar $calendars = null;
    private ?ImportService $imports = null;
    private ?Accounts $accounts = null;
    private ?PostingRules $postingRules = null;
    private ?Journals $journals = null;
    private ?PostingService $posting = null;
    private ?PaymentBatches $payments = null;
    private ?BackupService $backups = null;
    private ?RestoreService $restores = null;
    private ?IntegrationRegistry $integrations = null;
    private ?HttpTransport $http = null;
    private ?\Blue\Core\Modules\ModuleRegistry $modules = null;
    /** @var array<string, object> module services (App::service) */
    private array $services = [];
    public readonly Logger $logger;
    public readonly InstallState $install;

    public function __construct(
        public readonly string $root,
        public readonly AppConfig $config,
        public readonly Clock $clock = new SystemClock(),
        ?InstallState $install = null,
        ?string $logDir = null,
    ) {
        $logDir ??= $this->path($config->string('LOG_PATH', 'storage/logs'));
        $this->logger = new Logger($logDir, $clock, $config->string('LOG_LEVEL', 'info'));
        $this->install = $install ?? new InstallState($root);
        foreach ($config->warnings() as $w) {
            $this->logger->warning('config_warning', ['detail' => $w]);
        }
    }

    /** @param array<string, mixed> $overrides */
    public static function boot(string $root, array $overrides = []): self
    {
        $config = AppConfig::load($root, $overrides);
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
        return new self($root, $config);
    }

    public function path(string $relative): string
    {
        return str_starts_with($relative, '/') ? $relative : $this->root . '/' . $relative;
    }

    public function db(): Connection
    {
        return $this->db ??= Connection::fromConfig($this->config);
    }

    public function hasDatabase(): bool
    {
        return $this->config->installed() && $this->config->string('DB_NAME') !== '';
    }

    public function translator(): Translator
    {
        return $this->translator ??= new Translator(
            array_values($this->seedDirs()),
            $this->config->string('I18N_FALLBACK', 'en'),
            $this->config->string('I18N_DEFAULT', 'th'),
            $this->hasDatabase() ? $this->db() : null,
        );
    }

    public function view(): View
    {
        return $this->view ??= new View(
            $this->root . '/ui/views',
            $this->root . '/ui/components',
            $this->translator(),
            $this->root . '/public',
        );
    }

    /** Module Registry (P0-02 §6): manifests on disk + on/off switches in the `modules` table. */
    public function modules(): \Blue\Core\Modules\ModuleRegistry
    {
        return $this->modules ??= new \Blue\Core\Modules\ModuleRegistry($this->root, $this->hasDatabase() && $this->install->isLocked() ? $this->db() : null);
    }

    /**
     * One shared instance per key for module services (modules cannot add factories to App).
     *
     * @template T of object
     * @param class-string<T> $class
     * @param callable(App): T $factory
     * @return T
     */
    public function service(string $class, callable $factory): object
    {
        $svc = $this->services[$class] ??= $factory($this);
        assert($svc instanceof $class);
        return $svc;
    }

    public function jobs(): JobRegistry
    {
        if ($this->jobs === null) {
            $this->jobs = new JobRegistry();
            $this->jobs->register(new HeartbeatJob());
            $this->jobs->register(new CallbackJob('outbox.dispatch', 1, fn () => $this->outbox()->dispatch()));
            $this->jobs->register(new CallbackJob('workflow.escalate', 15, fn () => $this->workflow()->escalateDue()));
            $this->jobs->register(new CallbackJob('records.schedule', 1440, fn () => $this->documents()->recomputeSchedules()));
            $this->jobs->register(new CallbackJob('import.expire', 60, fn () => $this->imports()->expire()));
            $this->jobs->register(new BackupJob(fn (): BackupService => $this->backups()));
            $this->jobs->register(new CallbackJob('backup.prune', 1440, fn () => $this->backups()->prune()));
            foreach ($this->modules()->enabled() as $m) { // module.json "jobs": {"<code>.x": {"every": minutes, "call": "Blue\\Modules\\…::method"}}
                foreach ((array) ($m['jobs'] ?? []) as $type => $job) {
                    $call = (string) ($job['call'] ?? '');
                    if (is_callable($call)) {
                        $this->jobs->register(new CallbackJob((string) $type, (int) $job['every'], fn () => $call($this)));
                    }
                }
            }
        }
        return $this->jobs;
    }

    public function scheduler(): Scheduler
    {
        return $this->scheduler ??= new Scheduler($this->db(), $this->jobs(), $this->clock, $this->logger);
    }

    public function ai(): AiGateway
    {
        return $this->ai ??= new AiGateway($this->config, $this->logger);
    }

    public function auditContext(): AuditContext
    {
        return $this->auditContext ??= new AuditContext();
    }

    public function audit(): AuditLogger
    {
        return $this->audit ??= new AuditLogger($this->db(), $this->clock, $this->auditContext());
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new Crypto($this->config->string('APP_KEY'));
    }

    public function authSettings(): AuthSettings
    {
        return $this->authSettings ??= AuthSettings::fromConfig($this->config);
    }

    public function sessions(): SessionManager
    {
        $s = $this->authSettings();
        return $this->sessions ??= new SessionManager($this->db(), $this->clock, $this->crypto(), $s->idleMinutes, $s->absoluteHours);
    }

    public function throttle(): LoginThrottle
    {
        return $this->throttle ??= new LoginThrottle($this->db(), $this->clock, $this->crypto());
    }

    public function mailer(): Mailer
    {
        $driver = $this->config->isProduction() ? 'none' : $this->config->string('MAIL_DRIVER', 'none');
        return $this->mailer ??= new Mailer($this->db(), $this->clock, $driver);
    }

    public function auth(): AuthService
    {
        return $this->auth ??= new AuthService(
            $this->db(),
            $this->clock,
            $this->crypto(),
            $this->sessions(),
            $this->throttle(),
            new PasswordPolicy($this->authSettings()->passwordMin),
            $this->audit(),
            $this->mailer(),
            $this->authSettings(),
        );
    }

    public function mfa(): MfaService
    {
        return $this->mfa ??= new MfaService($this->db(), $this->clock, $this->crypto(), $this->audit());
    }

    public function security(): SecurityPolicy
    {
        return $this->security ??= new SecurityPolicy($this->db(), $this->clock, $this->audit());
    }

    public function iam(): IamService
    {
        return $this->iam ??= new IamService($this->db(), $this->clock, $this->audit(), $this->auth(), $this->sessions(), $this->throttle());
    }

    public function org(): OrgService
    {
        return $this->org ??= new OrgService($this->db(), $this->clock, $this->audit());
    }

    public function notifier(): Notifier
    {
        return $this->notifier ??= new Notifier($this->db(), $this->clock);
    }

    /** The approval engine with every core subject registered (modules register theirs from their manifest). */
    public function workflow(): WorkflowEngine
    {
        if ($this->workflow === null) {
            $this->workflow = new WorkflowEngine($this->db(), $this->clock, $this->audit(), $this->notifier());
            $this->workflow->register($this->registry());
            $this->workflow->register($this->locks());
            $this->workflow->register($this->amendments());
            $this->workflow->register($this->accessRequests());
            $this->workflow->register($this->retention());
            $this->workflow->register($this->holds());
            $this->workflow->register($this->disposition());
            $this->workflow->register($this->postingRules());
            $this->workflow->register($this->journals());
            $this->workflow->register($this->payments());
            $this->workflow->register($this->restores());
            $this->workflow->useCalendar($this->calendars(), $this->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
            // module routes (module.json "workflows"): Class::create(App) returns the subject that executes approved requests
            $made = [];
            foreach ($this->modules()->enabled() as $m) {
                foreach ((array) ($m['workflows'] ?? []) as $wcode => $wf) {
                    $class = (string) ($wf['class'] ?? ''); // no class = a core subject (e.g. config_version for a module-owned key)
                    if ($class !== '' && !isset($made[$class]) && is_callable([$class, 'create'])) {
                        $made[$class] = $class::create($this);
                    }
                    if ($class !== '' && isset($made[$class]) && $made[$class] instanceof \Blue\Core\Workflow\WorkflowSubject) {
                        $this->workflow->register($made[$class]);
                    }
                    if (!empty($wf['no_fallback'])) {
                        $this->workflow->noFallback((string) $wcode);
                    }
                    if (!empty($wf['distinct_steps'])) {
                        $this->workflow->distinctSteps((string) $wcode);
                    }
                }
            }
        }
        return $this->workflow;
    }

    public function registry(): ConfigRegistry
    {
        if ($this->registry === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            if ($this->registry === null) {
                $this->registry = new ConfigRegistry($this->db(), $this->clock, $this->audit(), $wf, $this->logger);
                $owners = [];
                foreach ($this->modules()->enabled() as $m) {
                    foreach ((array) ($m['config_owners'] ?? []) as $key => $own) {
                        $owners[(string) $key] = ['propose' => (string) $own['propose'], 'workflow' => (string) $own['workflow'],
                            'title' => $this->translator()->has('config.title.' . $key) ? $this->translator()->get('config.title.' . $key) : '', 'describe' => (string) ($own['describe'] ?? '')];
                    }
                }
                $this->registry->setOwners($owners, $this->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
            }
        }
        return $this->registry;
    }

    public function ledger(): CalculationLedger
    {
        return $this->ledger ??= new CalculationLedger($this->db(), $this->clock, $this->crypto(), $this->audit());
    }

    public function locks(): LockService
    {
        if ($this->locks === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            $this->locks ??= new LockService($this->db(), $this->clock, $this->audit(), $wf);
        }
        return $this->locks;
    }

    public function amendments(): AmendmentService
    {
        if ($this->amendments === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            $this->amendments ??= new AmendmentService($this->db(), $this->clock, $this->audit(), $wf);
        }
        return $this->amendments;
    }

    public function accessRequests(): AccessRequests
    {
        if ($this->accessRequests === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            $this->accessRequests ??= new AccessRequests($this->db(), $this->clock, $this->audit(), $wf, $this->iam());
        }
        return $this->accessRequests;
    }

    public function outbox(): Outbox
    {
        if ($this->outbox === null) {
            $this->outbox = new Outbox($this->db(), $this->clock, $this->auditContext(), $this->logger);
            // in-process consumers (closures: services are built only when an event is delivered)
            $this->outbox->subscribe(PostingService::CONSUMER, 'posting.requested', fn (array $e) => $this->posting()->consume($e));
            $this->outbox->subscribe('payments.posting', 'posting.*', fn (array $e) => $this->payments()->onPostingEvent($e));
        }
        return $this->outbox;
    }

    public function numbering(): NumberingService
    {
        return $this->numbering ??= new NumberingService($this->db(), $this->clock, $this->audit());
    }

    public function documents(): DocumentService
    {
        return $this->documents ??= new DocumentService(
            $this->db(),
            $this->clock,
            $this->audit(),
            new DocumentStorage($this->path($this->config->string('DOCUMENTS_PATH', 'storage/documents')), $this->crypto()),
            $this->registry(),
        );
    }

    public function templates(): TemplateService
    {
        return $this->templates ??= new TemplateService($this->db(), $this->clock, $this->audit(), $this->documents(), $this->numbering(), $this->registry(), $this->config->string('APP_TIMEZONE_DISPLAY', 'Asia/Bangkok'));
    }

    public function retention(): RetentionRules
    {
        if ($this->retention === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            $this->retention ??= new RetentionRules($this->db(), $this->clock, $this->audit(), $wf, $this->documents());
        }
        return $this->retention;
    }

    public function holds(): LegalHolds
    {
        if ($this->holds === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            $this->holds ??= new LegalHolds($this->db(), $this->clock, $this->audit(), $wf, $this->documents());
        }
        return $this->holds;
    }

    public function disposition(): Disposition
    {
        if ($this->disposition === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            $this->disposition ??= new Disposition($this->db(), $this->clock, $this->audit(), $wf, $this->documents());
        }
        return $this->disposition;
    }

    public function calendars(): WorkCalendar
    {
        return $this->calendars ??= new WorkCalendar($this->db(), $this->clock, $this->audit());
    }

    public function imports(): ImportService
    {
        if ($this->imports === null) {
            $this->imports = new ImportService($this->db(), $this->clock, $this->audit(), $this->crypto(), $this->translator(), $this->registry());
            $this->imports->register(new CalendarDaysImport($this->db(), $this->calendars()));
            $this->imports->register(new PaymentItemsImport($this->db(), $this->payments()));
            $this->imports->register(new PaymentResultsImport($this->db(), $this->payments()));
            foreach ($this->modules()->enabled() as $m) { // module.json "imports": ["Blue\\Modules\\…\\XImport"] — Class::create(App)
                foreach ((array) ($m['imports'] ?? []) as $class) {
                    if (is_callable([(string) $class, 'create'])) {
                        $def = ((string) $class)::create($this);
                        if ($def instanceof \Blue\Core\Import\ImportDefinition) {
                            $this->imports->register($def);
                        }
                    }
                }
            }
        }
        return $this->imports;
    }

    // ---- phase 3C ------------------------------------------------------------------------------------

    public function accounts(): Accounts
    {
        return $this->accounts ??= new Accounts($this->db(), $this->clock, $this->audit(), $this->locks(), $this->registry());
    }

    public function postingRules(): PostingRules
    {
        if ($this->postingRules === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            if ($this->postingRules === null) {
                $this->postingRules = new PostingRules($this->db(), $this->clock, $this->audit(), $wf, $this->accounts());
                $this->postingRules->afterApproval(fn (int $le, string $module, string $type): int => $this->posting()->retryWaiting($le, $module, $type));
            }
        }
        return $this->postingRules;
    }

    public function journals(): Journals
    {
        if ($this->journals === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            $this->journals ??= new Journals($this->db(), $this->clock, $this->audit(), $wf, $this->accounts(), $this->numbering(), $this->outbox(), $this->registry());
        }
        return $this->journals;
    }

    public function posting(): PostingService
    {
        return $this->posting ??= new PostingService($this->db(), $this->clock, $this->audit(), $this->outbox(), $this->accounts(), $this->postingRules(), $this->journals(), $this->notifier());
    }

    public function payments(): PaymentBatches
    {
        if ($this->payments === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            $this->payments ??= new PaymentBatches($this->db(), $this->clock, $this->audit(), $wf, $this->crypto(), $this->numbering(), $this->documents(), $this->outbox(), $this->registry(), $this->posting());
        }
        return $this->payments;
    }

    public function backups(): BackupService
    {
        return $this->backups ??= new BackupService(
            $this->db(),
            $this->clock,
            $this->audit(),
            $this->crypto(),
            $this->config,
            $this->registry(),
            $this->notifier(),
            $this->logger,
            $this->path($this->config->string('DOCUMENTS_PATH', 'storage/documents')),
            $this->path($this->config->string('BACKUP_PATH', 'storage/backups')),
            self::RELEASE,
        );
    }

    public function restores(): RestoreService
    {
        if ($this->restores === null) {
            $wf = $this->workflow(); // may construct and register this service itself
            $this->restores ??= new RestoreService(
                $this->db(),
                $this->clock,
                $this->audit(),
                $wf,
                $this->backups(),
                $this->crypto(),
                $this->config,
                $this->logger,
                $this->install,
                $this->path($this->config->string('DOCUMENTS_PATH', 'storage/documents'))
            );
        }
        return $this->restores;
    }

    public function integrations(): IntegrationRegistry
    {
        return $this->integrations ??= new IntegrationRegistry($this->db(), $this->clock, $this->audit(), $this->crypto());
    }

    /** Outgoing HTTP to registered integrations (cURL; tests replace it). */
    public function http(): HttpTransport
    {
        return $this->http ??= new CurlTransport();
    }

    public function setHttpTransport(HttpTransport $t): void
    {
        $this->http = $t;
    }

    /** @var (callable(string): list<string>)|null host resolver for the font import (tests replace it) */
    private $fontResolver = null;

    /** Organization fonts imported from a link (0.6.1), stored in storage/fonts and served from /brand/font/. */
    public function fonts(): \Blue\Core\Ui\FontImporter
    {
        return new \Blue\Core\Ui\FontImporter($this->http(), $this->fontsDir(), $this->fontResolver);
    }

    private ?string $fontsDir = null;

    /** Where imported fonts are kept (storage/fonts; tests use their own folder). */
    public function fontsDir(): string
    {
        return $this->fontsDir ?? $this->root . '/storage/fonts';
    }

    public function setFontsDir(string $dir): void
    {
        $this->fontsDir = $dir;
        $this->appearance = null;
    }

    /** @param callable(string): list<string> $resolve */
    public function setFontResolver(callable $resolve): void
    {
        $this->fontResolver = $resolve;
    }

    public function integrationAuth(): IntegrationAuth
    {
        return new IntegrationAuth($this->integrations(), $this->clock);
    }

    public function search(): SearchService
    {
        return new SearchService($this);
    }

    public function appearance(): Appearance
    {
        $ready = $this->hasDatabase() && $this->install->isLocked();
        return $this->appearance ??= new Appearance(
            $ready ? $this->registry() : null,
            $this->root,
            $this->config->string('APP_NAME', 'Blue OS'),
            $this->fontsDir(),
        );
    }

    public function preferences(): Preferences
    {
        return $this->preferences ??= new Preferences($this->db(), $this->clock);
    }

    private ?int $schemaPending = null;

    /** Migrations not applied yet (a newer release copied over an older install) — 0 when unknown. */
    public function schemaPending(): int
    {
        if ($this->schemaPending === null) {
            try {
                $runner = new \Blue\Core\Database\MigrationRunner($this->db(), $this->migrationPaths(), $this->clock, $this->logger);
                $this->schemaPending = count(array_filter($runner->status(), static fn (array $m): bool => $m['state'] !== 'applied'));
            } catch (\Throwable) {
                $this->schemaPending = 0;
            }
        }
        return $this->schemaPending;
    }

    public function resetSchemaPending(): void
    {
        $this->schemaPending = null;
    }

    /** @return array<string, string> module code => migrations dir (core first) */
    public function migrationPaths(): array
    {
        $paths = ['core' => $this->root . '/core/migrations'];
        foreach (glob($this->root . '/modules/*/migrations', GLOB_ONLYDIR) ?: [] as $dir) {
            $paths[basename(dirname($dir))] = $dir;
        }
        return $paths;
    }

    /** @return array<string, string> module code => translation seed dir */
    public function seedDirs(): array
    {
        $dirs = ['core' => $this->root . '/core/I18n/seed'];
        foreach (glob($this->root . '/modules/*/lang', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs[basename(dirname($dir))] = $dir;
        }
        return $dirs;
    }
}
