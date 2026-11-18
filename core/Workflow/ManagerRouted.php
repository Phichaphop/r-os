<?php

declare(strict_types=1);

namespace Blue\Core\Workflow;

/**
 * A workflow subject that can be routed to the line manager of the person it is about (approver type "manager").
 * The engine never routes to the requester; when no active manager is found the step goes to the fallback permission.
 */
interface ManagerRouted
{
    /** @return list<int> user ids of the subject's line manager(s) */
    public function managerUserIds(string $subjectId): array;
}
