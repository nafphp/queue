<?php

namespace Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Naf\Queue\Core\Queue;
use Naf\Queue\Drivers\QueueDriverInterface;
use Tests\NafTestCase;

class QueueTest extends NafTestCase
{
    private Queue $queue;
    private QueueDriverInterface $driver;

    protected function setUp(): void
    {
        $this->driver = $this->createMock(QueueDriverInterface::class);
        $this->queue  = new Queue($this->driver);
    }

    public function testPushCallsDriverEnqueue(): void
    {
        $this->driver->expects($this->once())
            ->method('enqueue')
            ->with('TestJob', ['foo' => 'bar']);

        $this->queue->push('TestJob', ['foo' => 'bar']);
    }

    public function testDelayedJobsRequireAnExplicitDriverCapability(): void
    {
        $this->driver->expects($this->never())->method('enqueue');
        $this->expectException(LogicException::class);
        $this->queue->pushAfter(30, 'TestJob');
    }

    public function testNegativeDelaysAreRejected(): void
    {
        $this->driver->expects($this->never())->method('enqueue');
        $this->expectException(InvalidArgumentException::class);
        $this->queue->pushAfter(-1, 'TestJob');
    }

    public function testPopCallsDriverDequeue(): void
    {
        $expectedData = ['class' => 'TestJob', 'payload' => ['foo' => 'bar']];

        $this->driver->expects($this->once())
            ->method('dequeue')
            ->willReturn($expectedData);

        $result = $this->queue->pop();
        $this->assertSame($expectedData, $result);
    }

    public function testPopReturnsNullWhenNoJobs(): void
    {
        $this->driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(null);

        $result = $this->queue->pop();
        $this->assertNull($result);
    }

    public function testPushWithEmptyPayload(): void
    {
        $this->driver->expects($this->once())
            ->method('enqueue')
            ->with('TestJob', []);

        $this->queue->push('TestJob');
    }
}
