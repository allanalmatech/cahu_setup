<?php
declare(strict_types=1);

/**
 * Forecast refresh.
 *
 * Two jobs in one pass:
 *
 *  1. Ensure every active planting has a baseline forecast. Without this a
 *     planting created outside the web form never appears in the production
 *     figures at all.
 *  2. Re-derive the revised forecast from the most recent inspection, so the
 *     expected-versus-actual figures on the command centre reflect what the
 *     officers actually saw in the field rather than what the variety
 *     catalogue predicted.
 *
 * Idempotent: forecasts are updated in place, never duplicated.
 *
 * Schedule:  30 5 * * *   php cron/forecasts.php
 */

require __DIR__ . '/bootstrap.php';

use Services\Forecaster;

if (!cahu_cron_lock('forecasts', 1800)) {
    cahu_cron_say('Another forecasts run is still in progress. Skipping.');
    exit(0);
}

$db         = cahu_cron_db();
$forecaster = new Forecaster($db);
$days       = cahu_cron_days();
$written    = 0;
$revised    = 0;

try {
    // Open plantings, newest activity first so the freshest ones get handled
    // even if the table is very large.
    $plantings = $db->select(
        'SELECT DISTINCT p.id
         FROM plantings p
         INNER JOIN gardens g ON g.id = p.garden_id
         INNER JOIN seasons s ON s.id = p.season_id
         WHERE p.status IN (:planted, :growing)
           AND g.deleted_at IS NULL
           AND s.status = :open
         ORDER BY p.planting_date DESC
         LIMIT 5000',
        ['planted' => 'planted', 'growing' => 'growing', 'open' => 'open']
    );

    foreach ($plantings as $planting) {
        $plantingId = (int) $planting['id'];

        $forecastId = $forecaster->writeForecast($plantingId);

        if ($forecastId !== null) {
            $written++;
        }

        // Only plantings touched by an inspection since the last revision need
        // recomputing; the rest would just churn the same numbers.
        $revision = $db->scalar(
            'SELECT 1 FROM inspections i
             INNER JOIN harvest_forecasts hf ON hf.planting_id = i.planting_id
             WHERE i.planting_id = :id
               AND i.crop_condition IS NOT NULL
               AND i.inspection_date >= DATE_SUB(hf.forecast_date, INTERVAL :days DAY)
             LIMIT 1',
            ['id' => $plantingId, 'days' => $days]
        );

        if ($revision !== null) {
            $forecaster->reviseFromInspection($plantingId);
            $revised++;
        }

        // Fold in anything harvested since the last pass.
        $forecaster->recordActual($plantingId);
    }
} catch (Throwable $exception) {
    cahu_cron_say('FAILED: ' . $exception->getMessage());
    cahu_cron_unlock('forecasts');
    exit(1);
}

cahu_cron_say('Forecast refresh complete. ' . $written . ' forecast(s) written, '
    . $revised . ' revised from inspection, over ' . count($plantings) . ' active planting(s).');

cahu_cron_unlock('forecasts');
