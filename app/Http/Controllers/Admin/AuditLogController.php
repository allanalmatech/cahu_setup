<?php
declare(strict_types=1);

namespace Http\Controllers\Admin;

use Core\Audit;
use Core\Controller;
use Core\Flash;
use Core\Form;
use Core\Paginator;
use Core\Response;

/**
 * The audit trail: who changed what, when, from where. Every write in the
 * platform lands here, which makes this the screen an administrator turns to
 * when a figure on a report has to be traced back to the person who entered it.
 *
 * The list is bounded by a paginator and the export is bounded harder still,
 * because the table grows without limit and an unbounded CSV of it would take
 * the server down rather than answer the question.
 */
final class AuditLogController extends Controller
{
    private const EXPORT_LIMIT = 50000;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
    }

    public function index(): Response
    {
        $params  = $this->listParams();
        $filters = $this->filters();

        [$where, $values] = $this->conditions($params['search'], $filters);
        $whereSql           = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar('SELECT COUNT(*) FROM audit_logs a' . $whereSql, $values);
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT a.id, a.user_id, a.user_name, a.action, a.module, a.entity_type, a.entity_id,
                    a.entity_label, a.old_values, a.new_values, a.ip_address, a.created_at
             FROM audit_logs a'
            . $whereSql
            . $this->sortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('admin/audit', [
            'pageTitle' => 'Audit log',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'module', 'label' => 'Module', 'options' => $this->moduleOptions()],
                ['key' => 'action', 'label' => 'Action', 'options' => $this->actionOptions()],
                ['key' => 'user_id', 'label' => 'User', 'options' => $this->userOptions()],
            ],
            'range'     => ['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''],
        ]);
    }

    /**
     * The same trail as the screen above, as a CSV of the same filtered set.
     * Useful when the question is what changed in a period rather than who
     * changed it.
     */
    public function export(): Response
    {
        [$where, $values] = $this->conditions($this->request->string('search'), $this->filters());
        $whereSql           = ' WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM audit_logs a' . $whereSql, $values);

        if ($total > self::EXPORT_LIMIT) {
            Flash::warning(
                'The export was capped at ' . number_format(self::EXPORT_LIMIT) . ' rows, the newest first, out of '
                . number_format($total) . ' matching entries. Narrow the dates or filters and export again for the rest.'
            );
        }

        $rows = $this->db->select(
            'SELECT a.id, a.created_at, a.user_name, a.action, a.module, a.entity_type, a.entity_id,
                    a.entity_label, a.ip_address, a.old_values, a.new_values
             FROM audit_logs a'
            . $whereSql
            . ' ORDER BY a.created_at DESC, a.id DESC'
            . ' LIMIT ' . self::EXPORT_LIMIT,
            $values
        );

        Audit::log('export', 'audit', 'audit_log', null, (string) $total . ' matching entr(ies)');

        return $this->csv('audit-log-' . date('Y-m-d') . '.csv', [
            'id', 'created_at', 'user_name', 'action', 'module', 'entity_type', 'entity_id',
            'entity_label', 'ip_address', 'old_values', 'new_values',
        ], $rows);
    }

    /* ------------------------------------------------------------------ */
    /* Query building                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * The filter set, read straight from the request rather than through
     * listParams(), whose allowlist does not cover an audit trail's columns.
     *
     * @return array<string,string>
     */
    private function filters(): array
    {
        $filters = [];

        foreach (['module' => 60, 'action' => 60] as $key => $maxLength) {
            $value = $this->request->string($key);

            if ($value !== '') {
                $filters[$key] = substr($value, 0, $maxLength);
            }
        }

        foreach (['from', 'to'] as $key) {
            $value = $this->request->date($key);

            if ($value !== null) {
                $filters[$key] = $value;
            }
        }

        $userId = $this->request->int('user_id');

        if ($userId > 0) {
            $filters['user_id'] = (string) $userId;
        }

        return $filters;
    }

    /**
     * @param array<string,string> $filters
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private function conditions(string $search, array $filters): array
    {
        $where  = ['1 = 1'];
        $values = [];

        if ($search !== '') {
            // One placeholder per column, all bound to the same term. The
            // connection uses native prepared statements, where PDO maps each
            // placeholder to exactly one bound value, so a single :s used six
            // times asks for a parameter that was never supplied and the query
            // fails with HY093.
            $term = '%' . $search . '%';

            $where[]      = '(a.user_name LIKE :s1 OR a.action LIKE :s2 OR a.module LIKE :s3
                            OR a.entity_label LIKE :s4 OR a.ip_address LIKE :s5 OR a.entity_type LIKE :s6)';
            $values['s1'] = $term;
            $values['s2'] = $term;
            $values['s3'] = $term;
            $values['s4'] = $term;
            $values['s5'] = $term;
            $values['s6'] = $term;
        }

        foreach (['module', 'action'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $where[]       = 'a.' . $key . ' = :' . $key;
                $values[$key] = $filters[$key];
            }
        }

        if (($filters['user_id'] ?? '') !== '') {
            $where[]          = 'a.user_id = :user_id';
            $values['user_id'] = (int) $filters['user_id'];
        }

        if (($filters['from'] ?? '') !== '') {
            $where[]       = 'a.created_at >= :from_date';
            $values['from_date'] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where[]     = 'a.created_at <= :to_date';
            $values['to_date'] = $filters['to'] . ' 23:59:59';
        }

        return [$where, $values];
    }

    private function sortClause(string $sort, string $direction): string
    {
        $allowed = ['created_at', 'user_name', 'action', 'module'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'created_at';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        return ' ORDER BY a.' . $column . ' ' . $order;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function moduleOptions(): array
    {
        $options = [['value' => '', 'label' => 'All modules']];

        foreach ($this->db->column('SELECT DISTINCT module FROM audit_logs ORDER BY module') as $module) {
            $options[] = ['value' => (string) $module, 'label' => (string) $module];
        }

        return $options;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function actionOptions(): array
    {
        $options = [['value' => '', 'label' => 'All actions']];

        foreach ($this->db->column('SELECT DISTINCT action FROM audit_logs ORDER BY action') as $action) {
            $options[] = ['value' => (string) $action, 'label' => (string) $action];
        }

        return $options;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function userOptions(): array
    {
        return Form::options(
            $this->db->select(
                'SELECT DISTINCT u.id, u.username FROM users u
                 INNER JOIN audit_logs a ON a.user_id = u.id
                 ORDER BY u.username LIMIT 500'
            ),
            'id',
            'username',
            'All users'
        );
    }
}