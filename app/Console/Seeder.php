<?php
declare(strict_types=1);

namespace Console;

use Core\Database;

/**
 * Fills a freshly imported schema with the data the application assumes exists.
 *
 * schema.sql creates 60+ empty tables and deliberately contains no INSERT
 * statements, so a new install has no users, no roles and no permissions and the
 * login screen rejects every credential. This class inserts:
 *
 *   - the permission catalogue the router and navigation check against
 *   - the standard roles, with permissions attached
 *   - one super administrator
 *   - the reference rows the forms assume exist: crops, seasons, regions,
 *     districts, activity types, problem types, input and knowledge categories
 *   - the system settings the dashboard reads
 *
 * It is safe to re-run: every write is an upsert keyed on a natural column, so a
 * second run changes nothing. The one deliberate exception is
 * system_settings.setting_value, which is left alone once it exists so that
 * re-seeding never discards a value an operator has changed.
 *
 * This is a plain class with no SAPI dependency on purpose. It used to live
 * inside database/seed.php, which could only ever run as `php database/seed.php`
 * because it read its answers with fgets(STDIN) and reported them with echo. That
 * made it impossible for the browser installer to seed the first administrator,
 * which in turn forced the installer to shell out to a PHP binary - something
 * that does not exist on a host where PHP runs as an Apache module, and that
 * shared hosting routinely disables. database/seed.php is now a thin wrapper
 * around this class, so both entry points share one implementation.
 *
 * Nothing here reads input or writes output directly: answers arrive in the
 * options array and progress goes to the $out callback, so the same run works
 * from a terminal and from an HTTP request.
 */
final class Seeder
{
    private Database $db;

    /** @var array<string, mixed> */
    private array $options;

    /** @var callable(string, string): void */
    private $out;

    /** @var array<string, int> Populated by seedAccessControl(). */
    private array $roleIds = [];

    /**
     * @param array<string, mixed> $options
     *        admin_username    Super administrator username (default "admin")
     *        admin_email       Super administrator email address
     *        admin_password    Super administrator password, minimum 8 characters
     *        demo              Create one demo account per role
     *        demo_password     Shared demo password (default "CahuDemo#2026")
     *        create_admin      Set false to seed only the reference data and
     *                        leave user creation to the caller (default true)
     * @param callable(string, string): void $out Receives (message, level) where
     *        level is one of info, ok, warn or error.
     */
    public function __construct(Database $db, array $options = [], ?callable $out = null)
    {
        $this->db      = $db;
        $this->options = $options;
        $this->out     = $out ?? static function (string $message, string $level = 'info'): void {
            // A caller that supplied no sink still gets the text on stdout, which
            // is what the command line wrapper wants anyway.
            echo $message . PHP_EOL;
        };
    }

    private function line(string $message, string $level = 'info'): void
    {
        ($this->out)($message, $level);
    }

