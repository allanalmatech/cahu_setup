<?php
declare(strict_types=1);

namespace Services;

use Core\Database;

/**
 * Crop lifecycle engine.
 *
 * Lifecycle templates are configuration, not code: an administrator defines
 * which activities apply to a crop (optionally narrowed to a variety) and at
 * which day offset from planting. When a planting is recorded, this service
 * turns those templates into dated crop_activities rows for that planting.
 *
 * Templates are resolved with the most specific match winning:
 *   1. variety + crop
 *   2. crop only
 *   3. global (crop_id IS NULL)
 */
final class CropLifecycle
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function templatesFor(int $cropId, ?int $varietyId): array
    {
        return $this->db->select(
            'SELECT * FROM crop_activity_templates
             WHERE active = 1 AND (variety_id = :variety OR (variety_id IS NULL AND crop_id = :crop)
                                   OR (crop_id IS NULL AND variety_id IS NULL))
             ORDER BY day_offset ASC, sort_order ASC',
            ['crop' => $cropId, 'variety' => $varietyId ?? 0]
        );
    }

    /**
     * Applies the specificity rules: a variety template overrides the crop
     * template at the same offset, and a global template fills any offset that
     * is not already covered.
     *
     * @param array<int,array<string,mixed>> $templates
     * @return array<int,array<string,mixed>>
     */
    public function resolveTemplates(array $templates): array
    {
        $byOffset = [];

        foreach ($templates as $template) {
            $offset     = (int) $template['day_offset'];
            $specificity = $template['variety_id'] !== null ? 3 : ($template['crop_id'] !== null ? 2 : 1);

            if (!isset($byOffset[$offset]) || $specificity >= $byOffset[$offset]['_specificity']) {
                $template['_specificity'] = $specificity;
                $byOffset[$offset]        = $template;
            }
        }

        $resolved = array_values($byOffset);
        usort($resolved, static fn (array $a, array $b): int => $a['day_offset'] <=> $b['day_offset']);

        return $resolved;
    }

    /**
     * Creates the activity schedule for a planting.
     *
     * Runs inside the caller's transaction so the planting row and its schedule
     * are committed together: a planting is never left without the activities
     * its crop requires.
     *
     * @return int Number of activities created
     */
    public function generateSchedule(int $plantingId): int
    {
        $planting = $this->db->selectOne(
            'SELECT p.*, g.acreage, g.village_name, c.name AS crop_name
             FROM plantings p
             INNER JOIN gardens g ON g.id = p.garden_id
             INNER JOIN crops c ON c.id = p.crop_id
             WHERE p.id = :id',
            ['id' => $plantingId]
        );

        if ($planting === null) {
            return 0;
        }

        $templates = $this->resolveTemplates(
            $this->templatesFor((int) $planting['crop_id'], $planting['variety_id'] === null ? null : (int) $planting['variety_id'])
        );

        $created = 0;

        foreach ($templates as $template) {
            $plannedDate = date(
                'Y-m-d',
                strtotime((string) $planting['planting_date'] . ' +' . (int) $template['day_offset'] . ' days')
            );

            $dueDate = date(
                'Y-m-d',
                strtotime($plannedDate . ' +' . max(0, (int) $template['duration_days'] - 1) . ' days')
            );

            // A farmer task is not assigned to an officer; everything else is
            // chased by the officer who supervises the plot.
            $assignsToOfficer = $template['assign_role'] !== 'farmer';

            $this->db->insert('crop_activities', [
                'activity_code' => \Core\Identifier::generate('activity', date_create_from_format('Y-m-d', $plannedDate)),
                'planting_id'   => $plantingId,
                'farmer_id'     => $planting['farmer_id'],
                'garden_id'     => $planting['garden_id'],
                'crop_id'       => $planting['crop_id'],
                'variety_id'    => $planting['variety_id'],
                'season_id'     => $planting['season_id'],
                'activity_type' => $template['activity_type'],
                'description'   => $template['description'],
                'planned_date'  => $plannedDate,
                'due_date'      => $dueDate,
                'assignee_type' => $template['assign_role'],
                'officer_id'    => $assignsToOfficer ? $planting['officer_id'] : null,
                'status'        => 'pending',
            ]);

            $created++;
        }

        $this->db->update('plantings', ['schedule_generated' => 1], 'id = :id', ['id' => $plantingId]);

        return $created;
    }

    /**
     * Removes the activities that are still pending for a planting. Used when a
     * planting is deleted or its crop/variety/planting date changes before any
     * work has been recorded against it.
     */
    public function discardSchedule(int $plantingId): int
    {
        return $this->db->delete(
            'crop_activities',
            'planting_id = :id AND status = :status',
            ['id' => $plantingId, 'status' => 'pending']
        );
    }

    /**
     * Works out the expected harvest date from the variety or crop lifecycle
     * when the user has not supplied one.
     */
    public function expectedHarvestDate(int $cropId, ?int $varietyId, string $plantingDate): ?string
    {
        $days = $this->db->scalar(
            'SELECT maturity_days FROM crop_varieties WHERE id = :id AND maturity_days IS NOT NULL LIMIT 1',
            ['id' => $varietyId ?? 0]
        );

        $days ??= $this->db->scalar(
            'SELECT lifecycle_days FROM crops WHERE id = :id AND lifecycle_days IS NOT NULL LIMIT 1',
            ['id' => $cropId]
        );

        if ($days === null) {
            return null;
        }

        return date('Y-m-d', strtotime($plantingDate . ' +' . (int) $days . ' days'));
    }
}
