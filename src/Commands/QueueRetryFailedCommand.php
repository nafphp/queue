<?php

declare(strict_types=1);

namespace Naf\Queue\Commands;

use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\Queue\Drivers\QueueDeadletterDriverInterface;
use function Naf\Queue\queue;

class QueueRetryFailedCommand extends AbstractCommand
{
    public const string NAME = 'queue:retry-failed';

    protected function configure(): void
    {
        $this->setTitle('NAF Queue Deadletter Retry')
            ->setDescription('Retry failed jobs')
            ->addOption('keep');
    }

    public function run(Input $input, Output $output): int
    {
        $driver = queue()->driver();

        if (! ($driver instanceof QueueDeadletterDriverInterface)) {
            $output->writeLine("❌ This driver does not support deadletter operations.");
            return static::ERROR;
        }

        $keep = $input->getOption('keep') ?? false;
        $count = $driver->retryFailed($keep);

        $output->writeLine("🔁 Retried $count failed job(s).");

        return static::SUCCESS;
    }

}