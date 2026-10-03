<?php
declare(strict_types=1);

namespace Core;

/**
 * Human readable record identifiers, e.g. FMR-UG-000001 or ACT-2026-000004.
 *
 * A dedicated counter table backs the sequence so that deleted records never
 * cause an identifier to be reused. Allocation happens inside the caller's
 * transaction (typically the same one that inserts the record), so a failed
 * insert never burns a number.
 */
final class Identifier
{
    /** entity => [prefix template, zero padding, period style] */
    private const SCHEMA = [
        'farmer'      => ['FMR-UG-',        6, 'none'],
        'garden'      => ['GDN-',          6, 'none'],
        'officer'     => ['OFF-',          6, 'none'],
        'group'       => ['GRP-',          6, 'none'],
        'planting'    => ['PLT-',          6, 'year'],
        'activity'    => ['ACT-',          6, 'year'],
        'input_issue' => ['INP-',          6, 'year'],
        'inspection'  => ['INS-',          6, 'year'],
        'problem'     => ['PRB-',          6, 'year'],
        'spray'       => ['SPR-',          6, 'year'],
        'visit'       => ['VIS-',          6, 'year'],
        'harvest'     => ['HRV-',          6, 'year'],
        'batch'       => ['BAT-',          6, 'year'],
        'sale'        => ['SAL-',          6, 'year'],
        'delivery'    => ['DEL-',          6, 'year'],
        'export'      => ['EXP-',          6, 'year'],
        'buyer'       => ['BYR-',          6, 'none'],
        'market'      => ['MKT-',          6, 'none'],
        'requirement' => ['REQ-',          6, 'year'],
        'input_txn'   => ['TXN-',          6, 'year'],
        'usage'       => ['USE-',          6, 'year'],
        'crop'        => ['CRP-',          4, 'none'],
        'variety'     => ['VAR-',          4, 'none'],
        'input_item'  => ['INM-',          4, 'none'],
    ];

    public static function generate(string $entity, ?\DateTimeInterface $on = null): string
    {
        if (!isset(self::SCHEMA[$entity])) {
            throw new \InvalidArgumentException('Unknown identifier entity: ' . $entity);
        }

        [$prefix, $padding, $periodStyle] = self::SCHEMA[$entity];

        $on     = $on ?? new \DateTimeImmutable();
        $period = $periodStyle === 'year' ? $on->format('Y') : '';

        $db = Database::instance();

        $db->query(
            'INSERT INTO id_counters (entity, period, current_value)
             VALUES (:entity, :period, 1)
             ON DUPLICATE KEY UPDATE current_value = current_value + 1',
            ['entity' => $entity, 'period' => $period]
        );

        $value = (int) $db->scalar(
            'SELECT current_value FROM id_counters WHERE entity = :entity AND period = :period',
            ['entity' => $entity, 'period' => $period]
        );

        $number = str_pad((string) $value, $padding, '0', STR_PAD_LEFT);

        return $period === '' ? $prefix . $number : $prefix . $period . '-' . $number;
    }
}
