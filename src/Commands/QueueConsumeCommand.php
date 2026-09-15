<?php

declare(strict_types=1);

namespace Naf\Queue\Commands;

use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\Decorators\AutoResolvingContainer;
use Naf\Queue\Core\QueueJobInterface;
use Naf\Queue\Decorators\Drivers\ChannelDeadletterDriverInterface;
use Naf\Queue\Drivers\LeaseQueueDriverInterface;
use Naf\Queue\Drivers\QueueDeadletterDriverInterface;
use RuntimeException;
use Throwable;

use function Naf\app;
use function Naf\config;
use function Naf\log;
use function Naf\Queue\queue;

class QueueConsumeCommand extends AbstractCommand
{
    public const string NAME = 'queue:consume';

    private const int SLEEP_DELAY = 1;

    protected function configure(): void
    {
        $this->setTitle('NAF Queue Worker')
            ->setDescription('Run the queue worker')
            ->addOption('once')
            ->addOption('verbose', 'v')
            ->addOption('channel', null, true)
            ->addOption('channels', null, true)
            ->addOption('max-jobs', null, true)
            ->addOption('max-runtime', null, true);
    }

    public function run(Input $input, Output $output): int
    {
        if ($input->getOption('help')) {
            $this->showHelp($output);

            return self::SUCCESS;
        }

        $jobCount    = 0;
        $maxJobs     = $input->getOption('max-jobs') ?? null;
        $maxRuntime  = $input->getOption('max-runtime') ?? null;
        $timeStarted = time();
        $isVerbose   = $input->getOption('verbose');

        $once     = $input->getOption('once');
        $channels = $this->resolveChannels($input);

        $container = app()->container();

        do {
            $heartbeat = config('queue:heartbeat_file');
            if (
                is_string($heartbeat)
                && $heartbeat !== ''
                && file_put_contents($heartbeat, (string) time(), LOCK_EX) === false
            ) {
                throw new RuntimeException('Cannot write queue heartbeat.');
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }

            if ($maxJobs && $jobCount >= $maxJobs) {
                $message = 'Max jobs reached... Quitting.';
                if ($isVerbose) {
                    $output->writeLine($message);
                }
                log()->info($message);
                break;
            }

            if ($maxRuntime && time() >= $timeStarted + $maxRuntime) {
                $message = 'Max runtime reached... Quitting.';
                if ($isVerbose) {
                    $output->writeLine($message);
                }
                log()->info($message);
                break;
            }

            [$jobData, $channelUsed] = $this->popFromChannels($channels);

            if (!$jobData) {
                if ($once) {
                    return static::SUCCESS;
                }
                if ($isVerbose) {
                    echo " Waiting for new job...\r";
                }
                sleep(static::SLEEP_DELAY);
                continue;
            }

            $class    = $jobData['class'];
            $payload  = $jobData['payload'];
            $queue    = queue($channelUsed);
            $leased   = $queue->driver() instanceof LeaseQueueDriverInterface;
            $attempts = $leased ? (int) $jobData['attempts'] - 1 : $payload['_attempts'] ?? 0;

            try {
                $attempts++;
                if (!class_exists($class)) {
                    throw new RuntimeException("Job class $class not found.");
                }

                if ($container instanceof AutoResolvingContainer) {
                    $job = app()->container()->make($class, $payload);
                } else {
                    $job = new $class($payload);
                }

                if (!($job instanceof QueueJobInterface)) {
                    throw new RuntimeException("$class does not implement QueueJobInterface.");
                }

                $date = date('Y-m-d H:i:s');

                if ($isVerbose) {
                    $output->writeLine("🕛 Job $class started at $date (attempt $attempts)...");
                }

                $start = microtime(true);
                $job->execute($output);

                if ($leased) {
                    $queue->driver()->acknowledge($jobData);
                }

                if ($isVerbose) {
                    $output->writeEmptyLine();
                    $output->writeLine(
                        "✔ Job $class done in " . number_format(microtime(true) - $start, 5) . 's.',
                    );
                }

                $jobCount++;
            } catch (Throwable $exception) {
                if ($isVerbose) {
                    $output->writeLine(
                        "⚠ Job $class failed: {$exception->getMessage()} (attempt $attempts)",
                    );
                }

                if ($leased) {
                    $queue->driver()->release(
                        $jobData,
                        $exception,
                        (int) config('queue:max_attempts', 3),
                        (int) config('queue:retry_delay', 5),
                    );
                    $jobCount++;
                    if ($once) {
                        return static::ERROR;
                    }
                    continue;
                }

                if ($attempts >= config('queue:max_attempts', 3)) {
                    $driver = $queue->driver();

                    if ($driver instanceof ChannelDeadletterDriverInterface) {
                        $driver->deadletterTo($channelUsed, $class, $payload, $exception);
                    } elseif ($driver instanceof QueueDeadletterDriverInterface) {
                        $driver->deadletter($class, $payload, $exception);
                    }

                    if ($isVerbose) {
                        $output->writeLine("❌ Giving up on $class after $attempts attempts.");
                    }
                    log()->error(
                        'Error still persisted after '
                            . $attempts
                            . ' attempts: '
                            . $exception->getMessage(),
                    );
                    $jobCount++;
                } else {
                    $payload['_attempts'] = $attempts;
                    sleep(config('queue:retry_delay', 5));
                    $queue->push($class, $payload);
                    if ($isVerbose) {
                        $output->writeLine("🔁 Retrying $class...");
                    }
                }
                if ($once) {
                    return static::ERROR;
                }
            }

            if ($isVerbose) {
                $output->writeLine('---');
                $output->writeEmptyLine();
            }

            if ($once) {
                return static::SUCCESS;
            }
        } while (true);

        return static::SUCCESS;
    }

    /**
     * @return string[] ordered a list of channels to listen to
     */
    private function resolveChannels(Input $input): array
    {
        $channels = [];

        $single = $input->getOption('channel');
        if (is_string($single) && trim($single) !== '') {
            $channels[] = trim($single);
        }

        $multi = $input->getOption('channels');
        if (is_string($multi) && trim($multi) !== '') {
            foreach (explode(',', $multi) as $channel) {
                $channel = trim($channel);
                if ($channel !== '') {
                    $channels[] = $channel;
                }
            }
        }

        // Fallback
        if ($channels === []) {
            $channels[] = 'default';
        }

        // Deduplicate while preserving order
        return array_values(array_unique($channels));
    }

    /**
     * @param string[] $channels
     * @return array{0:?array,1:string} [jobData, channelUsed]
     */
    private function popFromChannels(array $channels): array
    {
        foreach ($channels as $channel) {
            $job = queue($channel)->pop();
            if ($job) {
                return [$job, $channel];
            }
        }

        // none found -> return null and first channel for consistency
        return [null, $channels[0] ?? 'default'];
    }
}
