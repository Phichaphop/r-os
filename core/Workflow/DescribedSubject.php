<?php

declare(strict_types=1);

namespace Blue\Core\Workflow;

/** A (module) workflow subject that tells the approver what is being decided — shown next to the decision buttons. */
interface DescribedSubject
{
    /**
     * @param array<string, mixed> $instance workflow_instances row
     * @return array{rows: list<array{0: string, 1: string}>, link: ?string} label/value rows (already translated) and an optional detail link
     */
    public function describe(array $instance): array;
}
