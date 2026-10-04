<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Glueful\Database\Connection;

/**
 * The database's current wall-clock time (search block spec §3.5.5), never a worker's and never the
 * transaction's start: Postgres fixes `CURRENT_TIMESTAMP` when a transaction begins, so a lease
 * checked against it inside a long transaction could pass after expiry. Every worker reads the same
 * clock, so a lease means the same thing to all of them.
 */
final class DatabaseClock implements Clock
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function now(): string
    {
        $sql = match ($this->db->getDriverName()) {
            'pgsql' => "SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS now",
            'mysql' => "SELECT DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s') AS now",
            default => "SELECT strftime('%Y-%m-%d %H:%M:%S', 'now') AS now",
        };
        $row = $this->db->query()->executeRawFirst($sql);

        return (string) ($row['now'] ?? gmdate('Y-m-d H:i:s'));
    }
}
