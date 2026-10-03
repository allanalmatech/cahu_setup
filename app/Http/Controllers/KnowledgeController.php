<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Audit;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\Form;
use Core\HttpException;
use Core\Paginator;
use Core\Response;
use Core\Uploader;
use Core\Validator;
use Services\Lookups;

/**
 * Knowledge Centre: the advisory library the farmers are pointed at.
 *
 * A piece of content is a plain article or guide, a downloadable PDF, a picture,
 * a calendar, a video hosted elsewhere, or one of the specific guides (pest,
 * disease, fertiliser, spraying, gap analysis). Content is linked to the crop,
 * variety, farming stage and problem type it is about so the library can be
 * narrowed to the situation a farmer is actually facing.
 *
 * The slug is generated from the title and is the only public identifier, so it
 * is never taken from user input: a crafted POST could otherwise rewrite the
 * address an advisory has already been printed on. Content is unpublished before
 * it is deleted, because farmers may have been directed at a page that must
 * never silently disappear.
 */
final class KnowledgeController extends Controller
{
    private const UPLOAD_FOLDER = 'knowledge';

    /** Slug is VARCHAR(220); the title portion is kept short enough for the "-{id}" suffix. */
    private const SLUG_LENGTH = 180;

    private const CONTENT_TYPES = "'article','guide','pdf','video','image','calendar',"
        . "'pest_guide','disease_guide','fertilizer_guide','spraying_guide','gap'";

