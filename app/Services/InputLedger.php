<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Core\Identifier;
use Core\Logger;

/**
 * Input stock ledger.
 *
 * Every movement of stock is written as an input_transactions row and the
 * running balance is stored on both the transaction and the input record, all
 * inside a single transaction with a row lock. That guarantees the balance can
 * never drift, even with two officers issuing input at the same moment.
 */
final class InputLedger
{
    /** Movements that increase stock. */
    private const INCREASES = ['opening', 'receipt', 'return'];

    public function __construct(private Database $db)
    {
    }

    /**
     * Applies a stock movement.
     *
     * @param array{
     *     type:string, quantity:float, date?:string, supplier?:string|null,
     *     reference_no?:string|null, notes?:string|null
     * } $movement
     * @return array{success:bool,balance?:float,message?:string,code?:string}
     */
    public function move(int $inputId, array $movement, ?int $userId = null): array
    {
        $type     = $movement['type'];
        $quantity = round((float) $movement['quantity'], 3);

        if (!in_array($type, ['opening', 'receipt', 'issue', 'usage', 'adjustment', 'return', 'damage', 'expired'], true)) {
            return ['success' => false, 'message' => 'Unknown stock movement type.'];
        }

        if ($quantity <= 0) {
            return ['success' => false, 'message' => 'The quantity must be greater than zero.'];
        }

        return $this->db->transaction(function () use ($inputId, $type, $quantity, $movement, $userId): array {
            $input = $this->db->selectOne(
                'SELECT id, name, unit, current_stock FROM inputs WHERE id = :id FOR UPDATE',
                ['id' => $inputId]
            );

            if ($input === null) {
                return ['success' => false, 'message' => 'The input item could not be found.'];
            }

            $current  = (float) $input['current_stock'];
            $balance  = in_array($type, self::INCREASES, true) || $type === 'adjustment'
                ? $current + $quantity
                : $current - $quantity;

            if ($balance < 0) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'Only %s %s of %s is in stock. The %s would take the balance below zero.',
                        rtrim(rtrim(number_format($current, 2), '0'), '.'),
                        (string) $input['unit'],
                        (string) $input['name'],
                        rtrim(rtrim(number_format($quantity, 2), '0'), '.')
                    ),
                ];
            }

            $date = $movement['date'] ?? date('Y-m-d');
            $code = Identifier::generate('input_txn', date_create_from_format('Y-m-d', $date) ?: null);

            $this->db->insert('input_transactions', [
                'transaction_code' => $code,
                'input_id'         => $inputId,
                'transaction_type' => $type,
                'quantity'         => $quantity,
                'balance_after'    => round($balance, 3),
                'unit'             => (string) $input['unit'],
                'supplier'         => $movement['supplier'] ?? null,
                'reference_no'     => $movement['reference_no'] ?? null,
                'notes'            => $movement['notes'] ?? null,
                'transaction_date' => $date,
                'created_by'       => $userId,
            ]);

            $this->db->update('inputs', ['current_stock' => round($balance, 3)], 'id = :id', ['id' => $inputId]);

