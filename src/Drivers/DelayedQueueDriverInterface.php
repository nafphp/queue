<?php

declare(strict_types=1);

namespace Naf\Queue\Drivers;

/** Optional capability: persist a job now and make it eligible at a Unix timestamp. */
interface DelayedQueueDriverInterface extends QueueDriverInterface
{
    public function enqueueAt(string $class, array $payload, int $availableAt): void;
}
