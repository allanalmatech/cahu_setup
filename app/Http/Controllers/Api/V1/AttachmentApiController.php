<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Audit;
use Core\Response;

/**
 * Photo evidence uploaded by the field app.
 *
 * A phone that has been offline all day has a gallery full of pictures and no
 * way to send them. It queues the inspection first, then uploads the pictures
 * against the code that came back, so the evidence survives a round trip the
 * app may not survive.
 *
 * The payload is base64 inside the JSON body rather than a multipart upload.
 * That is deliberate: the file travels inside the same offline queue as
 * everything else, so a phone that goes offline mid-upload retries the photo
 * the same way it retries the inspection, with no second transport to get
 * wrong. The cost is a size ceiling, which is why the limit is enforced
 * explicitly rather than left to php.ini.
 */
final class AttachmentApiController extends ApiController
{
    /** Decoded payload ceiling for a single image. */
    private const MAX_BYTES = 4 * 1024 * 1024;

    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
    ];

    private const PARENTS = [
        'farmer' => 'farmers.edit',
        'garden' => 'gardens.manage',
        'inspection' => 'inspections.manage',
        'problem' => 'problems.manage',
        'activity' => 'activities.manage',
        'spraying' => 'spraying.manage',
        'harvest' => 'harvest.manage',
    ];

    /**
     * Table and natural code column per parent type.
     *
     * Every table here except `farmers` carries a `farmer_id`, which is what the
     * ownership check reads. Kept next to `PARENTS` deliberately: an attachment
     * type that is accepted but not resolvable would silently store a photo
     * nobody can find again.
     */
    private const PARENT_TABLES = [
        'farmer'     => ['farmers', 'farmer_code'],
        'garden'     => ['gardens', 'garden_code'],
        'inspection' => ['inspections', 'inspection_code'],
        'problem'    => ['problem_reports', 'problem_code'],
        'activity'   => ['crop_activities', 'activity_code'],
        'spraying'   => ['spraying_records', 'spray_code'],
        'harvest'    => ['harvest_records', 'harvest_code'],
    ];

    /**
     * POST /api/v1/attachments
     */
    public function store(): Response
    {
        $clientUuid = $this->clientUuid();

        if ($clientUuid === null) {
            return $this->fail('A client_uuid is required so a repeated upload is not duplicated.', 422);
        }

        // Replayed upload: the same photo arriving twice resolves to the row the
        // first attempt created instead of being written again.
        $existing = $this->db->selectOne(
            'SELECT id, file_path, parent_type FROM attachments WHERE client_uuid = :uuid LIMIT 1',
            ['uuid' => $clientUuid]
        );

        if ($existing !== null) {
            return $this->ok([
                'id'          => (int) $existing['id'],
                'file_path'   => (string) $existing['file_path'],
                'parent_type' => (string) $existing['parent_type'],
                'duplicate'   => true,
            ]);
        }

        $parentType = $this->request->string('parent_type');

        if (!array_key_exists($parentType, self::PARENTS)) {
            return $this->fail('parent_type must be one of: ' . implode(', ', array_keys(self::PARENTS)) . '.', 422);
        }

        $parentCode = trim((string) $this->request->input('parent_code', ''));
        $parentId   = $this->request->int('parent_id');
        $parentId   = $parentId !== null && $parentId > 0 ? $parentId : null;

        $resolvedId = $parentId ?? $this->resolveParentId($parentType, $parentCode);

        // Checked before the bytes are read: an unauthorised caller should not
        // be able to make the server decode a 4 MB body first and only then be
        // told no.
        $denied = $this->assertMayAttach($parentType, $resolvedId);

        if ($denied !== null) {
            return $this->fail($denied, 403);
        }

        $base64 = trim((string) $this->request->input('data_base64', ''));

        if ($base64 === '') {
            return $this->fail('No image data was received.', 422);
        }

        // The client may send a data: URL prefix, which is what a base64
        // encoder on the device produces.
        if (str_contains($base64, ',')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $binary = base64_decode($base64, true);

        if ($binary === false) {
            return $this->fail('The image data could not be decoded.', 422);
        }

        $size = strlen($binary);

        if ($size === 0) {
            return $this->fail('The image was empty.', 422);
        }

        if ($size > self::MAX_BYTES) {
            return $this->fail('The image is larger than the ' . (int) (self::MAX_BYTES / 1024 / 1024) . ' MB limit.', 413);
        }

        $mime = $this->detectMime($binary);

        if (!isset(self::ALLOWED[$mime])) {
            return $this->fail('Only JPEG, PNG, WebP and HEIC images are accepted.', 422);
        }

        $directory = $this->uploadDirectory();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->fail('The upload directory is not writable.', 500);
        }

        // The name is generated server-side from the client uuid, so a hostile
        // original_name can never influence the path on disk.
        $name = $clientUuid . '.' . self::ALLOWED[$mime];
        $path = $directory . '/' . $name;

        if (@file_put_contents($path, $binary) === false) {
            return $this->fail('The image could not be written to disk.', 500);
        }

        @chmod($path, 0644);

        $id = $this->db->insert('attachments', [
            'uuid'          => sprintf(
                '%08x-%04x-4%03x-%04x-%012x',
                random_int(0, 0xffffffff),
                random_int(0, 0xffff),
                random_int(0, 0xfff),
                random_int(0x8000, 0xbfff),
                random_int(0, 0xffffffffffff)
            ),
            'client_uuid'   => $clientUuid,
            'parent_type'   => $parentType,
            'parent_id'     => $resolvedId,
            'parent_code'   => $parentCode === '' ? null : mb_substr($parentCode, 0, 30),
            'file_path'     => $this->publicPath($name),
            'original_name' => mb_substr(trim((string) $this->request->input('original_name', '')), 0, 255) ?: null,
            'mime_type'     => $mime,
            'file_size'     => $size,
            'caption'       => mb_substr(trim((string) $this->request->input('caption', '')), 0, 255) ?: null,
            'latitude'      => $this->coordinate('latitude'),
            'longitude'     => $this->coordinate('longitude'),
            'captured_at'   => $this->capturedAt(),
            'uploaded_by'   => $this->auth->id(),
        ]);

        Audit::log('create', 'attachments', 'attachment', $id, $parentCode, null, [
            'parent_type' => $parentType,
            'source'      => 'mobile',
        ]);

        return $this->created([
            'id'          => $id,
            'file_path'   => $this->publicPath($name),
            'parent_type' => $parentType,
            'parent_id'   => $resolvedId,
            'duplicate'   => false,
        ]);
    }

    /**
     * GET /api/v1/attachments?parent_type=inspection&parent_code=INS-0001
     */
    public function index(): Response
    {
        $parentType = $this->request->string('parent_type');

        if ($parentType === '' || !array_key_exists($parentType, self::PARENT_TABLES)) {
            return $this->fail('Provide a valid parent_type.', 422);
        }

        $values = ['type' => $parentType];
        $where  = ['a.parent_type = :type'];

        $code = $this->request->string('parent_code');

        if ($code !== '') {
            $where[]        = 'a.parent_code = :code';
            $values['code'] = $code;
        } else {
            $id = $this->request->int('parent_id');

            if ($id === null || $id <= 0) {
                return $this->fail('Provide a parent_code or a parent_id.', 422);
            }

            $where[]       = 'a.parent_id = :id';
            $values['id']  = $id;
        }

        // Scoped the same way the parent record is: a farmer may only read the
        // evidence attached to their own gardens.
        if ($this->auth->farmerId() !== null) {
            $where[] = 'a.parent_id IN (SELECT g.id FROM gardens g WHERE g.farmer_id = :farmer)';
            $values['farmer'] = $this->auth->farmerId();
        } elseif ($this->auth->officerId() !== null && !$this->auth->isSuperAdmin()) {
            $where[] = 'a.parent_id IN (SELECT g.id FROM gardens g WHERE g.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :officer))';
            $values['officer'] = $this->auth->officerId();
        }

        $rows = $this->db->select(
            'SELECT a.id, a.parent_type, a.parent_id, a.parent_code, a.file_path,
                    a.original_name, a.mime_type, a.file_size, a.caption,
                    a.latitude, a.longitude, a.captured_at, a.created_at
             FROM attachments a
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY a.created_at DESC
             LIMIT 200',
            $values
        );

        return $this->ok(['attachments' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /**
     * Maps a parent type to the table and natural code column, so a photo taken
     * against GDN-000001 lands on the right garden row.
     */
    private function resolveParentId(string $parentType, string $code): ?int
    {
        if ($code === '') {
            return null;
        }

        if (!array_key_exists($parentType, self::PARENT_TABLES)) {
            return null;
        }

        [$table, $column] = self::PARENT_TABLES[$parentType];

        $id = $this->db->scalar(
            'SELECT id FROM ' . $table . ' WHERE ' . $column . ' = :code LIMIT 1',
            ['code' => $code]
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Where the bytes land on disk.
     *
     * This reads `uploads.path` rather than walking up with `dirname(__DIR__, 4)`,
     * which resolved to `app/storage/uploads` -- a second, unserved copy of the
     * upload tree that the documented backup path (`php/storage/uploads`) does
     * not cover. Going through the config also means UPLOAD_PATH is honoured, so
     * a deployment that puts uploads on a mounted volume keeps working.
     */
    private function uploadDirectory(): string
    {
        return rtrim((string) $this->config('uploads.path'), '/') . '/mobile';
    }

    /**
     * The stored `file_path`, in the same shape `Uploader` produces.
     *
     * Views render `file_path` straight into an `href`, so it has to be the
     * public URL prefix, not a bare relative path. A stored `mobile/x.jpg`
     * resolves against the current route and 404s; `/storage/uploads/mobile/x.jpg`
     * is served.
     */
    private function publicPath(string $name): string
    {
        return rtrim((string) $this->config('uploads.url'), '/') . '/mobile/' . $name;
    }

    /**
     * Confirms the caller may attach a photo to this parent, or explains why not.
     *
     * Two separate questions, and both matter:
     *
     *  1. Does the caller hold the manage permission for this record type at all?
     *  2. If the caller is a farmer, is the parent one of their own records?
     *
     * Without (1) a market officer could attach evidence to inspections; without
     * (2) a farmer could attach a photo to another farmer's inspection by sending
     * its code, since the code is otherwise just a string the client supplies.
     * The parent's `farmer_id` is looked up rather than trusted from the request.
     *
     * Returns null when the upload is allowed, or the message to refuse with.
     */
    private function assertMayAttach(string $parentType, ?int $parentId): ?string
    {
        $farmerId = $this->auth->farmerId();

        if ($farmerId === null) {
            // Staff: the type's manage permission is the whole gate. An
            // unresolved parent is allowed, because a photo can be uploaded
            // before the record it belongs to has come back from a sync.
            if (!$this->auth->isSuperAdmin() && !$this->auth->can(self::PARENTS[$parentType])) {
                return 'You do not have permission to attach files to a ' . $parentType . ' record.';
            }

            return null;
        }

        // A farmer's own farmer record is the one place the parent is not keyed
        // by farmer_id, so it is matched on the primary key.
        if ($parentType === 'farmer') {
            return $parentId === $farmerId
                ? null
                : 'You can only attach files to your own records.';
        }

        if ($parentId === null) {
            return 'The record this file belongs to could not be found.';
        }

        $owner = $this->db->scalar(
            'SELECT farmer_id FROM ' . self::PARENT_TABLES[$parentType] . ' WHERE id = :id LIMIT 1',
            ['id' => $parentId]
        );

        return $owner !== null && (int) $owner === $farmerId
            ? null
            : 'You can only attach files to your own records.';
    }

    /**
     * The declared content type is not trusted: the bytes decide.
     */
    private function detectMime(string $binary): string
    {
        $info = @getimagesizefromstring($binary);

        if (is_array($info) && isset($info['mime']) && is_string($info['mime'])) {
            return strtolower($info['mime']);
        }

        return 'application/octet-stream';
    }

    private function coordinate(string $key): ?float
    {
        $value = $this->request->input($key);

        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number === 0.0 ? null : $number;
    }

    private function capturedAt(): ?string
    {
        $raw = trim((string) $this->request->input('captured_at', ''));

        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
