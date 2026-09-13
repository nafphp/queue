<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Queue\Decorators\Drivers\ChannelDeadletterDriverInterface;
use Naf\Queue\Decorators\Drivers\ChannelQueueDriverInterface;

final class FakeChannelDriver implements ChannelQueueDriverInterface, ChannelDeadletterDriverInterface
{
    /** @var array<int, array{0:string,1:array}> */
    public array $calls = [];

    /** @var array<string, list<array{class:string,payload:array}>> */
    private array $queues = [];

    public function enqueue(string $class, array $payload): void
    {
        // not used by ChannelDriver, but required by interface inheritance
        $this->calls[] = ['enqueue', [$class, $payload]];
    }

    public function dequeue(): ?array
    {
        // not used by ChannelDriver, but required by interface inheritance
        $this->calls[] = ['dequeue', []];
        return null;
    }

    public function enqueueTo(string $channel, string $class, array $payload): void
    {
        $this->calls[] = ['enqueueTo', [$channel, $class, $payload]];
        $this->queues[$channel][] = ['class' => $class, 'payload' => $payload];
    }

    public function dequeueFrom(string $channel): ?array
    {
        $this->calls[] = ['dequeueFrom', [$channel]];
        return array_shift($this->queues[$channel]) ?? null;
    }

    public function deadletter(string $class, array $payload, \Throwable $exception): void
    {
        // required by QueueDeadletterDriverInterface (in case it's part of your inheritance graph)
        $this->calls[] = ['deadletter', [$class, $payload, $exception->getMessage()]];
    }

    public function deadletterTo(string $channel, string $class, array $payload, \Throwable $exception): void
    {
        $this->calls[] = ['deadletterTo', [$channel, $class, $payload, $exception->getMessage()]];
    }

    public function retryFailed(bool $keep = false): int
    {
        $this->calls[] = ['retryFailed', [$keep]];
        return 5;
    }

    public function retryFailedFrom(string $channel, bool $keep = false): int
    {
        $this->calls[] = ['retryFailedFrom', [$channel, $keep]];
        return 11;
    }
}
