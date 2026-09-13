<?php

declare(strict_types=1);

namespace Naf\Queue\Decorators\Drivers;

use Throwable;

interface ChannelDeadletterDriverInterface
{
    public function deadletterTo(string $channel, string $class, array $payload, Throwable $exception): void;
    public function retryFailedFrom(string $channel, bool $keep = false): int;
}