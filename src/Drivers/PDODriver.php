<?php

declare(strict_types=1);

namespace Naf\Queue\Drivers;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** One default channel. Enqueue uses the caller's PDO transaction; workers use a separate connection. */
final class PDODriver implements LeaseQueueDriverInterface, QueueDeadletterDriverInterface
{
    public function __construct(private PDO $pdo, private int $leaseSeconds = 300)
    {
        if ($leaseSeconds < 1) {
            throw new InvalidArgumentException('Lease must be positive.');
        }
        if (
            !in_array($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql', 'sqlite'], true)
        ) {
            throw new InvalidArgumentException('Unsupported PDO queue driver.');
        }
    }

    /** Explicit installation outside request/transaction paths, suitable for a host migration. */
    public function install(): void
    {
        if ($this->pdo->inTransaction() && $this->driver() === 'mysql') {
            throw new RuntimeException('MySQL queue DDL cannot run inside a transaction.');
        }
        $id = match ($this->driver()) {
            'mysql' => 'BIGINT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS naf_queue_jobs (id $id, job_class VARCHAR(255) NOT NULL, payload TEXT NOT NULL, dedup_key VARCHAR(64) NULL UNIQUE, state VARCHAR(16) NOT NULL DEFAULT 'ready', attempts INTEGER NOT NULL DEFAULT 0, available_at BIGINT NOT NULL, lease_until BIGINT NULL, lease_token VARCHAR(64) NULL, last_error TEXT NULL, created_at BIGINT NOT NULL)",
        );
        if ($this->driver() === 'mysql') {
            $n = $this->pdo
                ->query(
                    "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='naf_queue_jobs' AND index_name='naf_queue_due'",
                )
                ->fetchColumn();
            if (!(int) $n) {
                $this->pdo->exec(
                    'CREATE INDEX naf_queue_due ON naf_queue_jobs(state,available_at,lease_until)',
                );
            }
        } else {
            $this->pdo->exec(
                'CREATE INDEX IF NOT EXISTS naf_queue_due ON naf_queue_jobs(state,available_at,lease_until)',
            );
        }
    }

    public function enqueue(string $class, array $payload): void
    {
        if ($class === '' || strlen($class) > 255) {
            throw new InvalidArgumentException('Invalid job class.');
        }
        $key = isset($payload['_job_id'])
            ? hash('sha256', $class . ':' . (string) $payload['_job_id'])
            : null;
        $sql = 'INSERT INTO naf_queue_jobs(job_class,payload,dedup_key,available_at,created_at) VALUES(?,?,?,?,?)';
        $sql
            .= $this->driver() === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE id=id'
                : ' ON CONFLICT(dedup_key) DO NOTHING';
        $this->pdo
            ->prepare($sql)
            ->execute([$class, json_encode($payload, JSON_THROW_ON_ERROR), $key, time(), time()]);
    }

    public function dequeue(): ?array
    {
        return $this->reserve();
    }

    public function reserve(): ?array
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Reserve jobs outside application transactions.');
        }
        $this->pdo->beginTransaction();

        try {
            $now = time();
            $q   = $this->pdo->prepare(
                "SELECT * FROM naf_queue_jobs WHERE (state='ready' AND available_at<=?) OR (state='reserved' AND lease_until<=?) ORDER BY id LIMIT 1"
                    . $this->lock(),
            );
            $q->execute([$now, $now]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->pdo->commit();

                return null;
            }
            $token = bin2hex(random_bytes(24));
            $this->pdo
                ->prepare(
                    "UPDATE naf_queue_jobs SET state='reserved',attempts=attempts+1,lease_until=?,lease_token=? WHERE id=?",
                )
                ->execute([$now + $this->leaseSeconds, $token, $row['id']]);
            $this->pdo->commit();

            // Decode after committing the reservation: malformed records remain recoverable.
            return [
                'id'       => (string) $row['id'],
                'token'    => $token,
                'class'    => $row['job_class'],
                'payload'  => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
                'attempts' => (int) $row['attempts'] + 1,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function acknowledge(array $reservation): void
    {
        $this->fenced(
            'DELETE FROM naf_queue_jobs WHERE id=? AND lease_token=? AND state=\'reserved\' AND lease_until>?',
            [],
            $reservation,
        );
    }

    public function renew(array $reservation): void
    {
        $this->fenced(
            'UPDATE naf_queue_jobs SET lease_until=CASE WHEN lease_until>=? THEN lease_until+1 ELSE ? END WHERE id=? AND lease_token=? AND state=\'reserved\' AND lease_until>?',
            [time() + $this->leaseSeconds, time() + $this->leaseSeconds],
            $reservation,
        );
    }

    public function release(
        array $reservation,
        Throwable $error,
        int $maxAttempts,
        int $retryDelay,
    ): void {
        $failed = (int) $reservation['attempts'] >= max(1, $maxAttempts);
        $sql    = 'UPDATE naf_queue_jobs SET state=?,available_at=?,lease_token=NULL,lease_until=NULL,last_error=?'
            . ($failed ? ',dedup_key=NULL' : '')
            . " WHERE id=? AND lease_token=? AND state='reserved' AND lease_until>?";
        $this->fenced(
            $sql,
            [
                $failed ? 'failed' : 'ready',
                time() + max(0, $retryDelay),
                substr($error::class . ': ' . $error->getMessage(), 0, 4000),
            ],
            $reservation,
        );
    }

    public function deadletter(string $class, array $payload, Throwable $exception): void
    {
        $this->pdo
            ->prepare(
                "INSERT INTO naf_queue_jobs(job_class,payload,state,available_at,created_at,last_error) VALUES(?,?,'failed',?,?,?)",
            )
            ->execute([
                $class,
                json_encode($payload, JSON_THROW_ON_ERROR),
                time(),
                time(),
                substr($exception::class . ': ' . $exception->getMessage(), 0, 4000),
            ]);
    }

    public function retryFailed(bool $keep = false): int
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Retry failed jobs outside application transactions.');
        }
        $this->pdo->beginTransaction();

        try {
            $rows = $this->pdo
                ->query(
                    "SELECT id,job_class,payload FROM naf_queue_jobs WHERE state='failed' ORDER BY id"
                        . $this->lock(),
                )
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                unset($payload['_attempts']);
                $this->enqueue($row['job_class'], $payload);
                if (!$keep) {
                    $this->pdo
                        ->prepare("DELETE FROM naf_queue_jobs WHERE id=? AND state='failed'")
                        ->execute([$row['id']]);
                }
            }
            $this->pdo->commit();

            return count($rows);
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function fenced(string $sql, array $values, array $reservation): void
    {
        $q = $this->pdo->prepare($sql);
        $q->execute([...$values, $reservation['id'], $reservation['token'], time()]);
        if ($q->rowCount() !== 1) {
            throw new RuntimeException(
                'Queue reservation lease was lost; do not acknowledge or modify another worker\'s claim.',
            );
        }
    }

    private function driver(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function lock(): string
    {
        return $this->driver() === 'sqlite' ? '' : ' FOR UPDATE SKIP LOCKED';
    }
}
