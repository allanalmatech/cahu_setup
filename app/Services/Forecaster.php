<?php
declare(strict_types=1);

namespace Services;

use Core\Database;

/**
 * Production forecasting.
 *
 * Base forecast:    acreage x expected yield per acre
 * Revised forecast: the base adjusted by the most recent inspection, which is
 *                   the field evidence the platform actually holds about crop
 *                   condition. The adjustment factor per crop condition is
 *                   stored in system_settings so it is configurable rather
 *                   than hard-coded.
 */
final class Forecaster
{
    private const CONDITION_FACTORS = [
        'excellent' => 1.15,
        'good'      => 1.05,
        'fair'      => 0.90,
        'poor'      => 0.70,
        'critical'  => 0.45,
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function factors(): array
    {
        $factors = self::CONDITION_FACTORS;

        foreach (['excellent', 'good', 'fair', 'poor', 'critical'] as $condition) {
            $override = \Core\Setting::get('forecast.factor.' . $condition);

            if ($override !== null && is_numeric($override)) {
                $factors[$condition] = (float) $override;
            }
        }

        return $factors;
    }

    /**
     * Expected yield per acre for a crop/variety, falling back to the crop
     * level default when the variety does not define its own.
     */
    public function yieldPerAcre(int $cropId, ?int $varietyId): array
    {
        $row = $this->db->selectOne(
            'SELECT v.expected_yield_per_acre AS variety_yield, v.yield_unit AS variety_unit,
                    c.expected_yield_per_acre AS crop_yield, c.yield_unit AS crop_unit, c.primary_unit
             FROM crops c
             LEFT JOIN crop_varieties v ON v.id = :variety
             WHERE c.id = :crop
             LIMIT 1',
            ['crop' => $cropId, 'variety' => $varietyId ?? 0]
        );

        $yield = $row['variety_yield'] ?? $row['crop_yield'];
        $unit  = $row['variety_unit'] ?? $row['crop_unit'] ?? $row['primary_unit'] ?? 'KG';

        return ['yield_per_acre' => $yield === null ? null : (float) $yield, 'unit' => (string) $unit];
    }

    /**
     * Creates or refreshes the forecast rows for every planting in a season.
     *
     * @return int Number of forecasts written
     */
    public function generateForSeason(int $seasonId): int
    {
        $plantings = $this->db->select(
            'SELECT p.*, g.acreage AS garden_acreage
             FROM plantings p
             INNER JOIN gardens g ON g.id = p.garden_id
             WHERE p.season_id = :season AND p.status <> :failed
             ORDER BY p.planting_date ASC',
            ['season' => $seasonId, 'failed' => 'failed']
        );

        $written = 0;

        foreach ($plantings as $planting) {
            $this->writeForecast((int) $planting['id']);
            $written++;
        }

        return $written;
    }

    /**
     * Writes the forecast for a single planting, preserving any manual
     * revision a manager has already made.
     */
    public function writeForecast(int $plantingId): ?int
    {
        $planting = $this->db->selectOne(
            'SELECT * FROM plantings WHERE id = :id LIMIT 1',
            ['id' => $plantingId]
        );

        if ($planting === null) {
            return null;
        }

        $acreage = (float) ($planting['acreage_planted'] ?: 0);
        $basis   = $this->yieldPerAcre((int) $planting['crop_id'], $planting['variety_id'] === null ? null : (int) $planting['variety_id']);

        $perAcre = $basis['yield_per_acre'] ?? ($planting['expected_yield'] === null ? null : (float) $planting['expected_yield'] / max($acreage, 0.001));

        if ($perAcre === null) {
            return null;
        }

        $expected = round($acreage * $perAcre, 3);

        $existing = $this->db->selectOne(
            'SELECT id, revised_yield_per_acre, actual_production FROM harvest_forecasts WHERE planting_id = :id LIMIT 1',
            ['id' => $plantingId]
        );

        $data = [
            'planting_id'             => $plantingId,
            'farmer_id'               => $planting['farmer_id'],
            'garden_id'               => $planting['garden_id'],
            'crop_id'                 => $planting['crop_id'],
            'variety_id'              => $planting['variety_id'],
            'season_id'               => $planting['season_id'],
            'forecast_date'           => date('Y-m-d'),
            'acreage'                 => $acreage,
            'expected_yield_per_acre' => $perAcre,
            'expected_production'     => $expected,
            'unit'                    => $basis['unit'],
            'actual_production'       => $existing['actual_production'] ?? null,
        ];

        if ($existing === null) {
            return $this->db->insert('harvest_forecasts', $data);
        }

        $this->db->update('harvest_forecasts', $data, 'id = :id', ['id' => (int) $existing['id']]);

        return (int) $existing['id'];
    }

    /**
     * Recalculates the revised forecast for a planting using its latest
     * inspection. Explicit manual revisions are left untouched.
     */
    public function reviseFromInspection(int $plantingId): ?array
    {
        $forecast = $this->db->selectOne(
            'SELECT * FROM harvest_forecasts WHERE planting_id = :id LIMIT 1',
            ['id' => $plantingId]
        );

        if ($forecast === null) {
            return null;
        }

        $inspection = $this->db->selectOne(
            'SELECT crop_condition, estimated_yield_per_acre, inspection_date
             FROM inspections
             WHERE planting_id = :id AND crop_condition IS NOT NULL
             ORDER BY inspection_date DESC LIMIT 1',
            ['id' => $plantingId]
        );

        if ($inspection === null) {
            return null;
        }

        if ($inspection['estimated_yield_per_acre'] !== null) {
            $revisedPerAcre = (float) $inspection['estimated_yield_per_acre'];
            $reason         = 'Officer estimate at inspection on ' . $inspection['inspection_date'];
        } else {
            $factor         = $this->factors()[(string) $inspection['crop_condition']] ?? 1.0;
            $revisedPerAcre = round((float) $forecast['expected_yield_per_acre'] * $factor, 3);
            $reason         = 'Crop condition assessed as ' . $inspection['crop_condition'] . ' on ' . $inspection['inspection_date'];
        }

        $revised = round((float) $forecast['acreage'] * $revisedPerAcre, 3);

        $this->db->update('harvest_forecasts', [
            'revised_yield_per_acre' => $revisedPerAcre,
            'revised_production'     => $revised,
            'revision_reason'        => $reason,
            'confidence'             => $this->confidenceFor((string) $inspection['crop_condition']),
        ], 'id = :id', ['id' => (int) $forecast['id']]);

        return ['yield_per_acre' => $revisedPerAcre, 'production' => $revised, 'reason' => $reason];
    }

    /**
     * Applies an explicit manager revision.
     */
    public function manualRevision(int $forecastId, float $yieldPerAcre, string $reason): array
    {
        $forecast = $this->db->selectOne('SELECT * FROM harvest_forecasts WHERE id = :id', ['id' => $forecastId]);

        if ($forecast === null) {
            return ['success' => false, 'message' => 'The forecast could not be found.'];
        }

        $revised = round((float) $forecast['acreage'] * $yieldPerAcre, 3);

        $this->db->update('harvest_forecasts', [
            'revised_yield_per_acre' => $yieldPerAcre,
            'revised_production'     => $revised,
            'revision_reason'        => $reason,
            'generated_by'           => 'manual',
        ], 'id = :id', ['id' => $forecastId]);

        return ['success' => true, 'production' => $revised];
    }

    /**
     * Folds actual harvest quantities into the forecast and computes the
     * variance against the revised figure when one exists.
     */
    public function recordActual(int $plantingId): void
    {
        $forecast = $this->db->selectOne('SELECT * FROM harvest_forecasts WHERE planting_id = :id', ['id' => $plantingId]);

        if ($forecast === null) {
            return;
        }

        $actual = (float) ($this->db->scalar(
            'SELECT COALESCE(SUM(quantity_harvested), 0) FROM harvest_records
             WHERE planting_id = :id AND status <> :cancelled',
            ['id' => $plantingId, 'cancelled' => 'cancelled']
        ) ?? 0);

        $baseline = $forecast['revised_production'] !== null
            ? (float) $forecast['revised_production']
            : (float) $forecast['expected_production'];

        $variance = round($actual - $baseline, 3);
        $percent  = $baseline > 0 ? round(($variance / $baseline) * 100, 2) : null;

        $this->db->update('harvest_forecasts', [
            'actual_production' => $actual,
            'variance'          => $variance,
            'variance_pct'      => $percent,
        ], 'id = :id', ['id' => (int) $forecast['id']]);
    }

    private function confidenceFor(string $condition): float
    {
        return match ($condition) {
            'excellent' => 90.0,
            'good'      => 80.0,
            'fair'      => 65.0,
            'poor'      => 45.0,
            'critical'  => 30.0,
            default     => 70.0,
        };
    }
}
