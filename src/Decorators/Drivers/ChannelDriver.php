<?php

declare(strict_types=1);

namespace Naf\Queue\Decorators\Drivers;

use Naf\Queue\Drivers\QueueDeadletterDriverInterface;
use Naf\Queue\Drivers\QueueDriverInterface;
use Throwable;

class ChannelDriver implements QueueDriverInterface, QueueDeadletterDriverInterface
{
    public const string DEFAULT_CHANNEL = 'default';

    public function __construct(
        private readonly ChannelQueueDriverInterface $driver,
        private readonly string                      $channel,
    ) {
    }

    public function enqueue(string $class, array $payload): void
    {
        $this->driver->enqueueTo($this->channel, $class, $payload);
    }

    public function dequeue(): ?array
    {
        return $this->driver->dequeueFrom($this->channel);
    }

    public function deadletter(string $class, array $payload, Throwable $exception): void
    {
        // channel-aware deadletter
        if ($this->driver instanceof ChannelDeadletterDriverInterface) {
            $this->driver->deadletterTo($this->channel, $class, $payload, $exception);

            return;
        }

        // fallback (without channel support): global deadletter
        if ($this->driver instanceof QueueDeadletterDriverInterface) {
            $this->driver->deadletter($class, $payload, $exception);
        }
    }

    public function retryFailed(bool $keep = false): int
    {
        if ($this->driver instanceof ChannelDeadletterDriverInterface) {
            return $this->driver->retryFailedFrom($this->channel, $keep);
        }

        if ($this->driver instanceof QueueDeadletterDriverInterface) {
            return $this->driver->retryFailed($keep);
        }

        return 0;
    }
}
