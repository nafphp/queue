<?php

namespace Tests\Unit;

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
