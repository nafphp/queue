<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Queue\Decorators\Drivers\ChannelQueueDriverInterface;

final class FakeNoDeadletterDriver implements ChannelQueueDriverInterface
{
    /** @var array<int, array{0:string,1:array}> */
    public array $calls = [];

    public function enqueue(string $class, array $payload): void
    {
        $this->calls[] = ['enqueue', [$class, $payload]];
    }

    public function dequeue(): ?array
    {
        $this->calls[] = ['dequeue', []];

        return null;
    }

    public function enqueueTo(string $channel, string $class, array $payload): void
    {
        $this->calls[] = ['enqueueTo', [$channel, $class, $payload]];
    }

    public function dequeueFrom(string $channel): ?array
    {
        $this->calls[] = ['dequeueFrom', [$channel]];

        return null;
    }
}
