<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Audit;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\Form;
use Core\HttpException;
use Core\Identifier;
use Core\Paginator;
use Core\Response;
use Services\Lookups;

/**
 * Field visits: the officer's contact with a farmer and the plot behind them.
 *
 * A visit is the narrative record of an interaction — who was met, why, what
 * was advised and where the officer stood — and it is the evidence that outreach
 * actually happened rather than being planned on paper. Two consequences shape
 * this controller. The garden chosen on a visit is checked against the farmer,
 * because a visit filed against someone else's plot would corrupt the coverage
 * reporting that drives staffing. And a visit that inspections hang off cannot
 * be deleted: the inspections are the structured output of the visit, and
 * removing the parent would silently orphan them.
 */
final class VisitController extends Controller
{
    private Lookups $lookups;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    /**
     * The visit register: every contact with a farmer, newest first, filterable
     * by outcome, officer and date range.
     */
    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(v.visit_code LIKE :s OR v.visit_purpose LIKE :s
                             OR f.full_name LIKE :s OR f.farmer_code LIKE :s
                             OR o.full_name LIKE :s OR o.officer_code LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'v.status', 'officer_id' => 'v.officer_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $from = $this->request->date('from');
        $to   = $this->request->date('to');

        if ($from !== null) {
            $where[]       = 'v.visit_date >= :from_date';
            $values['from_date'] = $from;
        }

        if ($to !== null) {
            $where[]     = 'v.visit_date <= :to_date';
            $values['to_date'] = $to;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM field_visits v
             LEFT JOIN farmers f ON f.id = v.farmer_id
             LEFT JOIN field_officers o ON o.id = v.officer_id' . $whereSql,
            $values
        );

        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['visit_date', 'created_at'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'visit_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT v.id, v.visit_code, v.visit_date, v.visit_purpose, v.latitude, v.longitude,
                    v.people_met, v.status,
                    f.full_name, f.farmer_code,
                    g.garden_code, g.name AS garden_name,
                    o.full_name AS officer_name, o.officer_code,
                    (SELECT COUNT(*) FROM inspections i WHERE i.visit_id = v.id) AS inspection_count
             FROM field_visits v
             LEFT JOIN farmers f ON f.id = v.farmer_id
             LEFT JOIN gardens g ON g.id = v.garden_id
             LEFT JOIN field_officers o ON o.id = v.officer_id'
            . $whereSql
            . ' ORDER BY v.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('visits/index', [
            'pageTitle' => 'Field visits',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $column,
            'direction' => $params['direction'],
            'from'      => $from ?? '',
            'to'        => $to ?? '',
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'planned','completed','cancelled','missed'", 'All statuses')],
                ['key' => 'officer_id', 'label' => 'Officer', 'options' => $this->lookups->officerOptions('All officers')],
            ],
        ]);
    }

    /**
     * The visit form. A visit opened from a farmer, garden or group page arrives
     * pre-filled through those query parameters, and the officer defaults to
     * whoever is signed in.
     */
    public function create(): Response
    {
        $record = [
            'officer_id'  => $this->auth->officerId(),
            'farmer_id'   => $this->request->int('farmer_id') ?: null,
            'garden_id'   => $this->request->int('garden_id') ?: null,
            'group_id'    => $this->request->int('group_id') ?: null,
            'visit_date'  => date('Y-m-d'),
            'status'      => 'completed',
        ];

        if ($record['garden_id'] !== null && $record['farmer_id'] === null) {
            $garden = $this->db->selectOne('SELECT farmer_id FROM gardens WHERE id = :id', ['id' => $record['garden_id']]);

            if ($garden !== null) {
                $record['farmer_id'] = (int) $garden['farmer_id'];
            }
        }

        return $this->render('visits/form', [
            'pageTitle' => 'Record field visit',
            'record'    => $record,
            'sections'  => $this->sections(),
            'gpsLng'    => '',
        ]);
    }

    /**
     * Records a visit and issues its identifier inside the same transaction, so
     * a failed insert never burns a visit number.
     */
    public function store(): Response
    {
        $this->assertCsrf();

        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('visits/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, $this->nullableColumns());

        $garden = $this->gardenForFarmer(
            isset($values['garden_id']) ? (int) $values['garden_id'] : 0,
            isset($values['farmer_id']) ? (int) $values['farmer_id'] : 0
        );

        if ($garden === null) {
            return $this->backWithErrors(
                url('visits/create'),
                ['garden_id' => 'Select a garden that belongs to the chosen farmer.'],
                $input
            );
        }

        $values['garden_id']    = $garden['id'];
        $values['farmer_id']    = $garden['farmer_id'];
        $values['visit_date']   = $values['visit_date'] ?? date('Y-m-d');

        // An officer is who makes the visit, so an empty dropdown means the
        // signed-in officer rather than a visit nobody made.
        $officerId = isset($values['officer_id']) && $values['officer_id'] !== ''
            ? (int) $values['officer_id']
            : $this->auth->officerId();

        $values['officer_id'] = $officerId;
        $values['people_met'] = isset($values['people_met']) && $values['people_met'] !== ''
            ? (int) $values['people_met']
            : null;

        $result = $this->db->transaction(function () use ($values): array {
            $values['visit_code'] = Identifier::generate(
                'visit',
                date_create_from_format('Y-m-d', (string) $values['visit_date']) ?: null
            );
            $values['created_by'] = $this->auth->id();

            return [
                'id'   => $this->db->insert('field_visits', $values),
                'code' => $values['visit_code'],
            ];
        });

        Audit::log('create', 'field_visits', 'field_visit', $result['id'], $result['code'], null, $values);

        Flash::success('Field visit ' . $result['code'] . ' has been recorded.');

        return $this->redirect(url('visits/' . $result['code']));
    }

    /**
     * A single visit with the farmer and plot it belongs to, plus every
     * inspection raised from it. The inspection link carries the visit and
     * farmer so an officer can raise the structured record without re-picking
     * them from long dropdowns.
     */
    public function show(string $code): Response
    {
        $visit = $this->findByCodeOrFail($code);

        $inspections = $this->db->select(
            'SELECT i.inspection_code, i.inspection_date, i.crop_condition, i.pest_status,
                    i.disease_status, i.recommendations, o.full_name AS officer_name
             FROM inspections i
             LEFT JOIN field_officers o ON o.id = i.officer_id
             WHERE i.visit_id = :id
             ORDER BY i.inspection_date DESC',
            ['id' => $visit['id']]
        );

        $sprayings = $this->db->select(
            'SELECT sr.spray_code, sr.application_date, sr.product_name, sr.quantity_used, sr.unit
             FROM spraying_records sr
             WHERE sr.garden_id = :garden AND sr.application_date BETWEEN DATE_SUB(:from_date, INTERVAL 7 DAY) AND :to_date
             ORDER BY sr.application_date DESC LIMIT 5',
            [
                'garden'    => $visit['garden_id'] ?? 0,
                'from_date' => (string) $visit['visit_date'],
                'to_date'   => (string) $visit['visit_date'],
            ]
        );

        return $this->render('visits/show', [
            'pageTitle'    => 'Visit ' . $code,
            'visit'        => $visit,
            'inspections'  => $inspections,
            'sprayings'    => $sprayings,
            'inspectUrl'   => url(
                'inspections/create?visit_id=' . (int) $visit['id']
                . '&farmer_id=' . (int) ($visit['farmer_id'] ?? 0)
            ),
        ]);
    }

    /**
     * A visit that inspections hang off is never deleted: those inspections are
     * the structured evidence of the visit and would be left pointing at
     * nothing. The visit is cancelled instead, which keeps the record and its
     * history while making clear it did not lead to follow-up.
     */
    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $visit = $this->findByCodeOrFail($code);

        $inspections = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM inspections WHERE visit_id = :id',
            ['id' => $visit['id']]
        );

        if ($inspections > 0) {
            Flash::warning(
                'This visit has ' . $inspections . ' inspection record(s) raised from it. Cancel the visit instead '
                . 'so the inspections keep their context.'
            );

            return $this->redirect(url('visits/' . $code));
        }

        $this->db->delete('field_visits', 'id = :id', ['id' => $visit['id']]);

        Audit::log('delete', 'field_visits', 'field_visit', (int) $visit['id'], $code, $visit);

        Flash::success('Field visit ' . $code . ' has been removed.');

        return $this->redirect(url('visits'));
    }

    /**
     * Confirms the chosen garden belongs to the chosen farmer. A visit filed
     * against someone else's plot would make per-officer coverage meaningless.
     *
     * @return array<string,mixed>|null
     */
    private function gardenForFarmer(int $gardenId, int $farmerId): ?array
    {
        if ($gardenId <= 0 || $farmerId <= 0) {
            return null;
        }

        return $this->db->selectOne(
            'SELECT id, farmer_id, garden_code, name FROM gardens
             WHERE id = :id AND farmer_id = :farmer AND deleted_at IS NULL
             LIMIT 1',
            ['id' => $gardenId, 'farmer' => $farmerId]
        );
    }

    /**
     * Every nullable column on field_visits. Anything not listed here is
     * required, so a column can never be silently omitted from an insert.
     *
     * @return array<int,string>
     */
    private function nullableColumns(): array
    {
        return [
            'group_id', 'visit_purpose', 'latitude', 'longitude',
            'people_met', 'advisories_given',
        ];
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'The visit',
                'fields' => [
                    ['name' => 'visit_code', 'label' => 'Visit ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the visit is saved.'],
                    ['name' => 'visit_date', 'label' => 'Visit date', 'type' => Form::DATE, 'required' => true,
                     'colspan' => 6, 'default' => date('Y-m-d')],
                    ['name' => 'status', 'label' => 'Outcome', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'planned','completed','cancelled','missed'", ''),
                     'help' => 'Planned visits that did not happen are recorded as missed, not deleted.'],
                    ['name' => 'officer_id', 'label' => 'Officer who made the visit', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->officerOptions(), 'table' => 'field_officers'],
                    ['name' => 'group_id', 'label' => 'Cooperative / group', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->groupOptions('Not in a group'), 'table' => 'farmer_groups'],
                    ['name' => 'farmer_id', 'label' => 'Farmer', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->farmerOptions(), 'table' => 'farmers'],
                    ['name' => 'garden_id', 'label' => 'Garden visited', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->gardenOptions(), 'table' => 'gardens',
                     'help' => 'Must belong to the farmer above.'],
                    ['name' => 'people_met', 'label' => 'People met', 'type' => Form::NUMBER, 'colspan' => 6,
                     'min' => 1, 'max' => 500],
                    ['name' => 'visit_purpose', 'label' => 'Purpose of visit', 'type' => Form::TEXT, 'colspan' => 6,
                     'maxlength' => 150, 'placeholder' => 'e.g. Monitoring visit, seed distribution, training'],
                ],
            ],
            [
                'title'  => 'What was said and done',
                'fields' => [
                    ['name' => 'advisories_given', 'label' => 'Advisories given', 'type' => Form::TEXTAREA, 'colspan' => 12,
                     'rows' => 4, 'placeholder' => 'The advice the farmer actually received, in plain language'],
                    ['name' => 'latitude', 'label' => 'GPS location', 'type' => Form::GPS, 'colspan' => 12,
                     'help' => 'Capture where the visit took place, or type the coordinates.'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function groupOptions(string $placeholder = 'Select group...'): array
    {
        return Form::options(
            $this->db->select("SELECT id, name FROM farmer_groups WHERE status = 'active' AND deleted_at IS NULL ORDER BY name"),
            'id',
            'name',
            $placeholder
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function findByCodeOrFail(string $code): array
    {
        $visit = $this->db->selectOne(
            'SELECT v.*, o.officer_code, o.full_name AS officer_name, o.phone AS officer_phone,
                    f.full_name, f.farmer_code, f.phone AS farmer_phone, f.village_name,
                    g.garden_code, g.name AS garden_name, g.acreage AS garden_acreage,
                    g.latitude AS garden_latitude, g.longitude AS garden_longitude,
                    gr.name AS group_name, u.full_name AS recorded_by_name
             FROM field_visits v
             LEFT JOIN field_officers o ON o.id = v.officer_id
             LEFT JOIN farmers f ON f.id = v.farmer_id
             LEFT JOIN gardens g ON g.id = v.garden_id
             LEFT JOIN farmer_groups gr ON gr.id = v.group_id
             LEFT JOIN users u ON u.id = v.created_by
             WHERE v.visit_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($visit === null) {
            throw new HttpException(404, 'No field visit found with the ID ' . $code . '.');
        }

        return $visit;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}