<?php

declare(strict_types=1);

namespace Naf\Queue\Drivers;

use JsonException;
use Naf\Queue\Decorators\Drivers\ChannelDriver;
use Naf\Queue\Decorators\Drivers\ChannelQueueDriverInterface;
use Random\RandomException;
use Throwable;

use function Naf\app;
use function Naf\config;
use function Naf\guard;

class FileDriver implements QueueDriverInterface, QueueDeadletterDriverInterface, ChannelQueueDriverInterface
{
    public const string DEFAULT_QUEUE_PATH      = '/storage/queue';
    public const string DEFAULT_DEADLETTER_PATH = '/storage/queue/deadletter';

    /**
     * @param string|null $queuePath
     * @param string|null $deadLetterPath
     */
    public function __construct(
        private readonly ?string $queuePath = null,
        private readonly ?string $deadLetterPath = null,
    ) {
    }

    /**
     * @param string $class
     * @param array  $payload
     *
     * @return void
     * @throws JsonException
     * @throws RandomException
     */
    public function enqueue(string $class, array $payload): void
    {
        $this->enqueueTo(ChannelDriver::DEFAULT_CHANNEL, $class, $payload);
    }

    /**
     * @param string $channel
     * @param string $class
     * @param array  $payload
     *
     * @throws JsonException
     * @throws RandomException
     */
    public function enqueueTo(string $channel, string $class, array $payload): void
    {
        $path = $this->channelPath($channel);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $id                 = guard()->safePath($payload['_job_id'] ?? bin2hex(random_bytes(8)));
        $payload['_job_id'] = $id;

        $tmp   = sprintf('%s/%s.job.tmp', $path, $id);
        $final = sprintf('%s/%s.job', $path, $id);

        file_put_contents($tmp, json_encode([
            'class'   => $class,
            'payload' => $payload,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        rename($tmp, $final); // Prevent processing of corrupted files
    }

    /**
     * @return array|null
     */
    public function dequeue(): ?array
    {
        return $this->dequeueFrom(ChannelDriver::DEFAULT_CHANNEL);
    }

    /**
     * @param string $channel
     *
     * @return array|null
     */
    public function dequeueFrom(string $channel): ?array
    {
        $path  = $this->channelPath($channel);
        $files = glob($path . '/*.job') ?: [];
        if ($files === []) {
            return null;
        }

        sort($files);

        foreach ($files as $file) {
            $claimed = $file . '.lock';

            if (!@rename($file, $claimed)) {
                continue;
            } // Claim job

            $json = @file_get_contents($claimed);
            $data = $json ? json_decode($json, true) : null;

            if (is_array($data) && isset($data['class'])) {
                @unlink($claimed);

                return $data;
            }

            // Probably corrupted, move to /corrupted for later investigation
            $corrupted = $path . '/corrupted';
            if (!is_dir($corrupted)) {
                mkdir($corrupted, 0755, true);
            }
            @rename($claimed, $corrupted . '/' . basename($file));
        }

        return null;
    }

    /**
     * @param string     $class
     * @param array      $payload
     * @param Throwable  $exception
     *
     * @return void
     * @throws RandomException
     */
    public function deadletter(string $class, array $payload, Throwable $exception): void
    {
        // Backwards compatible: default channel
        $this->deadletterTo(ChannelDriver::DEFAULT_CHANNEL, $class, $payload, $exception);
    }

    /**
     * @param string    $channel
     * @param string    $class
     * @param array     $payload
     * @param Throwable $exception
     *
     * @return void
     * @throws RandomException
     */
    public function deadletterTo(string $channel, string $class, array $payload, Throwable $exception): void
    {
        $path = $this->deadletterChannelPath($channel);

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $id                 = guard()->safePath($payload['_job_id'] ?? ('rand_' . bin2hex(random_bytes(8))));
        $payload['_job_id'] = $id;

        $file = $path . '/' . $id . '.job';

        file_put_contents($file, json_encode([
            'id'        => $id,
            'channel'   => $channel,
            'class'     => $class,
            'payload'   => $payload,
            'error'     => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
            'failed_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param bool $keep
     *
     * @return int
     */
    public function retryFailed(bool $keep = false): int
    {
        return $this->retryFailedFrom(ChannelDriver::DEFAULT_CHANNEL, $keep);
    }

    /**
     * @param string $channel
     * @param bool   $keep
     *
     * @return int
     */
    public function retryFailedFrom(string $channel, bool $keep = false): int
    {
        $path = $this->deadletterChannelPath($channel);

        if (!is_dir($path)) {
            return 0;
        }

        $files = glob($path . '/*.job') ?: [];
        $count = 0;

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);

            if (!$data || !isset($data['class'], $data['payload'])) {
                continue;
            }

            $payload = $data['payload'];
            unset($payload['_attempts'], $payload['error'], $payload['trace'], $payload['failed_at']);

            // Important: back into the same channel
            $this->enqueueTo($channel, $data['class'], $payload);
            $count++;

            if (!$keep) {
                unlink($file);
            }
        }

        return $count;
    }

    /**
     * @param string $channel
     *
     * @return string
     */
    private function deadletterChannelPath(string $channel): string
    {
        $channel = guard()->safePath($channel);

        $default = app()->getBasePath() . self::DEFAULT_DEADLETTER_PATH;
        $base    = $this->deadLetterPath ?? config('queue:deadletterPath', $default);

        return rtrim($base, '/') . '/' . $channel;
    }

    /**
     * @param string $channel
     *
     * @return string
     */
    private function channelPath(string $channel): string
    {
        $channel  = guard()->safePath($channel);
        $basePath = app()->getBasePath() . self::DEFAULT_QUEUE_PATH;
        $path     = $this->queuePath ?? config('queue:path', $basePath);

        return rtrim($path, '/') . '/' . $channel;
    }
}
