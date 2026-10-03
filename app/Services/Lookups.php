<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Core\Form;

/**
 * Cached reference data.
 *
 * Screens need the same small lists constantly (regions, crops, seasons, input
 * categories). Reading them once per request keeps register pages down to a
 * single extra query for all of their filter dropdowns.
 */
final class Lookups
{
    /** @var array<string,array<int,array<string,mixed>>> */
    private static array $cache = [];

    public function __construct(private Database $db)
    {
    }

    private function fetch(string $key, string $sql, array $params = []): array
    {
        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = $this->db->select($sql, $params);
        }

        return self::$cache[$key];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * SQL expression resolving an activity_type string to its display name.
     *
     * crop_activities.activity_type is stored as free text so a planting can be
     * scheduled even when activity_types has not been populated yet. Templates
     * normally hold the code ('first_weeding'), but historic rows may hold the
     * label, so both are tried before falling back to the raw value.
     *
     * Written as a correlated subquery rather than a join so a row can never be
     * duplicated by two matching activity_types entries.
     */
    public static function activityNameSql(string $alias = 'a'): string
    {
        return "COALESCE(
                    (SELECT at1.name FROM activity_types at1 WHERE at1.code = {$alias}.activity_type LIMIT 1),
                    (SELECT at2.name FROM activity_types at2 WHERE at2.name = {$alias}.activity_type LIMIT 1),
                    {$alias}.activity_type
                )";
    }

    /**
     * Turns an activity type or status token into a readable label for views
     * that have no lookup row to join against.
     */
    public static function label(string $token): string
    {
        return ucwords(str_replace('_', ' ', $token));
    }

    public function regions(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM regions' . ($activeOnly ? '' : '') . ' ORDER BY name';

        return $this->fetch('regions', $sql);
    }

    public function districts(): array
    {
        return $this->fetch('districts', 'SELECT d.*, r.name AS region_name FROM districts d
            INNER JOIN regions r ON r.id = d.region_id ORDER BY r.name, d.name');
    }

    public function subCounties(): array
    {
        return $this->fetch('sub_counties', 'SELECT sc.*, d.name AS district_name FROM sub_counties sc
            INNER JOIN districts d ON d.id = sc.district_id ORDER BY d.name, sc.name');
    }

    public function parishes(): array
    {
        return $this->fetch('parishes', 'SELECT p.*, sc.name AS district_name, sc.name AS sub_county_name FROM parishes p
            INNER JOIN sub_counties sc ON sc.id = p.sub_county_id ORDER BY sc.name, p.name');
    }

    public function villages(): array
    {
        return $this->fetch('villages', 'SELECT v.*, p.name AS parish_name, sc.name AS district_name FROM villages v
            INNER JOIN parishes p ON p.id = v.parish_id
            INNER JOIN sub_counties sc ON sc.id = p.sub_county_id
            ORDER BY sc.name, p.name, v.name');
    }

    public function crops(): array
    {
        return $this->fetch('crops', "SELECT * FROM crops WHERE status = 'active' ORDER BY name");
    }

    public function varieties(): array
    {
        return $this->fetch('varieties', "SELECT v.*, c.name AS crop_name FROM crop_varieties v
            INNER JOIN crops c ON c.id = v.crop_id
            WHERE v.status = 'active' AND c.status = 'active' ORDER BY c.name, v.name");
    }

    public function seasons(): array
    {
        return $this->fetch('seasons', 'SELECT * FROM seasons ORDER BY start_date DESC');
    }

    public function inputCategories(): array
    {
        return $this->fetch('input_categories', 'SELECT * FROM input_categories WHERE active = 1 ORDER BY name');
    }

    public function problemTypes(): array
    {
        return $this->fetch('problem_types', 'SELECT * FROM problem_types WHERE active = 1 ORDER BY category, name');
    }

    public function activityTypes(): array
    {
        return $this->fetch('activity_types', 'SELECT * FROM activity_types WHERE active = 1 ORDER BY name');
    }

    public function knowledgeCategories(): array
    {
        return $this->fetch('knowledge_categories', 'SELECT * FROM knowledge_categories WHERE active = 1 ORDER BY name');
    }

    /* ------------------------------------------------------------------ */
    /* Option builders                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array{value:string,label:string}>
     */
    public function regionOptions(string $placeholder = 'All regions'): array
    {
        return Form::options($this->regions(), 'id', 'name', $placeholder);
    }

    public function districtOptions(string $placeholder = 'All districts'): array
    {
        return Form::options($this->districts(), 'id', 'name', $placeholder);
    }

    public function subCountyOptions(string $placeholder = 'All sub-counties'): array
    {
        return Form::options($this->subCounties(), 'id', 'name', $placeholder);
    }

    public function parishOptions(string $placeholder = 'All parishes'): array
    {
        return Form::options($this->parishes(), 'id', 'name', $placeholder);
    }

    public function villageOptions(string $placeholder = 'All villages'): array
    {
        return Form::options($this->villages(), 'id', 'name', $placeholder);
    }

    public function cropOptions(string $placeholder = 'Select crop...'): array
    {
        return Form::options($this->crops(), 'id', 'name', $placeholder);
    }

    public function varietyOptions(string $placeholder = 'Select variety...'): array
    {
        return Form::options($this->varieties(), 'id', 'name', $placeholder);
    }

    public function seasonOptions(string $placeholder = 'Select season...'): array
    {
        $options = $placeholder === '' ? [] : [['value' => '', 'label' => $placeholder]];

        foreach ($this->seasons() as $season) {
            $options[] = ['value' => (string) $season['id'], 'label' => $season['season_code'] . ' - ' . $season['name']];
        }

        return $options;
    }

    public function inputCategoryOptions(string $placeholder = 'All categories'): array
    {
        return Form::options($this->inputCategories(), 'id', 'name', $placeholder);
    }

    public function problemTypeOptions(string $placeholder = 'Select problem type...'): array
    {
        return Form::options($this->problemTypes(), 'id', 'name', $placeholder);
    }

    public function activityTypeOptions(string $placeholder = 'Select activity...'): array
    {
        return Form::options($this->activityTypes(), 'id', 'name', $placeholder);
    }

    /**
     * Officers for assignment dropdowns.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function officerOptions(string $placeholder = 'Unassigned'): array
    {
        return Form::options(
            $this->fetch('officers', "SELECT id, officer_code, full_name FROM field_officers WHERE status = 'active' ORDER BY full_name"),
            'id',
            'full_name',
            $placeholder
        );
    }

    /**
     * Farmers for assignment dropdowns. Bounded so a large register cannot be
     * pulled into a dropdown; a type-ahead is used beyond this limit.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function farmerOptions(string $placeholder = 'Select farmer...', int $limit = 500): array
    {
        return Form::options(
            $this->fetch('farmers_' . $limit,
                "SELECT id, farmer_code, full_name FROM farmers
                 WHERE deleted_at IS NULL AND status = 'active' ORDER BY full_name LIMIT {$limit}"),
            'id',
            'full_name',
            $placeholder
        );
    }

    /**
     * Gardens, optionally narrowed to one farmer.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function gardenOptions(?int $farmerId = null, string $placeholder = 'Select garden...', int $limit = 500): array
    {
        $rows = $this->fetch(
            'gardens_' . ($farmerId ?? 0) . '_' . $limit,
            "SELECT g.id, g.garden_code, g.name, f.full_name FROM gardens g
             INNER JOIN farmers f ON f.id = g.farmer_id
             WHERE g.deleted_at IS NULL AND g.status <> 'abandoned'
             " . ($farmerId === null ? '' : 'AND g.farmer_id = :farmer ')
             . "ORDER BY f.full_name, g.name LIMIT {$limit}",
            $farmerId === null ? [] : ['farmer' => $farmerId]
        );

        $options = $placeholder === '' ? [] : [['value' => '', 'label' => $placeholder]];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => $row['garden_code'] . ' - ' . $row['name'] . ' (' . $row['full_name'] . ')',
            ];
        }

        return $options;
    }
}
