<?php
declare(strict_types=1);

namespace Core;

/**
 * Thin PDO wrapper. Every query in the application goes through this class so
 * that prepared statements, transaction handling and error logging are applied
 * in exactly one place.
 */
final class Database
{
    private static ?self $instance = null;

    private \PDO $pdo;
    private int $transactionDepth = 0;

    private function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        $this->pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET SESSION time_zone = '" . $this->quoteOffset($config['timezone'] ?? 'UTC') . "'");
    }

    public static function instance(array $config = []): self
    {
        if (self::$instance === null) {
            if ($config === []) {
                throw new \RuntimeException('Database has not been configured yet.');
            }
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int|string,mixed> $params
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string,mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,mixed>
     */
    public function column(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        $this->query($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $assignments = [];
        $params      = [];

        foreach ($data as $column => $value) {
            $assignments[]          = sprintf('`%s` = :set_%s', $column, $column);
            $params['set_' . $column] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $assignments), $where);

        return $this->query($sql, array_merge($params, $whereParams))->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->query(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $params)->rowCount();
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 1) {
            $this->pdo->commit();
        }
        $this->transactionDepth--;
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth = 0;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    /**
     * Runs the callback inside a transaction. Nested calls join the outer
     * transaction via SAVEPOINT-free depth counting, which keeps multi-step
     * business operations (issue input + adjust stock + write audit) atomic.
     *
     * @template T
     * @param callable(self):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    /**
     * Table names and column names cannot be bound as parameters, so any value
     * that reaches SQL as an identifier is validated against this whitelist
     * pattern before use.
     */
    public static function safeIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Illegal SQL identifier: ' . $identifier);
        }

        return $identifier;
    }

    private function quoteOffset(string $timezone): string
    {
        $offset = '+00:00';

        try {
            $zone    = new \DateTimeZone($timezone);
            $offset  = $zone->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
            $sign    = $offset < 0 ? '-' : '+';
            $offset  = sprintf('%s%02d:%02d', $sign, abs($offset) / 3600, (abs($offset) % 3600) / 60);
        } catch (\Throwable) {
            // Fall back to UTC when an unknown timezone is configured.
        }

        return $offset;
    }
}
