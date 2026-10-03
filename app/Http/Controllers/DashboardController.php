<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Controller;
use Core\Database;
use Core\Flash;
use Core\Response;

/**
 * Role aware landing page.
 *
 * The same screen serves three audiences: programme managers see the whole
 * portfolio, field officers see only their assigned farmers, and a farmer
 * account sees just their own farm. Scoping happens in one place (scope()) so
 * no query below can accidentally leak another officer's portfolio.
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        $scope = $this->scope();

        $stats = [
            'farmers'      => $this->count('farmers f', $scope['farmerWhere'], $scope['farmerParams'], 'f.deleted_at IS NULL AND f.status = \'active\''),
            'gardens'      => $this->count('gardens g', $scope['gardenWhere'], $scope['gardenParams'], 'g.deleted_at IS NULL'),
            'acreage'      => $this->sum('gardens g', 'acreage', $scope['gardenWhere'], $scope['gardenParams'], 'g.deleted_at IS NULL'),
            // 'terminated' is the value a called-off planting carries; there is no
            // 'cancelled' status on this table.
            'plantings'    => $this->count('plantings p', $scope['plantingWhere'], $scope['plantingParams'], "p.status <> 'terminated'"),
            'activities'   => $this->count('crop_activities a', $scope['activityWhere'], $scope['activityParams'], "a.status <> 'cancelled'"),
            'openProblems' => $this->count('problem_reports pr', $scope['problemWhere'], $scope['problemParams'], "pr.status IN ('reported','assigned','investigating')"),
            'harvestTonnes' => $this->sum('harvest_records hr', 'quantity_harvested', $scope['harvestWhere'], $scope['harvestParams'], "hr.status <> 'cancelled'"),
        ];

        $attention = [
            'overdueActivities' => $this->count(
                'crop_activities a',
                $scope['activityWhere'],
                $scope['activityParams'],
                "a.status = 'overdue'"
            ),
            'dueThisWeek' => $this->count(
                'crop_activities a',
                $scope['activityWhere'],
                $scope['activityParams'],
                "a.status = 'due' AND a.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
            ),
            'criticalProblems' => $this->count(
                'problem_reports pr',
                $scope['problemWhere'],
                $scope['problemParams'],
                "pr.status IN ('reported','assigned','investigating') AND pr.severity = 'critical'"
            ),
            // inputs has no deleted_at - the row is retired by setting status to
            // inactive - and its stock column is current_stock, not
            // quantity_on_hand. reorder_level > 0 is what distinguishes a real
            // low-stock alert from an input nobody has set a threshold for.
            'lowStockInputs' => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM inputs
                 WHERE status = 'active' AND reorder_level > 0 AND current_stock <= reorder_level"
            ),
            'expiringDocuments' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM farmer_documents
                 WHERE expires_at IS NOT NULL AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)'
            ),
        ];

        $activityMix = $this->db->select(
            'SELECT a.status, COUNT(*) AS total FROM crop_activities a
             WHERE a.status <> ' . "'cancelled'" . ' ' . $scope['activityWhere'] . '
             GROUP BY a.status',
            $scope['activityParams']
        );

        $cropMix = $this->db->select(
            'SELECT c.name, COUNT(p.id) AS plantings, COALESCE(SUM(p.acreage_planted), 0) AS acreage
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             WHERE p.status <> ' . "'terminated'" . ' ' . $scope['plantingWhere'] . '
             GROUP BY c.id, c.name
             ORDER BY acreage DESC
             LIMIT 8',
            $scope['plantingParams']
        );

        $registrations = $this->db->select(
            "SELECT DATE_FORMAT(f.registration_date, '%b %Y') AS period, COUNT(*) AS total
             FROM farmers f
             WHERE f.deleted_at IS NULL AND f.registration_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) "
            . $scope['farmerWhere'] . '
             GROUP BY period
             ORDER BY MIN(f.registration_date) ASC',
            $scope['farmerParams']
        );

        $seasonProgress = null;

        $currentSeason = $this->db->selectOne(
            "SELECT * FROM seasons WHERE status = 'open' ORDER BY start_date DESC LIMIT 1"
        );

        if ($currentSeason !== null) {
            $seasonProgress = [
                'season'   => $currentSeason,
                'planted'  => $this->count('plantings p', $scope['plantingWhere'], $scope['plantingParams'], 'p.season_id = :season AND p.status = \'planted\'', ['season' => $currentSeason['id']]),
                'expected' => (float) $this->db->scalar(
                    'SELECT COALESCE(SUM(hr.quantity_harvested), 0) FROM harvest_records hr WHERE hr.season_id = :season',
                    ['season' => $currentSeason['id']]
                ),
                'activities' => $this->count('crop_activities a', $scope['activityWhere'], $scope['activityParams'], 'a.season_id = :season', ['season' => $currentSeason['id']]),
                'completed'  => $this->count('crop_activities a', $scope['activityWhere'], $scope['activityParams'], "a.season_id = :season AND a.status = 'completed'", ['season' => $currentSeason['id']]),
            ];
        }

        $recentActivity = $this->db->select(
            'SELECT a.activity_code, a.activity_type, a.status, a.due_date, a.completed_at,
                    f.full_name, f.farmer_code, c.name AS crop_name
             FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             LEFT JOIN crops c ON c.id = a.crop_id
             WHERE 1 = 1 ' . $scope['activityWhere'] . '
             ORDER BY a.updated_at DESC LIMIT 10',
            $scope['activityParams']
        );

        $recentProblems = $this->db->select(
            'SELECT pr.problem_code, pr.severity, pr.status, pr.report_date, pt.name AS problem_type,
                    f.full_name, f.farmer_code
             FROM problem_reports pr
             INNER JOIN farmers f ON f.id = pr.farmer_id
             LEFT JOIN problem_types pt ON pt.id = pr.problem_type_id
             WHERE 1 = 1 ' . $scope['problemWhere'] . '
             ORDER BY pr.report_date DESC LIMIT 8',
            $scope['problemParams']
        );

        return $this->render('dashboard', [
            'pageTitle'      => $this->headline(),
            'scopeLabel'     => $this->scopeLabel(),
            'stats'          => $stats,
            'attention'      => $attention,
            'activityMix'    => $activityMix,
            'cropMix'        => $cropMix,
            'registrations'  => $registrations,
            'seasonProgress' => $seasonProgress,
            'currentSeason'  => $currentSeason,
            'recentActivity' => $recentActivity,
            'recentProblems' => $recentProblems,
            'chartJs'        => true,
        ]);
    }

    /**
     * One search box across the whole record, so a user can paste a farmer ID,
     * a garden code, a batch code or a phone number and land on the right
     * screen. Farmers and officers only ever search their own scope.
     */
    public function search(): Response
    {
        $term = $this->request->string('search');
        $min  = 2;

        if (mb_strlen($term) < $min) {
            Flash::warning('Enter at least ' . $min . ' characters to search.');

            return $this->redirect(url('dashboard'));
        }

        $like    = '%' . $term . '%';
        $results = [];

        // Search respects the same scoping as the dashboard so an officer never
        // sees another officer's farmers in a global search.
        $scope = $this->scope();

        $farmerRows = $this->db->select(
            'SELECT f.farmer_code, f.full_name, f.phone, f.village_name
             FROM farmers f
             WHERE f.deleted_at IS NULL
               AND (f.farmer_code LIKE :term OR f.full_name LIKE :term OR f.phone LIKE :term
                    OR f.national_id LIKE :term)'
            . $scope['farmerWhere'] . '
             ORDER BY f.full_name LIMIT 10',
            ['term' => $like] + $scope['farmerParams']
        );

        foreach ($farmerRows as $row) {
            $results[] = [
                'group' => 'Farmers',
                'title' => $row['full_name'],
                'meta'  => $row['farmer_code'] . ' · ' . $row['phone'] . ' · ' . ($row['village_name'] ?: 'location not set'),
                'url'   => url('farmers/' . $row['farmer_code']),
            ];
        }

        $gardenRows = $this->db->select(
            'SELECT g.garden_code, g.name, f.full_name, f.farmer_code
             FROM gardens g INNER JOIN farmers f ON f.id = g.farmer_id
             WHERE g.deleted_at IS NULL AND (g.garden_code LIKE :term OR g.name LIKE :term)'
            . $scope['gardenWhere'] . '
             ORDER BY g.name LIMIT 10',
            ['term' => $like] + $scope['gardenParams']
        );

        foreach ($gardenRows as $row) {
            $results[] = [
                'group' => 'Gardens',
                'title' => $row['name'],
                'meta'  => $row['garden_code'] . ' · ' . $row['full_name'],
                'url'   => url('gardens/' . $row['garden_code']),
            ];
        }

        $plantingRows = $this->db->select(
            'SELECT p.planting_code, c.name AS crop_name, f.full_name, g.garden_code
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             INNER JOIN farmers f ON f.id = p.farmer_id
             LEFT JOIN gardens g ON g.id = p.garden_id
             WHERE (p.planting_code LIKE :term OR c.name LIKE :term)'
            . $scope['plantingWhere'] . '
             ORDER BY p.planting_date DESC LIMIT 10',
            ['term' => $like] + $scope['plantingParams']
        );

        foreach ($plantingRows as $row) {
            $results[] = [
                'group' => 'Crops',
                'title' => $row['crop_name'],
                'meta'  => $row['planting_code'] . ' · ' . $row['full_name'] . ' · ' . ($row['garden_code'] ?? ''),
                'url'   => url('plantings/' . $row['planting_code']),
            ];
        }

        $batchRows = $this->db->select(
            'SELECT b.batch_code, b.crop_id, c.name AS crop_name, b.quantity, b.status
             FROM harvest_batches b INNER JOIN crops c ON c.id = b.crop_id
             WHERE b.batch_code LIKE :term ORDER BY b.created_at DESC LIMIT 10',
            ['term' => $like]
        );

        foreach ($batchRows as $row) {
            $results[] = [
                'group' => 'Batches',
                'title' => $row['batch_code'],
                'meta'  => $row['crop_name'] . ' · ' . format_quantity($row['quantity']) . ' · ' . $row['status'],
                'url'   => url('batches/' . $row['batch_code']),
            ];
        }

        $orderRows = $this->db->select(
            'SELECT s.sale_code, s.sale_date, s.status, b.name AS buyer_name
             FROM sales s LEFT JOIN buyers b ON b.id = s.buyer_id
             WHERE s.sale_code LIKE :term ORDER BY s.sale_date DESC LIMIT 10',
            ['term' => $like]
        );

        foreach ($orderRows as $row) {
            $results[] = [
                'group' => 'Sales',
                'title' => $row['sale_code'],
                'meta'  => format_date($row['sale_date']) . ' · ' . ($row['buyer_name'] ?: 'No buyer') . ' · ' . $row['status'],
                'url'   => url('sales/' . $row['sale_code']),
            ];
        }

        return $this->render('search', [
            'pageTitle' => 'Search results',
            'term'      => $term,
            'results'   => $results,
        ]);
    }

    /**
     * Simplified, phone friendly screens for extension officers working without
     * a laptop. The same data, fewer columns, bigger targets.
     */
    public function portal(): Response
    {
        $officerId = $this->auth->officerId();
        $farmerId  = $this->auth->farmerId();

        $where  = $officerId !== null
            ? ' AND f.officer_id = :officer'
            : ($farmerId !== null ? ' AND f.id = :farmer' : '');
        $params = $officerId !== null
            ? ['officer' => $officerId]
            : ($farmerId !== null ? ['farmer' => $farmerId] : []);

        $today = $this->db->select(
            'SELECT a.activity_code, a.activity_type, a.due_date, a.status, a.priority, a.description,
                    ' . \Services\Lookups::activityNameSql('a') . ' AS activity_name,
                    f.full_name, f.farmer_code, g.garden_code
             FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             LEFT JOIN gardens g ON g.id = a.garden_id
             WHERE a.status IN (\'due\', \'overdue\', \'pending\') ' . $where . '
             ORDER BY FIELD(a.status, \'overdue\', \'due\', \'pending\'), a.due_date ASC
             LIMIT 25',
            $params
        );

        $portfolio = $this->db->selectOne(
            "SELECT COUNT(*) AS farmers, COALESCE(SUM(g.acreage), 0) AS acreage
             FROM farmers f
             LEFT JOIN gardens g ON g.farmer_id = f.id AND g.deleted_at IS NULL
             WHERE f.deleted_at IS NULL $where",
            $params
        ) ?? ['farmers' => 0, 'acreage' => 0];

        return $this->render('portal/index', [
            'pageTitle' => 'Field portal',
            'today'     => $today,
            'portfolio' => $portfolio,
        ]);
    }

    /**
     * The small completion form used from the field portal. Kept separate from
     * the full activity screen so the mobile flow stays one short page.
     */
    public function portalActivity(string $code): Response
    {
        return $this->render('portal/activity', [
            'pageTitle' => 'Record activity',
            'activity'  => $this->portalActivityOrFail($code),
        ]);
    }

    /**
     * Records the completion posted from the portal form.
     */
    public function completePortalActivity(string $code): Response
    {
        if (!\Core\Csrf::check($this->request->input('_token'))) {
            throw new \Core\HttpException(419, 'Your session expired. Please sign in again.');
        }

        $activity = $this->portalActivityOrFail($code);

        if ($activity['status'] === 'completed') {
            \Core\Flash::info('That activity was already completed at ' . format_datetime((string) $activity['completed_at']) . '.');

            return $this->redirect(url('portal'));
        }

        $date     = $this->request->date('completion_date') ?? date('Y-m-d');
        $remarks  = $this->request->string('completion_remarks');

        // A completion can never be dated in the future: it is a record of work
        // that has already happened.
        if ($date > date('Y-m-d')) {
            \Core\Flash::error('A completion date cannot be in the future.');

            return $this->redirect(url('portal/activity/' . $code));
        }

        $update = [
            'status'             => 'completed',
            'completed_at'       => $date . ' 00:00:00',
            'completed_by'       => $this->auth->id(),
            'completion_remarks' => $remarks !== '' ? $remarks : null,
        ];

        if (isset($_FILES['evidence']) && ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = (new \Core\Uploader(
                (string) $this->config('uploads.path'),
                (string) $this->config('uploads.url')
            ))->image('evidence', 'activities', (int) $this->config('uploads.max_image_kb', 4096));

            if ($upload['success'] === true) {
                $update['evidence'] = $upload['path'];
            } else {
                \Core\Flash::warning('The photo could not be saved, but the activity was still marked complete.');
            }
        }

        $this->db->transaction(function () use ($activity, $update, $date, $remarks): void {
            $this->db->update('crop_activities', $update, 'id = :id', ['id' => $activity['id']]);

            // The completions row is the permanent evidence record: geotag,
            // photo and remark, kept even if the activity itself is later
            // corrected.
            $this->db->insert('activity_completions', [
                'activity_id'  => $activity['id'],
                'completed_by' => $this->auth->id(),
                'completed_at' => $date . ' 00:00:00',
                'latitude'     => $this->request->float('latitude') ?: null,
                'longitude'    => $this->request->float('longitude') ?: null,
                'remarks'      => $remarks !== '' ? $remarks : null,
                'photo'        => $update['evidence'] ?? null,
            ]);
        });

        // The planting's schedule completion ratio is derived from the activity
        // rows, so nothing else needs updating here.
        \Core\Audit::log(
            'complete',
            'activities',
            'crop_activity',
            (int) $activity['id'],
            (string) $activity['activity_code'],
            $activity,
            ['completion_date' => $date, 'remarks' => $update['completion_remarks']]
        );

        \Core\Flash::success(
            ucwords(str_replace('_', ' ', (string) $activity['activity_type'])) . ' marked complete for '
            . $activity['full_name'] . '.'
        );

        return $this->redirect(url('portal'));
    }

    /**
     * Loads an activity for the portal, refusing anything outside the signed-in
     * user's scope. Without this check a farmer could complete another farmer's
     * task by guessing an activity code.
     */
    private function portalActivityOrFail(string $code): array
    {
        $activity = $this->db->selectOne(
            'SELECT a.*, f.full_name, f.farmer_code, f.officer_id AS farmer_officer_id,
                    c.name AS crop_name, v.name AS variety_name, g.garden_code, g.name AS garden_name
             FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             INNER JOIN gardens g ON g.id = a.garden_id
             LEFT JOIN crops c ON c.id = a.crop_id
             LEFT JOIN crop_varieties v ON v.id = a.variety_id
             WHERE a.activity_code = :code',
            ['code' => $code]
        );

        if ($activity === null) {
            throw new \Core\HttpException(404, 'That activity could not be found.');
        }

        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        $allowed = match (true) {
            $farmerId !== null  => (int) $activity['farmer_id'] === $farmerId,
            $officerId !== null => (int) $activity['farmer_officer_id'] === $officerId
                || (int) ($activity['officer_id'] ?? 0) === $officerId,
            default             => true,
        };

        if (!$allowed) {
            // Reported as "not found" rather than "forbidden" so the portal does
            // not confirm that another farmer's record exists.
            throw new \Core\HttpException(404, 'That activity could not be found.');
        }

        return $activity;
    }

    /**
     * Restricts every query to what the signed-in user is allowed to see.
     *
     * @return array{farmerWhere:string,farmerParams:array,gardenWhere:string,gardenParams:array,plantingWhere:string,plantingParams:array,activityWhere:string,activityParams:array,problemWhere:string,problemParams:array,harvestWhere:string,harvestParams:array}
     */
    private function scope(): array
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null) {
            $farmer = ' AND f.id = :scope_farmer';

            return [
                'farmerWhere'   => $farmer,
                'farmerParams'  => ['scope_farmer' => $farmerId],
                'gardenWhere'   => ' AND g.farmer_id = :scope_farmer',
                'gardenParams'  => ['scope_farmer' => $farmerId],
                'plantingWhere' => ' AND p.farmer_id = :scope_farmer',
                'plantingParams' => ['scope_farmer' => $farmerId],
                'activityWhere' => ' AND a.farmer_id = :scope_farmer',
                'activityParams' => ['scope_farmer' => $farmerId],
                'problemWhere'  => ' AND pr.farmer_id = :scope_farmer',
                'problemParams' => ['scope_farmer' => $farmerId],
                'harvestWhere'  => ' AND hr.farmer_id = :scope_farmer',
                'harvestParams' => ['scope_farmer' => $farmerId],
            ];
        }

        if ($officerId !== null) {
            $officer = ' AND f.officer_id = :scope_officer';

            return [
                'farmerWhere'   => $officer,
                'farmerParams'  => ['scope_officer' => $officerId],
                'gardenWhere'   => ' AND g.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)',
                'gardenParams'  => ['scope_officer' => $officerId],
                'plantingWhere' => ' AND p.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)',
                'plantingParams' => ['scope_officer' => $officerId],
                'activityWhere' => ' AND a.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)',
                'activityParams' => ['scope_officer' => $officerId],
                'problemWhere'  => ' AND pr.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)',
                'problemParams' => ['scope_officer' => $officerId],
                'harvestWhere'  => ' AND hr.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)',
                'harvestParams' => ['scope_officer' => $officerId],
            ];
        }

        $none = ['', []];

        return [
            'farmerWhere'    => '',
            'farmerParams'   => [],
            'gardenWhere'    => '',
            'gardenParams'   => [],
            'plantingWhere'  => '',
            'plantingParams' => [],
            'activityWhere'  => '',
            'activityParams' => [],
            'problemWhere'   => '',
            'problemParams'  => [],
            'harvestWhere'   => '',
            'harvestParams'  => [],
        ];
    }

    private function count(string $from, string $where, array $params, string $base, array $extra = []): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $base . $where . ($extra === [] ? '' : ' AND ' . implode(' AND ', $extra)),
            $params + $extra
        );
    }

    private function sum(string $from, string $column, string $where, array $params, string $base): float
    {
        return (float) $this->db->scalar(
            'SELECT COALESCE(SUM(' . Database::safeIdentifier($column) . '), 0) FROM ' . $from . ' WHERE ' . $base . $where,
            $params
        );
    }

    private function headline(): string
    {
        if ($this->auth->farmerId() !== null) {
            return 'My farm';
        }

        if ($this->auth->officerId() !== null) {
            return 'My portfolio';
        }

        return 'Programme overview';
    }

    private function scopeLabel(): string
    {
        if ($this->auth->farmerId() !== null) {
            return 'Everything recorded against your farm';
        }

        if ($this->auth->officerId() !== null) {
            return 'Farmers assigned to you';
        }

        return (string) $this->config('app.short_name', 'CAHU') . ' platform-wide figures';
    }
}
