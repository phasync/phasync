<?php

namespace phasync\Wrappers\PDO;

use phasync\Services\MySQLiPoll;

/**
 * PDOMySQLi class extends PDO to provide asynchronous query execution using MySQLi.
 */
class MySQL extends \PDO
{
    private \mysqli $mysqli;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        $dsnComponents = $this->parseDsn($dsn);

        $host   = $dsnComponents['host'] ?? 'localhost';
        $dbname = $dsnComponents['dbname'] ?? '';
        $port   = $dsnComponents['port'] ?? 3306;

        $this->mysqli = new \mysqli($host, $username, $password, $dbname, $port);

        if ($this->mysqli->connect_error) {
            throw new \PDOException('Connect Error (' . $this->mysqli->connect_errno . ') ' . $this->mysqli->connect_error);
        }

        parent::__construct($dsn, $username, $password, $options);
    }

    private function parseDsn(string $dsn): array
    {
        $dsnComponents = [];
        foreach (\explode(';', $dsn) as $component) {
            [$key, $value]       = \explode('=', $component);
            $dsnComponents[$key] = $value;
        }

        return $dsnComponents;
    }

    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): \PDOStatement|false
    {
        $this->mysqli->query($query, \MYSQLI_ASYNC);

        MySQLiPoll::poll($this->mysqli);

        $result = $this->mysqli->reap_async_query();
        if (false === $result) {
            throw new \PDOException($this->mysqli->error, $this->mysqli->errno);
        }

        return new MySQLStatement($result);
    }

    public function exec(string $query): int|false
    {
        $this->mysqli->query($query, \MYSQLI_ASYNC);

        MySQLiPoll::poll($this->mysqli);

        $result = $this->mysqli->reap_async_query();
        if (false === $result) {
            throw new \PDOException($this->mysqli->error, $this->mysqli->errno);
        }

        return $this->mysqli->affected_rows;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $stmt = $this->mysqli->prepare($query);
        if (false === $stmt) {
            throw new \PDOException($this->mysqli->error, $this->mysqli->errno);
        }

        return new MySQLStatement($stmt);
    }

    public function beginTransaction(): bool
    {
        return $this->mysqli->begin_transaction();
    }

    public function commit(): bool
    {
        return $this->mysqli->commit();
    }

    public function rollBack(): bool
    {
        return $this->mysqli->rollback();
    }

    public function lastInsertId(?string $name = null): string
    {
        return (string) $this->mysqli->insert_id;
    }

    public function errorCode(): ?string
    {
        return $this->mysqli->errno ? (string) $this->mysqli->errno : null;
    }

    public function errorInfo(): array
    {
        return [$this->mysqli->errno, $this->mysqli->error];
    }

    public function close(): void
    {
        $this->mysqli->close();
    }
}
