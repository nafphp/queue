<div align="center" style="text-align: center;">

![NAF](assets/naf-logo-small-square.png)

[![NAF Queue Plugin](https://github.com/nafphp/queue/actions/workflows/php.yml/badge.svg)](https://github.com/nafphp/queue/actions/workflows/php.yml)

</div>

[← Back to NAF](https://github.com/nafphp/framework)

---

# naf/queue

> **Minimalistic queueing for NAF – file-based, simple, and extendable.**

This plugin provides a lightweight job queue system with CLI worker support and no external dependencies by default.

> 🧩 Part of the official NAF plugin collection.  
> Use it when you want to delay tasks, run background jobs, or decouple logic – without setting up Redis or RabbitMQ.

## Documentation

**[Queues and workers →](https://nafphp.github.io/docs/queues/)**

Everything about this package — what it does, how it is configured and what it needs — lives
in the [NAF documentation](https://nafphp.github.io/docs/). Not sure which packages you need?
[Start here](https://nafphp.github.io/docs/choosing-packages/).

## Install

```bash
composer require naf/queue
```

## License

MIT. Part of [NAF](https://github.com/nafphp/framework).


## Unreleased Nafinity integration candidate

Target branch: `v0.2.3-rc`. This behavior is not a published release yet.

`queue:consume --once` returns a nonzero exit code when the selected job fails, including
missing job classes, with both legacy drivers and the PDO lease driver. Retry/deadletter
handling still retains the failed work.

PDODriver adds durable reserve/acknowledge/release/renew semantics through LeaseQueueDriverInterface. Explicitly call install(), bind the configured PDO driver, and use the default channel. Enqueue participates in a caller transaction. PostgreSQL/MariaDB use row locks with SKIP LOCKED; SQLite has a serialized contract implementation. Claims must be made outside an existing transaction. A failed/crashed claim becomes available after its lease expires; fenced tokens reject stale acknowledgements. Retries and deadletters retain attempts. The queue:consume command handles this contract and acknowledges only after success. Custom dequeue consumers must acknowledge the returned reservation. Ensure jobs finish inside the lease or explicitly renew; external delivery remains at least once. Optional queue:heartbeat_file records worker polling activity.

## PHP code style

Source, tests and PHP templates follow the shared [NAF code style](https://github.com/nafphp/docs/blob/main/CODE_STYLE.md)
(PER Coding Style 3.0 with the Nafinity readability rules). After `composer install`, run
`composer style:check` to verify formatting or `composer style:fix` to apply it. The formatter
is a development dependency. Review template output and run the package checks after changes.
