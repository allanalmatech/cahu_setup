<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Controller;
use Core\Database;
use Core\Response;
use Services\Lookups;

/**
 * Command Centre: the executive view of the whole programme.
 *
 * Every figure is a single scalar query. A dashboard that loads rows and counts
 * them in PHP is correct on a demo dataset and wrong on a real one, because its
 * cost grows with the number of farmers rather than with the number of
 * questions being asked. The same figure set is produced for the HTML page and
 * for the JSON endpoint, so the tiles a manager refreshes are the tiles that
 * were rendered, not a second, drifting implementation.
 *
 * The screen is read-only: nothing here inserts, updates or deletes.
 */
final class CommandCentreController extends Controller
{
    private Lookups $lookups;

    public function __construct(Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    public function index(): Response
    {
        $filters = $this->filters();
        $figures = $this->figures($filters);

        return $this->render('command_centre', [
            'pageTitle' => 'Command Centre',
            'sections'  => $figures['sections'],
            'charts'    => $figures['charts'],
            'filters'   => $filters,
            'districts' => $this->lookups->districtOptions('All districts'),
            'seasons'   => $this->lookups->seasonOptions('All seasons'),
            'crops'     => $this->lookups->cropOptions('All crops'),
            'dataUrl'   => url('command-centre/data'),
            'chartJs'   => true,
        ]);
    }

    /**
     * The same figures as the page, for the in-page refresh. Mirrors the
     * notifications poll in the layout rather than reloading the document.
     */
    public function data(): Response
    {
        $filters = $this->filters();

        return $this->json(array_merge($this->figures($filters), [
            'filters' => $filters['values'],
        ]));
    }

    /* ------------------------------------------------------------------ */
    /* Figures                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $filters
     * @return array{sections:array<int,array<string,mixed>>,charts:array<string,mixed>}
     */
    private function figures(array $filters): array
    {
        return [
            'sections' => [
                $this->farmerSection($filters),
                $this->gardenSection($filters),
                $this->cropSection($filters),
                $this->activitySection($filters),
                $this->problemSection($filters),
                $this->productionSection($filters),
                $this->marketSection($filters),
                $this->officerSection($filters),
            ],
            'charts' => [
                'registrations' => $this->registrationTrend($filters),
                'cropMix'       => $this->cropMix($filters),
                'activityMix'   => $this->activityMix($filters),
                'districtProduction' => $this->districtProduction($filters),
            ],
        ];
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array{key:string,label:string,tiles:array<int,array<string,mixed>>}
     */
    private function farmerSection(array $f): array
    {
        $where  = ['f.deleted_at IS NULL'];
        $values = [];

        if ($f['district_id'] !== null) {
            $where[]            = 'f.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        $total    = $this->count('farmers f', $where, $values);
        $active   = $this->count('farmers f', [...$where, "f.status = 'active'"], $values);
        $inactive = $this->count('farmers f', [...$where, "f.status <> 'active'"], $values);
        $recent   = $this->count(
            'farmers f',
            [...$where, 'f.registration_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'],
            $values
        );

        return [
            'key'   => 'farmers',
            'label' => 'Farmers',
            'tiles' => [
                ['key' => 'farmers_total', 'label' => 'Total farmers', 'value' => $total, 'tone' => 'green',
                 'meta' => percent($active, $total) . '% of the register is active'],
                ['key' => 'farmers_active', 'label' => 'Active farmers', 'value' => $active, 'tone' => 'green'],
                ['key' => 'farmers_new', 'label' => 'New registrations', 'value' => $recent, 'tone' => 'blue',
                 'meta' => 'in the last 30 days'],
                ['key' => 'farmers_inactive', 'label' => 'Inactive farmers', 'value' => $inactive, 'tone' => 'amber',
                 'meta' => 'inactive, exited, deceased or still pending'],
            ],
        ];
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array{key:string,label:string,tiles:array<int,array<string,mixed>>}
     */
    private function gardenSection(array $f): array
    {
        $from   = 'gardens g INNER JOIN farmers f ON f.id = g.farmer_id';
        $where  = ['g.deleted_at IS NULL'];
        $values = [];

        if ($f['district_id'] !== null) {
            $where[]              = 'g.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        if ($f['season_id'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM plantings p WHERE p.garden_id = g.id AND p.season_id = :season_id)';
            $values['season_id'] = $f['season_id'];
        }

        $total   = $this->count($from, $where, $values);
        $acreage = $this->sum($from, 'g', 'acreage', $where, $values);
        $mapped  = $this->count($from, [...$where, 'g.mapped = 1'], $values);

        return [
            'key'   => 'gardens',
            'label' => 'Gardens',
            'tiles' => [
                ['key' => 'gardens_total', 'label' => 'Total gardens', 'value' => $total, 'tone' => 'blue'],
                ['key' => 'gardens_acreage', 'label' => 'Total acreage', 'value' => round($acreage, 2), 'tone' => 'soil',
                 'meta' => 'acres under management', 'format' => 'number'],
                ['key' => 'gardens_mapped', 'label' => 'Mapped gardens', 'value' => $mapped, 'tone' => 'green',
                 'meta' => percent($mapped, $total) . '% georeferenced'],
                ['key' => 'gardens_average', 'label' => 'Average plot', 'value' => $total > 0 ? round($acreage / $total, 2) : 0.0, 'tone' => 'soil',
                 'meta' => 'acres per garden', 'format' => 'number'],
            ],
        ];
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array{key:string,label:string,tiles:array<int,array<string,mixed>>}
     */
    private function cropSection(array $f): array
    {
        $where  = ["c.status = 'active'"];
        $values = [];

        if ($f['crop_id'] !== null) {
            $where[]              = 'c.id = :crop_id';
            $values['crop_id']    = $f['crop_id'];
        }

        $activeCrops = $this->count('crops c', $where, $values);

        // The crop filter applies to the crop list, not to the planting query,
        // so the two statements carry their own parameters.
        $plantingWhere  = ["p.status <> 'terminated'"];
        $plantingValues = [];

        if ($f['season_id'] !== null) {
            $plantingWhere[]         = 'p.season_id = :season_id';
            $plantingValues['season_id'] = $f['season_id'];
        }

        if ($f['district_id'] !== null) {
            $plantingWhere[]              = 'g.district_id = :district_id';
            $plantingValues['district_id'] = $f['district_id'];
        }

        $from = 'plantings p
                 INNER JOIN gardens g ON g.id = p.garden_id
                 INNER JOIN crops c ON c.id = p.crop_id';

        $plantings = $this->count($from, $plantingWhere, $plantingValues);
        $acreage   = $this->sum($from, 'p', 'acreage_planted', $plantingWhere, $plantingValues);

        return [
            'key'   => 'crops',
            'label' => 'Crops',
            'tiles' => [
                ['key' => 'crops_active', 'label' => 'Active crops', 'value' => $activeCrops, 'tone' => 'green'],
                ['key' => 'crops_plantings', 'label' => 'Plantings', 'value' => $plantings, 'tone' => 'blue'],
                ['key' => 'crops_acreage', 'label' => 'Acreage planted', 'value' => round($acreage, 2), 'tone' => 'soil',
                 'meta' => 'across the selected plantings', 'format' => 'number'],
                ['key' => 'crops_average', 'label' => 'Average planting', 'value' => $plantings > 0 ? round($acreage / $plantings, 2) : 0.0, 'tone' => 'soil',
                 'meta' => 'acres per planting', 'format' => 'number'],
            ],
        ];
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array{key:string,label:string,tiles:array<int,array<string,mixed>>}
     */
    private function activitySection(array $f): array
    {
        $from   = 'crop_activities a INNER JOIN farmers f ON f.id = a.farmer_id';
        $where  = ["a.status <> 'cancelled'"];
        $values = [];

        if ($f['season_id'] !== null) {
            $where[]              = 'a.season_id = :season_id';
            $values['season_id'] = $f['season_id'];
        }

        if ($f['district_id'] !== null) {
            $where[]              = 'f.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        $completed = $this->count($from, [...$where, "a.status = 'completed'"], $values);
        $pending   = $this->count($from, [...$where, "a.status = 'pending'"], $values);
        $due       = $this->count($from, [...$where, "a.status = 'due'"], $values);

        // The stored 'overdue' flag is only rewritten by the nightly cron, so a
        // task whose due date has passed since the last run is counted here as
        // well. Otherwise the number a manager sees between runs is stale.
        $overdue = $this->count(
            $from,
            [...$where, "(a.status = 'overdue' OR (a.status IN ('pending', 'due') AND a.due_date < CURDATE()))"],
            $values
        );

        return [
            'key'   => 'activities',
            'label' => 'Activities',
            'tiles' => [
                ['key' => 'activities_completed', 'label' => 'Completed', 'value' => $completed, 'tone' => 'green'],
                ['key' => 'activities_pending', 'label' => 'Pending', 'value' => $pending, 'tone' => 'blue'],
                ['key' => 'activities_due', 'label' => 'Due', 'value' => $due, 'tone' => 'amber'],
                ['key' => 'activities_overdue', 'label' => 'Overdue', 'value' => $overdue, 'tone' => 'red',
                 'meta' => 'past its due date, whether or not the cron has run'],
            ],
        ];
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array{key:string,label:string,tiles:array<int,array<string,mixed>>}
     */
    private function problemSection(array $f): array
    {
        $from   = 'problem_reports pr INNER JOIN farmers f ON f.id = pr.farmer_id';
        $where  = [];
        $values = [];

        if ($f['season_id'] !== null) {
            $where[]              = 'pr.season_id = :season_id';
            $values['season_id'] = $f['season_id'];
        }

        if ($f['district_id'] !== null) {
            $where[]              = 'pr.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        $open     = $this->count($from, [...$where, "pr.status NOT IN ('resolved', 'closed')"], $values);
        $critical = $this->count($from, [...$where, "pr.status NOT IN ('resolved', 'closed')", "pr.severity = 'critical'"], $values);
        $resolved = $this->count($from, [...$where, "pr.status IN ('resolved', 'closed')"], $values);

        $diseaseWhere = [
            ...$where,
            "pr.status NOT IN ('resolved', 'closed')",
            "pt.category = 'disease'",
        ];

        $disease = $this->count(
            $from . ' INNER JOIN problem_types pt ON pt.id = pr.problem_type_id',
            $diseaseWhere,
            $values
        );

        return [
            'key'   => 'problems',
            'label' => 'Problems',
            'tiles' => [
                ['key' => 'problems_open', 'label' => 'Open problems', 'value' => $open, 'tone' => 'amber'],
                ['key' => 'problems_disease', 'label' => 'Disease alerts', 'value' => $disease, 'tone' => 'red',
                 'meta' => 'open reports classified as disease'],
                ['key' => 'problems_critical', 'label' => 'Critical problems', 'value' => $critical, 'tone' => 'red'],
                ['key' => 'problems_resolved', 'label' => 'Resolved problems', 'value' => $resolved, 'tone' => 'green'],
            ],
        ];
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array{key:string,label:string,tiles:array<int,array<string,mixed>>}
     */
    private function productionSection(array $f): array
    {
        $forecastWhere  = ["s.status = 'open'"];
        $harvestWhere   = ["s.status = 'open'"];
        $values         = [];

        if ($f['season_id'] !== null) {
            $forecastWhere[]    = 'hf.season_id = :season_id';
            $harvestWhere[]     = 'hr.season_id = :season_id';
            $values['season_id'] = $f['season_id'];
        }

        if ($f['district_id'] !== null) {
            $forecastWhere[]              = 'g.district_id = :district_id';
            $harvestWhere[]               = 'g.district_id = :district_id';
            $values['district_id']        = $f['district_id'];
        }

        $forecastFrom = 'harvest_forecasts hf
                         INNER JOIN seasons s ON s.id = hf.season_id
                         INNER JOIN gardens g ON g.id = hf.garden_id';
        $harvestFrom  = 'harvest_records hr
                         INNER JOIN seasons s ON s.id = hr.season_id
                         INNER JOIN gardens g ON g.id = hr.garden_id';

        $expected = $this->sum($forecastFrom, 'hf', 'expected_production', $forecastWhere, $values);
        $revised  = $this->sum($forecastFrom, 'hf', 'revised_production', $forecastWhere, $values);
        $actual   = $this->sum($harvestFrom, 'hr', 'quantity_harvested', [...$harvestWhere, "hr.status <> 'cancelled'"], $values);
        $variance = $this->sum($forecastFrom, 'hf', 'variance', $forecastWhere, $values);

        $basis = $expected > 0.0 ? $revised > 0.0 ? $revised : $expected : $expected;

        return [
            'key'   => 'production',
            'label' => 'Production',
            'tiles' => [
                ['key' => 'production_expected', 'label' => 'Expected production', 'value' => round($expected, 2), 'tone' => 'blue',
                 'meta' => 'forecast at the start of the season', 'format' => 'number'],
                ['key' => 'production_revised', 'label' => 'Revised forecast', 'value' => round($revised, 2), 'tone' => 'soil',
                 'meta' => 'after in-field revisions', 'format' => 'number'],
                ['key' => 'production_actual', 'label' => 'Actual production', 'value' => round($actual, 2), 'tone' => 'green',
                 'meta' => 'recorded at harvest', 'format' => 'number'],
                ['key' => 'production_variance', 'label' => 'Variance', 'value' => round($variance, 2), 'tone' => 'amber',
                 'meta' => $basis > 0.0 ? percent($variance, $basis) . '% of forecast' : 'no forecast to compare with', 'format' => 'number'],
            ],
        ];
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array{key:string,label:string,tiles:array<int,array<string,mixed>>}
     */
    private function marketSection(array $f): array
    {
        $cropWhere  = [];
        $cropValues = [];

        if ($f['crop_id'] !== null) {
            $cropWhere[]           = 'c.id = :crop_id';
            $cropValues['crop_id'] = $f['crop_id'];
        }

        $demand = $this->sum(
            'market_requirements mr INNER JOIN crops c ON c.id = mr.crop_id',
            'mr',
            'quantity_required',
            [...$cropWhere, "mr.status IN ('open', 'partially_filled')"],
            $cropValues
        );

        $available = $this->sum(
            'harvest_batches hb INNER JOIN crops c ON c.id = hb.crop_id',
            'hb',
            'total_quantity',
            [...$cropWhere, "hb.status IN ('open', 'aggregated')"],
            $cropValues
        );

        $committed = $this->sum(
            'sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN crops c ON c.id = si.crop_id',
            'si',
            'quantity',
            [...$cropWhere, "s.status <> 'cancelled'"],
            $cropValues
        );

        $sold = $this->sum(
            'sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             INNER JOIN crops c ON c.id = si.crop_id',
            'si',
            'quantity',
            [...$cropWhere, "s.status = 'fulfilled'"],
            $cropValues
        );

        return [
            'key'   => 'markets',
            'label' => 'Markets',
            'tiles' => [
                ['key' => 'markets_demand', 'label' => 'Market demand', 'value' => round($demand, 2), 'tone' => 'blue',
                 'meta' => 'open and partly filled requirements', 'format' => 'number'],
                ['key' => 'markets_available', 'label' => 'Available production', 'value' => round($available, 2), 'tone' => 'green',
                 'meta' => 'batches open or aggregated', 'format' => 'number'],
                ['key' => 'markets_committed', 'label' => 'Committed production', 'value' => round($committed, 2), 'tone' => 'soil',
                 'meta' => 'allocated against a live sale', 'format' => 'number'],
                ['key' => 'markets_sold', 'label' => 'Sold production', 'value' => round($sold, 2), 'tone' => 'green',
                 'meta' => 'fulfilled sales', 'format' => 'number'],
            ],
        ];
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array{key:string,label:string,tiles:array<int,array<string,mixed>>}
     */
    private function officerSection(array $f): array
    {
        $officerWhere = ['o.deleted_at IS NULL', "o.status = 'active'"];
        $officerValues = [];

        if ($f['district_id'] !== null) {
            $officerWhere[]              = 'o.district_id = :district_id';
            $officerValues['district_id'] = $f['district_id'];
        }

        $officers = $this->count('field_officers o', $officerWhere, $officerValues);

        $farmerWhere  = ['f.deleted_at IS NULL', 'f.officer_id IS NOT NULL'];
        $farmerValues = [];

        if ($f['district_id'] !== null) {
            $farmerWhere[]              = 'f.district_id = :district_id';
            $farmerValues['district_id'] = $f['district_id'];
        }

        $assigned = $this->count('farmers f', $farmerWhere, $farmerValues);

        $visitWhere  = ["v.status = 'completed'"];
        $visitValues = [];

        if ($f['district_id'] !== null) {
            $visitWhere[]              = 'f.district_id = :district_id';
            $visitValues['district_id'] = $f['district_id'];
        }

        $visits = $this->count(
            'field_visits v INNER JOIN farmers f ON f.id = v.farmer_id',
            $visitWhere,
            $visitValues
        );

        $inspectionWhere  = [];
        $inspectionValues = [];

        if ($f['season_id'] !== null) {
            $inspectionWhere[]              = 'i.season_id = :season_id';
            $inspectionValues['season_id'] = $f['season_id'];
        }

        if ($f['district_id'] !== null) {
            $inspectionWhere[]              = 'g.district_id = :district_id';
            $inspectionValues['district_id'] = $f['district_id'];
        }

        $inspections = $this->count(
            'inspections i INNER JOIN gardens g ON g.id = i.garden_id',
            $inspectionWhere,
            $inspectionValues
        );

        return [
            'key'   => 'officers',
            'label' => 'Field officers',
            'tiles' => [
                ['key' => 'officers_active', 'label' => 'Active officers', 'value' => $officers, 'tone' => 'green'],
                ['key' => 'officers_assigned', 'label' => 'Farmers assigned', 'value' => $assigned, 'tone' => 'blue'],
                ['key' => 'officers_visits', 'label' => 'Visits completed', 'value' => $visits, 'tone' => 'soil'],
                ['key' => 'officers_inspections', 'label' => 'Inspections completed', 'value' => $inspections, 'tone' => 'amber'],
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Chart series                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array<int,array<string,mixed>>
     */
    private function registrationTrend(array $f): array
    {
        $where  = ['f.deleted_at IS NULL', 'f.registration_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)'];
        $values = [];

        if ($f['district_id'] !== null) {
            $where[]              = 'f.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        return $this->db->select(
            "SELECT DATE_FORMAT(f.registration_date, '%b %Y') AS period, COUNT(*) AS total
             FROM farmers f
             WHERE " . implode(' AND ', $where) . '
             GROUP BY period
             ORDER BY MIN(f.registration_date) ASC',
            $values
        );
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array<int,array<string,mixed>>
     */
    private function cropMix(array $f): array
    {
        $where  = ["p.status <> 'terminated'"];
        $values = [];

        if ($f['season_id'] !== null) {
            $where[]              = 'p.season_id = :season_id';
            $values['season_id'] = $f['season_id'];
        }

        if ($f['district_id'] !== null) {
            $where[]              = 'g.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        return $this->db->select(
            'SELECT c.name, COUNT(p.id) AS plantings, COALESCE(SUM(p.acreage_planted), 0) AS acreage
             FROM plantings p
             INNER JOIN gardens g ON g.id = p.garden_id
             INNER JOIN crops c ON c.id = p.crop_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY c.id, c.name
             ORDER BY acreage DESC
             LIMIT 8',
            $values
        );
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array<int,array<string,mixed>>
     */
    private function activityMix(array $f): array
    {
        $where  = ["a.status <> 'cancelled'", 'a.due_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)'];
        $values = [];

        if ($f['season_id'] !== null) {
            $where[]              = 'a.season_id = :season_id';
            $values['season_id'] = $f['season_id'];
        }

        if ($f['district_id'] !== null) {
            $where[]              = 'f.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        return $this->db->select(
            "SELECT DATE_FORMAT(a.due_date, '%b %Y') AS period, a.status, COUNT(*) AS total
             FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             WHERE " . implode(' AND ', $where) . '
             GROUP BY period, a.status
             ORDER BY MIN(a.due_date) ASC',
            $values
        );
    }

    /**
     * @param array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $f
     * @return array<int,array<string,mixed>>
     */
    private function districtProduction(array $f): array
    {
        $where  = ["hr.status <> 'cancelled'"];
        $values = [];

        if ($f['season_id'] !== null) {
            $where[]              = 'hr.season_id = :season_id';
            $values['season_id'] = $f['season_id'];
        }

        if ($f['district_id'] !== null) {
            $where[]              = 'g.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        return $this->db->select(
            'SELECT d.name AS district, COALESCE(SUM(hr.quantity_harvested), 0) AS quantity
             FROM harvest_records hr
             INNER JOIN gardens g ON g.id = hr.garden_id
             INNER JOIN districts d ON d.id = g.district_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY d.id, d.name
             ORDER BY quantity DESC
             LIMIT 10',
            $values
        );
    }

    /* ------------------------------------------------------------------ */
    /* Query helpers                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int,string> $where
     * @param array<string,mixed> $values
     */
    private function count(string $from, array $where, array $values): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ' . $from . ' WHERE ' . ($where === [] ? '1 = 1' : implode(' AND ', $where)),
            $values
        );
    }

    /**
     * The column is validated as a bare identifier and the alias is concatenated
     * separately, so neither reaches SQL unverified.
     *
     * @param array<int,string> $where
     * @param array<string,mixed> $values
     */
    private function sum(string $from, string $alias, string $column, array $where, array $values): float
    {
        $qualified = $alias . '.' . Database::safeIdentifier($column);

        return (float) $this->db->scalar(
            'SELECT COALESCE(SUM(' . $qualified . '), 0) FROM ' . $from
            . ' WHERE ' . ($where === [] ? '1 = 1' : implode(' AND ', $where)),
            $values
        );
    }

/**
     * @return array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>}
     */
    private function filters(): array
    {
        $districtId = $this->request->int('district_id') ?: null;
        $seasonId   = $this->request->int('season_id') ?: null;
        $cropId     = $this->request->int('crop_id') ?: null;

        return [
            'district_id' => $districtId,
            'season_id'   => $seasonId,
            'crop_id'     => $cropId,
            'values'      => ['district_id' => $districtId, 'season_id' => $seasonId, 'crop_id' => $cropId],
        ];
    }
}