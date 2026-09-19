<?php

declare(strict_types=1);

namespace Naf\Queue\Decorators\Drivers;

use Naf\Queue\Drivers\QueueDriverInterface;

interface ChannelQueueDriverInterface extends QueueDriverInterface
{
    public function enqueueTo(string $channel, string $class, array $payload): void;

    public function dequeueFrom(string $channel): ?array;
}
