<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Response;

/**
 * Input API.
 *
 * A farmer sees the inputs issued to them and how much of each is still
 * outstanding; an officer or manager sees the same for the farmers they are
 * responsible for. `input_issues` has no `deleted_at` column, and its location
 * column is `farmer_id`, which is what the shared scope clause filters on.
 */
final class InputApiController extends ApiController
{
    /**
     * GET /api/v1/inputs
     */
    public function index(): Response
    {
        $params = $this->listParams(50);
        $values = [];
        $scope  = $this->scopeWhere($values, 'ii');

        $where = ['1 = 1'];

        if ($this->request->int('farmer_id') > 0) {
            $where[]    = 'ii.farmer_id = :farmer';
            $values['farmer'] = $this->request->int('farmer_id');
        }

        if ($this->request->int('input_id') > 0) {
            $where[]    = 'ii.input_id = :input';
            $values['input'] = $this->request->int('input_id');
        }

        $status = $this->request->string('status');

        if ($status !== '') {
            $where[]         = 'ii.status = :status';
            $values['status'] = $status;
        }

        $whereSql = implode(' AND ', $where) . $scope;

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM input_issues ii WHERE ' . $whereSql,
            $values
        );

        $rows = $this->db->select(
            'SELECT ii.id, ii.issue_code, ii.issue_date, ii.quantity, ii.quantity_used,
                    ii.unit, ii.status, ii.purpose, ii.reference_no,
                    (ii.quantity - ii.quantity_used) AS quantity_remaining,
                    i.input_code, i.name AS input_name, c.name AS category_name,
                    f.farmer_code, f.full_name AS farmer_name,
                    g.garden_code, g.name AS garden_name,
                    s.season_code, o.officer_code, o.full_name AS officer_name
             FROM input_issues ii
             INNER JOIN inputs i ON i.id = ii.input_id
             INNER JOIN input_categories c ON c.id = i.category_id
             INNER JOIN farmers f ON f.id = ii.farmer_id
             LEFT JOIN gardens g ON g.id = ii.garden_id
             LEFT JOIN seasons s ON s.id = ii.season_id
             LEFT JOIN field_officers o ON o.id = ii.officer_id
             WHERE ' . $whereSql . '
             ORDER BY ii.issue_date DESC, ii.id DESC
             LIMIT ' . $params['per_page'] . ' OFFSET ' . (($params['page'] - 1) * $params['per_page']),
            $values
        );

        return $this->paginate($rows, $total, $params, 'issues');
    }

    /**
     * GET /api/v1/inputs/usage
     *
     * What the farmer actually applied, as opposed to what was handed over.
     */
    public function usage(): Response
    {
        $params = $this->listParams(50);
        $values = [];
        $scope  = $this->scopeWhere($values, 'iu');

        $where  = ['1 = 1'];
        $whereSql = implode(' AND ', $where) . $scope;

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM input_usage iu WHERE ' . $whereSql,
            $values
        );

        $rows = $this->db->select(
            'SELECT iu.id, iu.usage_code, iu.quantity_used, iu.unit, iu.application_date,
                    iu.purpose,
                    i.input_code, i.name AS input_name, c.name AS category_name,
                    f.farmer_code, f.full_name AS farmer_name,
                    g.garden_code, g.name AS garden_name
             FROM input_usage iu
             INNER JOIN inputs i ON i.id = iu.input_id
             INNER JOIN input_categories c ON c.id = i.category_id
             INNER JOIN farmers f ON f.id = iu.farmer_id
             LEFT JOIN gardens g ON g.id = iu.garden_id
             WHERE ' . $whereSql . '
             ORDER BY iu.application_date DESC, iu.id DESC
             LIMIT ' . $params['per_page'] . ' OFFSET ' . (($params['page'] - 1) * $params['per_page']),
            $values
        );

        return $this->paginate($rows, $total, $params, 'usage');
    }
}
