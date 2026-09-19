<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Queue\Decorators\Drivers\ChannelDriver;
use RuntimeException;
use Tests\Fixtures\FakeChannelDriver;
use Tests\Fixtures\FakeGlobalDeadletterDriver;
use Tests\Fixtures\FakeNoDeadletterDriver;
use Tests\NafTestCase;

final class ChannelDriverTest extends NafTestCase
{
    public function testEnqueueRoutesToChannel(): void
    {
        $fake = new FakeChannelDriver();
        $d    = new ChannelDriver($fake, 'mcp_out');

        $d->enqueue('JobX', ['a' => 1]);

        $this->assertSame(['enqueueTo', ['mcp_out', 'JobX', ['a' => 1]]], $fake->calls[0]);
    }

    public function testDequeueRoutesToChannel(): void
    {
        $fake = new FakeChannelDriver();
        $fake->enqueueTo('emails', 'JobY', ['x' => 1]);

        $d   = new ChannelDriver($fake, 'emails');
        $job = $d->dequeue();

        $this->assertSame('JobY', $job['class']);
        $this->assertSame(1, $job['payload']['x']);

        // last call should be dequeueFrom('emails')
        $this->assertSame(['dequeueFrom', ['emails']], $fake->calls[array_key_last($fake->calls)]);
    }

    public function testDeadletterUsesChannelAwareWhenAvailable(): void
    {
        $fake = new FakeChannelDriver();
        $d    = new ChannelDriver($fake, 'a');

        $d->deadletter('FailJob', ['foo' => 'bar'], new RuntimeException('boom'));

        // should call deadletterTo(channel,...)
        $this->assertSame(['deadletterTo', ['a', 'FailJob', ['foo' => 'bar'], 'boom']], $fake->calls[0]);
    }

    public function testDeadletterFallsBackToGlobalWhenChannelDeadletterNotAvailable(): void
    {
        $fake = new FakeGlobalDeadletterDriver();
        $d    = new ChannelDriver($fake, 'b');

        $d->deadletter('FailJob', ['x' => 1], new RuntimeException('nope'));

        $this->assertSame(['deadletter', ['FailJob', ['x' => 1], 'nope']], $fake->calls[0]);
    }

    public function testRetryFailedUsesChannelAwareWhenAvailable(): void
    {
        $fake = new FakeChannelDriver();
        $d    = new ChannelDriver($fake, 'emails');

        $count = $d->retryFailed(true);

        $this->assertSame(11, $count);
        $this->assertSame(['retryFailedFrom', ['emails', true]], $fake->calls[0]);
    }

    public function testRetryFailedFallsBackToGlobalWhenChannelRetryNotAvailable(): void
    {
        $fake = new FakeGlobalDeadletterDriver();
        $d    = new ChannelDriver($fake, 'default');

        $count = $d->retryFailed(false);

        $this->assertSame(3, $count);
        $this->assertSame(['retryFailed', [false]], $fake->calls[0]);
    }

    public function testRetryFailedReturnsZeroIfNoDeadletterSupport(): void
    {
        $fake = new FakeNoDeadletterDriver();
        $d    = new ChannelDriver($fake, 'x');

        $this->assertSame(0, $d->retryFailed());
    }
}
