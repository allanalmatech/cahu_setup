<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Controller;
use Core\Database;
use Core\Form;
use Core\HttpException;
use Core\Response;
use Core\Setting;
use Services\Lookups;

/**
 * Reporting: a catalogue of fixed, parameterised extracts of the register.
 *
 * A report is defined once, in reports(), as a title, a column list, the
 * filters it understands and a query callable. The HTML screen, the CSV export
 * and the print view are three renderings of that single definition, so a
 * column can never appear on one and be missing from another.
 *
 * Every query is read-only and bounded: a report is an extract, not a bulk
 * dump, and the row ceiling is stated on the page whenever it bites.
 */
final class ReportController extends Controller
{
    /**
     * Ceiling on the rows any single report pulls into PHP.
     */
    private const ROW_LIMIT = 2000;

    private const DATE_FILTERS = ['from', 'to'];

    private Lookups $lookups;

    public function __construct(Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    public function index(): Response
    {
        $catalogue = [];

        foreach ($this->reports() as $slug => $report) {
            $catalogue[$report['module']][$slug] = [
                'title'       => $report['title'],
                'description' => $report['description'],
                'filters'     => $report['filters'],
                'url'         => url('reports/' . $slug),
                'csv'         => url('reports/' . $slug . '/csv'),
                'print'       => url('reports/' . $slug . '/print'),
            ];
        }

        return $this->render('reports/index', [
            'pageTitle' => 'Reports',
            'catalogue' => $catalogue,
        ]);
    }

    public function show(string $report): Response
    {
        $definition = $this->definition($report);
        $filters    = $this->filters();
        $rows       = $this->run($definition, $filters);

        return $this->render('reports/show', [
            'pageTitle'  => $definition['title'],
            'slug'       => $report,
            'definition' => $definition,
            'columns'    => $definition['columns'],
            'rows'       => $rows,
            'filters'    => $filters,
            'applied'    => $this->applied($definition, $filters),
            'options'    => $this->filterOptions(),
            'truncated'  => count($rows) >= (int) $filters['limit'],
            'sort'       => $this->sort($definition),
        ]);
    }

    /**
     * The CSV route action, and the inherited download helper in one method.
     *
     * The route is named csv() and so is Controller::csv(), so the signature is
     * kept compatible with the parent: called with a report slug alone it builds
     * that report's extract, and called with a column list and rows it is the
     * plain download helper again. The extract therefore cannot drift from the
     * table it was rendered beside.
     *
     * @param array<int,string>             $columns
     * @param array<int,array<string,mixed>> $rows
     */
    public function csv(string $report, array $columns = [], array $rows = []): Response
    {
        if ($columns === []) {
            $definition = $this->definition($report);

            $columns = $definition['columns'];
            $rows    = $this->run($definition, $this->filters());
        }

        return parent::csv('report-' . $report . '-' . date('Y-m-d') . '.csv', $columns, $rows);
    }

    /**
     * Rendered with no application chrome: the page has to go straight to a
     * printer or a PDF driver without dragging a sidebar through the output.
     */
    public function printView(string $report): Response
    {
        $definition = $this->definition($report);
        $filters    = $this->filters();
        $rows       = $this->run($definition, $filters);

        return $this->render('reports/print', [
            'pageTitle'  => $definition['title'],
            'slug'       => $report,
            'definition' => $definition,
            'columns'    => $definition['columns'],
            'rows'       => $rows,
            'filters'    => $filters,
            'applied'    => $this->applied($definition, $filters),
            'truncated'  => count($rows) >= (int) $filters['limit'],
        ], '');
    }

    /* ------------------------------------------------------------------ */
    /* Definitions                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Each entry declares its module, its columns and the filters it honours.
     * 'sortable' maps a column label to its default direction; the label is
     * checked against 'columns' before it is ever used to order the extract.
     *
     * @return array<string,array{
     *     title:string,module:string,description:string,columns:array<int,string>,
     *     filters:array<int,string>,sortable:array<string,string>,defaultSort:string,
     *     query:callable
     * }>
     */
    public function reports(): array
    {
        return [
            'farmer-performance' => [
                'title'       => 'Farmer performance',
                'module'      => 'Farmers',
                'description' => 'Participation indicators for every farmer, scored against the weights held in system settings.',
                'columns'     => ['Farmer ID', 'Farmer', 'District', 'Group', 'Field officer', 'Gardens', 'Acreage', 'Activities completed', 'Activities total', 'Completion %', 'Gardens inspected', 'Problems', 'Harvest', 'Score', 'Grade'],
                'filters'     => ['season_id', 'district_id', 'crop_id', 'group_id'],
                'sortable'    => ['Farmer ID' => 'asc', 'Farmer' => 'asc', 'Gardens' => 'desc', 'Acreage' => 'desc', 'Score' => 'desc'],
                'defaultSort' => 'Farmer',
                'query'       => fn (array $f): array => $this->farmerPerformance($f),
            ],
            'farmers' => [
                'title'       => 'Farmer register',
                'module'      => 'Farmers',
                'description' => 'The registration record of every farmer: identity, contact, location, membership and standing.',
                'columns'     => ['Farmer ID', 'Name', 'Gender', 'Phone', 'District', 'Sub-county', 'Village', 'Group', 'Field officer', 'Registered', 'Type', 'Status', 'Gardens', 'Acreage'],
                'filters'     => [...self::DATE_FILTERS, 'season_id', 'district_id', 'crop_id', 'group_id'],
                'sortable'    => ['Farmer ID' => 'asc', 'Name' => 'asc', 'Registered' => 'desc', 'Gardens' => 'desc', 'Acreage' => 'desc'],
                'defaultSort' => 'Name',
                'query'       => fn (array $f): array => $this->farmerRegister($f),
            ],
            'gardens' => [
                'title'       => 'Garden register',
                'module'      => 'Gardens',
                'description' => 'Every plot with its acreage, land tenure, georeferencing, crop and recorded harvest.',
                'columns'     => ['Garden ID', 'Garden', 'Farmer ID', 'Farmer', 'District', 'Sub-county', 'Village', 'Acres', 'Ownership', 'Soil', 'Irrigation', 'Mapped', 'Status', 'Crop', 'Harvest'],
                'filters'     => [...self::DATE_FILTERS, 'season_id', 'district_id', 'crop_id', 'group_id'],
                'sortable'    => ['Garden ID' => 'asc', 'Garden' => 'asc', 'Farmer' => 'asc', 'Acres' => 'desc'],
                'defaultSort' => 'Farmer',
                'query'       => fn (array $f): array => $this->gardenRegister($f),
            ],
            'crops' => [
                'title'       => 'Crop acreage and distribution',
                'module'      => 'Crops',
                'description' => 'Plantings, farmers, gardens and acreage per crop, with what has actually been harvested.',
                'columns'     => ['Crop', 'Code', 'Category', 'Plantings', 'Farmers', 'Gardens', 'Varieties', 'Acreage', 'Yield / acre', 'Harvest', 'Status'],
                'filters'     => [...self::DATE_FILTERS, 'season_id', 'district_id', 'crop_id'],
                'sortable'    => ['Crop' => 'asc', 'Plantings' => 'desc', 'Acreage' => 'desc', 'Harvest' => 'desc'],
                'defaultSort' => 'Acreage',
                'query'       => fn (array $f): array => $this->cropAcreage($f),
            ],
            'activities' => [
                'title'       => 'Activity compliance',
                'module'      => 'Activities',
                'description' => 'Every scheduled activity with its due date and outcome, including the tasks that ran late.',
                'columns'     => ['Activity ID', 'Farmer ID', 'Farmer', 'Garden', 'Crop', 'Activity', 'Planned', 'Due', 'Status', 'Priority', 'Completed'],
                'filters'     => [...self::DATE_FILTERS, 'season_id', 'district_id', 'crop_id'],
                'sortable'    => ['Activity ID' => 'asc', 'Farmer' => 'asc', 'Due' => 'desc', 'Status' => 'asc'],
                'defaultSort' => 'Due',
                'query'       => fn (array $f): array => $this->activityCompliance($f),
            ],
            'inputs' => [
                'title'       => 'Input stock and movement',
                'module'      => 'Inputs',
                'description' => 'Inventory position per product with the receipts, issues and usage recorded in the period.',
                'columns'     => ['Input code', 'Product', 'Category', 'Unit', 'Reorder level', 'Balance', 'Received', 'Issued', 'Used', 'Below reorder', 'Status'],
                'filters'     => [...self::DATE_FILTERS, 'crop_id'],
                'sortable'    => ['Input code' => 'asc', 'Product' => 'asc', 'Balance' => 'desc', 'Issued' => 'desc', 'Used' => 'desc'],
                'defaultSort' => 'Product',
                'query'       => fn (array $f): array => $this->inputMovement($f),
            ],
            'production' => [
                'title'       => 'Production forecast and yield',
                'module'      => 'Production',
                'description' => 'Forecast against delivery per season and crop, with the variance between the two and yield per acre.',
                'columns'     => ['Season', 'Crop', 'Plantings', 'Farmers', 'Acreage', 'Expected', 'Revised', 'Actual', 'Variance', 'Variance %', 'Yield / acre'],
                'filters'     => [...self::DATE_FILTERS, 'season_id', 'district_id', 'crop_id'],
                'sortable'    => ['Season' => 'desc', 'Crop' => 'asc', 'Expected' => 'desc', 'Actual' => 'desc'],
                'defaultSort' => 'Season',
                'query'       => fn (array $f): array => $this->productionReport($f),
            ],
            'markets' => [
                'title'       => 'Market demand and sales',
                'module'      => 'Markets',
                'description' => 'Buyer demand against what is available, committed and already sold, per crop.',
                'columns'     => ['Crop', 'Demand', 'Available', 'Committed', 'Sold', 'Balance', 'Sales value', 'Buyers', 'Requirements', 'Deliveries'],
                'filters'     => [...self::DATE_FILTERS, 'crop_id'],
                'sortable'    => ['Crop' => 'asc', 'Demand' => 'desc', 'Sold' => 'desc', 'Sales value' => 'desc'],
                'defaultSort' => 'Demand',
                'query'       => fn (array $f): array => $this->marketReport($f),
            ],
            'audit' => [
                'title'       => 'Audit trail',
                'module'      => 'Administration',
                'description' => 'Every recorded system action, who made it, and against which record.',
                'columns'     => ['Logged', 'User', 'Action', 'Module', 'Entity', 'Record', 'IP address'],
                'filters'     => [...self::DATE_FILTERS],
                'sortable'    => ['Logged' => 'desc', 'User' => 'asc', 'Action' => 'asc', 'Module' => 'asc'],
                'defaultSort' => 'Logged',
                'query'       => fn (array $f): array => $this->auditTrail($f),
            ],
        ];
    }

    /**
     * @return array{
     *     title:string,module:string,description:string,columns:array<int,string>,
     *     filters:array<int,string>,sortable:array<string,string>,defaultSort:string,query:callable
     * }
     */
    private function definition(string $slug): array
    {
        $reports = $this->reports();

        if (!isset($reports[$slug])) {
            throw new HttpException(404, 'Unknown report.');
        }

        return $reports[$slug];
    }

    /**
     * The requested sort column, accepted only when it is one of this report's
     * own columns. Nothing from the request reaches SQL.
     *
     * @param array{sortable:array<string,string>,columns:array<int,string>,defaultSort:string} $definition
     */
    private function sort(array $definition): string
    {
        $requested = $this->request->string('sort');

        return in_array($requested, $definition['columns'], true) && isset($definition['sortable'][$requested])
            ? $requested
            : $definition['defaultSort'];
    }

    /**
     * @param array{query:callable} $definition
     * @param array<string,mixed>   $filters
     * @return array<int,array<string,mixed>>
     */
    private function run(array $definition, array $filters): array
    {
        /** @var callable(array<string,mixed>):array<int,array<string,mixed>> $query */
        $query = $definition['query'];

        return $query($filters);
    }

    /* ------------------------------------------------------------------ */
    /* Filters                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Normalised once, for every rendering of a report. Dates are real calendar
     * days or nothing, identifiers are integers or nothing, and a reversed
     * range is corrected rather than silently returning an empty extract.
     *
     * @return array{from:?string,to:?string,season_id:?int,district_id:?int,crop_id:?int,group_id:?int,limit:int}
     */
    private function filters(): array
    {
        $from = $this->request->date('from');
        $to   = $this->request->date('to');

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from'        => $from,
            'to'          => $to,
            'season_id'   => $this->request->int('season_id') ?: null,
            'district_id' => $this->request->int('district_id') ?: null,
            'crop_id'     => $this->request->int('crop_id') ?: null,
            'group_id'    => $this->request->int('group_id') ?: null,
            'limit'       => self::ROW_LIMIT,
        ];
    }

