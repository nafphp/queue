<?php

declare(strict_types=1);

namespace Naf\Queue\Drivers;

use Throwable;

interface QueueDeadletterDriverInterface
{

    /**
     * @param string     $class
     * @param array      $payload
     * @param Throwable  $exception
     *
     * @return void
     */
    public function deadletter(string $class, array $payload, Throwable $exception): void;

    /**
     * @param bool $keep
     *
     * @return int
     */
    public function retryFailed(bool $keep = false): int;

}
