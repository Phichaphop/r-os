<?php

declare(strict_types=1);

namespace Blue\Core\Http;

/** Thrown by RequestContext::requireFreshReauth(); the kernel turns it into a redirect to /reauth. */
final class ReauthRequired extends \RuntimeException
{
}
