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
 * Crop varieties: the specific cultivars a farmer may plant under a crop.
 *
 * A variety carries the agronomy that actually differs from the parent crop —
 * maturity period, expected yield and the areas it is recommended for — and it
 * is also the narrowest scope a lifecycle template can attach to. Plantings hold
 * a foreign key to the variety that is set to NULL on delete, so a variety that
 * has already been planted is deactivated rather than removed: silently dropping
 * the link would leave the planting with no explanation of what was grown.
 */
final class VarietyController extends Controller
{
    private Lookups $lookups;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    /**
     * Variety register, grouped by the crop each variety belongs to so the
     * agronomy of a crop and its cultivars can be read on one screen.
     */
    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(v.variety_code LIKE :s OR v.name LIKE :s OR c.name LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['crop_id' => 'v.crop_id', 'status' => 'v.status'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM crop_varieties v INNER JOIN crops c ON c.id = v.crop_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['variety_code', 'name', 'maturity_days', 'expected_yield_per_acre', 'status', 'created_at'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'name';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT v.id, v.crop_id, v.variety_code, v.name, v.maturity_days, v.expected_yield_per_acre,
                    v.yield_unit, v.recommended_areas, v.status, v.created_at,
                    c.name AS crop_name, c.crop_code,
                    (SELECT COUNT(*) FROM plantings p WHERE p.variety_id = v.id) AS planting_count
             FROM crop_varieties v
             INNER JOIN crops c ON c.id = v.crop_id'
            . $whereSql
            . ' ORDER BY v.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('varieties/index', [
            'pageTitle' => 'Crop varieties',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => $this->lookups->cropOptions('All crops')],
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'active','inactive'", 'All statuses')],
            ],
        ]);
    }

    public function create(): Response
    {
        $cropId = $this->request->int('crop_id');

        return $this->render('varieties/form', [
            'pageTitle' => 'Add variety',
            'record'    => $cropId > 0 ? ['crop_id' => $cropId] : null,
            'sections'  => $this->sections(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('varieties/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'maturity_days', 'expected_yield_per_acre', 'yield_unit', 'recommended_areas', 'description',
        ]);

        $id = $this->db->transaction(function () use ($values): int {
            $values['variety_code'] = Identifier::generate('variety');

            return $this->db->insert('crop_varieties', $values);
        });

        Audit::log('create', 'varieties', 'crop_variety', $id, (string) $values['variety_code'], null, $values);

        Flash::success('Variety ' . $values['variety_code'] . ' has been added.');

        return $this->redirect(url('varieties/' . $id . '/edit'));
    }

    public function edit(string $id): Response
    {
        $variety = $this->findOrFail($id);

        return $this->render('varieties/form', [
            'pageTitle' => 'Edit ' . $variety['variety_code'],
            'record'    => $variety,
            'sections'  => $this->sections(),
        ]);
    }

    public function update(string $id): Response
    {
        $this->assertCsrf();

        $variety  = $this->findOrFail($id);
        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('varieties/' . $id . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'maturity_days', 'expected_yield_per_acre', 'yield_unit', 'recommended_areas', 'description',
        ]);

        $this->db->update('crop_varieties', $values, 'id = :id', ['id' => $variety['id']]);

        Audit::log('update', 'varieties', 'crop_variety', (int) $variety['id'], (string) $variety['variety_code'], $variety, $values);

        Flash::success('Variety record updated.');

        return $this->redirect(url('varieties/' . $variety['id'] . '/edit'));
    }

    /**
     * A variety that has been planted is deactivated, never deleted: the foreign
     * key on plantings is ON DELETE SET NULL, so removing the row would strip the
     * variety from every planting that referenced it without a trace.
     */
    public function destroy(string $id): Response
    {
        $this->assertCsrf();

        $variety = $this->findOrFail($id);

        $plantings = (int) $this->db->scalar('SELECT COUNT(*) FROM plantings WHERE variety_id = :id', ['id' => $variety['id']]);

        if ($plantings > 0) {
            $this->db->update('crop_varieties', ['status' => 'inactive'], 'id = :id', ['id' => $variety['id']]);

            Audit::log(
                'deactivate',
                'varieties',
                'crop_variety',
                (int) $variety['id'],
                (string) $variety['variety_code'],
                $variety,
                ['status' => 'inactive']
            );

            Flash::warning(
                'This variety is referenced by ' . $plantings . ' planting(s), so it has been marked inactive rather '
                . 'than deleted. Deleting it would silently detach it from those records. It is hidden from new '
                . 'plantings, and the existing ones keep the variety that was actually grown.'
            );

            return $this->redirect(url('varieties'));
        }

        $this->db->transaction(function () use ($variety): void {
            $this->db->delete('crop_activity_templates', 'variety_id = :id', ['id' => $variety['id']]);
            $this->db->delete('crop_varieties', 'id = :id', ['id' => $variety['id']]);
        });

        Audit::log('delete', 'varieties', 'crop_variety', (int) $variety['id'], (string) $variety['variety_code'], $variety);

        Flash::success('Variety ' . $variety['variety_code'] . ' has been deleted.');

        return $this->redirect(url('varieties'));
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Variety',
                'fields' => [
                    ['name' => 'variety_code', 'label' => 'Variety code', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the record is saved.'],
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions(), 'table' => 'crops'],
                    ['name' => 'name', 'label' => 'Variety name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6,
                     'placeholder' => 'e.g. NARO 1, Kilimo, Kainam 1'],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'active','inactive'", '')],
                    ['name' => 'maturity_days', 'label' => 'Maturity period (days)', 'type' => Form::NUMBER,
                     'min' => 1, 'max' => 1000, 'colspan' => 6,
                     'help' => 'Overrides the crop lifecycle when predicting the harvest date.'],
                ],
            ],
            [
                'title'  => 'Performance and recommendation',
                'fields' => [
                    ['name' => 'expected_yield_per_acre', 'label' => 'Expected yield per acre', 'type' => Form::NUMBER,
                     'min' => 0, 'step' => 0.001, 'colspan' => 6],
                    ['name' => 'yield_unit', 'label' => 'Yield unit', 'type' => Form::TEXT, 'colspan' => 6,
                     'placeholder' => 'kg, tonnes, crates...'],
                    ['name' => 'recommended_areas', 'label' => 'Recommended areas', 'type' => Form::TEXT, 'colspan' => 6,
                     'maxlength' => 255, 'placeholder' => 'e.g. Highlands, Lake Victoria basin',
                     'help' => 'Where this variety is known to perform well.'],
                    ['name' => 'description', 'label' => 'Description', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
        ];
    }

    private function findOrFail(string $id): array
    {
        $variety = $this->db->selectOne(
            'SELECT v.*, c.name AS crop_name, c.crop_code FROM crop_varieties v
             INNER JOIN crops c ON c.id = v.crop_id
             WHERE v.id = :id',
            ['id' => $id]
        );

        if ($variety === null) {
            throw new HttpException(404, 'No variety found with the ID ' . $id . '.');
        }

        return $variety;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}
