<?php
declare(strict_types=1);

namespace Core;

/**
 * Base model. Provides a small query helper on top of PDO plus the shared
 * behaviours every register needs: filtering, searching, sorting, pagination
 * and safe deletion.
 *
 * Subclasses declare their table and expose domain specific queries; business
 * rules (input stock, harvest batches, etc.) live in the services rather than
 * here so that they cannot be bypassed by a controller.
 */
abstract class Model
{
    protected Database $db;
    protected string $table;
    protected string $primaryKey = 'id';
    /** Columns that may be used for ORDER BY. Anything else is ignored. */
    protected array $sortable = ['id'];
    /** Columns searched by the register's search box. */
    protected array $searchable = [];

    public function __construct(Database $db)
    {
        $this->db    = $db;
        $this->table = Database::safeIdentifier(static::tableName());
    }

    abstract public static function tableName(): string;

    public function db(): Database
    {
        return $this->db;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        return $this->db->insert($this->table, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateById(int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        return $this->db->update($this->table, $data, $this->primaryKey . ' = :id', ['id' => $id]);
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = :id LIMIT 1', $this->table, $this->primaryKey),
            ['id' => $id]
        );
    }

    public function findBy(string $column, mixed $value): ?array
    {
        $column = Database::safeIdentifier($column);

        return $this->db->selectOne(
            sprintf('SELECT * FROM `%s` WHERE `%s` = :value LIMIT 1', $this->table, $column),
            ['value' => $value]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(string $orderBy = 'id DESC'): array
    {
        return $this->db->select(sprintf('SELECT * FROM `%s` ORDER BY %s', $this->table, $this->safeOrder($orderBy)));
    }

    public function count(string $where = '', array $params = []): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM `%s`', $this->table);

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) $this->db->scalar($sql, $params);
    }

    /**
     * Soft delete where a deleted_at column exists, hard delete otherwise.
     */
    public function deleteById(int $id): int
    {
        if ($this->hasSoftDeletes()) {
            return $this->db->update($this->table, ['deleted_at' => date('Y-m-d H:i:s')], $this->primaryKey . ' = :id', ['id' => $id]);
        }

        return $this->db->delete($this->table, $this->primaryKey . ' = :id', ['id' => $id]);
    }

    public function hasSoftDeletes(): bool
    {
        return $this->columnExists('deleted_at');
    }

    /**
     * Filtered, sorted, paginated list for register screens.
     *
     * @param array<string,mixed> $filters   Exact-match column filters
     * @param array<int,string>   $extraSql  Additional WHERE fragments (already parameterised)
     * @param array<string,mixed> $params
     * @return array{rows:array<int,array<string,mixed>>,total:int,paginator:Paginator}
     */
    public function paginate(
        array $filters = [],
        string $search = '',
        string $sort = '',
        int $page = 1,
        int $perPage = 25,
        array $extraSql = [],
        array $params = [],
        string $baseSql = ''
    ): array {
        $where  = [];
        $params = $params;

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // A dotted column names a joined table and is trusted as a literal
            // already validated by the caller; a bare column must exist here.
            if (!str_contains($column, '.') && !$this->columnExists($column)) {
                continue;
            }

            $safeColumn = $this->qualify($column);
            $paramKey   = 'f_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column);
            $where[]    = "{$safeColumn} = :{$paramKey}";
            $params[$paramKey] = $value;
        }

        if ($search !== '' && $this->searchable !== []) {
            $searchParts = [];
            foreach ($this->searchable as $index => $column) {
                $paramKey    = 's_' . $index;
                $searchParts[]      = $this->qualify($column) . " LIKE :{$paramKey}";
                $params[$paramKey] = '%' . $search . '%';
            }
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }

        foreach ($extraSql as $fragment) {
            $where[] = $fragment;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from     = $baseSql !== '' ? $baseSql : sprintf('`%s`', $this->table);

        $total     = (int) $this->db->scalar(sprintf('SELECT COUNT(*) FROM %s%s', $from, $whereSql), $params);
        $paginator = new Paginator($total, $page, $perPage);

        $orderSql = $this->safeOrder($sort !== '' ? $sort : $this->primaryKey . ' DESC');

        $rows = $this->db->select(
            sprintf(
                'SELECT %s FROM %s%s ORDER BY %s LIMIT %d OFFSET %d',
                $this->selectColumns(),
                $from,
                $whereSql,
                $orderSql,
                $paginator->perPage,
                $paginator->offset()
            ),
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'paginator' => $paginator];
    }

    protected function selectColumns(): string
    {
        return '*';
    }

    /**
     * @param array<string,mixed> $values
     */
    public function whereIn(string $column, array $values, string $placeholder = 'val'): array
    {
        if ($values === []) {
            return ['1 = 0', []];
        }

        $safeColumn = $this->qualify($column);
        $keys       = [];
        $params     = [];

        foreach (array_values($values) as $index => $value) {
            $key          = $placeholder . '_' . $index;
            $keys[]       = ':' . $key;
            $params[$key] = $value;
        }

        return [$safeColumn . ' IN (' . implode(', ', $keys) . ')', $params];
    }

    /**
     * Quotes a column for use in SQL. A qualified column keeps its own table
     * alias; a bare column is qualified with this model's table so joins cannot
     * produce an ambiguous reference.
     */
    public function qualify(string $column): string
    {
        $column = trim($column);

        if (str_contains($column, '.')) {
            [$alias, $bare] = array_pad(explode('.', $column, 2), 2, null);

            return '`' . Database::safeIdentifier($alias) . '`.`' . Database::safeIdentifier((string) $bare) . '`';
        }

        return '`' . $this->table . '`.`' . Database::safeIdentifier($column) . '`';
    }

    protected function safeOrder(string $order): string
    {
        $parts = [];

        foreach (explode(',', $order) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            [$column, $direction] = array_pad(explode(' ', $part, 2), 2, 'desc');
            $column = Database::safeIdentifier($column);

            if (!in_array($column, $this->sortable, true)) {
                continue;
            }

            $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
            $parts[]   = '`' . $column . '` ' . $direction;
        }

        return $parts === [] ? '`' . $this->primaryKey . '` DESC' : implode(', ', $parts);
    }

    private function columnExists(string $column): bool
    {
        if (str_contains($column, '.')) {
            $column = substr($column, strrpos($column, '.') + 1);
        }

        static $columns = [];

        if (!isset($columns[$this->table])) {
            $columns[$this->table] = $this->db->column('SHOW COLUMNS FROM `' . $this->table . '`');
        }

        return in_array($column, $columns[$this->table], true);
    }

    /**
     * Small helper for dashboard aggregates: SUM with a COALESCE to 0.
     */
    protected function sum(string $column, string $where = '', array $params = []): float
    {
        $sql = sprintf('SELECT COALESCE(SUM(`%s`), 0) FROM `%s`', Database::safeIdentifier($column), $this->table);

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (float) $this->db->scalar($sql, $params);
    }
}