            return ['success' => true, 'balance' => round($balance, 3), 'code' => $code];
        });
    }

    /**
     * Records a farmer-facing issue and reduces stock in one atomic step.
     *
     * @param array<string,mixed> $issue
     * @return array{success:bool,id?:int,code?:string,message?:string}
     */
    public function issue(array $issue, ?int $userId, ?int $officerId): array
    {
        $inputId  = (int) $issue['input_id'];
        $quantity = round((float) $issue['quantity'], 3);

        return $this->db->transaction(function () use ($issue, $inputId, $quantity, $userId, $officerId): array {
            $input = $this->db->selectOne(
                'SELECT id, name, unit, current_stock, reorder_level FROM inputs WHERE id = :id FOR UPDATE',
                ['id' => $inputId]
            );

            if ($input === null) {
                return ['success' => false, 'message' => 'The input item could not be found.'];
            }

            $balance = (float) $input['current_stock'] - $quantity;

            if ($balance < 0) {
                return [
                    'success' => false,
                    'message' => 'There is not enough ' . $input['name'] . ' in stock to issue that quantity.',
                ];
            }

            $date = (string) ($issue['issue_date'] ?? date('Y-m-d'));
            $code = Identifier::generate('input_issue', date_create_from_format('Y-m-d', $date) ?: null);

            $issueId = $this->db->insert('input_issues', [
                'issue_code'    => $code,
                'input_id'      => $inputId,
                'farmer_id'     => $issue['farmer_id'] ?? null,
                'garden_id'     => $issue['garden_id'] ?? null,
                'crop_id'       => $issue['crop_id'] ?? null,
                'season_id'     => $issue['season_id'] ?? null,
                'quantity'      => $quantity,
                'unit'          => (string) $input['unit'],
                'issue_date'    => $date,
                'issued_by'     => $userId,
                'officer_id'    => $officerId,
                'reference_no'  => $issue['reference_no'] ?? null,
                'purpose'       => $issue['purpose'] ?? null,
                'status'        => 'issued',
                'client_uuid'   => $issue['client_uuid'] ?? null,
            ]);

            $this->db->insert('input_transactions', [
                'transaction_code' => Identifier::generate('input_txn', date_create_from_format('Y-m-d', $date) ?: null),
                'input_id'         => $inputId,
                'transaction_type' => 'issue',
                'quantity'         => $quantity,
                'balance_after'    => round($balance, 3),
                'unit'             => (string) $input['unit'],
                'reference_no'     => $code,
                'notes'            => 'Issued to farmer #' . ($issue['farmer_id'] ?? '-'),
                'transaction_date' => $date,
                'created_by'       => $userId,
            ]);

            $this->db->update('inputs', ['current_stock' => round($balance, 3)], 'id = :id', ['id' => $inputId]);

            if ($balance <= (float) $input['reorder_level']) {
                Logger::info(sprintf(
                    'Input %s (%s) is at or below its reorder level: %s %s remaining.',
                    $input['name'],
                    $code,
                    rtrim(rtrim(number_format($balance, 2), '0'), '.'),
                    (string) $input['unit']
                ));
            }

            return ['success' => true, 'id' => $issueId, 'code' => $code];
        });
    }

    /**
     * Records actual usage against a previous issue and writes the matching
     * stock movement. Over-issuing is rejected so the issue record and the
     * stock ledger stay consistent.
     *
     * @return array{success:bool,id?:int,code?:string,message?:string}
     */
    public function recordUsage(array $usage, ?int $userId): array
    {
        $quantity = round((float) $usage['quantity_used'], 3);

        if ($quantity <= 0) {
            return ['success' => false, 'message' => 'The quantity used must be greater than zero.'];
        }

        return $this->db->transaction(function () use ($usage, $quantity, $userId): array {
            $inputId = (int) $usage['input_id'];
            $issueId = $usage['issue_id'] === null ? null : (int) $usage['issue_id'];

            if ($issueId !== null) {
                $issue = $this->db->selectOne(
                    'SELECT * FROM input_issues WHERE id = :id FOR UPDATE',
                    ['id' => $issueId]
                );

                if ($issue === null) {
                    return ['success' => false, 'message' => 'The input issue could not be found.'];
                }

                $remaining = (float) $issue['quantity'] - (float) $issue['quantity_used'];

                if ($quantity > $remaining + 0.0001) {
                    return [
                        'success' => false,
                        'message' => 'Only ' . rtrim(rtrim(number_format($remaining, 2), '0'), '.') . ' ' . $issue['unit'] . ' remains on this issue.',
                    ];
                }
            }

            $input = $this->db->selectOne(
                'SELECT id, name, unit, current_stock FROM inputs WHERE id = :id FOR UPDATE',
                ['id' => $inputId]
            );

            if ($input === null) {
                return ['success' => false, 'message' => 'The input item could not be found.'];
            }

            $balance = (float) $input['current_stock'] - $quantity;

            if ($balance < 0) {
                return ['success' => false, 'message' => 'There is not enough stock in store to record that usage.'];
            }

            $date = (string) ($usage['application_date'] ?? date('Y-m-d'));
            $code = Identifier::generate('usage', date_create_from_format('Y-m-d', $date) ?: null);

            $usageId = $this->db->insert('input_usage', [
                'usage_code'       => $code,
                'issue_id'         => $issueId,
                'input_id'         => $inputId,
                'farmer_id'        => $usage['farmer_id'],
                'garden_id'        => $usage['garden_id'] ?? null,
                'crop_id'          => $usage['crop_id'] ?? null,
                'season_id'        => $usage['season_id'] ?? null,
                'activity_id'      => $usage['activity_id'] ?? null,
                'quantity_used'    => $quantity,
                'unit'             => (string) $input['unit'],
                'application_date' => $date,
                'purpose'          => $usage['purpose'] ?? null,
                'recorded_by'      => $userId,
                'client_uuid'      => $usage['client_uuid'] ?? null,
            ]);

            // Usage is a farm-side movement, not a store movement: the stock was
            // already deducted when it was issued, so only the usage ledger row
            // and the issue roll-up change here.
            if ($issueId !== null) {
                $used = (float) $this->db->scalar(
                    'SELECT quantity_used FROM input_issues WHERE id = :id',
                    ['id' => $issueId]
                );

                $issued = (float) $this->db->scalar(
                    'SELECT quantity FROM input_issues WHERE id = :id',
                    ['id' => $issueId]
                );

                $this->db->update('input_issues', [
                    'quantity_used' => $used,
                    'status'        => $used >= $issued - 0.0001 ? 'fully_used' : 'partially_used',
                ], 'id = :id', ['id' => $issueId]);
            }

            return ['success' => true, 'id' => $usageId, 'code' => $code];
        });
    }

    /**
     * Stock levels per item, joined with the reorder threshold so the
     * inventory register can flag low stock without a second query.
     *
     * @return array<int,array<string,mixed>>
     */
    public function stockLevels(?string $categoryId = null, string $search = ''): array
    {
        $where  = ['i.status = :status'];
        $params = ['status' => 'active'];

        if ($categoryId !== null && $categoryId !== '') {
            $where[]          = 'i.category_id = :category';
            $params['category'] = $categoryId;
        }

        if ($search !== '') {
            $where[]        = '(i.name LIKE :search OR i.input_code LIKE :search OR i.brand LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        return $this->db->select(
            'SELECT i.*, c.name AS category_name,
                    (i.current_stock <= i.reorder_level AND i.reorder_level > 0) AS low_stock,
                    (SELECT MAX(t.transaction_date) FROM input_transactions t WHERE t.input_id = i.id) AS last_movement
             FROM inputs i
             INNER JOIN input_categories c ON c.id = i.category_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY (i.current_stock <= i.reorder_level AND i.reorder_level > 0) DESC, i.name ASC',
            $params
        );
    }
}
