<?php

declare(strict_types=1);

namespace Blue\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Blue\Core\Authz\Authorizer;
use Blue\Core\Error\BusinessException;
use Blue\Core\Error\HttpException;
use Blue\Core\Workflow\WorkflowSubject;
use Blue\Tests\Support\TestApp;

/** TC-WF (6.9, 6.9b, 6.30.4): versions, self-approval, no approver, delegation, escalation, return/cancel, execute failure + retry. */
final class WorkflowEngineTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = TestApp::create($this);
    }

    protected function tearDown(): void
    {
        $this->t->cleanup();
    }

    private function uid(string $username): int
    {
        return (int) $this->t->user($username)['id'];
    }

    private function le(string $code): int
    {
        return (int) $this->t->app->db()->scalar('SELECT id FROM legal_entities WHERE code = ?', [$code]);
    }

    /** employee.demo1 asks for HRT on DEMO1 — approvers: system admins (core.user.grant over DEMO1). */
    private function accessRequest(string $user = 'employee.demo1', string $role = 'HRT'): string
    {
        $pid = $this->t->app->accessRequests()->create($this->uid($user), $role, 'legal_entity', $this->le('DEMO1'), 'need it');
        return (string) $this->t->app->db()->scalar('SELECT i.public_id FROM workflow_instances i WHERE subject_id = ?', [$pid]);
    }

    /** @return list<string> usernames with an open task on the instance */
    private function assignees(string $instance): array
    {
        return array_column($this->t->app->db()->all(
            "SELECT u.username FROM workflow_tasks t JOIN workflow_instances i ON i.id = t.instance_id JOIN users u ON u.id = t.assignee_id
              WHERE i.public_id = ? AND t.status = 'open' ORDER BY u.username",
            [$instance]
        ), 'username');
    }

    public function testApprovalGrantsTheRoleAndMyRequestsShowsTheResult(): void
    {
        $wf = $this->t->app->workflow();
        $inst = $this->accessRequest();
        self::assertSame(['admin.test', 'sysadmin2.demo'], $this->assignees($inst));
        self::assertSame(1, $wf->openTaskCount($this->uid('sysadmin2.demo')));
        $wf->decide($inst, $this->uid('sysadmin2.demo'), 'approve');
        $row = $wf->instanceRow($inst);
        self::assertSame(['approved', 'succeeded'], [$row['status'], $row['execution_status']]);
        self::assertSame([], $this->assignees($inst), 'other approvers\' tasks are closed');
        $az = new Authorizer($this->t->app->db(), $this->t->clock, $this->uid('employee.demo1'));
        self::assertContains('HRT', $az->roleCodes());
        $mine = $wf->myRequests($this->uid('employee.demo1'), '', 1, 20);
        self::assertSame(1, $mine['total']);
        self::assertSame('approved', $mine['rows'][0]['status']);
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND event_type = 'workflow.approved'", [$this->uid('employee.demo1')]));
    }

    public function testRunningRequestsKeepTheirVersionWhenTheRouteChanges(): void
    {
        $wf = $this->t->app->workflow();
        $old = $this->accessRequest();
        $steps = [
            ['approver' => ['type' => 'permission', 'value' => 'core.user.grant'], 'scope' => 'subject', 'sla_hours' => null, 'escalate_to' => null],
            ['approver' => ['type' => 'role', 'value' => 'AUD'], 'scope' => 'any', 'sla_hours' => null, 'escalate_to' => null],
        ];
        self::assertSame(2, $wf->publishVersion('core.access_request', $steps, 'add auditor step', $this->uid(TestApp::ADMIN)));
        $wf->decide($old, $this->uid('sysadmin2.demo'), 'approve');
        self::assertSame('approved', $wf->instanceRow($old)['status'], 'old request finishes on version 1 (one step)');

        $new = $this->accessRequest('hr.demo1', 'HRT');
        $wf->decide($new, $this->uid('sysadmin2.demo'), 'approve');
        self::assertSame(['pending', 2], [$wf->instanceRow($new)['status'], (int) $wf->instanceRow($new)['current_step']]);
        self::assertSame(['auditor.demo'], $this->assignees($new));
    }

    public function testRequesterNeverApprovesAndNoApproverNeedsAttention(): void
    {
        $wf = $this->t->app->workflow();
        // sysadmin2 asks for something: only admin.test may decide (requester excluded)
        $inst = $this->accessRequest('sysadmin2.demo', 'AUD');
        self::assertSame(['admin.test'], $this->assignees($inst));
        try {
            $wf->decide($inst, $this->uid('sysadmin2.demo'), 'approve');
            self::fail('requester decided');
        } catch (HttpException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        // nobody left to approve: the only other system admin is suspended → needs attention, never auto-approved
        $this->t->app->db()->run("UPDATE users SET status = 'suspended' WHERE username = 'sysadmin2.demo'");
        $stuck = $this->accessRequest(TestApp::ADMIN, 'AUD');
        self::assertSame('needs_attention', $wf->instanceRow($stuck)['status']);
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'workflow.no_approver'"));
    }

    public function testDelegationRoutesTasksAndEndsOnTime(): void
    {
        $wf = $this->t->app->workflow();
        $now = $this->t->clock->now();
        $wf->delegate($this->uid('sysadmin2.demo'), $this->uid('hr.demo1'), $now->modify('-1 hour'), $now->modify('+1 day'), 'on leave');
        $inst = $this->accessRequest();
        self::assertSame(['admin.test', 'hr.demo1'], $this->assignees($inst));
        $wf->decide($inst, $this->uid('hr.demo1'), 'approve', 'for sysadmin2');
        $d = $this->t->app->db()->one("SELECT actor_id, on_behalf_of_id FROM workflow_decisions WHERE decision = 'approve'");
        self::assertSame([$this->uid('hr.demo1'), $this->uid('sysadmin2.demo')], [(int) $d['actor_id'], (int) $d['on_behalf_of_id']]);

        // a delegation that ended gives the task back to the delegator
        $inst2 = $this->accessRequest('hr.demo2', 'HRT');
        $this->t->clock->advance('+2 days');
        try {
            $wf->decide($inst2, $this->uid('hr.demo1'), 'approve');
            self::fail('expired delegation used');
        } catch (BusinessException $e) {
            self::assertSame('workflow.error.delegation_expired', $e->messageKey);
        }
        self::assertSame(['admin.test', 'sysadmin2.demo'], $this->assignees($inst2));
        // a delegate is never the requester's way to approve their own request
        $wf->delegate($this->uid('admin.test'), $this->uid('employee.demo1'), $this->t->clock->now(), $this->t->clock->now()->modify('+1 day'), 'x');
        $own = $this->accessRequest('employee.demo1', 'MGR');
        self::assertNotContains('employee.demo1', $this->assignees($own));
    }

    public function testOverdueTasksEscalateOnce(): void
    {
        $wf = $this->t->app->workflow();
        $steps = [['approver' => ['type' => 'role', 'value' => 'AUD'], 'scope' => 'any', 'sla_hours' => 24, 'escalate_to' => ['type' => 'role', 'value' => 'SYS']]];
        $wf->publishVersion('core.access_request', $steps, 'auditor first', $this->uid(TestApp::ADMIN));
        $inst = $this->accessRequest();
        self::assertSame(['auditor.demo'], $this->assignees($inst));
        self::assertSame(0, $wf->escalateDue());
        $this->t->clock->advance('+25 hours');
        self::assertSame(1, $wf->escalateDue());
        self::assertSame(['admin.test', 'auditor.demo', 'sysadmin2.demo'], $this->assignees($inst));
        self::assertSame(0, $wf->escalateDue(), 'escalates only once');
        $wf->decide($inst, $this->uid('admin.test'), 'approve');
        self::assertSame('approved', $wf->instanceRow($inst)['status']);
    }

    public function testReturnResubmitRejectAndCancel(): void
    {
        $wf = $this->t->app->workflow();
        $inst = $this->accessRequest();
        try {
            $wf->decide($inst, $this->uid('admin.test'), 'return');
            self::fail('return without comment');
        } catch (BusinessException $e) {
            self::assertSame('form.has_errors', $e->messageKey);
        }
        $wf->decide($inst, $this->uid('admin.test'), 'return', 'which department?');
        self::assertSame('returned', $this->t->app->db()->scalar("SELECT status FROM access_requests"));
        $wf->resubmit($inst, $this->uid('employee.demo1'), 'HR department');
        self::assertSame(['pending', 'pending'], [$wf->instanceRow($inst)['status'], $this->t->app->db()->scalar('SELECT status FROM access_requests')]);
        self::assertSame(['admin.test', 'sysadmin2.demo'], $this->assignees($inst));
        $wf->decide($inst, $this->uid('sysadmin2.demo'), 'reject', 'not needed');
        self::assertSame('rejected', $this->t->app->db()->scalar('SELECT status FROM access_requests'));

        $other = $this->accessRequest('employee.demo1', 'MGR');
        try {
            $wf->cancel($other, $this->uid('hr.demo1'), 'not mine');
            self::fail('someone else cancelled');
        } catch (HttpException) {
            self::assertTrue(true);
        }
        $wf->cancel($other, $this->uid('employee.demo1'), 'changed my mind');
        self::assertSame('cancelled', $wf->instanceRow($other)['status']);
        self::assertSame([], $this->assignees($other));
    }

    public function testFailedExecutionKeepsTheDecisionAndCanBeRetried(): void
    {
        $wf = $this->t->app->workflow();
        $subject = new class implements WorkflowSubject {
            public int $calls = 0;

            public function type(): string
            {
                return 'access_request';
            }

            public function link(string $subjectId): string
            {
                return '/';
            }

            public function onApproved(array $instance, int $approverId): void
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('bank file service unavailable');
                }
            }

            public function onClosed(array $instance, string $outcome): void
            {
            }

            public function onResubmitted(array $instance): void
            {
            }
        };
        $wf->register($subject);
        $inst = $this->accessRequest();
        $wf->decide($inst, $this->uid('admin.test'), 'approve');
        $row = $wf->instanceRow($inst);
        self::assertSame(['approved', 'failed'], [$row['status'], $row['execution_status']]);
        try {
            $wf->retryExecution($inst, new Authorizer($this->t->app->db(), $this->t->clock, $this->uid('hr.demo1')));
            self::fail('retry without permission');
        } catch (HttpException) {
            self::assertTrue(true);
        }
        $wf->retryExecution($inst, new Authorizer($this->t->app->db(), $this->t->clock, $this->uid('sysadmin2.demo')));
        self::assertSame('succeeded', $wf->instanceRow($inst)['execution_status']);
        self::assertSame(2, $subject->calls);
        $this->expectException(BusinessException::class);
        $wf->retryExecution($inst, new Authorizer($this->t->app->db(), $this->t->clock, $this->uid('sysadmin2.demo')));
    }

    public function testDetailIsOnlyForPeopleInvolved(): void
    {
        $wf = $this->t->app->workflow();
        $inst = $this->accessRequest();
        $az = fn (string $u) => new Authorizer($this->t->app->db(), $this->t->clock, $this->uid($u));
        self::assertFalse($wf->detail($inst, $az('employee.demo1'))['can_decide']);
        self::assertTrue($wf->detail($inst, $az('admin.test'))['can_decide']);
        $this->expectException(HttpException::class);
        $wf->detail($inst, $az('hr.demo2'));
    }

    public function testDoubleSubmitStartsOneRequest(): void
    {
        $wf = $this->t->app->workflow();
        $a = $wf->start('core.access_request', 'X-SUBJECT', 't', null, $this->uid('employee.demo1'));
        $b = $wf->start('core.access_request', 'X-SUBJECT', 't', null, $this->uid('employee.demo1'));
        self::assertSame($a, $b);
        self::assertSame(1, (int) $this->t->app->db()->scalar("SELECT COUNT(*) FROM workflow_instances WHERE subject_id = 'X-SUBJECT'"));
        unset($wf);
        self::assertInstanceOf(DateTimeImmutable::class, $this->t->clock->now());
    }
}
