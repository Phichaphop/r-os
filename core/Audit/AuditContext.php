<?php

declare(strict_types=1);

namespace Blue\Core\Audit;

/** Who is acting in the current request/job — filled by middleware, read by AuditLogger. */
final class AuditContext
{
    public ?int $actorId = null;
    public ?int $onBehalfOfId = null;
    public string $actorType = 'anonymous';
    public string $correlationId = '-';
    public ?string $ipHash = null;
}
