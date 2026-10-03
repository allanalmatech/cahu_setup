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
use Services\Notifier;

/**
 * Field inspections.
 *
 * An inspection is the officer's structured read on one plot at one point in
 * the crop cycle. It is the input to three downstream things, so it is never
 * silently discarded: the condition score feeds the yield forecast, the
 * recommendations feed the next activity, and the record is what settles a
 * disagreement about what a crop looked like on a given day.
 *
 * That is why there is no hard delete on a submitted inspection. A draft can be
 * discarded; anything that reached a farmer or an officer cannot.
 */
final class InspectionController extends Controller
{
    private Lookups $lookups;
    private Notifier $notifier;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups  = new Lookups($db);
        $this->notifier = new Notifier($db);
    }

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(i.inspection_code LIKE :s OR f.full_name LIKE :s OR f.farmer_code LIKE :s
                             OR g.garden_code LIKE :s OR g.name LIKE :s OR i.recommendations LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'i.status', 'crop_id' => 'i.crop_id', 'season_id' => 'i.season_id', 'officer_id' => 'i.officer_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $scope = $this->scopeWhere($values);

        $whereSql = ' WHERE ' . implode(' AND ', $where) . $scope;

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM inspections i
             INNER JOIN farmers f ON f.id = i.farmer_id
             LEFT JOIN gardens g ON g.id = i.garden_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['inspection_date', 'crop_condition', 'status', 'inspection_code'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'inspection_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT i.*, f.full_name, f.farmer_code,
                    g.garden_code, g.name AS garden_name,
                    c.name AS crop_name, o.full_name AS officer_name,
                    (SELECT COUNT(*) FROM inspection_findings fi WHERE fi.inspection_id = i.id) AS finding_count
             FROM inspections i
             INNER JOIN farmers f ON f.id = i.farmer_id
             LEFT JOIN gardens g ON g.id = i.garden_id
             LEFT JOIN crops c ON c.id = i.crop_id
             LEFT JOIN field_officers o ON o.id = i.officer_id'
            . $whereSql
            . ' ORDER BY i.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('inspections/index', [
            'pageTitle' => 'Inspections',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'summary'   => $this->summary(),
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'draft','submitted','reviewed'", 'All statuses')],
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => $this->lookups->cropOptions('All crops')],
                ['key' => 'season_id', 'label' => 'Season', 'options' => $this->lookups->seasonOptions('All seasons')],
                ['key' => 'officer_id', 'label' => 'Officer', 'options' => $this->lookups->officerOptions('All officers')],
            ],
        ]);
    }

    public function create(): Response
    {
        $gardenId = $this->request->int('garden_id');
        $record   = null;

        if ($gardenId > 0) {
            $garden = $this->db->selectOne(
                'SELECT g.id, g.farmer_id, g.garden_code, g.name, c.id AS crop_id, p.id AS planting_id, p.season_id
                 FROM gardens g
                 LEFT JOIN plantings p ON p.garden_id = g.id AND p.status <> \'terminated\'
                 LEFT JOIN crops c ON c.id = p.crop_id
                 WHERE g.id = :id LIMIT 1',
                ['id' => $gardenId]
            );

            if ($garden !== null) {
                $record = [
                    'garden_id'   => $garden['id'],
                    'farmer_id'   => $garden['farmer_id'],
                    'crop_id'     => $garden['crop_id'],
                    'planting_id' => $garden['planting_id'],
                    'season_id'   => $garden['season_id'],
                ];
            }
        }

        return $this->render('inspections/form', [
            'pageTitle' => 'Record inspection',
            'record'    => $record,
            'sections'  => $this->sections(),
            'findings'  => [],
            'gpsLng'    => '',
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);

        // The garden carries the farmer, so the pair must agree: accepting a
        // garden that belongs to another farmer would break the scoping that
        // every inspection query relies on.
        if ($validator->fails()) {
            return $this->backWithErrors(url('inspections/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, $this->nullableColumns());

        $code   = Identifier::generate('inspection', $this->dateArg($values['inspection_date'] ?? null));
        $status = (string) ($values['status'] ?? 'submitted') === 'draft' ? 'draft' : 'submitted';

        // The garden carries the farmer, so the pair cannot be chosen
        // independently: accepting a garden that belongs to someone else would
        // break the scoping every inspection query depends on.
        $garden = $this->gardenOrFail((int) ($values['garden_id'] ?? 0));

        $values['farmer_id']  = (int) $garden['farmer_id'];
        $values['garden_id']  = (int) $garden['id'];
        $values['officer_id'] = $this->auth->officerId();
        $values['status']     = $status;
        $values['created_by'] = $this->auth->id();

        $id = $this->db->insert('inspections', $values);

        $this->storeFindings($id, $input);
        $this->storePhoto($id, $input);

        Audit::log('create', 'inspections', 'inspection', $id, $code, null, $values);

        // A draft is private working, so it notifies nobody. A submitted one
        // tells the farmer their plot was inspected and carries the advice with
        // it, which is the part the farmer actually acts on.
        if ($status === 'submitted') {
            $this->notifier->notify([
                'type'        => 'inspection_completed',
                'title'       => 'Your garden was inspected',
                'message'     => sprintf(
                    'Garden %s was inspected on %s.%s',
                    (string) $garden['garden_code'],
                    format_date((string) $values['inspection_date']),
                    ($values['recommendations'] ?? '') !== null && ($values['recommendations'] ?? '') !== ''
                        ? ' Advice: ' . (string) $values['recommendations']
                        : ''
                ),
                'farmer_id'   => (int) $garden['farmer_id'],
                'link'        => url('inspections/' . $code),
                'entity_type' => 'inspection',
                'entity_id'   => $id,
                'dedupe_key'  => 'inspection_completed:' . $code,
            ]);
        }

        Flash::success(
            $status === 'draft'
                ? 'Inspection ' . $code . ' saved as a draft. It is not counted in reports until it is submitted.'
                : 'Inspection ' . $code . ' has been recorded.'
        );

        return $this->redirect(url('inspections/' . $code));
    }

    public function show(string $code): Response
    {
        $inspection = $this->findByCodeOrFail($code);
        $this->assertScope($inspection);

        $findings = $this->db->select(
            'SELECT * FROM inspection_findings WHERE inspection_id = :id ORDER BY id',
            ['id' => $inspection['id']]
        );

        $photos = $this->db->select(
            'SELECT * FROM photos WHERE entity_type = :type AND entity_id = :id ORDER BY id DESC',
            ['type' => 'inspection', 'id' => $inspection['id']]
        );

        // The forecast is derived from inspection condition scores, so showing
        // what the forecast currently stands at makes the number auditable
        // instead of something a buyer has to take on trust.
        $forecastBasis = $inspection['planting_id'] === null
            ? null
            : $this->db->selectOne(
                'SELECT forecast_code, expected_production, revised_production, unit
                 FROM harvest_forecasts WHERE planting_id = :planting
                 ORDER BY forecast_date DESC LIMIT 1',
                ['planting' => (int) $inspection['planting_id']]
            );

        // Earlier inspections of the same plot, so a trend is visible rather
        // than each reading standing alone.
        $history = $this->db->select(
            'SELECT inspection_code, inspection_date, crop_condition, pest_status, disease_status, weed_status
             FROM inspections
             WHERE garden_id = :garden AND id <> :id
             ORDER BY inspection_date DESC LIMIT 8',
            ['garden' => $inspection['garden_id'], 'id' => $inspection['id']]
        );

        return $this->render('inspections/show', [
            'pageTitle'     => 'Inspection ' . $code,
            'inspection'    => $inspection,
            'findings'      => $findings,
            'photos'        => $photos,
            'forecastBasis' => $forecastBasis,
            'history'       => $history,
        ]);
    }

    public function edit(string $code): Response
    {
        $inspection = $this->findByCodeOrFail($code);
        $this->assertScope($inspection);

        return $this->render('inspections/form', [
            'pageTitle' => 'Edit ' . $code,
            'record'    => $inspection,
            'sections'  => $this->sections(),
            'findings'  => $this->db->select(
                'SELECT * FROM inspection_findings WHERE inspection_id = :id ORDER BY id',
                ['id' => $inspection['id']]
            ),
            'gpsLng'    => (string) ($inspection['longitude'] ?? ''),
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $inspection = $this->findByCodeOrFail($code);
        $this->assertScope($inspection);

        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('inspections/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, $this->nullableColumns());

        $garden = $this->gardenOrFail((int) ($values['garden_id'] ?? 0));

        $values['farmer_id'] = (int) $garden['farmer_id'];
        $values['status']    = (string) ($values['status'] ?? 'submitted') === 'draft' ? 'draft' : 'submitted';

        $before = $inspection;

        $this->db->update('inspections', $values, 'id = :id', ['id' => $inspection['id']]);

        // Findings are replaced wholesale rather than diffed: the officer is
        // correcting one list, and a half-applied list is worse than none.
        //
        // Only when a finding list was actually posted. An edit form that
        // failed validation comes back with the rows the officer typed, and a
        // post with no finding array at all means nothing was submitted - not
        // that every existing finding should be thrown away.
        if (is_array($input['finding_name'] ?? null)) {
            $this->db->delete('inspection_findings', 'inspection_id = :id', ['id' => $inspection['id']]);
            $this->storeFindings($inspection['id'], $input);
        }

        Audit::log('update', 'inspections', 'inspection', (int) $inspection['id'], $code, $before, $values);

        Flash::success('Inspection ' . $code . ' has been updated.');

        return $this->redirect(url('inspections/' . $code));
    }

    /**
     * A submitted inspection is part of the record a farmer and a buyer may
     * both rely on, so it is withdrawn rather than deleted. Drafts carry no
     * such weight and are removed outright.
     */
    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $inspection = $this->findByCodeOrFail($code);
        $this->assertScope($inspection);

        if ($inspection['status'] === 'draft') {
            $this->db->delete('inspections', 'id = :id', ['id' => $inspection['id']]);

            Audit::log('delete', 'inspections', 'inspection', (int) $inspection['id'], $code, $inspection);

            Flash::success('Draft inspection ' . $code . ' has been discarded.');

            return $this->redirect(url('inspections'));
        }

        $this->db->update('inspections', ['status' => 'draft'], 'id = :id', ['id' => $inspection['id']]);

        Audit::log('delete', 'inspections', 'inspection', (int) $inspection['id'], $code, $inspection);

        Flash::success('Inspection ' . $code . ' has been withdrawn and is no longer counted in reports.');

        return $this->redirect(url('inspections'));
    }

    /**
     * Counts for the summary strip, scoped the same way as the register so a
     * farmer never sees a cooperative-wide number.
     *
     * @return array<string,int>
     */
    private function summary(): array
    {
        $values = [];
        $scope  = $this->scopeWhere($values);

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(i.inspection_date >= CURDATE() - INTERVAL 30 DAY) AS recent,
                    SUM(i.crop_condition IN ('poor','critical')) AS poor,
                    SUM(i.status = 'draft') AS drafts
             FROM inspections i WHERE 1 = 1" . $scope,
            $values
        ) ?? [];

        return [
            'total'   => (int) ($row['total'] ?? 0),
            'recent'  => (int) ($row['recent'] ?? 0),
            'poor'    => (int) ($row['poor'] ?? 0),
            'drafts'  => (int) ($row['drafts'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function gardenOrFail(int $gardenId): array
    {
        if ($gardenId <= 0) {
            throw new HttpException(422, 'Select the garden that was inspected.');
        }

        $garden = $this->db->selectOne(
            'SELECT id, farmer_id, garden_code FROM gardens WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $gardenId]
        );

        if ($garden === null) {
            throw new HttpException(404, 'That garden could not be found.');
        }

        $farmerId = $this->auth->farmerId();

        if ($farmerId !== null && (int) $garden['farmer_id'] !== $farmerId) {
            throw new HttpException(404, 'That garden could not be found.');
        }

        return $garden;
    }

    /**
     * Optional photo evidence. A failed upload must not lose the inspection
     * itself, so the error is surfaced as a warning rather than a rollback.
     *
     * @param array<string,mixed> $input
     */
    private function storePhoto(int $inspectionId, array $input): void
    {
        if (!isset($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $upload = (new \Core\Uploader(
            (string) $this->config('uploads.path'),
            (string) $this->config('uploads.url')
        ))->image('photo', 'inspections', (int) $this->config('uploads.max_image_kb', 4096));

        if ($upload['success'] !== true) {
            Flash::warning('The inspection was saved, but the photo could not be stored.');

            return;
        }

        [$latitude, $longitude] = $this->gps($input);

        $this->db->insert('photos', [
            'entity_type'   => 'inspection',
            'entity_id'     => $inspectionId,
            'path'          => (string) $upload['path'],
            'caption'       => 'Inspection evidence',
            'is_geo_tagged' => $latitude !== null ? 1 : 0,
            'latitude'      => $latitude,
            'longitude'     => $longitude,
            'uploaded_by'   => $this->auth->id(),
        ]);
    }

    /**
     * Structured findings, submitted as parallel arrays so an officer can list
     * several problems in one pass. Empty rows are skipped rather than stored
     * as blanks.
     *
     * @param array<string,mixed> $input
     */
    private function storeFindings(int $inspectionId, array $input): void
    {
        $names = $input['finding_name'] ?? null;

        if (!is_array($names)) {
            return;
        }

        $types     = (array) ($input['finding_type'] ?? []);
        $severities = (array) ($input['finding_severity'] ?? []);
        $areas     = (array) ($input['finding_area'] ?? []);
        $notes     = (array) ($input['finding_notes'] ?? []);

        foreach (array_values($names) as $index => $rawName) {
            $name = is_scalar($rawName) ? trim((string) $rawName) : '';

            if ($name === '') {
                continue;
            }

            $this->db->insert('inspection_findings', [
                'inspection_id' => $inspectionId,
                'finding_type'  => $this->arrayValue($types, $index, 'other'),
                'name'          => $name,
                'severity'      => $this->arrayValue($severities, $index, 'low'),
                'affected_area' => $this->arrayNumber($areas, $index),
                'notes'         => $this->arrayValue($notes, $index) ?: null,
            ]);
        }
    }

    /**
     * @param array<int,mixed> $values
     */
    private function arrayValue(array $values, int $index, string $default = ''): string
    {
        $value = $values[$index] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function arrayNumber(array $values, int $index): ?float
    {
        $value = $values[$index] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<int,string>
     */
    private function nullableColumns(): array
    {
        return [
            'planting_id', 'crop_id', 'season_id',
            'latitude', 'longitude',
            'crop_condition', 'pest_status', 'disease_status', 'weed_status',
            'soil_condition', 'weather_condition', 'growth_stage',
            'field_coverage', 'estimated_yield_per_acre',
            'recommendations', 'remarks',
        ];
    }

    private function dateArg(mixed $value): ?\DateTimeInterface
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date === false ? null : $date;
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'What was inspected',
                'fields' => [
                    ['name' => 'inspection_code', 'label' => 'Inspection ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically and never reused.'],
                    ['name' => 'garden_id', 'label' => 'Garden', 'type' => Form::SELECT, 'required' => true,
                     'colspan' => 6, 'options' => $this->lookups->gardenOptions(), 'table' => 'gardens'],
                    ['name' => 'inspection_date', 'label' => 'Inspection date', 'type' => Form::DATE, 'required' => true,
                     'colspan' => 6, 'default' => date('Y-m-d')],
                    ['name' => 'planting_id', 'label' => 'Planting', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->plantingOptions()],
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions('Not specified')],
                    ['name' => 'season_id', 'label' => 'Season', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->seasonOptions('Not specified')],
                ],
            ],
            [
                'title'  => 'Crop condition',
                'fields' => [
                    ['name' => 'crop_condition', 'label' => 'Overall condition', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'excellent','good','fair','poor','critical'", 'Not assessed')],
                    ['name' => 'growth_stage', 'label' => 'Growth stage', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions(
                         "'germination','vegetative','flowering','fruiting','maturity','harvest_ready'",
                         'Not recorded'
                     )],
                    ['name' => 'pest_status', 'label' => 'Pests', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'none','low','moderate','high','severe'", 'None seen')],
                    ['name' => 'disease_status', 'label' => 'Disease', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'none','low','moderate','high','severe'", 'None seen')],
                    ['name' => 'weed_status', 'label' => 'Weeds', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'none','low','moderate','high','severe'", 'None seen')],
                    ['name' => 'soil_condition', 'label' => 'Soil condition', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'excellent','good','fair','poor'", 'Not assessed')],
                    ['name' => 'weather_condition', 'label' => 'Weather', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'sunny','cloudy','rainy','stormy','dry','foggy'", 'Not recorded')],
                    ['name' => 'field_coverage', 'label' => 'Field coverage (%)', 'type' => Form::NUMBER,
                     'colspan' => 6, 'min' => 0, 'max' => 100, 'step' => 1,
                     'help' => 'Share of the plot with plants still standing.'],
                    ['name' => 'estimated_yield_per_acre', 'label' => 'Estimated yield per acre', 'type' => Form::NUMBER,
                     'colspan' => 6, 'min' => 0, 'step' => 0.01,
                     'help' => 'What this plot looks likely to yield. Feeds the production forecast.'],
                    ['name' => 'latitude', 'label' => 'GPS location', 'type' => Form::GPS, 'colspan' => 12,
                     'help' => 'Capture the spot you are standing in, or type the coordinates.'],
                    ['name' => 'recommendations', 'label' => 'Recommendations', 'type' => Form::TEXTAREA, 'colspan' => 12,
                     'help' => 'What the farmer should do next, in plain language.'],
                    ['name' => 'remarks', 'label' => 'Remarks', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
            [
                'title'  => 'Record keeping',
                'fields' => [
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'draft','submitted'", ''),
                     'help' => 'A draft is not counted in reports until it is submitted.'],
                    ['name' => 'photo', 'label' => 'Photo evidence', 'type' => Form::FILE, 'colspan' => 6,
                     'accept' => 'image/*'],
                ],
            ],
        ];
    }

    /**
     * Plantings available for attachment, bounded so the dropdown stays usable
     * on a large register.
     *
     * @return array<int,array{value:string,label:string}>
     */
    private function plantingOptions(): array
    {
        $rows = $this->db->select(
            'SELECT p.id, p.planting_code, c.name AS crop_name, f.full_name, g.name AS garden_name
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             INNER JOIN farmers f ON f.id = p.farmer_id
             LEFT JOIN gardens g ON g.id = p.garden_id
             WHERE p.status <> \'terminated\'
             ORDER BY p.planting_date DESC LIMIT 300'
        );

        $options = [['value' => '', 'label' => 'Not linked to a planting']];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => sprintf(
                    '%s — %s (%s)',
                    $row['crop_name'],
                    $row['garden_name'] ?: 'no garden',
                    $row['planting_code']
                ),
            ];
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $values
     * @return string
     */
    private function scopeWhere(array &$values): string
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null) {
            $values['scope_farmer'] = $farmerId;

            return ' AND i.farmer_id = :scope_farmer';
        }

        if ($officerId !== null) {
            $values['scope_officer'] = $officerId;

            return ' AND i.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function assertScope(array $inspection): void
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null && (int) $inspection['farmer_id'] !== $farmerId) {
            throw new HttpException(404, 'That inspection could not be found.');
        }

        if ($officerId === null) {
            return;
        }

        $assigned = $this->db->scalar(
            'SELECT 1 FROM farmers WHERE id = :id AND officer_id = :officer LIMIT 1',
            ['id' => $inspection['farmer_id'], 'officer' => $officerId]
        );

        if ($assigned === null) {
            throw new HttpException(404, 'That inspection could not be found.');
        }
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function findByCodeOrFail(string $code): array
    {
        $inspection = $this->db->selectOne(
            'SELECT i.*, f.full_name, f.farmer_code, f.phone,
                    g.garden_code, g.name AS garden_name, g.acreage AS garden_acreage,
                    c.name AS crop_name, v.name AS variety_name, s.season_code,
                    p.planting_code, p.acreage_planted,
                    o.full_name AS officer_name, u.full_name AS recorded_by_name
             FROM inspections i
             INNER JOIN farmers f ON f.id = i.farmer_id
             INNER JOIN gardens g ON g.id = i.garden_id
             LEFT JOIN crops c ON c.id = i.crop_id
             LEFT JOIN crop_varieties v ON v.id = (SELECT variety_id FROM plantings WHERE id = i.planting_id)
             LEFT JOIN seasons s ON s.id = i.season_id
             LEFT JOIN plantings p ON p.id = i.planting_id
             LEFT JOIN field_officers o ON o.id = i.officer_id
             LEFT JOIN users u ON u.id = i.created_by
             WHERE i.inspection_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($inspection === null) {
            throw new HttpException(404, 'No inspection found with the ID ' . $code . '.');
        }

        return $inspection;
    }
}
