<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Naf\Queue\Drivers\FileDriver;
use Tests\NafTestCase;

final class FileDriverTest extends NafTestCase
{
    private string $basePath;
    private FileDriver $driver;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/naf-queue-test-' . uniqid('', true);
        @mkdir($this->basePath . '/storage/queue', 0777, true);

        $this->driver = new FileDriver(
            $this->basePath . '/storage/queue',
            $this->basePath . '/storage/queue/deadletter'
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->basePath);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Helper: find where the driver wrote a job file for a given id.
     * Supports both old and new directory layouts.
     */
    private function findJobFile(string $jobId, ?string $channel = null): ?string
    {
        $paths = [];

        // old layout (no channels)
        $paths[] = $this->basePath . '/storage/queue/' . $jobId . '.job';

        // new layout (channels)
        if ($channel !== null) {
            $paths[] = $this->basePath . '/storage/queue/' . $channel . '/' . $jobId . '.job';
        } else {
            $paths[] = $this->basePath . '/storage/queue/default/' . $jobId . '.job';
        }

        foreach ($paths as $p) {
            if (is_file($p)) return $p;
        }

        return null;
    }

    private function assertMethodExists(string $method): void
    {
        $this->assertTrue(
            method_exists($this->driver, $method),
            "Expected FileDriver to have method {$method}()."
        );
    }

    public function testEnqueueAndDequeueDefault(): void
    {
        $this->driver->enqueue('TestJob', ['foo' => 'bar']);
        $data = $this->driver->dequeue();

        $this->assertNotNull($data);
        $this->assertSame('TestJob', $data['class']);
        $this->assertSame('bar', $data['payload']['foo']);
        $this->assertArrayHasKey('_job_id', $data['payload']);
    }

    public function testEnqueueGeneratesJobIdWhenMissing(): void
    {
        // Channel-aware enqueue preferred; fallback to default enqueue
        if (method_exists($this->driver, 'enqueueTo')) {
            $this->driver->enqueueTo('gen', 'GenJob', ['foo' => 'bar']);
            $job = $this->driver->dequeueFrom('gen');
        } else {
            $this->driver->enqueue('GenJob', ['foo' => 'bar']);
            $job = $this->driver->dequeue();
        }

        $this->assertNotNull($job);
        $this->assertSame('GenJob', $job['class']);
        $this->assertArrayHasKey('_job_id', $job['payload']);
        $this->assertNotSame('', (string) $job['payload']['_job_id']);
    }

    public function testChannelsAreIsolatedIfSupported(): void
    {
        if (!method_exists($this->driver, 'enqueueTo') || !method_exists($this->driver, 'dequeueFrom')) {
            $this->markTestSkipped('Channel methods not available on this FileDriver version.');
        }

        $this->driver->enqueueTo('a', 'JobA', ['x' => 1]);
        $this->driver->enqueueTo('b', 'JobB', ['x' => 2]);

        $jobA = $this->driver->dequeueFrom('a');
        $jobB = $this->driver->dequeueFrom('b');

        $this->assertSame('JobA', $jobA['class']);
        $this->assertSame(1, $jobA['payload']['x']);

        $this->assertSame('JobB', $jobB['class']);
        $this->assertSame(2, $jobB['payload']['x']);

        $this->assertNull($this->driver->dequeueFrom('a'));
        $this->assertNull($this->driver->dequeueFrom('b'));
    }

    public function testFifoOrderWithinChannelIfSupported(): void
    {
        if (!method_exists($this->driver, 'enqueueTo') || !method_exists($this->driver, 'dequeueFrom')) {
            $this->markTestSkipped('Channel methods not available on this FileDriver version.');
        }

        // With lexicographical sorting, this gives deterministic order
        $this->driver->enqueueTo('fifo', 'Job1', ['_job_id' => '0001']);
        $this->driver->enqueueTo('fifo', 'Job2', ['_job_id' => '0002']);
        $this->driver->enqueueTo('fifo', 'Job3', ['_job_id' => '0003']);

        $j1 = $this->driver->dequeueFrom('fifo');
        $j2 = $this->driver->dequeueFrom('fifo');
        $j3 = $this->driver->dequeueFrom('fifo');

        $this->assertSame('Job1', $j1['class']);
        $this->assertSame('Job2', $j2['class']);
        $this->assertSame('Job3', $j3['class']);
    }

    public function testDequeueSkipsInvalidJobFiles(): void
    {
        // enqueue a valid job with known id
        $this->driver->enqueue('RealJob', ['_job_id' => '0002', 'foo' => 'bar']);

        $validFile = $this->findJobFile('0002', 'default');
        $this->assertNotNull($validFile, 'Could not find enqueued job file in expected locations.');
        $dir = dirname($validFile);

        // invalid JSON comes before valid file when sorted
        file_put_contents($dir . '/0001.job', '{not-json');

        $job = $this->driver->dequeue();

        $this->assertNotNull($job);
        $this->assertSame('RealJob', $job['class']);
        $this->assertSame('bar', $job['payload']['foo']);
    }

    public function testDeadletterDefaultChannel(): void
    {
        $payload = ['foo' => 'bar', '_job_id' => 'abc123'];
        $exception = new \RuntimeException('Something went wrong');

        $this->driver->deadletter('FailingJob', $payload, $exception);

        // Your current expected path (channel-aware deadletter)
        $file = $this->basePath . '/storage/queue/deadletter/default/abc123.job';
        $this->assertFileExists($file);

        $data = json_decode((string) file_get_contents($file), true);

        $this->assertSame('FailingJob', $data['class']);
        $this->assertSame('Something went wrong', $data['error']);
    }

