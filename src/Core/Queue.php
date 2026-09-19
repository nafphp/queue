<?php

declare(strict_types=1);

namespace Naf\Queue\Core;

use Naf\Queue\Drivers\QueueDriverInterface;

use function Naf\app;

class Queue
{
    protected QueueDriverInterface $driver;

    /**
     * @param QueueDriverInterface $driver
     */
    public function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * @param string $class
     * @param array  $payload
     *
     * @return void
     */
    public function push(string $class, array $payload = []): void
    {
        $this->driver->enqueue($class, $payload);
    }

    /**
     * @return array|null
     */
    public function pop(): ?array
    {
        return $this->driver->dequeue();
    }

    /**
     * @return QueueDriverInterface
     */
    public function driver(): QueueDriverInterface
    {
        return $this->driver;
    }

    /**
     * @param string $class
     * @param array  $payload
     *
     * @return void
     */
    public function pushAndRun(string $class, array $payload = []): void
    {
        $this->push($class, $payload);

        $basePath = escapeshellarg(app()->getBasePath());
        $command  = 'cd ' . $basePath . ' && ./vendor/bin/naf queue:consume --once';

        // Fire off a background PHP process to handle the next job
        exec($command . ' > /dev/null 2>&1 &');
    }
}