    private Lookups $lookups;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    /**
     * The advisory library. Cards rather than a table: a farmer browses by
     * recognising a title and a picture of the problem, not by reading columns.
     */
    public function index(): Response
    {
        $params  = $this->listParams(12);
        $filters = $this->filters($params);

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(k.title LIKE :s OR k.summary LIKE :s OR k.body LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['content_type' => 'k.content_type', 'category_id' => 'k.category_id', 'crop_id' => 'k.crop_id'] as $key => $column) {
            if (($filters[$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $filters[$key];
            }
        }

        if (($filters['farming_stage'] ?? '') !== '') {
            $where[]                = 'k.farming_stage = :farming_stage';
            $values['farming_stage'] = $filters['farming_stage'];
        }

        if (($filters['is_published'] ?? '') !== '') {
            $where[]                = 'k.is_published = :is_published';
            $values['is_published'] = (int) $filters['is_published'];
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM knowledge_content k' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT k.id, k.title, k.slug, k.summary, k.content_type, k.farming_stage, k.language,
                    k.view_count, k.published_at, k.created_at, k.is_published, k.file_path,
                    kc.name AS category_name, c.name AS crop_name, v.name AS variety_name
             FROM knowledge_content k
             LEFT JOIN knowledge_categories kc ON kc.id = k.category_id
             LEFT JOIN crops c ON c.id = k.crop_id
             LEFT JOIN crop_varieties v ON v.id = k.variety_id'
            . $whereSql
            . ' ORDER BY ' . $this->sortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('knowledge/index', [
            'pageTitle'  => 'Knowledge Centre',
            'rows'       => $rows,
            'paginator'  => $paginator,
            'sort'       => $params['sort'],
            'direction'  => $params['direction'],
            'categories' => $this->categoryTree(),
            'filters'    => [
                ['key' => 'content_type', 'label' => 'Type', 'options' => Form::enumOptions(self::CONTENT_TYPES, 'All types')],
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => $this->lookups->cropOptions('All crops')],
                ['key' => 'farming_stage', 'label' => 'Stage', 'options' => $this->stageOptions('All stages')],
                ['key' => 'is_published', 'label' => 'Status', 'options' => [
                    ['value' => '', 'label' => 'All'],
                    ['value' => '1', 'label' => 'Published'],
                    ['value' => '0', 'label' => 'Draft'],
                ]],
            ],
        ]);
    }

    public function create(): Response
    {
        $categoryId = $this->request->int('category_id');

        return $this->render('knowledge/form', [
            'pageTitle' => 'Add content',
            'record'    => $categoryId > 0 ? ['category_id' => $categoryId] : null,
            'sections'  => $this->sections(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $this->validateUrl($validator, 'external_url', $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('knowledge/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, $this->nullable());

        // A rejected upload must not leave a half-written advisory behind: the
        // row is only created once the file is known to have been stored.
        $file = $this->uploadFile();

        if ($file === false) {
            Flash::withInput($input);

            return $this->redirect(url('knowledge/create'));
        }

        if (is_string($file)) {
            $values['file_path'] = $file;
        }

        $values['author_id']    = $this->auth->id();
        $values['language']     = $this->language($values);
        $values['published_at'] = !empty($values['is_published']) ? date('Y-m-d H:i:s') : null;

        $created = $this->db->transaction(fn (): array => $this->insertWithSlug($values, (string) $values['title'], null));

        Audit::log('create', 'knowledge', 'knowledge_content', $created['id'], $values['title'] ?? null, null, $values);

        Flash::success(
            !empty($values['is_published'])
                ? 'The advisory has been published and is now visible in the library.'
                : 'The advisory has been saved as a draft.'
        );

        return $this->redirect(url('knowledge/' . $created['slug']));
    }

    /**
     * A single advisory, written to be read without any technical background.
     * Reading it counts as a view, which is how the most-used material is
     * identified for the field team.
     */
    public function show(string $slug): Response
    {
        $item = $this->findBySlugOrFail($slug);

        $this->db->query(
            'UPDATE knowledge_content SET view_count = view_count + 1 WHERE id = :id',
            ['id' => $item['id']]
        );

        return $this->render('knowledge/show', [
            'pageTitle' => $item['title'],
            'item'      => $item,
            'views'     => (int) $item['view_count'] + 1,
            'embedUrl'  => $this->videoEmbed((string) ($item['external_url'] ?? '')),
            'related'   => $this->related($item),
        ]);
    }

    public function edit(string $slug): Response
    {
        $item = $this->findBySlugOrFail($slug);

        return $this->render('knowledge/form', [
            'pageTitle' => 'Edit ' . $item['title'],
            'record'    => $item,
            'sections'  => $this->sections($item),
        ]);
    }

    public function update(string $slug): Response
    {
        $this->assertCsrf();

        $item   = $this->findBySlugOrFail($slug);
        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections($item), 'fields'));

        $validator = Form::validate($fields, $input);
        $this->validateUrl($validator, 'external_url', $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('knowledge/' . $slug . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, $this->nullable());
        $values['language'] = $this->language($values);

        $file = $this->uploadFile();

        if ($file === false) {
            Flash::withInput($input);

            return $this->redirect(url('knowledge/' . $slug . '/edit'));
        }

        if (is_string($file)) {
            $values['file_path'] = $file;
        }

        $wasPublished = (int) $item['is_published'] === 1;

        // Turning publication on records when the material became visible.
        // Turning it off leaves the original date alone, so the history of
        // when this advisory was live is not lost.
        if (!empty($values['is_published']) && !$wasPublished) {
            $values['published_at'] = date('Y-m-d H:i:s');
        }

        // The address only follows the title when the title actually changed,
        // so an edit to the guidance never breaks a link already handed out.
        $newSlug = (string) $values['title'] === (string) $item['title']
            ? (string) $item['slug']
            : $this->slugForUpdate($values, (int) $item['id']);

        if ($newSlug !== (string) $item['slug']) {
            $values['slug'] = $newSlug;
        }

        $this->db->update('knowledge_content', $values, 'id = :id', ['id' => $item['id']]);

        Audit::log('update', 'knowledge', 'knowledge_content', (int) $item['id'], $item['title'], $item, $values);

        Flash::success('The advisory has been updated.');

        return $this->redirect(url('knowledge/' . $newSlug));
    }

    /**
     * Retiring an advisory. It is unpublished first, so anyone still holding
     * the old link finds it withdrawn rather than being shown a dead page, and
     * the removal is recorded in the audit trail.
     */
    public function destroy(string $slug): Response
    {
        $this->assertCsrf();

        $item = $this->findBySlugOrFail($slug);

        $this->db->transaction(function () use ($item): void {
            $this->db->update(
                'knowledge_content',
                ['is_published' => 0],
                'id = :id',
                ['id' => $item['id']]
            );

            $this->db->delete('knowledge_content', 'id = :id', ['id' => $item['id']]);
        });

        Audit::log('delete', 'knowledge', 'knowledge_content', (int) $item['id'], $item['title'], $item);

        Flash::warning(
            '"' . $item['title'] . '" has been withdrawn. Farmers who were directed to it will no longer find it in the library.'
        );

        return $this->redirect(url('knowledge'));
    }

    /**
     * Insert filters not covered by the shared list parameters. The library is
     * browsed by type, crop and stage, none of which every other register needs.
     *
     * @param array<string,mixed> $params
     * @return array<string,string>
     */
    private function filters(array $params): array
    {
        $filters = [];

        foreach (['content_type', 'category_id', 'crop_id'] as $key) {
            $value = (string) ($params['filters'][$key] ?? '');

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $stage = $this->request->string('farming_stage');
        if ($stage !== '') {
            $filters['farming_stage'] = $stage;
        }

        $published = $this->request->string('is_published');
        if ($published === '0' || $published === '1') {
            $filters['is_published'] = $published;
        }

        return $filters;
    }

    private function sortClause(string $sort, string $direction): string
    {
        $allowed = ['title', 'created_at', 'view_count'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'created_at';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        return 'k.' . $column . ' ' . $order;
    }

    /**
     * The category tree with a count of what sits in each branch, so the
     * filter strip shows where the library actually has material.
     *
     * @return array<int,array{id:string,name:string,total:int,children:array<int,array{id:string,name:string,total:int}>}>
     */
    private function categoryTree(): array
    {
        $rows = $this->db->select(
            'SELECT kc.id, kc.name, kc.parent_id, COUNT(k.id) AS content_count
             FROM knowledge_categories kc
             LEFT JOIN knowledge_content k ON k.category_id = kc.id
             WHERE kc.active = 1
             GROUP BY kc.id, kc.name, kc.parent_id
             ORDER BY kc.name'
        );

        $branches = [];

        foreach ($rows as $row) {
            if ($row['parent_id'] === null) {
                $branches[(int) $row['id']] = [
                    'id'       => (string) $row['id'],
                    'name'     => (string) $row['name'],
                    'total'    => (int) $row['content_count'],
                    'children' => [],
                ];
            }
        }

        $children = [];

        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                $children[(int) $row['parent_id']][] = [
                    'id'    => (string) $row['id'],
                    'name'  => (string) $row['name'],
                    'total' => (int) $row['content_count'],
                ];
            }
        }

        foreach ($children as $parentId => $items) {
            if (isset($branches[$parentId])) {
                $branches[$parentId]['children'] = $items;
            }
        }

        ksort($branches);

        return array_values($branches);
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function stageOptions(string $placeholder = 'All stages'): array
    {
        $rows = $this->db->column(
            'SELECT DISTINCT farming_stage FROM knowledge_content
             WHERE farming_stage IS NOT NULL AND farming_stage <> \'\'
             ORDER BY farming_stage'
        );

        $options = [['value' => '', 'label' => $placeholder]];

        foreach ($rows as $row) {
            $options[] = ['value' => (string) $row, 'label' => (string) $row];
        }

        return $options;
    }

    /**
     * Other material on the same crop or in the same category, most used first.
     *
     * @param array<string,mixed> $item
     * @return array<int,array<string,mixed>>
     */
    private function related(array $item): array
    {
        $where  = ['k.id <> :id', 'k.is_published = 1'];
        $values = ['id' => $item['id']];

        $crop     = $item['crop_id'] === null ? null : (int) $item['crop_id'];
        $category = $item['category_id'] === null ? null : (int) $item['category_id'];

        if ($crop !== null && $category !== null) {
            $where[]             = '(k.crop_id = :crop OR k.category_id = :category)';
            $values['crop']      = $crop;
            $values['category']  = $category;
        } elseif ($crop !== null) {
            $where[]        = 'k.crop_id = :crop';
            $values['crop'] = $crop;
        } elseif ($category !== null) {
            $where[]             = 'k.category_id = :category';
            $values['category']  = $category;
        } else {
            $where[]         = 'k.content_type = :content_type';
            $values['content_type'] = (string) $item['content_type'];
        }

        return $this->db->select(
            'SELECT k.id, k.title, k.slug, k.summary, k.content_type, k.view_count, k.published_at,
                    c.name AS crop_name, kc.name AS category_name
             FROM knowledge_content k
             LEFT JOIN crops c ON c.id = k.crop_id
             LEFT JOIN knowledge_categories kc ON kc.id = k.category_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY k.view_count DESC, k.published_at DESC, k.title
             LIMIT 4',
            $values
        );
    }

    /**
     * A safe embed address for the well known video hosts, or null when the
     * link points somewhere that must not be framed. Only an https address
     * built from a validated video id is ever handed to the template, so a
     * crafted external_url cannot inject markup into the advisory.
     */
    private function videoEmbed(string $url): ?string
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if (preg_match('#^/embed/([A-Za-z0-9_-]{6,})#', $path, $matches) === 1) {
                return 'https://www.youtube.com/embed/' . $matches[1];
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $id = (string) ($query['v'] ?? '');

            return preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) === 1 ? 'https://www.youtube.com/embed/' . $id : null;
        }

        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            $id = ltrim($path, '/');

            return preg_match('/^[A-Za-z0-9_-]{6,}$/', $id) === 1 ? 'https://www.youtube.com/embed/' . $id : null;
        }

        if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true)) {
            $id = trim($path, '/');

            return preg_match('/^\d{6,}$/', $id) === 1 ? 'https://player.vimeo.com/video/' . $id : null;
        }

        return null;
    }

    /**
     * A link is only useful to a farmer if it is complete, so a bare word is
     * rejected rather than stored as an address that will never resolve.
     *
     * @param array<string,mixed> $values
     */
    private function validateUrl(Validator $validator, string $field, array $values): void
    {
        $value = trim((string) ($values[$field] ?? ''));

        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return;
        }

        $validator->addError($field, 'The link must be a full web address, starting with http:// or https://.');
    }

    /**
     * Inserts the advisory with a server generated slug. A title that collides
     * is first stored under a throwaway unique slug and renamed once the record
     * id is known; the unique key is what actually stops two advisories sharing
     * an address.
     *
     * @param array<string,mixed> $values
     * @return array{id:int,slug:string}
     */
    private function insertWithSlug(array $values, string $title, ?int $ignoreId): array
    {
        $base        = $this->baseSlug($title);
        $provisional = false;

        $slug = $base;

        if ($this->slugTaken($base, $ignoreId)) {
            $slug        = $base . '-' . bin2hex(random_bytes(4));
            $provisional = true;
        }

        $values['slug'] = $slug;
        $id             = $this->db->insert('knowledge_content', $values);

        if ($provisional) {
            $final = $this->baseSlug($title) . '-' . $id;

            $this->db->update('knowledge_content', ['slug' => $final], 'id = :id', ['id' => $id]);
            $slug = $final;
        }

        return ['id' => $id, 'slug' => $slug];
    }

    private function baseSlug(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, self::SLUG_LENGTH);
        $slug = trim($slug, '-');

        return $slug === '' ? 'advisory' : $slug;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        $sql    = 'SELECT 1 FROM knowledge_content WHERE slug = :slug';
        $params = ['slug' => $slug];

        if ($ignoreId !== null) {
            $sql            .= ' AND id <> :id';
            $params['id']    = $ignoreId;
        }

        return $this->db->scalar($sql . ' LIMIT 1', $params) !== null;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function slugForUpdate(array $values, int $id): string
    {
        $base = $this->baseSlug((string) $values['title']);

        return $this->slugTaken($base, $id) ? $base . '-' . $id : $base;
    }

    /**
     * Stores the attached file. Only PDF and picture uploads are accepted,
     * which is what the document handler verifies; anything else is refused
     * before a row is written.
     *
     * @return string|false|null the stored path, false when the upload was rejected, null when none was sent
     */
    private function uploadFile(): string|false|null
    {
        if ($this->request->file('file') === null) {
            return null;
        }

        $result = $this->uploader()->document(
            'file',
            self::UPLOAD_FOLDER,
            (int) $this->config('uploads.max_doc_kb', 10240)
        );

        if ($result['success'] !== true) {
            Flash::error((string) ($result['error'] ?? 'The file could not be uploaded.'));

            return false;
        }

        return (string) $result['path'];
    }

    private function uploader(): Uploader
    {
        return new Uploader(
            (string) $this->config('uploads.path'),
            (string) $this->config('uploads.url')
        );
    }

    /**
     * The language column is NOT NULL, so a cleared field falls back to English
     * rather than writing an empty string that nothing can interpret.
     *
     * @param array<string,mixed> $values
     */
    private function language(array $values): string
    {
        $code = strtolower(trim((string) ($values['language'] ?? '')));

        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code) === 1 ? $code : 'en';
    }

