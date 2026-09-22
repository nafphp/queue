# Working on naf/queue

NAF is a small PHP framework with optional Composer plugins. Its core owns boot,
configuration, the service container, routing, events and PSR-7 responses. Prefer existing
NAF helpers, services and extension interfaces; keep application business rules in the host.
This package declares `type: naf-plugin` and is discovered after installation in a NAF host.
The plugin repository itself is not the application's web root.

Before changing code, read the [shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md)
and [release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the same documents are in the sibling `docs/` checkout;
use the linked copies when working from a standalone clone. Preserve other contributors' work.
Review and update user documentation with every behavior change. Source fixes use an RC branch;
verified documentation-only changes can be merged and published by the agent.

## What this plugin does

`naf/queue` queues class-name jobs and runs them through the NAF CLI worker. Install with
`composer require naf/queue`; `naf/cli` is a dependency. The default bootstrap uses a file
driver; an SQLite driver and driver interfaces are also available. The scheduler is a separate
plugin that can enqueue work here.

## Use it

Create a host class at `app/Jobs/ReportJob.php`:

```php
<?php
namespace App\Jobs;

use Naf\CLI\Core\Output;
use Naf\Queue\Core\QueueJobInterface;

final class ReportJob implements QueueJobInterface
{
    public function __construct(private string $reportId) {}
    public function execute(Output $output): void
    {
        $output->writeLine('Processing report ' . $this->reportId);
    }
}
```

After host boot, enqueue constructor data:

```php
<?php
use App\Jobs\ReportJob;
use function Naf\Queue\queue;

queue()->push(ReportJob::class, ['reportId' => 'daily']);
```

Run `vendor/bin/naf queue:consume` in the host. Prefer serializable identifiers/payloads and
idempotent job work; do not serialize service objects or depend on a browser request/session.
Use `queue($channel)` only with a driver supporting channels.

## Change it here

[Queue](src/Core/Queue.php), [job contract](src/Core/QueueJobInterface.php),
[drivers](src/Drivers/), [channel decorators](src/Decorators/Drivers/) and
[worker/retry commands](src/Commands/) are the entry points. Replace `Queue::class` with a
configured driver when adapting storage. Preserve claims, acknowledgement, retry/deadletter
behavior and payload reconstruction. Unsupported channels fall back to the default queue with
a warning. Keep worker limits and stale request/database state in mind.

## Verify

Run `composer test` and `composer validate --strict`. Use [tests](tests/) with isolated queue
paths/databases. Test enqueue, consumption, failure, retry and channel isolation through the
actual worker when changing its behavior. Never consume an application's real queue as a test.
No `analyse` script is declared.

User docs: [Queues](https://nafphp.github.io/docs/queues/).

Follow the shared [PHP code style](https://github.com/nafphp/docs/blob/main/CODE_STYLE.md)
and `.php-cs-fixer.dist.php`. Run `composer style:check`; `composer style:fix` applies the rules.
Keep logical steps and local names readable, preserving public signatures and template output.

## Boot order

`extra.naf.boot.after` in `composer.json` declares the prerequisites used by this
plugin during bootstrap. With the automatic-order framework, installed targets boot
first; absent optional targets remain absent. Keep Composer installation requirements
separate from boot order. No host `plugins.php` entry is needed.
