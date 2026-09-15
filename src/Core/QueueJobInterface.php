<?php

declare(strict_types=1);

namespace Naf\Queue\Core;

use Naf\CLI\Core\Output;

interface QueueJobInterface
{
    /**
     * @param Output $output
     *
     * @return void
     */
    public function execute(Output $output): void;
}
