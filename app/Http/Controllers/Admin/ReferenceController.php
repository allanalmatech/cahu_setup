<?php
declare(strict_types=1);

namespace Http\Controllers\Admin;

use Core\Audit;
use Core\Controller;
use Core\Csrf;
use Core\Database;
use Core\Flash;
use Core\Form;
use Core\HttpException;
use Core\Response;
use Services\Lookups;

/**
 * The configurable lookup sets every other screen picks from: the administrative
 * hierarchy, the activity and problem vocabularies, and the input categories.
 *
 * These tables grow through use rather than through configuration files, so the
 * screen adds to them inline. The geography is a strict five-level chain, which
 * is why its table name arrives in a form field: the level decides which table
 * is written and which parent is required, and that mapping is a fixed whitelist
 * rather than anything the request can name.
 */
final class ReferenceController extends Controller
{
    /**
     * Level => [table, parent column, parent table, label of the parent level].
     *
     * The chain is fixed: a district belongs to a region, a sub-county to a
     * district, a parish to a sub-county and a village to a parish. Spelling it
     * out once here is what lets one form write to five different tables.
     *
     * @var array<string,array{table:string,parent:?string,parent_table:?string,parent_label:string,label:string,name_max:int}>
     */
    private const LOCATIONS = [
        'region'     => ['table' => 'regions',      'parent' => null,            'parent_table' => null,            'parent_label' => '',            'label' => 'Region',     'name_max' => 80],
        'district'   => ['table' => 'districts',    'parent' => 'region_id',     'parent_table' => 'regions',      'parent_label' => 'Region',      'label' => 'District',   'name_max' => 100],
        'sub_county' => ['table' => 'sub_counties', 'parent' => 'district_id',   'parent_table' => 'districts',    'parent_label' => 'District',    'label' => 'Sub-county', 'name_max' => 100],
        'parish'     => ['table' => 'parishes',     'parent' => 'sub_county_id', 'parent_table' => 'sub_counties', 'parent_label' => 'Sub-county', 'label' => 'Parish',     'name_max' => 100],
        'village'    => ['table' => 'villages',     'parent' => 'parish_id',     'parent_table' => 'parishes',     'parent_label' => 'Parish',      'label' => 'Village',    'name_max' => 120],
    ];

    private const PROBLEM_CATEGORIES = "'pest','disease','weather','input','soil','theft','other'";

    private Lookups $lookups;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    public function index(): Response
    {
        return $this->render('admin/reference', [
            'pageTitle'     => 'Reference data',
            'regions'       => $this->lookups->regions(),
            'districts'     => $this->lookups->districts(),
            'subCounties'   => $this->lookups->subCounties(),
            'parishes'      => $this->lookups->parishes(),
            'villages'      => $this->lookups->villages(),
            'activityTypes' => $this->lookups->activityTypes(),
            'problemTypes'  => $this->lookups->problemTypes(),
            'inputCategories' => $this->lookups->inputCategories(),
            'parentOptions' => [
                'region'        => $this->lookups->regionOptions('Select region...'),
                'district'      => $this->lookups->districtOptions('Select district...'),
                'sub_county'    => $this->lookups->subCountyOptions('Select sub-county...'),
                'parish'        => $this->lookups->parishOptions('Select parish...'),
            ],
        ]);
    }

