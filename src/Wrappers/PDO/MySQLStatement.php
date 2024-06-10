<?php

namespace phasync\Wrappers\PDO;

use PDOStatement;
use phasync\Services\MySQLiPoll;

/**
 * PDOMySQLStatement class mimics PDOStatement using MySQLi.
 */
class MySQLStatement extends \PDOStatement implements \IteratorAggregate
{
    private $stmt;
    private $currentRow;

    protected function __construct($stmt)
    {
        $this->stmt = $stmt;
    }

    public function bindColumn($column, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null): bool
    {
        throw new \PDOException('bindColumn is not supported.');
    }

    public function bindParam($param, &$var, $type = \PDO::PARAM_STR, $maxLength = 0, $driverOptions = null): bool
    {
        throw new \PDOException('bindParam is not supported.');
    }

    public function bindValue($param, $value, $type = \PDO::PARAM_STR): bool
    {
        throw new \PDOException('bindValue is not supported.');
    }

    public function closeCursor(): bool
    {
        $this->stmt->close();

        return true;
    }

    public function columnCount(): int
    {
        return $this->stmt->field_count;
    }

    public function debugDumpParams(): ?bool
    {
        throw new \PDOException('debugDumpParams is not supported.');
    }

    public function errorCode(): ?string
    {
        return $this->stmt->errno ? (string) $this->stmt->errno : null;
    }

    public function errorInfo(): array
    {
        return [$this->stmt->errno, $this->stmt->error];
    }

    public function execute(?array $params = null): bool
    {
        if ($params) {
            $types = \str_repeat('s', \count($params));
            $this->stmt->bind_param($types, ...$params);
        }

        $this->stmt->execute();

        MySQLiPoll::poll($this->stmt->mysqli);

        $result = $this->stmt->get_result();
        if (false === $result) {
            throw new \PDOException($this->stmt->error, $this->stmt->errno);
        }

        return true;
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $result = $this->stmt->get_result();

        return $result->fetch_array($this->convertFetchStyle($mode));
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$fetchModeArgs): array
    {
        $result = $this->stmt->get_result();

        return $result->fetch_all($this->convertFetchStyle($mode));
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $result = $this->stmt->get_result();
        $row    = $result->fetch_array(\PDO::FETCH_NUM);

        return $row[$column] ?? null;
    }

    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        $result = $this->stmt->get_result();

        return $result->fetch_object($class, $constructorArgs);
    }

    public function getAttribute(int $name): mixed
    {
        throw new \PDOException('getAttribute is not supported.');
    }

    public function getColumnMeta(int $column): array|false
    {
        $meta   = $this->stmt->result_metadata();
        $fields = $meta->fetch_fields();

        return $fields[$column] ?? false;
    }

    public function getIterator(): \Iterator
    {
        $result = $this->stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            yield $row;
        }
    }

    public function nextRowset(): bool
    {
        throw new \PDOException('nextRowset is not supported.');
    }

    public function rowCount(): int
    {
        return $this->stmt->affected_rows;
    }

    public function setAttribute(int $attribute, $value): bool
    {
        throw new \PDOException('setAttribute is not supported.');
    }

    public function setFetchMode($mode, $className = null, ...$params)
    {
        throw new \PDOException('setFetchMode is not supported.');
    }

    public function close(): void
    {
        $this->stmt->close();
    }

    private function convertFetchStyle(int $fetchStyle): int
    {
        return match ($fetchStyle) {
            \PDO::FETCH_ASSOC => \MYSQLI_ASSOC,
            \PDO::FETCH_NUM   => \MYSQLI_NUM,
            \PDO::FETCH_BOTH  => \MYSQLI_BOTH,
            default           => \MYSQLI_ASSOC,
        };
    }
}
