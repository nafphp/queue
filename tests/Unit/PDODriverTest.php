<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Queue\Drivers\PDODriver;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PDODriverTest extends TestCase
{
    private PDO $pdo;
    private PDODriver $driver;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->driver = new PDODriver($this->pdo, 30);
        $this->driver->install();
    }

    public function testTransactionalEnqueueAndCoalescing(): void
    {
        $this->pdo->beginTransaction();
        $this->driver->enqueue('Job', []);
        $this->pdo->rollBack();
        self::assertNull($this->driver->reserve());
        $this->driver->enqueue('Job', ['_job_id' => 'one']);
        $this->driver->enqueue('Job', ['_job_id' => 'one']);
        $job = $this->driver->reserve();
        self::assertNotNull($job);
        self::assertNull($this->driver->reserve());
        $this->driver->renew($job);
        $this->driver->acknowledge($job);
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM naf_queue_jobs')->fetchColumn(),
        );
    }

    public function testExpiredReservationIsRecoveredAndOldOwnerFenced(): void
    {
        $this->driver->enqueue('Job', []);
        $first = $this->driver->reserve();
        $this->pdo->exec('UPDATE naf_queue_jobs SET lease_until=0');
        $second = $this->driver->reserve();
        self::assertSame(2, $second['attempts']);
        self::assertNotSame($first['token'], $second['token']);

        try {
            $this->driver->acknowledge($first);
            self::fail('Old owner ack accepted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('lease was lost', $e->getMessage());
        }
        $this->driver->acknowledge($second);
        self::assertNull($this->driver->reserve());
    }

    public function testRetryDeadletterAndManualRecovery(): void
    {
        $this->driver->enqueue('Job', ['hello' => 'world']);
        $one = $this->driver->reserve();
        $this->driver->release($one, new RuntimeException('failed'), 2, 0);
        $two = $this->driver->reserve();
        self::assertSame(2, $two['attempts']);
        $this->driver->release($two, new RuntimeException('failed again'), 2, 0);
        self::assertNull($this->driver->reserve());
        self::assertSame(
            'failed',
            $this->pdo->query('SELECT state FROM naf_queue_jobs')->fetchColumn(),
        );
        self::assertSame(1, $this->driver->retryFailed());
        $retry = $this->driver->reserve();
        self::assertSame(1, $retry['attempts']);
        self::assertSame(['hello' => 'world'], $retry['payload']);
        $this->driver->acknowledge($retry);
    }
}
