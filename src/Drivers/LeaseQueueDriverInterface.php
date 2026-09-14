<?php
declare(strict_types=1);
namespace Naf\Queue\Drivers;
use Throwable;
/** Optional at-least-once delivery. Reservations are opaque and fenced by their token. */
interface LeaseQueueDriverInterface extends QueueDriverInterface
{
    public function reserve(): ?array;
    public function acknowledge(array $reservation): void;
    public function release(array $reservation, Throwable $error, int $maxAttempts, int $retryDelay): void;
    public function renew(array $reservation): void;
}
