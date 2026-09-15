<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\Core\Config;
use Naf\Queue\Commands\QueueConsumeCommand;
use Naf\Queue\Core\Queue;
use Naf\Queue\Drivers\QueueDeadletterDriverInterface;
use Naf\Queue\Drivers\QueueDriverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use function Naf\app;

final class QueueConsumeCommandTest extends TestCase
{
    public function testMissingClassReturnsFailureAndDeadlettersWithLegacyDrivers(): void
    {
        $container = app()->container();
        $config    = $container->get(Config::class);
        $logger    = $container->get(LoggerInterface::class);
        $hadQueue  = $container->has(Queue::class);
        $queue     = $hadQueue ? $container->get(Queue::class) : null;
        $driver    = $this->createMockForIntersectionOfInterfaces([QueueDriverInterface::class, QueueDeadletterDriverInterface::class]);
        $driver->expects($this->once())->method('dequeue')->willReturn(['class' => 'MissingReviewJob', 'payload' => []]);
        $driver->expects($this->once())->method('deadletter')->with('MissingReviewJob', [], $this->isInstanceOf(RuntimeException::class));
        $container->set(Config::class, new Config(['queue' => ['max_attempts' => 1, 'retry_delay' => 0]]));
        $container->set(LoggerInterface::class, new NullLogger());
        $container->set(Queue::class, new Queue($driver));

        try {
            $command = $container->make(QueueConsumeCommand::class);
            $status  = $command->run(new Input(['--once'], $command->getDefinition()), $this->createStub(Output::class));
            $this->assertSame(1, $status);
        } finally {
            $container->set(Config::class, $config);
            $container->set(LoggerInterface::class, $logger);
            if ($hadQueue) {
                $container->set(Queue::class, $queue);
            } else {
                $container->reset(Queue::class);
            }
        }
    }
}
