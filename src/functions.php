<?php

declare(strict_types=1);

namespace Naf\Queue;

use Naf\Queue\Core\Queue;
use Naf\Queue\Decorators\Drivers\ChannelDriver;
use Naf\Queue\Decorators\Drivers\ChannelQueueDriverInterface;

use function Naf\app;
use function Naf\log;

function queue(?string $channel = null): Queue
{
    $defaultQueue = app()->container()->get(Queue::class);

    if (empty($channel) || $channel === ChannelDriver::DEFAULT_CHANNEL) {
        return $defaultQueue;
    }

    $driver = $defaultQueue->driver();

    if (!$driver instanceof ChannelQueueDriverInterface) {
        log()->warning('Queue driver does not support channels.');

        return $defaultQueue;
    }

    return new Queue(new ChannelDriver($driver, $channel));
}