    /**
     * A plain-language summary of what the extract is scoped to, printed above
     * the table and repeated on the printout so a paper copy carries its own
     * provenance.
     *
     * @param array{title:string,filters:array<int,string>} $definition
     * @param array<string,mixed>                            $filters
     * @return array<string,string>
     */
    private function applied(array $definition, array $filters): array
    {
        $options = $this->filterOptions();
        $applied = [];

        foreach ($definition['filters'] as $key) {
            $value = $filters[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($key === 'from') {
                $applied['Period'] = 'from ' . format_date((string) $filters['from'])
                    . ($filters['to'] !== null ? ' to ' . format_date((string) $filters['to']) : '');

                continue;
            }

            if ($key === 'to') {
                $applied['Period'] = 'up to ' . format_date((string) $filters['to']);

                continue;
            }

            $applied[ucwords(str_replace('_id', '', $key))] = $this->optionLabel($options[$key] ?? [], (int) $value);
        }

        return $applied;
    }

    /**
     * @param array<int,array{value:string,label:string}> $options
     */
    private function optionLabel(array $options, int $id): string
    {
        foreach ($options as $option) {
            if ((int) $option['value'] === $id) {
                return $option['label'];
            }
        }

        return (string) $id;
    }

    /**
     * @return array<string,array<int,array{value:string,label:string}>>
     */
    private function filterOptions(): array
    {
        return [
            'district_id' => $this->lookups->districtOptions(),
            'season_id'   => $this->lookups->seasonOptions(),
            'crop_id'     => $this->lookups->cropOptions(),
            'group_id'    => Form::options(
                $this->db->select('SELECT id, name FROM farmer_groups WHERE deleted_at IS NULL ORDER BY name'),
                'id',
                'name',
                'All groups'
            ),
        ];
    }

    /**
     * Adds one equality condition per requested filter. Each contributes a
     * single placeholder, and only when it was asked for.
     *
     * @param array<int,string>   $where
     * @param array<string,mixed> $values
     * @param array<string,mixed> $filters
     * @param array<string,string> $map
     */
    private function apply(array &$where, array &$values, array $filters, array $map): void
    {
        foreach ($map as $key => $column) {
            if (($filters[$key] ?? null) === null) {
                continue;
            }

            $where[]      = $column . ' = :' . $key;
            $values[$key] = $filters[$key];
        }
    }

    /**
     * @param array<int,string>    $where
     * @param array<string,mixed>  $values
     * @param array<string,mixed>  $filters
     */
    private function dateWindow(array &$where, array &$values, array $filters, string $column): void
    {
        if (($filters['from'] ?? null) !== null) {
            $where[]        = $column . ' >= :from';
            $values['from'] = $filters['from'];
        }

        if (($filters['to'] ?? null) !== null) {
            $where[]      = $column . ' <= :to';
            $values['to'] = $filters['to'];
        }
    }

    /**
     * @param array<int,string> $where
     */
    private function whereSql(array $where): string
    {
        return $where === [] ? '1 = 1' : implode(' AND ', $where);
    }

    /**
     * Re-orders a bounded extract in memory when the reader asked for a
     * supported column. The SQL already carries a sensible default order, so
     * this only runs for an explicit, whitelisted choice.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array{sortable:array<string,string>,columns:array<int,string>} $definition
     * @return array<int,array<string,mixed>>
     */
    private function sortRows(array $rows, array $definition): array
    {
        $requested = $this->request->string('sort');

        if ($rows === []
            || !in_array($requested, $definition['columns'], true)
            || !isset($definition['sortable'][$requested])) {
            return $rows;
        }

        $direction = $definition['sortable'][$requested] === 'asc' ? 1 : -1;

        usort($rows, static function (array $a, array $b) use ($requested, $direction): int {
            $left  = $a[$requested] ?? '';
            $right = $b[$requested] ?? '';

            if (is_numeric($left) && is_numeric($right)) {
                return $direction * ((float) $left <=> (float) $right);
            }

            return $direction * strcasecmp((string) $left, (string) $right);
        });

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* Farmer reports                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function farmerPerformance(array $f): array
    {
        [$where, $values] = $this->farmerScope($f);

        $rows = $this->db->select(
            'SELECT f.farmer_code AS `Farmer ID`, f.full_name AS `Farmer`,
                    d.name AS `District`, gr.name AS `Group`, o.full_name AS `Field officer`,
                    (SELECT COUNT(*) FROM gardens gd
                      WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS `Gardens`,
                    (SELECT COALESCE(SUM(gd.acreage), 0) FROM gardens gd
                      WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS `Acreage`,
                    (SELECT COUNT(*) FROM crop_activities ca
                      WHERE ca.farmer_id = f.id AND ca.status = \'completed\') AS `Activities completed`,
                    (SELECT COUNT(*) FROM crop_activities ca
                      WHERE ca.farmer_id = f.id AND ca.status <> \'cancelled\') AS `Activities total`,
                    (SELECT COUNT(DISTINCT i.garden_id) FROM inspections i
                      WHERE i.farmer_id = f.id) AS `Gardens inspected`,
                    (SELECT COUNT(*) FROM problem_reports pr
                      WHERE pr.farmer_id = f.id) AS `Problems`,
                    (SELECT COALESCE(SUM(hr.quantity_harvested), 0) FROM harvest_records hr
                      WHERE hr.farmer_id = f.id AND hr.status <> \'cancelled\') AS `Harvest`
             FROM farmers f
             LEFT JOIN districts d ON d.id = f.district_id
             LEFT JOIN farmer_groups gr ON gr.id = f.group_id
             LEFT JOIN field_officers o ON o.id = f.officer_id
             WHERE ' . $this->whereSql($where) . '
             ORDER BY f.full_name ASC
             LIMIT ' . (int) $f['limit'],
            $values
        );

        $weights = $this->scoreWeights();

        foreach ($rows as $index => $row) {
            $completion = (float) $row['Activities total'] > 0.0
                ? min(1.0, (float) $row['Activities completed'] / (float) $row['Activities total'])
                : 0.0;
            $inspected = (float) $row['Gardens'] > 0.0
                ? min(1.0, (float) $row['Gardens inspected'] / (float) $row['Gardens'])
                : 0.0;
            $reporting = min(1.0, (float) $row['Problems'] / 10.0);
            $harvested = (float) $row['Activities completed'] > 0.0 ? 1.0 : 0.0;

            $earned = $completion * $weights['activity_completion']
                + $inspected * $weights['inspection_uptake']
                + $reporting * $weights['problem_reporting']
                + $harvested * $weights['harvest_submission'];

            $score = $earned / max(1.0, array_sum($weights)) * 100.0;

            $rows[$index]['Completion %'] = round($completion * 100, 1);
            $rows[$index]['Score']       = round(min(100.0, $score), 1);
            $rows[$index]['Grade']       = $this->grade($rows[$index]['Score']);
        }

        return $this->sortRows($rows, $this->definition('farmer-performance'));
    }

    /**
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function farmerRegister(array $f): array
    {
        [$where, $values] = $this->farmerScope($f, true);

        $rows = $this->db->select(
            'SELECT f.farmer_code AS `Farmer ID`, f.full_name AS `Name`, f.gender AS `Gender`,
                    f.phone AS `Phone`, d.name AS `District`, sc.name AS `Sub-county`,
                    COALESCE(f.village_name, v.name) AS `Village`, gr.name AS `Group`,
                    o.full_name AS `Field officer`, f.registration_date AS `Registered`,
                    f.farmer_type AS `Type`, f.status AS `Status`,
                    (SELECT COUNT(*) FROM gardens gd
                      WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS `Gardens`,
                    (SELECT COALESCE(SUM(gd.acreage), 0) FROM gardens gd
                      WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS `Acreage`
             FROM farmers f
             LEFT JOIN districts d ON d.id = f.district_id
             LEFT JOIN sub_counties sc ON sc.id = f.sub_county_id
             LEFT JOIN villages v ON v.id = f.village_id
             LEFT JOIN farmer_groups gr ON gr.id = f.group_id
             LEFT JOIN field_officers o ON o.id = f.officer_id
             WHERE ' . $this->whereSql($where) . '
             ORDER BY f.full_name ASC
             LIMIT ' . (int) $f['limit'],
            $values
        );

        return $this->sortRows($rows, $this->definition('farmers'));
    }

    /**
     * Farmers filtered by register attributes, and by the crops and seasons they
     * actually plant in when those filters are asked for.
     *
     * @param array<string,mixed> $f
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private function farmerScope(array $f, bool $withRegistrationWindow = false): array
    {
        $where  = ['f.deleted_at IS NULL'];
        $values = [];

        if ($f['district_id'] !== null) {
            $where[]              = 'f.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        if ($f['group_id'] !== null) {
            $where[]           = 'f.group_id = :group_id';
            $values['group_id'] = $f['group_id'];
        }

        if ($f['crop_id'] !== null) {
            $where[]         = 'EXISTS (SELECT 1 FROM plantings p
                                  WHERE p.farmer_id = f.id AND p.crop_id = :crop_id)';
            $values['crop_id'] = $f['crop_id'];
        }

        if ($f['season_id'] !== null) {
            $where[]           = 'EXISTS (SELECT 1 FROM plantings p
                                  WHERE p.farmer_id = f.id AND p.season_id = :season_id)';
            $values['season_id'] = $f['season_id'];
        }

        if ($withRegistrationWindow) {
            $this->dateWindow($where, $values, $f, 'f.registration_date');
        }

        return [$where, $values];
    }

    /* ------------------------------------------------------------------ */
    /* Garden reports                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function gardenRegister(array $f): array
    {
        $where  = ['g.deleted_at IS NULL'];
        $values = [];

        $this->apply($where, $values, $f, ['district_id' => 'g.district_id', 'group_id' => 'f.group_id']);

        if ($f['crop_id'] !== null) {
            $where[]         = 'EXISTS (SELECT 1 FROM plantings p
                                  WHERE p.garden_id = g.id AND p.crop_id = :crop_id)';
            $values['crop_id'] = $f['crop_id'];
        }

        if ($f['season_id'] !== null) {
            $where[]           = 'EXISTS (SELECT 1 FROM plantings p
                                  WHERE p.garden_id = g.id AND p.season_id = :season_id)';
            $values['season_id'] = $f['season_id'];
        }

        $this->dateWindow($where, $values, $f, 'g.created_at');

        $rows = $this->db->select(
            'SELECT g.garden_code AS `Garden ID`, g.name AS `Garden`,
                    f.farmer_code AS `Farmer ID`, f.full_name AS `Farmer`,
                    d.name AS `District`, sc.name AS `Sub-county`,
                    COALESCE(g.village_name, v.name) AS `Village`,
                    g.acreage AS `Acres`, g.land_ownership AS `Ownership`,
                    g.soil_type AS `Soil`, g.irrigation_status AS `Irrigation`,
                    IF(g.mapped = 1, \'yes\', \'no\') AS `Mapped`,
                    g.status AS `Status`,
                    (SELECT c.name FROM plantings p
                       INNER JOIN crops c ON c.id = p.crop_id
                      WHERE p.garden_id = g.id AND p.status <> \'terminated\'
                      ORDER BY p.planting_date DESC LIMIT 1) AS `Crop`,
                    (SELECT COALESCE(SUM(hr.quantity_harvested), 0) FROM harvest_records hr
                      WHERE hr.garden_id = g.id AND hr.status <> \'cancelled\') AS `Harvest`
             FROM gardens g
             INNER JOIN farmers f ON f.id = g.farmer_id
             LEFT JOIN districts d ON d.id = g.district_id
             LEFT JOIN sub_counties sc ON sc.id = g.sub_county_id
             LEFT JOIN villages v ON v.id = g.village_id
             WHERE ' . $this->whereSql($where) . '
             ORDER BY f.full_name ASC, g.name ASC
             LIMIT ' . (int) $f['limit'],
            $values
        );

        return $this->sortRows($rows, $this->definition('gardens'));
    }

    /* ------------------------------------------------------------------ */
    /* Crop reports                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function cropAcreage(array $f): array
    {
        $where  = ["p.status <> 'terminated'"];
        $values = [];

        $this->apply($where, $values, $f, [
            'season_id'   => 'p.season_id',
            'district_id' => 'g.district_id',
            'crop_id'     => 'c.id',
        ]);

        $this->dateWindow($where, $values, $f, 'p.planting_date');

        $rows = $this->db->select(
            'SELECT c.name AS `Crop`, c.crop_code AS `Code`, c.category AS `Category`,
                    COUNT(p.id) AS `Plantings`,
                    COUNT(DISTINCT p.farmer_id) AS `Farmers`,
                    COUNT(DISTINCT p.garden_id) AS `Gardens`,
                    (SELECT COUNT(*) FROM crop_varieties cv WHERE cv.crop_id = c.id) AS `Varieties`,
                    COALESCE(SUM(p.acreage_planted), 0) AS `Acreage`,
                    COALESCE(c.expected_yield_per_acre, 0) AS `Yield / acre`,
                    (SELECT COALESCE(SUM(hr.quantity_harvested), 0) FROM harvest_records hr
                      WHERE hr.crop_id = c.id AND hr.status <> \'cancelled\') AS `Harvest`,
                    c.status AS `Status`
             FROM plantings p
             INNER JOIN gardens g ON g.id = p.garden_id
             INNER JOIN crops c ON c.id = p.crop_id
             WHERE ' . $this->whereSql($where) . '
             GROUP BY c.id, c.name, c.crop_code, c.category, c.expected_yield_per_acre, c.status
             ORDER BY `Acreage` DESC
             LIMIT ' . (int) $f['limit'],
            $values
        );

        return $this->sortRows($rows, $this->definition('crops'));
    }

    /* ------------------------------------------------------------------ */
    /* Activity reports                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function activityCompliance(array $f): array
    {
        $where  = [];
        $values = [];

        $this->apply($where, $values, $f, [
            'season_id'   => 'a.season_id',
            'district_id' => 'f.district_id',
            'crop_id'     => 'a.crop_id',
        ]);

        $this->dateWindow($where, $values, $f, 'a.due_date');

        $rows = $this->db->select(
            'SELECT a.activity_code AS `Activity ID`, f.farmer_code AS `Farmer ID`,
                    f.full_name AS `Farmer`, g.garden_code AS `Garden`, c.name AS `Crop`,
                    ' . Lookups::activityNameSql('a') . ' AS `Activity`,
                    a.planned_date AS `Planned`, a.due_date AS `Due`, a.status AS `Status`,
                    a.priority AS `Priority`, a.completed_at AS `Completed`
             FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             INNER JOIN gardens g ON g.id = a.garden_id
             LEFT JOIN crops c ON c.id = a.crop_id
             WHERE ' . $this->whereSql($where) . '
             ORDER BY a.due_date DESC
             LIMIT ' . (int) $f['limit'],
            $values
        );

        return $this->sortRows($rows, $this->definition('activities'));
    }

    /* ------------------------------------------------------------------ */
    /* Input reports                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function inputMovement(array $f): array
    {
        $where  = [];
        $values = [];

        $this->apply($where, $values, $f, ['crop_id' => 'i.crop_id']);

        // The movement window belongs to the ledger, not to the product list, so
        // it is applied inside the derived table rather than the outer query.
        $movementWhere  = [];
        $movementValues = [];

        $this->dateWindow($movementWhere, $movementValues, $f, 't.transaction_date');

        $rows = $this->db->select(
            'SELECT i.input_code AS `Input code`, i.name AS `Product`, cat.name AS `Category`,
                    i.unit AS `Unit`, i.reorder_level AS `Reorder level`,
                    i.current_stock AS `Balance`,
                    COALESCE(m.received, 0) AS `Received`,
                    COALESCE(m.issued, 0) AS `Issued`,
                    COALESCE(m.used, 0) AS `Used`,
                    IF(i.current_stock <= i.reorder_level, \'yes\', \'no\') AS `Below reorder`,
                    i.status AS `Status`
             FROM inputs i
             LEFT JOIN input_categories cat ON cat.id = i.category_id
             LEFT JOIN (
                 SELECT t.input_id,
                        SUM(CASE WHEN t.transaction_type IN (\'opening\', \'receipt\') THEN t.quantity ELSE 0 END) AS received,
                        SUM(CASE WHEN t.transaction_type = \'issue\' THEN t.quantity ELSE 0 END) AS issued,
                        SUM(CASE WHEN t.transaction_type = \'usage\' THEN t.quantity ELSE 0 END) AS used
                 FROM input_transactions t
                 WHERE ' . $this->whereSql($movementWhere) . '
                 GROUP BY t.input_id
             ) m ON m.input_id = i.id
             WHERE ' . $this->whereSql($where) . '
             ORDER BY i.name ASC
             LIMIT ' . (int) $f['limit'],
            $movementValues + $values
        );

        return $this->sortRows($rows, $this->definition('inputs'));
    }

    /* ------------------------------------------------------------------ */
    /* Production reports                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function productionReport(array $f): array
    {
        $where  = ["p.status <> 'terminated'"];
        $values = [];

        $this->apply($where, $values, $f, [
            'season_id'   => 'p.season_id',
            'district_id' => 'g.district_id',
            'crop_id'     => 'c.id',
        ]);

        $this->dateWindow($where, $values, $f, 'p.planting_date');

        $rows = $this->db->select(
            'SELECT s.season_code AS `Season`, c.name AS `Crop`,
                    COUNT(p.id) AS `Plantings`, COUNT(DISTINCT p.farmer_id) AS `Farmers`,
                    COALESCE(SUM(p.acreage_planted), 0) AS `Acreage`,
                    COALESCE(fc.expected, 0) AS `Expected`,
                    COALESCE(fc.revised, 0) AS `Revised`,
                    COALESCE(hv.actual, 0) AS `Actual`,
                    COALESCE(hv.actual, 0) - COALESCE(fc.expected, 0) AS `Variance`
             FROM plantings p
             INNER JOIN gardens g ON g.id = p.garden_id
             INNER JOIN crops c ON c.id = p.crop_id
             INNER JOIN seasons s ON s.id = p.season_id
             LEFT JOIN (
                 SELECT hf.season_id, hf.crop_id,
                        SUM(hf.expected_production) AS expected,
                        SUM(COALESCE(hf.revised_production, 0)) AS revised
                 FROM harvest_forecasts hf
                 GROUP BY hf.season_id, hf.crop_id
             ) fc ON fc.season_id = p.season_id AND fc.crop_id = p.crop_id
             LEFT JOIN (
                 SELECT hr.season_id, hr.crop_id, SUM(hr.quantity_harvested) AS actual
                 FROM harvest_records hr
                 WHERE hr.status <> \'cancelled\'
                 GROUP BY hr.season_id, hr.crop_id
             ) hv ON hv.season_id = p.season_id AND hv.crop_id = p.crop_id
             WHERE ' . $this->whereSql($where) . '
             GROUP BY s.id, s.season_code, s.start_date, c.id, c.name,
                      fc.expected, fc.revised, hv.actual
             ORDER BY s.start_date DESC, c.name ASC
             LIMIT ' . (int) $f['limit'],
            $values
        );

        foreach ($rows as $index => $row) {
            $expected = (float) $row['Expected'];
            $acreage  = (float) $row['Acreage'];

            $rows[$index]['Variance %']   = $expected > 0.0
                ? round(((float) $row['Actual'] - $expected) / $expected * 100, 1)
                : 0.0;
            $rows[$index]['Yield / acre'] = $acreage > 0.0
                ? round((float) $row['Actual'] / $acreage, 2)
                : 0.0;
        }

        return $this->sortRows($rows, $this->definition('production'));
    }

    /* ------------------------------------------------------------------ */
    /* Market reports                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function marketReport(array $f): array
    {
        $where  = [];
        $values = [];

        $this->apply($where, $values, $f, ['crop_id' => 'c.id']);

        // Sales figures belong to the period, so the date window narrows the
        // sale aggregates rather than the crop list they are joined onto. Each
        // aggregate binds its own copy of the window: a named placeholder may
        // not be repeated in a natively prepared statement.
        $scopes = [];

        foreach (['committed', 'sold', 'deliveries'] as $suffix) {
            [$conditions, $scopeValues] = $this->saleScope($f, $suffix);
            $scopes[$suffix] = $this->whereSql($conditions);
            $values          = $values + $scopeValues;
        }

        $rows = $this->db->select(
            'SELECT c.name AS `Crop`,
                    COALESCE(dm.demand, 0) AS `Demand`,
                    COALESCE(av.available, 0) AS `Available`,
                    COALESCE(cm.committed, 0) AS `Committed`,
                    COALESCE(sd.sold, 0) AS `Sold`,
                    COALESCE(av.available, 0) - COALESCE(cm.committed, 0) AS `Balance`,
                    COALESCE(sd.value, 0) AS `Sales value`,
                    COALESCE(dm.buyers, 0) AS `Buyers`,
                    COALESCE(dm.requirements, 0) AS `Requirements`,
                    COALESCE(dv.deliveries, 0) AS `Deliveries`
             FROM crops c
             LEFT JOIN (
                 SELECT mr.crop_id,
                        SUM(mr.quantity_required) AS demand,
                        COUNT(DISTINCT mr.buyer_id) AS buyers,
                        COUNT(*) AS requirements
                 FROM market_requirements mr
                 WHERE mr.status IN (\'open\', \'partially_filled\')
                 GROUP BY mr.crop_id
             ) dm ON dm.crop_id = c.id
             LEFT JOIN (
                 SELECT hb.crop_id, SUM(hb.total_quantity) AS available
                 FROM harvest_batches hb
                 WHERE hb.status IN (\'open\', \'aggregated\')
                 GROUP BY hb.crop_id
             ) av ON av.crop_id = c.id
             LEFT JOIN (
                 SELECT si.crop_id, SUM(si.quantity) AS committed
                 FROM sale_items si
                 INNER JOIN sales s ON s.id = si.sale_id
                 WHERE s.status <> \'cancelled\' AND ' . $scopes['committed'] . '
                 GROUP BY si.crop_id
             ) cm ON cm.crop_id = c.id
             LEFT JOIN (
                 SELECT si.crop_id, SUM(si.quantity) AS sold, SUM(si.total_value) AS value
                 FROM sale_items si
                 INNER JOIN sales s ON s.id = si.sale_id
                 WHERE s.status = \'fulfilled\' AND ' . $scopes['sold'] . '
                 GROUP BY si.crop_id
             ) sd ON sd.crop_id = c.id
             LEFT JOIN (
                 SELECT si.crop_id, COUNT(d.id) AS deliveries
                 FROM sale_items si
                 INNER JOIN sales s ON s.id = si.sale_id
                 INNER JOIN deliveries d ON d.sale_id = s.id
                 WHERE s.status <> \'cancelled\' AND ' . $scopes['deliveries'] . '
                 GROUP BY si.crop_id
             ) dv ON dv.crop_id = c.id
             WHERE ' . $this->whereSql($where) . '
             ORDER BY `Demand` DESC, c.name ASC
             LIMIT ' . (int) $f['limit'],
            $values
        );

        return $this->sortRows($rows, $this->definition('markets'));
    }

    /**
     * The sale date window under a suffixed placeholder name, so several
     * aggregates in one statement can each carry it.
     *
     * @param array<string,mixed> $f
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private function saleScope(array $f, string $suffix): array
    {
        $where  = [];
        $values = [];

        if (($f['from'] ?? null) !== null) {
            $where[]                    = 's.sale_date >= :from_' . $suffix;
            $values['from_' . $suffix] = $f['from'];
        }

        if (($f['to'] ?? null) !== null) {
            $where[]                  = 's.sale_date <= :to_' . $suffix;
            $values['to_' . $suffix] = $f['to'];
        }

        return [$where, $values];
    }

    /* ------------------------------------------------------------------ */
    /* Administration reports                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    private function auditTrail(array $f): array
    {
        $where  = [];
        $values = [];

        $this->dateWindow($where, $values, $f, 'a.created_at');

        $rows = $this->db->select(
            'SELECT a.created_at AS `Logged`, COALESCE(a.user_name, u.username) AS `User`,
                    a.action AS `Action`, a.module AS `Module`, a.entity_type AS `Entity`,
                    a.entity_label AS `Record`, a.ip_address AS `IP address`
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE ' . $this->whereSql($where) . '
             ORDER BY a.created_at DESC
             LIMIT ' . (int) $f['limit'],
            $values
        );

        return $this->sortRows($rows, $this->definition('audit'));
    }

    /* ------------------------------------------------------------------ */
    /* Scoring                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Participation weights, held in settings so a programme can change its
     * policy without a deployment. The score is normalised over the weights
     * actually in force, so it stays a percentage however they are set.
     *
     * @return array<string,float>
     */
    private function scoreWeights(): array
    {
        return [
            'activity_completion' => (float) Setting::get('score.weight.activity_completion', 40),
            'inspection_uptake'   => (float) Setting::get('score.weight.inspection_uptake', 15),
            'problem_reporting'   => (float) Setting::get('score.weight.problem_reporting', 10),
            'harvest_submission'  => (float) Setting::get('score.weight.harvest_submission', 15),
        ];
    }

    private function grade(float $score): string
    {
        return match (true) {
            $score >= 85 => 'Excellent',
            $score >= 70 => 'Good',
            $score >= 50 => 'Fair',
            $score >= 30 => 'Needs support',
            default      => 'At risk',
        };
    }
}