    /**
     * Seeds everything and returns a summary for the caller to report.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $this->line('Connected to database ' . $this->db->scalar('SELECT DATABASE()'));

        $access = $this->seedAccessControl();
        $season = $this->seedReference();
        $places = $this->seedDistricts();
        $count  = $this->seedSettings($season);

        $admin = ($this->options['create_admin'] ?? true) === false
            ? ['created' => false, 'skipped' => true, 'username' => null]
            : $this->ensureAdministrator();

        $demo = ($this->options['demo'] ?? false) === true ? $this->seedDemoAccounts() : null;

        $this->line('Seed complete.', 'ok');

        return [
            'permissions'      => $access['permissions'],
            'roles'            => $access['roles'],
            'districts'        => $places['districts'],
            'sub_counties'     => $places['sub_counties'],
            'settings'         => $count,
            'current_season'   => $season,
            'admin'            => $admin,
            'demo_accounts'    => $demo,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Permissions and roles                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{permissions: int, roles: int}
     */
    private function seedAccessControl(): array
    {
        $permissions = $this->permissionCatalogue();

        try {
            $this->db->beginTransaction();

            $permissionIds = [];

            foreach ($permissions as $module => $entries) {
                foreach ($entries as $code => $description) {
                    // PDO is configured with EMULATE_PREPARES off, and MySQL's
                    // native prepared statements reject a named placeholder that
                    // appears twice in one statement. Every ON DUPLICATE KEY
                    // UPDATE clause therefore binds its own ":set_" parameters
                    // instead of reusing the insert values.
                    $this->db->query(
                        'INSERT INTO permissions (module, name, code, description)
                         VALUES (:module, :name, :code, :description)
                         ON DUPLICATE KEY UPDATE
                            `module`      = :set_module,
                            `name`        = :set_name,
                            `description` = :set_description',
                        [
                            'module'          => $module,
                            'name'            => $description,
                            'code'            => $code,
                            'description'     => $description,
                            'set_module'      => $module,
                            'set_name'        => $description,
                            'set_description' => $description,
                        ]
                    );

                    $permissionIds[$code] = (int) $this->db->scalar(
                        'SELECT id FROM permissions WHERE code = :code',
                        ['code' => $code]
                    );
                }
            }

            $allPermissionCodes = array_keys($permissionIds);

            /*
             * code => [name, description, permission codes]. '*' grants
             * everything and is how the super administrator account is
             * recognised by Auth::isSuperAdmin().
             */
            $roles = [
                'super_admin' => [
                    'name'        => 'Super Administrator',
                    'description' => 'Unrestricted access to every part of the platform.',
                    'permissions' => ['*'],
                ],
                'manager' => [
                    'name'        => 'Programme Manager',
                    'description' => 'Oversight across all modules, without user administration.',
                    'permissions' => array_values(array_filter(
                        $allPermissionCodes,
                        static fn (string $c): bool => !in_array($c, ['admin.users', 'admin.roles'], true)
                    )),
                ],
                'field_officer' => [
                    'name'        => 'Field Officer',
                    'description' => 'Field work: farmers, gardens, visits, inspections and problems.',
                    'permissions' => [
                        'farmers.view', 'farmers.create', 'farmers.edit',
                        'groups.view',
                        'officers.view',
                        'gardens.view', 'gardens.manage',
                        'crops.view', 'seasons.view', 'plantings.view', 'activities.view',
                        'visits.view', 'visits.manage',
                        'inspections.view', 'inspections.manage',
                        'problems.view', 'problems.manage',
                        'spraying.view', 'spraying.manage',
                        'inputs.view', 'inputs.manage', 'inputs.issue',
                        'harvest.view', 'harvest.manage',
                        'batches.manage',
                        'markets.view',
                        'knowledge.view',
                        'map.view', 'reports.view',
                    ],
                ],
                'agronomist' => [
                    'name'        => 'Agronomist',
                    'description' => 'Technical oversight of production, inspections and inputs.',
                    'permissions' => [
                        'farmers.view', 'groups.view', 'gardens.view',
                        'crops.view', 'crops.manage', 'seasons.view', 'seasons.manage',
                        'plantings.view', 'plantings.manage',
                        'activities.view', 'activities.manage',
                        'inspections.view', 'inspections.manage',
                        'problems.view', 'problems.manage',
                        'spraying.view', 'spraying.manage',
                        'inputs.view', 'inputs.manage', 'inputs.issue',
                        'harvest.view', 'harvest.manage', 'batches.manage',
                        'knowledge.view', 'knowledge.manage',
                        'map.view', 'reports.view',
                    ],
                ],
                'market_officer' => [
                    'name'        => 'Market Officer',
                    'description' => 'Buyers, markets, demand, sales and deliveries.',
                    'permissions' => [
                        'farmers.view', 'groups.view',
                        'markets.view', 'markets.manage',
                        'harvest.view',
                        'sales.manage', 'exports.manage',
                        'reports.view',
                    ],
                ],
                'farmer' => [
                    'name'        => 'Farmer',
                    'description' => 'Self-service portal access to own records and field activities.',
                    'permissions' => [],
                ],
            ];

            $this->roleIds = [];

            foreach ($roles as $code => $definition) {
                $this->db->query(
                    'INSERT INTO roles (name, code, description, is_system)
                     VALUES (:name, :code, :description, 1)
                     ON DUPLICATE KEY UPDATE
                        `name`        = :set_name,
                        `description` = :set_description,
                        `is_system`   = 1',
                    [
                        'name'            => $definition['name'],
                        'code'            => $code,
                        'description'     => $definition['description'],
                        'set_name'        => $definition['name'],
                        'set_description' => $definition['description'],
                    ]
                );

                $roleId = (int) $this->db->scalar(
                    'SELECT id FROM roles WHERE code = :code',
                    ['code' => $code]
                );

                $this->roleIds[$code] = $roleId;

                // A wildcard role holds no rows in role_permissions: Auth::can()
                // treats a role whose code is super_admin as having everything, so
                // writing '*' into the join table would produce a permission row
                // that matches nothing.
                $this->db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role',
                    ['role' => $roleId]
                );

                if ($definition['permissions'] === ['*']) {
                    continue;
                }

                foreach ($definition['permissions'] as $permissionCode) {
                    if (!isset($permissionIds[$permissionCode])) {
                        continue;
                    }

                    $this->db->query(
                        'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)',
                        ['role' => $roleId, 'permission' => $permissionIds[$permissionCode]]
                    );
                }
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw new \RuntimeException(
                'Failed to seed permissions and roles: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $this->line('Permissions: ' . count($permissionIds) . ' (' . count($permissions) . ' modules)');
        $this->line('Roles: ' . count($this->roleIds));

        return [
            'permissions' => count($permissionIds),
            'roles'       => count($this->roleIds),
        ];
    }

    /**
     * module => [code => description]. The keys are the exact strings the router
     * passes to requirePermission(), so a typo here silently locks a page away.
     *
     * @return array<string, array<string, string>>
     */
    private function permissionCatalogue(): array
    {
        return [
            'reports' => [
                'reports.view' => 'View the command centre, reports and map',
            ],
            'farmers' => [
                'farmers.view'    => 'View the farmer register and farmer records',
                'farmers.create'  => 'Register new farmers',
                'farmers.edit'    => 'Edit farmer records',
                'farmers.delete'  => 'Remove farmer records',
            ],
            'groups' => [
                'groups.view'   => 'View farmer groups',
                'groups.manage' => 'Create, edit and remove farmer groups',
            ],
            'officers' => [
                'officers.view'   => 'View field officers',
                'officers.manage' => 'Create, edit and remove field officers',
            ],
            'gardens' => [
                'gardens.view'   => 'View the garden register',
                'gardens.manage' => 'Create, edit and remove gardens',
            ],
            'map' => [
                'map.view' => 'View the farm map',
            ],
            'crops' => [
                'crops.view'   => 'View crops and varieties',
                'crops.manage' => 'Create, edit and remove crops and varieties',
            ],
            'seasons' => [
                'seasons.view'   => 'View seasons',
                'seasons.manage' => 'Create, edit and remove seasons',
            ],
            'plantings' => [
                'plantings.view'   => 'View plantings',
                'plantings.manage' => 'Create, edit and remove plantings',
            ],
            'activities' => [
                'activities.view'   => 'View crop activities and the calendar',
                'activities.manage' => 'Schedule, edit and complete crop activities',
            ],
            'visits' => [
                'visits.view'   => 'View field visits',
                'visits.manage' => 'Schedule, edit and close field visits',
            ],
            'inspections' => [
                'inspections.view'   => 'View inspections',
                'inspections.manage' => 'Record, edit and review inspections',
            ],
            'problems' => [
                'problems.view'   => 'View problem reports',
                'problems.manage' => 'Report, assign and resolve problems',
            ],
            'spraying' => [
                'spraying.view'   => 'View spraying records',
                'spraying.manage' => 'Record and edit spraying',
            ],
            'inputs' => [
                'inputs.view'   => 'View input inventory',
                'inputs.manage' => 'Create and edit inputs',
                'inputs.issue'  => 'Issue inputs and record usage',
            ],
            'harvest' => [
                'harvest.view'   => 'View harvest records and forecasts',
                'harvest.manage' => 'Record harvests and manage batches',
            ],
            'batches' => [
                'batches.manage' => 'Create, edit and close traceability batches',
            ],
            'markets' => [
                'markets.view'   => 'View buyers, markets and demand',
                'markets.manage' => 'Register buyers and markets, record demand',
            ],
            'sales' => [
                'sales.manage' => 'Record sales and deliveries',
            ],
            'exports' => [
                'exports.manage' => 'Create and approve exports',
            ],
            'knowledge' => [
                'knowledge.view'   => 'View the knowledge centre',
                'knowledge.manage' => 'Publish and edit knowledge articles',
            ],
            'admin' => [
                'admin.settings' => 'Change system settings and reference data',
                'admin.users'    => 'Manage user accounts',
                'admin.roles'    => 'Manage roles and permissions',
                'admin.audit'    => 'Read the audit log',
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Reference data                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Returns the season code the dashboard treats as current, which the caller
     * needs in order to write system_settings.default_season.
     */
    private function seedReference(): string
    {
        $reference = $this->referenceRows();

        /*
         * The natural key differs per table: most carry a unique `code`, but
         * crops uses `crop_code` and knowledge_categories uses `slug`. Upserting
         * on the wrong one would fail, so the key is declared alongside the data
         * rather than assumed.
         */
        $referenceKeys = [
            'regions'              => 'code',
            'crops'                => 'crop_code',
            'problem_types'        => 'code',
            'activity_types'       => 'code',
            'input_categories'     => 'code',
            'knowledge_categories' => 'slug',
            'seasons'              => 'season_code',
        ];

        try {
            $this->db->beginTransaction();

            foreach ($reference as $table => $rows) {
                $key = $referenceKeys[$table] ?? 'code';

                foreach ($rows as $row) {
                    if (!array_key_exists($key, $row)) {
                        throw new \RuntimeException(
                            'Seed row for ' . $table . ' is missing its key column "' . $key . '".'
                        );
                    }

                    $columns   = array_keys($row);
                    $holders   = implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns));
                    $updatable = array_values(array_filter($columns, static fn (string $c): bool => $c !== $key));
                    $params    = $row;

                    // The key column carries a UNIQUE index, so it makes a safe
                    // natural key for the upsert. A row whose name is edited keeps
                    // its key and is renamed rather than duplicated.
                    //
                    // The update clause binds distinct ":set_" parameters.
                    // Reusing the insert placeholders would fail with HY093 under
                    // native prepares.
                    if ($updatable === []) {
                        $updates = $key . ' = ' . $key;
                    } else {
                        $assignments = [];

                        foreach ($updatable as $column) {
                            $assignments[]            = '`' . $column . '` = :set_' . $column;
                            $params['set_' . $column] = $row[$column];
                        }

                        $updates = implode(', ', $assignments);
                    }

                    $this->db->query(
                        'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . $holders . ')
                         ON DUPLICATE KEY UPDATE ' . $updates,
                        $params
                    );
                }
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw new \RuntimeException(
                'Failed to seed reference data: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $this->line(
            'Reference data: ' . array_sum(array_map('count', $reference)) . ' row(s) across '
            . count($reference) . ' table(s)'
        );

        // The season the dashboard treats as current: whichever one is open
        // today, otherwise the next one to start.
        return $this->currentSeason($reference['seasons']);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function currentSeason(array $rows): string
    {
        $today = date('Y-m-d');
        $next  = null;

        foreach ($rows as $row) {
            if ($row['status'] === 'open') {
                return $row['season_code'];
            }

            if ($row['start_date'] > $today && ($next === null || $row['start_date'] < $next)) {
                $next = $row['season_code'];
            }
        }

        return $next ?? '';
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function referenceRows(): array
    {
        return [
            'regions' => [
                ['name' => 'Central', 'code' => 'CEN'],
                ['name' => 'Eastern', 'code' => 'EAS'],
                ['name' => 'Northern', 'code' => 'NOR'],
                ['name' => 'Western', 'code' => 'WES'],
            ],
            'crops' => [
                // crops is keyed on crop_code, not code, and its category enum is
                // cereal/legume/beverage/vegetable/fruit/root_tuber/oilseed/fibre/other.
                ['crop_code' => 'CRP-0001', 'name' => 'Maize', 'category' => 'cereal', 'is_perishable' => 0, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0002', 'name' => 'Rice', 'category' => 'cereal', 'is_perishable' => 0, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0003', 'name' => 'Beans', 'category' => 'legume', 'is_perishable' => 0, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0004', 'name' => 'Groundnuts', 'category' => 'legume', 'is_perishable' => 0, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0005', 'name' => 'Soybean', 'category' => 'legume', 'is_perishable' => 0, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0006', 'name' => 'Coffee', 'category' => 'beverage', 'is_perishable' => 0, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0007', 'name' => 'Bananas', 'category' => 'fruit', 'is_perishable' => 1, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0008', 'name' => 'Cassava', 'category' => 'root_tuber', 'is_perishable' => 0, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0009', 'name' => 'Sweet potatoes', 'category' => 'root_tuber', 'is_perishable' => 0, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0010', 'name' => 'Tomatoes', 'category' => 'vegetable', 'is_perishable' => 1, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0011', 'name' => 'Onions', 'category' => 'vegetable', 'is_perishable' => 1, 'primary_unit' => 'KG'],
                ['crop_code' => 'CRP-0012', 'name' => 'Sunflower', 'category' => 'oilseed', 'is_perishable' => 0, 'primary_unit' => 'KG'],
            ],
            'problem_types' => [
                // The column is is_critical, not critical, and the flag that hides
                // a retired type is `active` rather than a status enum.
                ['name' => 'Fall armyworm', 'code' => 'pest_faw', 'category' => 'pest', 'is_critical' => 1],
                ['name' => 'Armyworm', 'code' => 'pest_armyworm', 'category' => 'pest', 'is_critical' => 0],
                ['name' => 'Banana weevil', 'code' => 'pest_banana_weevil', 'category' => 'pest', 'is_critical' => 0],
                ['name' => 'Coffee berry borer', 'code' => 'pest_cbb', 'category' => 'pest', 'is_critical' => 1],
                ['name' => 'Cassava mosaic virus', 'code' => 'disease_cmv', 'category' => 'disease', 'is_critical' => 1],
                ['name' => 'Wilt disease', 'code' => 'disease_wilt', 'category' => 'disease', 'is_critical' => 0],
                ['name' => 'Leaf blight', 'code' => 'disease_leaf_blight', 'category' => 'disease', 'is_critical' => 0],
                ['name' => 'Nutrient deficiency', 'code' => 'soil_nutrient', 'category' => 'soil', 'is_critical' => 0],
                ['name' => 'Soil erosion', 'code' => 'soil_erosion', 'category' => 'soil', 'is_critical' => 0],
                ['name' => 'Drought', 'code' => 'weather_drought', 'category' => 'weather', 'is_critical' => 1],
                ['name' => 'Flooding', 'code' => 'weather_flood', 'category' => 'weather', 'is_critical' => 0],
                ['name' => 'Hail damage', 'code' => 'weather_hail', 'category' => 'weather', 'is_critical' => 0],
                ['name' => 'Input shortage', 'code' => 'input_shortage', 'category' => 'input', 'is_critical' => 0],
                ['name' => 'Theft of produce', 'code' => 'theft_produce', 'category' => 'theft', 'is_critical' => 0],
            ],
            'activity_types' => [
                ['name' => 'Land preparation', 'code' => 'land_prep', 'category' => 'pre-planting'],
                ['name' => 'Planting', 'code' => 'planting', 'category' => 'pre-planting'],
                ['name' => 'First weeding', 'code' => 'first_weeding', 'category' => 'crop care'],
                ['name' => 'Second weeding', 'code' => 'second_weeding', 'category' => 'crop care'],
                ['name' => 'Top dressing', 'code' => 'top_dressing', 'category' => 'crop care'],
                ['name' => 'Spraying', 'code' => 'spraying', 'category' => 'crop care'],
                ['name' => 'Thinning', 'code' => 'thinning', 'category' => 'crop care'],
                ['name' => 'Harvesting', 'code' => 'harvesting', 'category' => 'post-harvest'],
            ],
            'input_categories' => [
                // input_categories has no unit column: the unit belongs on each
                // input row, not on the category.
                ['name' => 'Seeds', 'code' => 'seed'],
                ['name' => 'Fertiliser', 'code' => 'fertiliser'],
                ['name' => 'Pesticide', 'code' => 'pesticide'],
                ['name' => 'Herbicide', 'code' => 'herbicide'],
                ['name' => 'Tools', 'code' => 'tools'],
            ],
            'knowledge_categories' => [
                // keyed on slug, not code.
                ['name' => 'Crop Production', 'slug' => 'crop-production'],
                ['name' => 'Pest Management', 'slug' => 'pest-management'],
                ['name' => 'Post-Harvest Handling', 'slug' => 'post-harvest-handling'],
                ['name' => 'Market Access', 'slug' => 'market-access'],
                ['name' => 'Financial Literacy', 'slug' => 'financial-literacy'],
            ],
            // plantings.season_id is NOT NULL, so a fresh install cannot record a
            // single planting unless at least one season exists. Dates follow the
            // two main Ugandan cropping seasons and are refreshed on each re-run.
            'seasons' => $this->seasonRows(),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function seasonRows(): array
    {
        $year  = (int) date('Y');
        $today = date('Y-m-d');

        $status = static function (string $start, string $end) use ($today): string {
            if ($today < $start) {
                return 'upcoming';
            }

            return $today > $end ? 'closed' : 'open';
        };

        $seasonAStart = $year . '-03-01';
        $seasonAEnd   = $year . '-10-31';
        $seasonBStart = $year . '-11-01';
        $seasonBEnd   = ($year + 1) . '-03-10';

        return [
            [
                'season_code' => $year . '-A',
                'name'        => $year . ' Season A',
                'start_date'  => $seasonAStart,
                'end_date'    => $seasonAEnd,
                'status'      => $status($seasonAStart, $seasonAEnd),
            ],
            [
                'season_code' => $year . '-B',
                'name'        => $year . '-' . ($year + 1) . ' Season B',
                'start_date'  => $seasonBStart,
                'end_date'    => $seasonBEnd,
                'status'      => $status($seasonBStart, $seasonBEnd),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Districts                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{districts: int, sub_counties: int}
     */
    private function seedDistricts(): array
    {
        $districts = [
            'CEN' => ['Kampala', 'Wakiso', 'Mukono', 'Masaka'],
            'EAS' => ['Jinja', 'Mbale', 'Iganga', 'Kamuli', 'Tororo'],
            'NOR' => ['Gulu', 'Kitgum', 'Lira', 'Arua'],
            'WES' => ['Mbarara', 'Fort Portal', 'Hoima', 'Kabale', 'Kasese'],
        ];

        try {
            $this->db->beginTransaction();

            foreach ($districts as $regionCode => $names) {
                $regionId = (int) $this->db->scalar(
                    'SELECT id FROM regions WHERE code = :code',
                    ['code' => $regionCode]
                );

                if ($regionId === 0) {
                    continue;
                }

                foreach ($names as $name) {
                    $this->db->query(
                        'INSERT INTO districts (region_id, name, code) VALUES (:region, :name, :code)
                         ON DUPLICATE KEY UPDATE `name` = :set_name, `region_id` = :set_region',
                        [
                            'region'     => $regionId,
                            'name'       => $name,
                            'code'       => $regionCode . '-' . strtoupper(substr(str_replace(' ', '', $name), 0, 8)),
                            'set_name'   => $name,
                            'set_region' => $regionId,
                        ]
                    );
                }
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw new \RuntimeException(
                'Failed to seed locations: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $districtCount  = (int) $this->db->scalar('SELECT COUNT(*) FROM districts');
        $subCountyCount = (int) $this->db->scalar('SELECT COUNT(*) FROM sub_counties');

        $this->line('Locations: ' . $districtCount . ' district(s), ' . $subCountyCount . ' sub-county(s)');

        return ['districts' => $districtCount, 'sub_counties' => $subCountyCount];
    }

    /* ------------------------------------------------------------------ */
    /* System settings                                                    */
    /* ------------------------------------------------------------------ */

    private function seedSettings(string $currentSeason): int
    {
        $settings = [
            ['institution_name', 'CAHU Agribusiness Platform', 'general', 'Name shown in the header and in outbound email.', 0],
            ['institution_short_name', 'CAHU', 'general', 'Abbreviation used in compact screens.', 0],
            ['institution_tagline', 'Cooperative Agribusiness Hub Uganda', 'general', 'Strapline shown under the institution name.', 0],
            ['default_currency', 'UGX', 'general', 'Currency code used for monetary totals.', 0],
            ['default_season', $currentSeason, 'general', 'Season code marked as current on the dashboard.', 0],
            ['map_default_zoom', '7', 'map', 'Zoom level the farm map opens at.', 0],
            ['map_default_lat', '1.3733', 'map', 'Latitude the farm map centres on.', 0],
            ['map_default_lng', '32.8883', 'map', 'Longitude the farm map centres on.', 0],
            ['harvest_ready_window_days', '14', 'production', 'Days before expected harvest a garden is flagged ready.', 0],
            ['support_email', '', 'general', 'Address shown as the contact for support requests.', 0],
            ['support_phone', '', 'general', 'Telephone shown as the contact for support requests.', 0],
        ];

        try {
            $this->db->beginTransaction();

            foreach ($settings as [$key, $value, $group, $description, $secret]) {
                // setting_value is deliberately left alone on conflict: once an
                // operator has tuned a setting, re-seeding must not silently
                // revert it. The metadata columns are always refreshed so the
                // catalogue stays accurate.
                $this->db->query(
                    'INSERT INTO system_settings (setting_key, setting_value, setting_group, description, is_secret)
                     VALUES (:key, :value, :group_name, :description, :secret)
                     ON DUPLICATE KEY UPDATE
                        `setting_group` = :set_group,
                        `description`   = :set_description,
                        `is_secret`     = :set_secret',
                    [
                        'key'             => $key,
                        'value'           => $value,
                        'group_name'      => $group,
                        'description'     => $description,
                        'secret'          => $secret,
                        'set_group'       => $group,
                        'set_description' => $description,
                        'set_secret'      => $secret,
                    ]
                );
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw new \RuntimeException(
                'Failed to seed system settings: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $this->line('Settings: ' . count($settings));

        return count($settings);
    }

    /* ------------------------------------------------------------------ */
    /* Accounts                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{created: bool, skipped: bool, username: string|null}
     */
    private function ensureAdministrator(): array
    {
        $username = trim((string) ($this->options['admin_username'] ?? 'admin'));
        $email    = trim((string) ($this->options['admin_email'] ?? ''));
        $password = (string) ($this->options['admin_password'] ?? '');

        if ($username === '') {
            $username = 'admin';
        }

        if ((int) $this->db->scalar(
            'SELECT COUNT(*) FROM users WHERE username = :username',
            ['username' => $username]
        ) > 0) {
            $this->line('User "' . $username . '" already exists; leaving the account untouched.', 'warn');

            return ['created' => false, 'skipped' => true, 'username' => $username];
        }

        if (strlen($password) < 8) {
            throw new \RuntimeException('The administrator password must be at least 8 characters. Nothing was created.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('"' . $email . '" is not a valid email address. Nothing was created.');
        }

        try {
            $this->db->beginTransaction();

            $this->ensureUser($username, $email, $password, 'Super', 'Administrator', 'super_admin');

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw new \RuntimeException(
                'Failed to create the super administrator: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $this->line('Super administrator created: ' . $username, 'ok');
        $this->line('Sign in at /login and change the password when prompted.');

        return ['created' => true, 'skipped' => false, 'username' => $username];
    }

    /**
     * @return array{created: int, linked: int, accounts: array<int, array<string, string>>}
     */
    private function seedDemoAccounts(): array
    {
        $demoPassword = (string) ($this->options['demo_password'] ?? 'CahuDemo#2026');

        if (strlen($demoPassword) < 8) {
            throw new \RuntimeException('The demo password must be at least 8 characters. No demo accounts were created.');
        }

        // One account per role, so signing in as each one shows exactly what that
        // role is allowed to reach. The role code is attached through user_roles,
        // which is the only thing Auth::permissions() reads: a user with no role
        // row has no permissions at all, regardless of what the accounts table
        // says.
        $demoAccounts = [
            ['demo.manager',        'manager@cahu.test',        'Sarah',  'Nakato',    'manager'],
            ['demo.field_officer',  'officer@cahu.test',        'Musa',   'Ssemwanga', 'field_officer'],
            ['demo.agronomist',     'agronomist@cahu.test',     'Alice',  'Acheng',    'agronomist'],
            ['demo.market_officer', 'market@cahu.test',         'Acme',   'Exports',   'market_officer'],
            ['demo.farmer',         'farmer@cahu.test',         'Grace',  'Nabirye',   'farmer'],
        ];

        $created = 0;
        $linked  = 0;

        try {
            $this->db->beginTransaction();

            foreach ($demoAccounts as [$demoUser, $demoEmail, $first, $last, $roleCode]) {
                $before = (int) $this->db->scalar(
                    'SELECT COUNT(*) FROM users WHERE username = :u',
                    ['u' => $demoUser]
                );

                $this->ensureUser($demoUser, $demoEmail, $demoPassword, $first, $last, $roleCode);

                $created += (int) $this->db->scalar(
                    'SELECT COUNT(*) FROM users WHERE username = :u',
                    ['u' => $demoUser]
                ) - $before;
                $linked++;
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw new \RuntimeException(
                'Failed to create the demo accounts: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $accounts = [];

        foreach ($demoAccounts as [$demoUser, , , , $roleCode]) {
            $accounts[] = ['username' => $demoUser, 'role' => $roleCode, 'password' => $demoPassword];
        }

        $this->line('Demo accounts (' . $created . ' created, ' . $linked . ' linked to a role):', 'warn');

        return ['created' => $created, 'linked' => $linked, 'accounts' => $accounts];
    }

    /**
     * Creates one user, or returns the id of the existing one with that username.
     * A user that already exists is never reactivated or reassigned: seeding must
     * not silently undo an administrator's later changes.
     */
    private function ensureUser(
        string $username,
        string $email,
        string $password,
        string $firstName,
        string $lastName,
        string $roleCode
    ): int {
        // Resolved once, up front: a role code that does not exist would otherwise
        // become role_id 0 and trip the user_roles foreign key, or silently leave a
        // user with no permissions and no explanation.
        $roleId = (int) ($this->roleIds[$roleCode] ?? 0);

        if ($roleId === 0) {
            throw new \RuntimeException('Unknown role code "' . $roleCode . '"; cannot create the account.');
        }

        $existing = (int) $this->db->scalar(
            'SELECT id FROM users WHERE username = :username',
            ['username' => $username]
        );

        if ($existing > 0) {
            // The role is (re)asserted on an existing user, because a user with
            // no user_roles row has no permissions at all. Anything else is left
            // alone.
            $this->db->query(
                'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user, :role)',
                ['user' => $existing, 'role' => $roleId]
            );

            return $existing;
        }

        $userId = (int) $this->db->insert('users', [
            'uuid'                 => sprintf(
                '%08x-%04x-4%03x-%04x-%012x',
                random_int(0, 0xffffffff),
                random_int(0, 0xffff),
                random_int(0, 0xfff),
                random_int(0x8000, 0xbfff),
                random_int(0, 0xffffffffffff)
            ),
            'username'             => $username,
            'email'                => $email,
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'first_name'           => $firstName,
            'last_name'            => $lastName,
            'status'               => 'active',
            'must_change_password' => 1,
            'password_changed_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->db->query(
            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user, :role)',
            ['user' => $userId, 'role' => $roleId]
        );

        return $userId;
    }
}