    /**
     * @return array<int,string>
     */
    private function nullable(): array
    {
        return ['category_id', 'summary', 'body', 'external_url', 'crop_id', 'variety_id', 'farming_stage', 'problem_type_id'];
    }

    /**
     * @param array<string,mixed>|null $record
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(?array $record = null): array
    {
        return [
            [
                'title'  => 'The advisory',
                'fields' => [
                    ['name' => 'slug', 'label' => 'Address', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Generated from the title. Advisories already printed keep this address unchanged.'],
                    ['name' => 'title', 'label' => 'Title', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6,
                     'maxlength' => 200, 'placeholder' => 'e.g. How to spot and control coffee berry borer'],
                    ['name' => 'content_type', 'label' => 'Type of material', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions(self::CONTENT_TYPES, '')],
                    ['name' => 'category_id', 'label' => 'Category', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->categoryOptions(), 'table' => 'knowledge_categories'],
                    ['name' => 'summary', 'label' => 'Short summary', 'type' => Form::TEXT, 'colspan' => 12,
                     'maxlength' => 500,
                     'help' => 'One or two plain sentences. This is what a farmer reads before deciding to open the advisory.'],
                    ['name' => 'body', 'label' => 'The guidance', 'type' => Form::TEXTAREA, 'colspan' => 12, 'rows' => 14,
                     'help' => 'Write it as you would speak it. Short sentences, no abbreviations, and say what to do step by step.'],
                ],
            ],
            [
                'title'  => 'What it applies to',
                'fields' => [
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions('Not crop specific'), 'table' => 'crops'],
                    ['name' => 'variety_id', 'label' => 'Variety', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->varietyOptions('Not variety specific'), 'table' => 'crop_varieties'],
                    ['name' => 'farming_stage', 'label' => 'Farming stage', 'type' => Form::TEXT, 'colspan' => 6, 'maxlength' => 80,
                     'placeholder' => 'e.g. flowering, after harvest, nursery stage',
                     'help' => 'Leave empty when the guidance is useful at any time of year.'],
                    ['name' => 'problem_type_id', 'label' => 'Problem it solves', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->problemTypeOptions('Not problem specific'), 'table' => 'problem_types'],
                ],
            ],
            [
                'title'  => 'Attachment and publication',
                'fields' => [
                    ['name' => 'file', 'label' => 'Attached file', 'type' => Form::FILE, 'colspan' => 6,
                     'accept' => 'application/pdf,image/jpeg,image/png', 'filetype' => 'document',
                     'existing' => (string) ($record['file_path'] ?? ''),
                     'help' => 'PDF, JPEG or PNG, up to 10 MB. Used for PDF leaflets and pictures.'],
                    ['name' => 'external_url', 'label' => 'Video or document link', 'type' => Form::TEXT, 'colspan' => 6,
                     'maxlength' => 500, 'placeholder' => 'https://www.youtube.com/watch?v=...',
                     'help' => 'For videos hosted elsewhere. Paste the full web address.'],
                    ['name' => 'language', 'label' => 'Language', 'type' => Form::TEXT, 'colspan' => 6, 'maxlength' => 10,
                     'default' => 'en', 'help' => 'Language code the material is written in, e.g. en or lug.'],
                    ['name' => 'is_published', 'label' => 'Publish in the library', 'type' => Form::CHECKBOX, 'colspan' => 6,
                     'default' => 1,
                     'help' => 'Unpublished material stays a draft and is not shown to farmers.'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function categoryOptions(): array
    {
        return Form::options($this->lookups->knowledgeCategories(), 'id', 'name', 'No category');
    }

    /**
     * @return array<string,mixed>
     */
    private function findBySlugOrFail(string $slug): array
    {
        $item = $this->db->selectOne(
            'SELECT k.*, kc.name AS category_name, c.name AS crop_name, v.name AS variety_name,
                    pt.name AS problem_name, pt.category AS problem_category, u.username AS author_name
             FROM knowledge_content k
             LEFT JOIN knowledge_categories kc ON kc.id = k.category_id
             LEFT JOIN crops c ON c.id = k.crop_id
             LEFT JOIN crop_varieties v ON v.id = k.variety_id
             LEFT JOIN problem_types pt ON pt.id = k.problem_type_id
             LEFT JOIN users u ON u.id = k.author_id
             WHERE k.slug = :slug LIMIT 1',
            ['slug' => $slug]
        );

        if ($item === null) {
            throw new HttpException(404, 'That advisory could not be found. It may have been withdrawn.');
        }

        return $item;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}
