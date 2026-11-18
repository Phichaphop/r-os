<?php

declare(strict_types=1);

namespace Blue\Core\Install;

use Blue\Core\Authz\RbacSync;
use Blue\Core\Modules\ModuleRegistry;
use Blue\Core\Integrations\IntegrationRegistry;
use Blue\Core\Payments\BankFile;
use Blue\Core\Database\Connection;
use Blue\Core\Records\DocumentService;
use Blue\Core\Records\RetentionRules;
use Blue\Core\Registry\ConfigRegistry;
use Blue\Core\Support\Clock;
use Blue\Core\Workflow\WorkflowEngine;

/**
 * Brings catalogue data in line with the code after install / migrate / "update database":
 * permissions + roles, configuration definitions (never values), workflow definitions (version 1 only),
 * document classes, the AI DRAFT retention schedule (drafts only — DEC-48), demo calendars/templates (DEC-34), the standard
 * bank file format (DEC-31), the integration registry entries known from the decisions and the 3C finance demo (DEMO only).
 * Idempotent; never changes something an administrator has already edited.
 */
final class PlatformSync
{
    /** @return array{config: int, workflow: int, documents: int} */
    public static function run(Connection $db, Clock $clock, ?string $root = null): array
    {
        $modules = ModuleRegistry::discover($root ?? dirname(__DIR__, 2));
        (new RbacSync($db, $clock, $modules))->sync();
        $demo = (int) ($db->scalar('SELECT COUNT(*) FROM organizations WHERE is_demo = 1') ?? 0) > 0
            || (int) ($db->scalar('SELECT COUNT(*) FROM legal_entities WHERE is_demo = 1') ?? 0) > 0;
        $out = ['config' => ConfigRegistry::sync($db, $clock, $demo, $modules), 'workflow' => WorkflowEngine::sync($db, $clock, $modules), 'documents' => 0];
        if ($db->tableExists('document_classes')) {
            $out['documents'] = DocumentService::sync($db, $clock, $modules);
            RetentionRules::seedDrafts($db, $clock);
            if ($demo) {
                RecordsDemo::run($db, $clock);
            }
        }
        if ($db->tableExists('pmt_file_formats')) {
            $db->run(
                "INSERT IGNORE INTO pmt_file_formats (code, bank_code, version, name, column_spec_json, status, updated_at) VALUES (?, NULL, ?, ?, ?, 'active', ?)",
                [BankFile::FORMAT, BankFile::VERSION, 'Blue OS standard Excel (DEC-31)', json_encode(BankFile::spec(), JSON_UNESCAPED_UNICODE), $clock->now()->format('Y-m-d H:i:s.u')]
            );
            IntegrationRegistry::seed($db, $clock);
            if ($demo) {
                FinanceDemo::run($db, $clock);
            }
        }
        if ($demo) {
            PrivacyDemo::run($db, $clock);
        }
        if ($demo) { // module demo data (module.json "demo": "Class::method") — each hook checks its own tables and runs once
            foreach ($modules as $m) {
                $call = (string) ($m['demo'] ?? '');
                if ($call !== '' && is_callable($call)) {
                    $call($db, $clock);
                }
            }
            CompanyDemo::run($db, $clock); // 0.40.0: the one full demo company (Q-20) — after the module demos made their accounts
        }
        return $out;
    }
}