    /**
     * Adds a place to the administrative hierarchy. The level is chosen on the
     * form, so every level is written through the same screen.
     */
    public function storeLocation(): Response
    {
        $this->assertCsrf();

        $input = $this->request->all();
        $type  = $this->request->string('loc_type');

        if (!array_key_exists($type, self::LOCATIONS)) {
            return $this->backWithErrors(url('admin/reference'), ['loc_type' => 'Choose which level of the hierarchy this place belongs to.']);
        }

        $level = self::LOCATIONS[$type];
        $table = Database::safeIdentifier($level['table']);

        // Field names are prefixed per form because four add-forms share this
        // screen, and an unprefixed "name" would light up on all of them at once.
        // The name limit is the narrowest column in the chain, not the widest:
        // a 120 character village name would be refused outright by the 80
        // character regions column under strict SQL mode.
        $fields = [
            ['name' => 'loc_name', 'label' => 'Name', 'type' => Form::TEXT, 'required' => true,
             'maxlength' => $level['name_max']],
            ['name' => 'loc_code', 'label' => 'Code', 'type' => Form::TEXT, 'maxlength' => 20,
             'help' => 'Optional. Generated from the name when left blank. Stored for regions and districts, which key off it.'],
        ];

        if ($level['parent'] !== null) {
            $fields[] = [
                'name' => 'loc_parent_id',
                'label' => $level['parent_label'],
                'type' => Form::SELECT,
                'required' => true,
                'options' => Form::options(
                    $this->parentsFor($level['parent_table']),
                    'id',
                    'name',
                    'Select ' . strtolower($level['parent_label']) . '...'
                ),
                'table' => $level['parent_table'],
            ];
        }

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('admin/reference'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['loc_code']);

        $name = (string) $values['loc_name'];
        $code = (string) ($values['loc_code'] ?? '');

        // Only regions and districts carry a code column; the levels below them
        // are addressed by name.
        if ($type === 'region' || $type === 'district') {
            if ($code === '') {
                $code = $this->uniqueCode($table, $name);
            } elseif ($this->codeTaken($table, $code)) {
                return $this->backWithErrors(url('admin/reference'), ['loc_code' => 'That code is already in use for a ' . strtolower($level['label']) . '.']);
            }
        } else {
            $code = '';
        }

        $record = ['name' => $name];

        if ($level['parent'] !== null) {
            $record[$level['parent']] = (int) $values['loc_parent_id'];
        }

        if ($code !== '') {
            $record['code'] = $code;
        }

        $id = $this->db->insert($table, $record);

        Audit::log('create', 'reference', $type, $id, $name, null, $record);

        Flash::success($level['label'] . ' ' . $name . ' has been added.');

        return $this->redirect(url('admin/reference'));
    }

    public function storeActivityType(): Response
    {
        $this->assertCsrf();

        $input = $this->request->all();

        $fields = [
            ['name' => 'act_name', 'label' => 'Activity name', 'type' => Form::TEXT, 'required' => true, 'maxlength' => 80],
            ['name' => 'act_code', 'label' => 'Code', 'type' => Form::TEXT, 'maxlength' => 50,
             'help' => 'Optional. Generated from the name when left blank.'],
            ['name' => 'act_category', 'label' => 'Category', 'type' => Form::TEXT, 'maxlength' => 60,
             'help' => 'Groups the activity on the schedule, e.g. land preparation, crop care, post harvest.'],
            ['name' => 'act_photo', 'label' => 'Photo evidence required', 'type' => Form::CHECKBOX],
        ];

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('admin/reference'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['act_code', 'act_category']);

        $code = (string) ($values['act_code'] ?? '');

        if ($code === '') {
            $code = $this->uniqueCode('activity_types', (string) $values['act_name']);
        } elseif ($this->codeTaken('activity_types', $code)) {
            return $this->backWithErrors(url('admin/reference'), ['act_code' => 'That activity code is already in use.']);
        }

        $record = [
            'name'           => (string) $values['act_name'],
            'code'           => $code,
            'category'       => $values['act_category'] ?? null,
            'requires_photo' => (int) $values['act_photo'],
            'active'         => 1,
        ];

        $id = $this->db->insert('activity_types', $record);

        Audit::log('create', 'reference', 'activity_type', $id, $record['name'], null, $record);

        Flash::success('Activity type ' . $record['name'] . ' has been added.');

        return $this->redirect(url('admin/reference'));
    }

