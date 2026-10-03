<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Controller;
use Core\HttpException;
use Core\Paginator;
use Core\Response;

/**
 * Shared behaviour for the REST API.
 *
 * Three things are handled here rather than repeated in every endpoint:
 *
 *  1. The response envelope, so the mobile client has one shape to parse for
 *     success, failure and pagination.
 *  2. Row scoping. A token belongs to a user, and a user may only ever see the
 *     farmers they are responsible for. Every list endpoint filters through
 *     scopeWhere() and every single record passes through assertVisible().
 *  3. Offline idempotency. A phone that loses signal mid-request retries the
 *     same call, so a create carrying a client_uuid must return the record
 *     that already exists instead of inserting a second one.
 *
 * No CSRF check is applied here: the API is authenticated with a bearer token
 * rather than a session cookie, so it is not vulnerable to CSRF, and requiring
 * a token would break the mobile client.
 */
abstract class ApiController extends Controller
{
    /**
     * Successful response envelope.
     */
    protected function ok(mixed $data = null, array $meta = [], int $status = 200): Response
    {
        $payload = ['success' => true, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return Response::json($payload, $status);
    }

    protected function created(array $data): Response
    {
        return $this->ok($data, [], 201);
    }

    /**
     * Failure envelope. Field errors are keyed by field name so the client can
     * attach them to the right input.
     */
    protected function fail(string $message, int $status = 400, array $errors = []): Response
    {
        $payload = ['success' => false, 'message' => $message];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return Response::json($payload, $status);
    }

    protected function validationFailed(array $errors): Response
    {
        return $this->fail('Please correct the highlighted fields.', 422, $errors);
    }

    protected function unauthorized(string $message = 'Sign in to continue.'): Response
    {
        return $this->fail($message, 401);
    }

    protected function notFound(string $message = 'Record not found.'): Response
    {
        return $this->fail($message, 404);
    }

    /**
     * Wraps a row set with pagination metadata the client can page through.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    protected function paginate(array $rows, int $total, array $params, string $key = 'data'): Response
    {
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        return $this->ok(
            [$key => $rows],
            [
                'total'    => $paginator->total,
                'page'     => $paginator->page,
                'per_page' => $paginator->perPage,
                'last_page' => $paginator->lastPage,
            ]
        );
    }

    /**
     * The client-generated temporary id used to make offline creates safe.
     *
     * Only a well-formed UUID is accepted, so the value cannot be used to probe
     * for records created by other devices.
     */
    protected function clientUuid(): ?string
    {
        $uuid = trim((string) $this->request->input('client_uuid', ''));

        if ($uuid === '') {
            return null;
        }

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        return preg_match($pattern, $uuid) === 1 ? strtolower($uuid) : null;
    }

    /**
     * Looks up a record already created with this client id.
     *
     * @return array<string,mixed>|null
     */
    protected function findByClientUuid(string $table, string $columns = '*'): ?array
    {
        $uuid = $this->clientUuid();

        if ($uuid === null) {
            return null;
        }

        $row = $this->db->selectOne(
            sprintf('SELECT %s FROM %s WHERE client_uuid = :uuid LIMIT 1', $columns, $table),
            ['uuid' => $uuid]
        );

        // A record created by a retry must not be shown to a user who is not
        // entitled to it, so visibility is checked before the row is returned.
        if ($row !== null && array_key_exists('farmer_id', $row)) {
            $this->assertVisible($row);

            return $row;
        }

        return $row;
    }

    /**
     * Visibility scope for list queries.
     *
     * Returns a fragment beginning with " AND " so it can be appended to an
     * existing WHERE clause. A plain manager sees everything.
     *
     * @param array<string,mixed> $values
     */
    protected function scopeWhere(array &$values, string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null) {
            $values['scope_farmer'] = $farmerId;

            return ' AND ' . $prefix . 'farmer_id = :scope_farmer';
        }

        if ($officerId !== null) {
            $values['scope_officer'] = $officerId;

            return ' AND ' . $prefix . 'farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)';
        }

        return '';
    }

    /**
     * Rejects access to a record outside the caller's scope.
     *
     * A 404 rather than a 403 is deliberate: a farmer must not be able to tell
     * that another farmer's record exists by probing identifiers.
     *
     * @param array<string,mixed> $row
     */
    protected function assertVisible(array $row): void
    {
        if (!array_key_exists('farmer_id', $row)) {
            return;
        }

        $farmerId = $this->auth->farmerId();

        if ($farmerId !== null && (int) $row['farmer_id'] !== $farmerId) {
            throw new HttpException(404, 'Record not found.');
        }
    }
}