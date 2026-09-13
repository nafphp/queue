<?php

declare(strict_types=1);

namespace Naf\Queue\Drivers;

interface QueueDriverInterface
{
    /**
     * @param string $class
     * @param array  $payload
     *
     * @return void
     */
    public function enqueue(string $class, array $payload): void;

    /**
     * @return array|null
     */
    public function dequeue(): ?array;
}