    public function testDeadletterPerChannelIfSupported(): void
    {
        if (!method_exists($this->driver, 'deadletterTo')) {
            $this->markTestSkipped('Channel deadletter methods not available on this FileDriver version.');
        }

        $payload = ['foo' => 'bar', '_job_id' => 'dead-a'];
        $this->driver->deadletterTo('a', 'FailingJob', $payload, new \RuntimeException('nope'));

        $file = $this->basePath . '/storage/queue/deadletter/a/dead-a.job';
        $this->assertFileExists($file);

        $data = json_decode((string) file_get_contents($file), true);
        $this->assertSame('a', $data['channel']);
        $this->assertSame('FailingJob', $data['class']);
        $this->assertSame('nope', $data['error']);
        $this->assertSame('dead-a', $data['id']);
    }

    public function testRetryFailedDefaultChannel(): void
    {
        $payload = ['foo' => 'bar', '_job_id' => 'retryme'];
        $exception = new \RuntimeException('Nope');

        $this->driver->deadletter('RetryJob', $payload, $exception);

        $count = $this->driver->retryFailed();
        $this->assertSame(1, $count);

        $dequeued = $this->driver->dequeue();
        $this->assertNotNull($dequeued);
        $this->assertSame('RetryJob', $dequeued['class']);
        $this->assertSame('bar', $dequeued['payload']['foo']);
        $this->assertArrayHasKey('_job_id', $dequeued['payload']);
        $this->assertSame('retryme', $dequeued['payload']['_job_id']);
    }

    public function testRetryKeepsFileDefaultChannel(): void
    {
        $payload = ['foo' => 'bar', '_job_id' => 'keepme'];
        $this->driver->deadletter('KeepJob', $payload, new \Exception('fail'));

        $count = $this->driver->retryFailed(true);
        $this->assertSame(1, $count);

        $path = $this->basePath . '/storage/queue/deadletter/default/keepme.job';
        $this->assertFileExists($path);
    }

    public function testRetryFailedFromChannelIfSupported(): void
    {
        if (!method_exists($this->driver, 'retryFailedFrom') || !method_exists($this->driver, 'deadletterTo')) {
            $this->markTestSkipped('Channel retry methods not available on this FileDriver version.');
        }

        $payload = ['foo' => 'bar', '_job_id' => 'retry-a'];
        $this->driver->deadletterTo('a', 'RetryJob', $payload, new \RuntimeException('fail'));

        $count = $this->driver->retryFailedFrom('a');
        $this->assertSame(1, $count);

        $job = $this->driver->dequeueFrom('a');
        $this->assertNotNull($job);
        $this->assertSame('RetryJob', $job['class']);
        $this->assertSame('bar', $job['payload']['foo']);
        $this->assertSame('retry-a', $job['payload']['_job_id']);

        // should not be in default queue
        $this->assertNull($this->driver->dequeue());
    }

    public function testRetryKeepDoesNotDeleteDeadletterFileInChannelIfSupported(): void
    {
        if (!method_exists($this->driver, 'retryFailedFrom') || !method_exists($this->driver, 'deadletterTo')) {
            $this->markTestSkipped('Channel retry methods not available on this FileDriver version.');
        }

        $payload = ['foo' => 'bar', '_job_id' => 'keep-a'];
        $this->driver->deadletterTo('a', 'KeepJob', $payload, new \RuntimeException('fail'));

        $count = $this->driver->retryFailedFrom('a', true);
        $this->assertSame(1, $count);

        $file = $this->basePath . '/storage/queue/deadletter/a/keep-a.job';
        $this->assertFileExists($file);
    }

    public function testChannelNameIsSanitizedIfSupported(): void
    {
        if (!method_exists($this->driver, 'enqueueTo') || !method_exists($this->driver, 'dequeueFrom')) {
            $this->markTestSkipped('Channel methods not available on this FileDriver version.');
        }
        
        $this->expectException(InvalidArgumentException::class);

        // Try path traversal-like channel. We only assert: consistent mapping + job works.
        $this->driver->enqueueTo('../evil', 'EvilJob', ['_job_id' => 'x', 'foo' => 'bar']);
        $job = $this->driver->dequeueFrom('../evil');

        $this->assertNotNull($job);
        $this->assertSame('EvilJob', $job['class']);
        $this->assertSame('bar', $job['payload']['foo']);
    }

    public function testDeadletterGeneratesJobIdWhenMissingIfSupported(): void
    {
        if (!method_exists($this->driver, 'deadletterTo')) {
            $this->markTestSkipped('Channel deadletter methods not available on this FileDriver version.');
        }

        $this->driver->deadletterTo('gen', 'FailJob', ['foo' => 'bar'], new \RuntimeException('fail'));

        $dir = $this->basePath . '/storage/queue/deadletter/gen';
        $this->assertDirectoryExists($dir);

        $files = glob($dir . '/*.job') ?: [];
        $this->assertCount(1, $files);

        $data = json_decode((string) file_get_contents($files[0]), true);
        $this->assertSame('gen', $data['channel']);
        $this->assertSame('FailJob', $data['class']);
        $this->assertSame('fail', $data['error']);
        $this->assertNotEmpty($data['id']);
    }
}