    public function storeProblemType(): Response
    {
        $this->assertCsrf();

        $input = $this->request->all();

        $fields = [
            ['name' => 'prob_name', 'label' => 'Problem name', 'type' => Form::TEXT, 'required' => true, 'maxlength' => 120],
            ['name' => 'prob_code', 'label' => 'Code', 'type' => Form::TEXT, 'maxlength' => 50,
             'help' => 'Optional. Generated from the name when left blank.'],
            ['name' => 'prob_category', 'label' => 'Category', 'type' => Form::SELECT, 'required' => true,
             'options' => Form::enumOptions(self::PROBLEM_CATEGORIES, '')],
            ['name' => 'prob_critical', 'label' => 'Escalate immediately when reported', 'type' => Form::CHECKBOX],
        ];

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('admin/reference'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['prob_code']);

        $code = (string) ($values['prob_code'] ?? '');

        if ($code === '') {
            $code = $this->uniqueCode('problem_types', (string) $values['prob_name']);
        } elseif ($this->codeTaken('problem_types', $code)) {
            return $this->backWithErrors(url('admin/reference'), ['prob_code' => 'That problem code is already in use.']);
        }

        $record = [
            'name'        => (string) $values['prob_name'],
            'code'        => $code,
            'category'    => (string) $values['prob_category'],
            'is_critical' => (int) $values['prob_critical'],
            'active'      => 1,
        ];

        $id = $this->db->insert('problem_types', $record);

        Audit::log('create', 'reference', 'problem_type', $id, $record['name'], null, $record);

        Flash::success('Problem type ' . $record['name'] . ' has been added.');

        return $this->redirect(url('admin/reference'));
    }

    public function storeInputCategory(): Response
    {
        $this->assertCsrf();

        $input = $this->request->all();

        $fields = [
            ['name' => 'cat_name', 'label' => 'Category name', 'type' => Form::TEXT, 'required' => true, 'maxlength' => 80],
            ['name' => 'cat_code', 'label' => 'Code', 'type' => Form::TEXT, 'maxlength' => 50,
             'help' => 'Optional. Generated from the name when left blank.'],
            ['name' => 'cat_description', 'label' => 'Description', 'type' => Form::TEXTAREA, 'maxlength' => 255],
        ];

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('admin/reference'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['cat_code', 'cat_description']);

        $code = (string) ($values['cat_code'] ?? '');

        if ($code === '') {
            $code = $this->uniqueCode('input_categories', (string) $values['cat_name']);
        } elseif ($this->codeTaken('input_categories', $code)) {
            return $this->backWithErrors(url('admin/reference'), ['cat_code' => 'That input category code is already in use.']);
        }

        $record = [
            'name'        => (string) $values['cat_name'],
            'code'        => $code,
            'description' => $values['cat_description'] ?? null,
            'active'      => 1,
        ];

        $id = $this->db->insert('input_categories', $record);

        Audit::log('create', 'reference', 'input_category', $id, $record['name'], null, $record);

        Flash::success('Input category ' . $record['name'] . ' has been added.');

        return $this->redirect(url('admin/reference'));
    }

    /* ------------------------------------------------------------------ */
    /* Supporting data                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * The candidate parents of a level, read from the level immediately above it.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parentsFor(?string $table): array
    {
        if ($table === null) {
            return [];
        }

        return match ($table) {
            'regions'      => $this->db->select('SELECT id, name FROM regions ORDER BY name'),
            'districts'    => $this->db->select('SELECT id, name FROM districts ORDER BY name'),
            'sub_counties' => $this->db->select('SELECT id, name FROM sub_counties ORDER BY name'),
            'parishes'     => $this->db->select('SELECT id, name FROM parishes ORDER BY name'),
            default        => throw new HttpException(400, 'That administrative level is not configured.'),
        };
    }

    /**
     * A short code taken from the name, falling back to a suffix when the
     * obvious one is already taken. The first candidate is the readable one, so
     * a code only grows a number when it has to.
     */
    private function uniqueCode(string $table, string $name): string
    {
        $table = Database::safeIdentifier($table);

        $letters = preg_replace('/[^A-Za-z]/', '', $name) ?? '';
        $base    = strtoupper(substr($letters, 0, 3));

        if ($base === '') {
            $base = 'REF';
        }

        $candidates = [
            $base,
            $base . random_int(2, 9),
            $base . random_int(10, 99),
        ];

        foreach ($candidates as $candidate) {
            if (!$this->codeTaken($table, $candidate)) {
                return $candidate;
            }
        }

        return $base . random_int(1000, 9999);
    }

    private function codeTaken(string $table, string $code): bool
    {
        $table = Database::safeIdentifier($table);

        return $this->db->scalar("SELECT 1 FROM `{$table}` WHERE code = :code LIMIT 1", ['code' => $code]) !== null;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}