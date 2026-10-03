<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Controller;
use Core\Database;
use Core\Response;
use Core\Setting;
use Services\Lookups;

/**
 * Map / GIS: the command centre's spatial view of the portfolio.
 *
 * The screen is strictly read-only. Everything it draws is a bounded slice of
 * an existing table: no geometry is ever written back and no layer is allowed
 * to grow without a ceiling, because a single request must not pull tens of
 * thousands of coordinates into PHP. Each layer therefore reports the row count
 * it would have returned alongside the rows it actually returned, so the UI can
 * say "showing 2000 of 8140" instead of quietly implying completeness.
 */
final class MapController extends Controller
{
    /**
     * Layer keys, in the order they are drawn and listed in the sidebar. The
     * public constant is the whitelist the request filter is checked against.
     */
    public const LAYERS = ['gardens', 'harvest-ready', 'farmers', 'officers', 'problems', 'inspections'];

    private const LAYER_LIMIT = 2000;

    private const PROBLEM_STATUSES = ['open', 'reported', 'assigned', 'investigating', 'action_taken', 'resolved', 'closed'];

    private const HARVEST_STATUSES = ['growing', 'harvest_ready', 'harvested'];

    private Lookups $lookups;

    public function __construct(Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    public function index(): Response
    {
        $filters = $this->filters();

        return $this->render('map/index', [
            'pageTitle'      => 'Farm map',
            'layers'         => self::LAYERS,
            'activeLayers'   => $filters['layers'],
            'filters'        => $filters,
            'harvestWindow'  => $this->harvestWindow(),
            'mapConfig'      => $this->mapConfig(),
            'layerLimit'     => self::LAYER_LIMIT,
            'districts'      => $this->lookups->districtOptions('All districts'),
            'subCounties'    => $this->lookups->subCountyOptions('All sub-counties'),
            'crops'          => $this->lookups->cropOptions('All crops'),
            'seasons'        => $this->lookups->seasonOptions('All seasons'),
            'officers'       => $this->lookups->officerOptions('All officers'),
            'groups'         => $this->groupOptions(),
        ]);
    }

    /**
     * The GeoJSON consumed by the Leaflet shell. Filters are read from the
     * query string, so the same endpoint serves the first page load and every
     * subsequent filter change without a reload.
     */
    public function data(): Response
    {
        $filters  = $this->filters();
        $features = [];
        $layers   = [];

        foreach ($filters['layers'] as $layer) {
            $built = $this->layer($layer, $filters);

            foreach ($built['features'] as $feature) {
                $features[] = $feature;
            }

            $layers[$layer] = [
                'shown'     => count($built['features']),
                'total'     => $built['total'],
                'truncated' => $built['total'] > count($built['features']),
            ];
        }

        return $this->json([
            'type'     => 'FeatureCollection',
            'features' => $features,
            'meta'     => array_merge($this->mapConfig(), [
                'layers'           => $layers,
                'limit'            => self::LAYER_LIMIT,
                'harvestWindow'    => $this->harvestWindow(),
                'filters'          => $filters['values'],
            ]),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Layers                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed> $filters
     * @return array{features:array<int,array<string,mixed>>,total:int}
     */
    private function layer(string $layer, array $filters): array
    {
        return match ($layer) {
            'gardens'       => $this->gardensLayer($filters),
            'farmers'       => $this->farmersLayer($filters),
            'harvest-ready' => $this->harvestReadyLayer($filters),
            'officers'      => $this->officersLayer($filters),
            'problems'      => $this->problemsLayer($filters),
            'inspections'   => $this->inspectionsLayer($filters),
            default         => ['features' => [], 'total' => 0],
        };
    }

    /**
     * @param array<string,mixed> $f
     * @return array{features:array<int,array<string,mixed>>,total:int}
     */
    private function gardensLayer(array $f): array
    {
        $from = 'FROM gardens g
                 INNER JOIN farmers f ON f.id = g.farmer_id
                 LEFT JOIN districts d ON d.id = g.district_id
                 WHERE ' . $this->gardenWhere($f, 'g', 'f');

        $values = $this->gardenValues($f);

        $total = (int) $this->db->scalar('SELECT COUNT(*) ' . $from, $values);

        $rows = $this->db->select(
            "SELECT g.garden_code, g.name, g.acreage, g.status, g.mapped, g.latitude, g.longitude,
                    f.farmer_code, f.full_name AS farmer_name, d.name AS district,
                    (SELECT c.name FROM plantings p
                       INNER JOIN crops c ON c.id = p.crop_id
                      WHERE p.garden_id = g.id AND p.status <> 'terminated'
                      ORDER BY p.planting_date DESC LIMIT 1) AS crop_name
             " . $from . '
             ORDER BY g.id ASC LIMIT ' . self::LAYER_LIMIT,
            $values
        );

        $features = [];

        foreach ($rows as $row) {
            $geometry = $this->point($row['latitude'], $row['longitude']);

            if ($geometry === null) {
                continue;
            }

            $features[] = [
                'type'       => 'Feature',
                'geometry'   => $geometry,
                'properties' => [
                    'layer'       => 'gardens',
                    'title'       => $row['name'],
                    'code'        => $row['garden_code'],
                    'acreage'     => (float) $row['acreage'],
                    'status'      => $row['status'],
                    'mapped'      => (int) $row['mapped'] === 1,
                    'farmer_code' => $row['farmer_code'],
                    'farmer_name' => $row['farmer_name'],
                    'district'    => $row['district'],
                    'crop'        => $row['crop_name'],
                    'url'         => url('gardens/' . $row['garden_code']),
                    'popup'       => $this->gardenPopup($row),
                ],
            ];
        }

        return ['features' => $features, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $f
     * @return array{features:array<int,array<string,mixed>>,total:int}
     */
    private function farmersLayer(array $f): array
    {
        $from = 'FROM farmers f
                 LEFT JOIN districts d ON d.id = f.district_id
                 LEFT JOIN farmer_groups g ON g.id = f.group_id
                 WHERE f.deleted_at IS NULL
                   AND f.latitude IS NOT NULL AND f.longitude IS NOT NULL
                   AND ' . $this->farmerWhere($f, 'f');

        $values = $this->farmerValues($f);

        $total = (int) $this->db->scalar('SELECT COUNT(*) ' . $from, $values);

        $rows = $this->db->select(
            "SELECT f.farmer_code, f.full_name, f.phone, f.status, f.latitude, f.longitude,
                    d.name AS district, g.name AS farmer_group,
                    (SELECT COUNT(*) FROM gardens gd
                      WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS garden_count,
                    (SELECT COALESCE(SUM(gd.acreage), 0) FROM gardens gd
                      WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS acreage
             " . $from . '
             ORDER BY f.id ASC LIMIT ' . self::LAYER_LIMIT,
            $values
        );

        $features = [];

        foreach ($rows as $row) {
            $geometry = $this->point($row['latitude'], $row['longitude']);

            if ($geometry === null) {
                continue;
            }

            $features[] = [
                'type'       => 'Feature',
                'geometry'   => $geometry,
                'properties' => [
                    'layer'   => 'farmers',
                    'title'   => $row['full_name'],
                    'code'    => $row['farmer_code'],
                    'status'  => $row['status'],
                    'district' => $row['district'],
                    'group'   => $row['farmer_group'],
                    'gardens' => (int) $row['garden_count'],
                    'acreage' => (float) $row['acreage'],
                    'url'     => url('farmers/' . $row['farmer_code']),
                    'popup'   => $this->farmerPopup($row),
                ],
            ];
        }

        return ['features' => $features, 'total' => $total];
    }

    /**
     * Gardens whose planting is expected to come in inside the configurable
     * window. Only plots that actually carry coordinates can be shown.
     *
     * @param array<string,mixed> $f
     * @return array{features:array<int,array<string,mixed>>,total:int}
     */
    private function harvestReadyLayer(array $f): array
    {
        $window = $this->harvestWindow();

        $where  = $this->gardenWhere($f, 'g', 'f');
        $values = $this->gardenValues($f);

        $values['window'] = $window;

        // The window is bound once per statement: a named placeholder may not be
        // repeated in a natively prepared statement, so the crop name is folded
        // in with an aggregate instead of a second copy of the same condition.
        $from = 'FROM gardens g
                 INNER JOIN farmers f ON f.id = g.farmer_id
                 LEFT JOIN districts d ON d.id = g.district_id
                 INNER JOIN plantings p
                         ON p.garden_id = g.id
                        AND p.expected_harvest_date IS NOT NULL
                        AND p.status NOT IN (\'harvested\', \'terminated\')
                        AND p.expected_harvest_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :window DAY)
                 LEFT JOIN crops c ON c.id = p.crop_id
                 WHERE ' . $where;

        $group = ' GROUP BY g.id, g.garden_code, g.name, g.acreage, g.status, g.latitude, g.longitude,
                            f.farmer_code, f.full_name, d.name';

        $total = (int) $this->db->scalar('SELECT COUNT(DISTINCT g.id) ' . $from, $values);

        $rows = $this->db->select(
            "SELECT g.garden_code, g.name, g.acreage, g.status, g.latitude, g.longitude,
                    f.farmer_code, f.full_name AS farmer_name, d.name AS district,
                    MIN(p.expected_harvest_date) AS expected_harvest_date,
                    GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS crop_name"
            . $from . $group . '
             ORDER BY expected_harvest_date ASC LIMIT ' . self::LAYER_LIMIT,
            $values
        );

        $features = [];

        foreach ($rows as $row) {
            $geometry = $this->point($row['latitude'], $row['longitude']);

            if ($geometry === null) {
                continue;
            }

            $features[] = [
                'type'       => 'Feature',
                'geometry'   => $geometry,
                'properties' => [
                    'layer'       => 'harvest-ready',
                    'title'       => $row['name'],
                    'code'        => $row['garden_code'],
                    'acreage'     => (float) $row['acreage'],
                    'crop'        => $row['crop_name'],
                    'expected'    => $row['expected_harvest_date'],
                    'farmer_code' => $row['farmer_code'],
                    'farmer_name' => $row['farmer_name'],
                    'district'    => $row['district'],
                    'url'         => url('gardens/' . $row['garden_code']),
                    'popup'       => $this->harvestReadyPopup($row, $window),
                ],
            ];
        }

        return ['features' => $features, 'total' => $total];
    }

    /**
     * Field officers.
     *
     * Neither field_officers nor users carries a latitude, so an officer's
     * position is the centroid of the plots of the farmers assigned to them:
     * a real location taken from the register rather than an invented column.
     * An officer with no geocoded portfolio has nowhere to be drawn and is left
     * off the map entirely.
     *
     * @param array<string,mixed> $f
     * @return array{features:array<int,array<string,mixed>>,total:int}
     */
    private function officersLayer(array $f): array
    {
        $where  = ["o.deleted_at IS NULL", "f.latitude IS NOT NULL", "f.longitude IS NOT NULL"];
        $values = [];

        if ($f['district_id'] !== null) {
            $where[]            = 'o.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        if ($f['officer_id'] !== null) {
            $where[]              = 'o.id = :officer_id';
            $values['officer_id'] = $f['officer_id'];
        }

        if ($f['group_id'] !== null) {
            $where[]           = 'f.group_id = :group_id';
            $values['group_id'] = $f['group_id'];
        }

        if ($f['crop_id'] !== null) {
            $where[]          = 'EXISTS (SELECT 1 FROM plantings p WHERE p.farmer_id = f.id AND p.crop_id = :crop_id)';
            $values['crop_id'] = $f['crop_id'];
        }

        if ($f['season_id'] !== null) {
            $where[]            = 'EXISTS (SELECT 1 FROM plantings p WHERE p.farmer_id = f.id AND p.season_id = :season_id)';
            $values['season_id'] = $f['season_id'];
        }

        if ($f['sub_county_id'] !== null) {
            $where[]              = 'f.sub_county_id = :sub_county_id';
            $values['sub_county_id'] = $f['sub_county_id'];
        }

        $from = 'FROM field_officers o
                 INNER JOIN farmers f ON f.officer_id = o.id
                 LEFT JOIN districts d ON d.id = o.district_id
                 WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->scalar('SELECT COUNT(DISTINCT o.id) ' . $from, $values);

        $rows = $this->db->select(
            'SELECT o.id AS officer_id, o.officer_code, o.full_name, o.phone, o.status, d.name AS district,
                    AVG(f.latitude) AS latitude, AVG(f.longitude) AS longitude,
                    COUNT(DISTINCT f.id) AS farmer_count
             ' . $from . '
             GROUP BY o.id, o.officer_code, o.full_name, o.phone, o.status, d.name
             ORDER BY o.officer_code ASC LIMIT ' . self::LAYER_LIMIT,
            $values
        );

        $features = [];

        foreach ($rows as $row) {
            $geometry = $this->point($row['latitude'], $row['longitude']);

            if ($geometry === null) {
                continue;
            }

            $features[] = [
                'type'       => 'Feature',
                'geometry'   => $geometry,
                'properties' => [
                    'layer'    => 'officers',
                    'title'    => $row['full_name'],
                    'code'     => $row['officer_code'],
                    'status'   => $row['status'],
                    'district' => $row['district'],
                    'farmers'  => (int) $row['farmer_count'],
                    'url'      => url('officers/' . $row['officer_id']),
                    'popup'    => $this->officerPopup($row),
                ],
            ];
        }

        return ['features' => $features, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $f
     * @return array{features:array<int,array<string,mixed>>,total:int}
     */
    private function problemsLayer(array $f): array
    {
        $where  = ['pr.latitude IS NOT NULL', 'pr.longitude IS NOT NULL'];
        $values = [];

        if ($f['problem_status'] !== null) {
            $where[] = $f['problem_status'] === 'open'
                ? "pr.status NOT IN ('resolved', 'closed')"
                : 'pr.status = :problem_status';

            if ($f['problem_status'] !== 'open') {
                $values['problem_status'] = $f['problem_status'];
            }
        }

        if ($f['district_id'] !== null) {
            $where[]              = 'pr.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        if ($f['crop_id'] !== null) {
            $where[]          = 'pr.crop_id = :crop_id';
            $values['crop_id'] = $f['crop_id'];
        }

        if ($f['season_id'] !== null) {
            $where[]            = 'pr.season_id = :season_id';
            $values['season_id'] = $f['season_id'];
        }

        if ($f['officer_id'] !== null) {
            $where[]              = 'pr.assigned_officer_id = :officer_id';
            $values['officer_id'] = $f['officer_id'];
        }

        if ($f['group_id'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM farmers gf WHERE gf.id = pr.farmer_id AND gf.group_id = :group_id)';
            $values['group_id'] = $f['group_id'];
        }

        if ($f['sub_county_id'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM farmers gf WHERE gf.id = pr.farmer_id AND gf.sub_county_id = :sub_county_id)';
            $values['sub_county_id'] = $f['sub_county_id'];
        }

        $from = 'FROM problem_reports pr
                 INNER JOIN farmers f ON f.id = pr.farmer_id
                 INNER JOIN problem_types pt ON pt.id = pr.problem_type_id
                 LEFT JOIN districts d ON d.id = pr.district_id
                 WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->scalar('SELECT COUNT(*) ' . $from, $values);

        $rows = $this->db->select(
            'SELECT pr.problem_code, pr.severity, pr.status, pr.report_date, pr.description,
                    pr.latitude, pr.longitude, f.farmer_code, d.name AS district,
                    pt.name AS problem_type, pt.category
             ' . $from . '
             ORDER BY FIELD(pr.severity, \'critical\', \'high\', \'medium\', \'low\'), pr.report_date DESC
             LIMIT ' . self::LAYER_LIMIT,
            $values
        );

        $features = [];

        foreach ($rows as $row) {
            $geometry = $this->point($row['latitude'], $row['longitude']);

            if ($geometry === null) {
                continue;
            }

            $features[] = [
                'type'       => 'Feature',
                'geometry'   => $geometry,
                'properties' => [
                    'layer'       => 'problems',
                    'title'       => $row['problem_type'],
                    'code'        => $row['problem_code'],
                    'severity'    => $row['severity'],
                    'status'      => $row['status'],
                    'category'    => $row['category'],
                    'date'        => $row['report_date'],
                    'farmer_code' => $row['farmer_code'],
                    'district'    => $row['district'],
                    'url'         => url('problems/' . $row['problem_code']),
                    'popup'       => $this->problemPopup($row),
                ],
            ];
        }

        return ['features' => $features, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $f
     * @return array{features:array<int,array<string,mixed>>,total:int}
     */
    private function inspectionsLayer(array $f): array
    {
        $where  = ['i.latitude IS NOT NULL', 'i.longitude IS NOT NULL'];
        $values = [];

        if ($f['district_id'] !== null) {
            $where[]              = 'g.district_id = :district_id';
            $values['district_id'] = $f['district_id'];
        }

        if ($f['sub_county_id'] !== null) {
            $where[]              = 'g.sub_county_id = :sub_county_id';
            $values['sub_county_id'] = $f['sub_county_id'];
        }

        if ($f['officer_id'] !== null) {
            $where[]              = 'i.officer_id = :officer_id';
            $values['officer_id'] = $f['officer_id'];
        }

        if ($f['crop_id'] !== null) {
            $where[]          = 'i.crop_id = :crop_id';
            $values['crop_id'] = $f['crop_id'];
        }

        if ($f['season_id'] !== null) {
            $where[]            = 'i.season_id = :season_id';
            $values['season_id'] = $f['season_id'];
        }

        if ($f['group_id'] !== null) {
            $where[]           = 'f.group_id = :group_id';
            $values['group_id'] = $f['group_id'];
        }

        $from = 'FROM inspections i
                 INNER JOIN gardens g ON g.id = i.garden_id
                 INNER JOIN farmers f ON f.id = i.farmer_id
                 LEFT JOIN crops c ON c.id = i.crop_id
                 LEFT JOIN field_officers o ON o.id = i.officer_id
                 WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->scalar('SELECT COUNT(*) ' . $from, $values);

        $rows = $this->db->select(
            'SELECT i.inspection_code, i.inspection_date, i.crop_condition, i.pest_status,
                    i.disease_status, i.status, c.name AS crop_name, o.full_name AS officer_name,
                    g.garden_code, f.farmer_code, i.latitude, i.longitude
             ' . $from . '
             ORDER BY i.inspection_date DESC, i.id DESC LIMIT ' . self::LAYER_LIMIT,
            $values
        );

        $features = [];

        foreach ($rows as $row) {
            $geometry = $this->point($row['latitude'], $row['longitude']);

            if ($geometry === null) {
                continue;
            }

            $features[] = [
                'type'       => 'Feature',
                'geometry'   => $geometry,
                'properties' => [
                    'layer'       => 'inspections',
                    'title'       => $row['crop_name'] ?: 'Inspection',
                    'code'        => $row['inspection_code'],
                    'date'        => $row['inspection_date'],
                    'condition'   => $row['crop_condition'],
                    'officer'     => $row['officer_name'],
                    'farmer_code' => $row['farmer_code'],
                    'url'         => url('inspections/' . $row['inspection_code']),
                    'popup'       => $this->inspectionPopup($row),
                ],
            ];
        }

        return ['features' => $features, 'total' => $total];
    }

    /* ------------------------------------------------------------------ */
    /* Filter plumbing                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Every filter, normalised once. Identifiers are cast to integers and the
     * two enum filters are checked against their whitelists, so nothing that
     * reaches SQL was ever free text.
     *
     * @return array{
     *     district_id:?int,sub_county_id:?int,crop_id:?int,season_id:?int,group_id:?int,
     *     officer_id:?int,problem_status:?string,harvest_status:?string,
     *     layers:array<int,string>,values:array<string,mixed>
     * }
     */
    private function filters(): array
    {
        $problemStatus = $this->request->string('problem_status');
        $harvestStatus = $this->request->string('harvest_status');

        $requested = $this->request->input('layer');
        $requested = is_array($requested)
            ? array_map(static fn ($value): string => strtolower(trim((string) $value)), $requested)
            : array_map(
                static fn (string $value): string => strtolower(trim($value)),
                explode(',', (string) $requested)
            );

        $layers = array_values(array_filter($requested, static fn (string $layer): bool => in_array($layer, self::LAYERS, true)));

        $filters = [
            'district_id'    => $this->request->int('district_id') ?: null,
            'sub_county_id'  => $this->request->int('sub_county_id') ?: null,
            'crop_id'        => $this->request->int('crop_id') ?: null,
            'season_id'      => $this->request->int('season_id') ?: null,
            'group_id'       => $this->request->int('group_id') ?: null,
            'officer_id'     => $this->request->int('officer_id') ?: null,
            'problem_status' => in_array($problemStatus, self::PROBLEM_STATUSES, true) ? $problemStatus : null,
            'harvest_status' => in_array($harvestStatus, self::HARVEST_STATUSES, true) ? $harvestStatus : null,
            'layers'         => $layers === [] ? self::LAYERS : $layers,
        ];

        $filters['values'] = [
            'district_id'    => $filters['district_id'],
            'sub_county_id'  => $filters['sub_county_id'],
            'crop_id'        => $filters['crop_id'],
            'season_id'      => $filters['season_id'],
            'group_id'       => $filters['group_id'],
            'officer_id'     => $filters['officer_id'],
            'problem_status' => $filters['problem_status'],
            'harvest_status' => $filters['harvest_status'],
            'layer'          => implode(',', $filters['layers']),
        ];

        return $filters;
    }

    /**
     * @param array<string,mixed> $f
     */
    private function gardenWhere(array $f, string $gardenAlias, string $farmerAlias): string
    {
        $where = [
            $gardenAlias . '.deleted_at IS NULL',
            $gardenAlias . '.latitude IS NOT NULL',
            $gardenAlias . '.longitude IS NOT NULL',
        ];

        if ($f['district_id'] !== null) {
            $where[] = $gardenAlias . '.district_id = :district_id';
        }

        if ($f['sub_county_id'] !== null) {
            $where[] = $gardenAlias . '.sub_county_id = :sub_county_id';
        }

        if ($f['group_id'] !== null) {
            $where[] = $farmerAlias . '.group_id = :group_id';
        }

        if ($f['officer_id'] !== null) {
            $where[] = $farmerAlias . '.officer_id = :officer_id';
        }

        if ($f['crop_id'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM plantings p
                                WHERE p.garden_id = ' . $gardenAlias . '.id AND p.crop_id = :crop_id)';
        }

        if ($f['season_id'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM plantings p
                                WHERE p.garden_id = ' . $gardenAlias . '.id AND p.season_id = :season_id)';
        }

        if ($f['problem_status'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM problem_reports pr
                                WHERE pr.garden_id = ' . $gardenAlias . '.id AND ' . $this->problemStatusSql($f['problem_status'], 'pr.status') . ')';
        }

        if ($f['harvest_status'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM plantings p
                                WHERE p.garden_id = ' . $gardenAlias . '.id AND ' . $this->harvestStatusSql($f['harvest_status'], 'p') . ')';
        }

        return implode(' AND ', $where);
    }

    /**
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private function gardenValues(array $f): array
    {
        $values = [];

        foreach (['district_id', 'sub_county_id', 'crop_id', 'season_id', 'group_id', 'officer_id'] as $key) {
            if ($f[$key] !== null) {
                $values[$key] = $f[$key];
            }
        }

        if ($f['problem_status'] !== null && $f['problem_status'] !== 'open') {
            $values['problem_status'] = $f['problem_status'];
        }

        if ($f['harvest_status'] === 'harvest_ready') {
            $values['window_filter'] = $this->harvestWindow();
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $f
     */
    private function farmerWhere(array $f, string $alias): string
    {
        $where = [];

        if ($f['district_id'] !== null) {
            $where[] = $alias . '.district_id = :district_id';
        }

        if ($f['sub_county_id'] !== null) {
            $where[] = $alias . '.sub_county_id = :sub_county_id';
        }

        if ($f['group_id'] !== null) {
            $where[] = $alias . '.group_id = :group_id';
        }

        if ($f['officer_id'] !== null) {
            $where[] = $alias . '.officer_id = :officer_id';
        }

        if ($f['crop_id'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM plantings p WHERE p.farmer_id = ' . $alias . '.id AND p.crop_id = :crop_id)';
        }

        if ($f['season_id'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM plantings p WHERE p.farmer_id = ' . $alias . '.id AND p.season_id = :season_id)';
        }

        if ($f['problem_status'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM problem_reports pr
                                WHERE pr.farmer_id = ' . $alias . '.id AND ' . $this->problemStatusSql($f['problem_status'], 'pr.status') . ')';
        }

        return $where === [] ? '1 = 1' : implode(' AND ', $where);
    }

    /**
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    private function farmerValues(array $f): array
    {
        $values = [];

        foreach (['district_id', 'sub_county_id', 'crop_id', 'season_id', 'group_id', 'officer_id'] as $key) {
            if ($f[$key] !== null) {
                $values[$key] = $f[$key];
            }
        }

        if ($f['problem_status'] !== null && $f['problem_status'] !== 'open') {
            $values['problem_status'] = $f['problem_status'];
        }

        return $values;
    }

    /**
     * The status token has already been whitelisted, so this only turns it into
     * the SQL fragment it stands for.
     */
    private function problemStatusSql(string $status, string $column): string
    {
        return $status === 'open'
            ? $column . " NOT IN ('resolved', 'closed')"
            : $column . ' = :problem_status';
    }

    private function harvestStatusSql(string $status, string $plantingAlias): string
    {
        return match ($status) {
            'harvested'     => $plantingAlias . ".status = 'harvested'",
            // Bound as window_filter rather than window so this fragment can sit
            // in the same statement as the harvest-ready join without repeating
            // a named placeholder, which a native prepared statement rejects.
            'harvest_ready' => $plantingAlias . '.expected_harvest_date IS NOT NULL'
                . ' AND ' . $plantingAlias . ".status NOT IN ('harvested', 'terminated')"
                . ' AND ' . $plantingAlias . '.expected_harvest_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :window_filter DAY)',
            default         => $plantingAlias . ".status = 'planted'",
        };
    }

    /* ------------------------------------------------------------------ */
    /* Geometry and presentation                                           */
    /* ------------------------------------------------------------------ */

    /**
     * A GeoJSON Point, or null when the pair is not a place. A half-filled or
     * out-of-range coordinate is dropped rather than pinned at (0, 0), which
     * would drop a marker in the Gulf of Guinea.
     *
     * @return array<string,mixed>|null
     */
    private function point(mixed $latitude, mixed $longitude): ?array
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return ['type' => 'Point', 'coordinates' => [round($lng, 7), round($lat, 7)]];
    }

    /**
     * Popups are assembled here, escaped, so the browser never has to build
     * markup out of values that reached it through JSON.
     *
     * @param array<string,mixed> $row
     */
    private function gardenPopup(array $row): string
    {
        $lines = [];

        if (!empty($row['crop_name'])) {
            $lines[] = e($row['crop_name']);
        }

        $lines[] = e(format_number($row['acreage'])) . ' acres &middot; '
            . ((int) $row['mapped'] === 1 ? 'mapped' : 'not mapped');

        return '<h4>' . e($row['name']) . '</h4>'
            . '<div class="cahu-popup__code">' . e($row['garden_code']) . '</div>'
            . '<div>' . implode('<br>', $lines) . '</div>'
            . '<div class="mt-1">' . status_badge((string) $row['status']) . '</div>'
            . '<div class="mt-2"><a href="' . e(url('gardens/' . $row['garden_code'])) . '">Open garden record</a></div>'
            . '<div><a href="' . e(url('farmers/' . $row['farmer_code'])) . '">' . e($row['farmer_name']) . '</a></div>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function farmerPopup(array $row): string
    {
        return '<h4>' . e($row['full_name']) . '</h4>'
            . '<div class="cahu-popup__code">' . e($row['farmer_code']) . '</div>'
            . '<div>' . e((string) $row['phone']) . '</div>'
            . '<div>' . e((string) ($row['district'] ?? 'District not set')) . ' &middot; '
                . e((int) $row['garden_count']) . ' garden(s) &middot; '
                . e(format_number($row['acreage'])) . ' acres</div>'
            . '<div class="mt-1">' . status_badge((string) $row['status']) . '</div>'
            . '<div class="mt-2"><a href="' . e(url('farmers/' . $row['farmer_code'])) . '">Open farmer record</a></div>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function harvestReadyPopup(array $row, int $window): string
    {
        return '<h4>Ready to harvest</h4>'
            . '<div class="cahu-popup__code">' . e($row['garden_code']) . '</div>'
            . '<div>' . e((string) ($row['crop_name'] ?? 'Crop not recorded')) . '</div>'
            . '<div>Expected ' . e(format_date((string) $row['expected_harvest_date'])) . '</div>'
            . '<div class="text-muted">within ' . e($window) . ' day(s) &middot; '
                . e((string) $row['farmer_name']) . '</div>'
            . '<div class="mt-2"><a href="' . e(url('gardens/' . $row['garden_code'])) . '">Open garden record</a></div>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function officerPopup(array $row): string
    {
        return '<h4>' . e($row['full_name']) . '</h4>'
            . '<div class="cahu-popup__code">' . e($row['officer_code']) . '</div>'
            . '<div>' . e((string) ($row['phone'] ?? 'No phone on file')) . '</div>'
            . '<div>' . e((int) $row['farmer_count']) . ' geocoded farmer(s) assigned</div>'
            . '<div class="text-muted">Position is the centre of their mapped portfolio</div>'
            . '<div class="mt-1">' . status_badge((string) $row['status']) . '</div>'
            . '<div class="mt-2"><a href="' . e(url('officers/' . $row['officer_id'])) . '">Open officer record</a></div>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function problemPopup(array $row): string
    {
        return '<h4>' . e((string) $row['problem_type']) . '</h4>'
            . '<div class="cahu-popup__code">' . e($row['problem_code']) . '</div>'
            . '<div>' . e(mb_strimwidth((string) $row['description'], 0, 140, '...')) . '</div>'
            . '<div class="text-muted">Reported ' . e(format_date((string) $row['report_date'])) . ' &middot; '
                . e((string) ($row['farmer_code'] ?? '')) . '</div>'
            . '<div class="mt-1">' . status_badge((string) $row['severity']) . ' ' . status_badge((string) $row['status']) . '</div>'
            . '<div class="mt-2"><a href="' . e(url('problems/' . $row['problem_code'])) . '">Open problem record</a></div>';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function inspectionPopup(array $row): string
    {
        return '<h4>Inspection</h4>'
            . '<div class="cahu-popup__code">' . e($row['inspection_code']) . '</div>'
            . '<div>' . e(format_date((string) $row['inspection_date'])) . ' &middot; '
                . e((string) ($row['crop_name'] ?? 'Crop not recorded')) . '</div>'
            . '<div>Crop condition ' . status_badge((string) ($row['crop_condition'] ?? 'unknown')) . '</div>'
            . '<div class="text-muted">' . e((string) ($row['officer_name'] ?? 'Unassigned')) . ' &middot; '
                . e((string) $row['garden_code']) . '</div>'
            . '<div class="mt-2"><a href="' . e(url('inspections/' . $row['inspection_code'])) . '">Open inspection record</a></div>';
    }

    /* ------------------------------------------------------------------ */
    /* Configuration                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{lat:float,lng:float,zoom:int,tileUrl:string,attribution:string}
     */
    private function mapConfig(): array
    {
        return [
            'lat'         => (float) $this->config('map.default_lat', 1.3733),
            'lng'         => (float) $this->config('map.default_lng', 32.8883),
            'zoom'        => (int) $this->config('map.default_zoom', 7),
            'tileUrl'     => (string) $this->config('map.tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'),
            'attribution' => (string) $this->config('map.attribution', '&copy; OpenStreetMap contributors'),
        ];
    }

    /**
     * How far ahead of the expected date a planting counts as "ready".
     */
    private function harvestWindow(): int
    {
        $days = (int) Setting::get('map.harvest_ready_days', 21);

        return $days > 0 ? $days : 21;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function groupOptions(): array
    {
        return \Core\Form::options(
            $this->db->select("SELECT id, name FROM farmer_groups WHERE deleted_at IS NULL ORDER BY name"),
            'id',
            'name',
            'All groups'
        );
    }
}