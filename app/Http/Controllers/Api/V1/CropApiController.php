<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Response;

/**
 * Crop catalogue API.
 *
 * Reference data only, and it is public to every authenticated account because
 * the mobile app needs it to render planting, inspection and harvest forms even
 * for a farmer who sees nothing else.
 */
final class CropApiController extends ApiController
{
    /**
     * GET /api/v1/crops
     */
    public function index(): Response
    {
        $rows = $this->db->select(
            'SELECT c.id, c.crop_code, c.name, c.category, c.lifecycle_days,
                    c.expected_yield_per_acre, c.yield_unit, c.primary_unit, c.is_perishable,
                    (SELECT COUNT(*) FROM crop_varieties v
                      WHERE v.crop_id = c.id AND v.status = :active) AS variety_count
             FROM crops c
             WHERE c.status = :crop_active
             ORDER BY c.name ASC',
            ['active' => 'active', 'crop_active' => 'active']
        );

        return $this->ok(['crops' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /**
     * GET /api/v1/crops/{id}
     */
    public function show(int $id): Response
    {
        $crop = $this->db->selectOne(
            'SELECT id, crop_code, name, description, category, lifecycle_days,
                    expected_yield_per_acre, yield_unit, primary_unit, is_perishable, status
             FROM crops WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        if ($crop === null) {
            return $this->notFound('Crop not found.');
        }

        $crop['varieties'] = $this->db->select(
            'SELECT id, variety_code, name, maturity_days, expected_yield_per_acre,
                    yield_unit, recommended_areas, status
             FROM crop_varieties WHERE crop_id = :crop AND status = :active ORDER BY name',
            ['crop' => $id, 'active' => 'active']
        );

        // The lifecycle a planting on this crop would be scheduled against, with
        // the same specificity rules the scheduler applies.
        $crop['lifecycle'] = $this->db->select(
            'SELECT activity_type, description, day_offset, duration_days, assign_role,
                    is_mandatory, sort_order
             FROM crop_activity_templates
             WHERE active = 1 AND (variety_id IS NULL AND crop_id = :crop)
             ORDER BY day_offset ASC, sort_order ASC',
            ['crop' => $id]
        );

        return $this->ok($crop);
    }
}
