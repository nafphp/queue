<?php

declare(strict_types=1);

namespace Naf\Queue\Drivers;

use PDO;

class SQLiteDriver implements QueueDriverInterface
{
    protected PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->init();
    }

    protected function init(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class TEXT NOT NULL,
                payload TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function enqueue(string $class, array $payload): void
    {
        $stmt = $this->db->prepare("INSERT INTO queue (class, payload) VALUES (?, ?)");
        $stmt->execute([$class, json_encode($payload)]);
    }

    public function dequeue(): ?array
    {
        $stmt = $this->db->query("SELECT * FROM queue ORDER BY id ASC LIMIT 1");
        $job  = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job) {
            $this->db->prepare("DELETE FROM queue WHERE id = ?")->execute([$job['id']]);

            return [
                'class'   => $job['class'],
                'payload' => json_decode($job['payload'], true),
            ];
        }

        return null;
    }
}
