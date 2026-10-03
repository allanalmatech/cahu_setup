<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Request;
use Core\Response;

/**
 * Pick lists for the offline capture forms.
 *
 * A field phone records inspections, problems, harvests and sprayings with no
 * signal, so it cannot ask the server what a problem type is called or which
 * crops exist at the moment the officer opens the form. It has to hold these
 * lists locally and refresh them when it can. Without this endpoint the client
 * would be hard-coding enum values, and a hard-coded value that the database
 * rejects is silently discarded on the next sync.
 *
 * Guarded on the token alone rather than a permission code, because the farmer
 * role holds no permissions at all yet still has to report a problem. Nothing
 * here is sensitive: these are enumerations and catalogue names, already
 * visible to any signed-in user in the web interface.
 */
final class ReferenceApiController extends ApiController
{
    /**
     * GET /api/v1/reference
     */
    public function index(): Response
    {
        return $this->ok([
            'problem_types'   => $this->problemTypes(),
            'activity_types'  => $this->activityTypes(),
            'input_categories'=> $this->inputCategories(),
            'seasons'         => $this->seasons(),
            'crops'           => $this->crops(),
            'inputs'          => $this->inputs(),
        ]);
    }

    /**
     * Codes here are what SyncApiController::resolveProblemType() matches on,
     * so a client that sends `problem_type` must send one of these codes and
     * not the human label.
     *
     * @return array<int,array<string,mixed>>
     */
    private function problemTypes(): array
    {
        return $this->db->select(
            'SELECT pt.id, pt.code, pt.name, pt.category, pt.is_critical
             FROM problem_types pt
             WHERE pt.active = 1
             ORDER BY pt.category ASC, pt.name ASC'
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function activityTypes(): array
    {
        return $this->db->select(
            'SELECT at.id, at.code, at.name, at.category, at.requires_photo
             FROM activity_types at
             WHERE at.active = 1
             ORDER BY at.name ASC'
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function inputCategories(): array
    {
        return $this->db->select(
            'SELECT id, code, name FROM input_categories WHERE active = 1 ORDER BY name ASC'
        );
    }

    /**
     * Open and recent seasons, newest first. A harvest has to be tied to a
     * season_id or the server refuses it, so this is what makes the harvest
     * form possible.
     *
     * @return array<int,array<string,mixed>>
     */
    private function seasons(): array
    {
        return $this->db->select(
            'SELECT s.id, s.season_code, s.name, s.start_date, s.end_date, s.status
             FROM seasons s
             ORDER BY s.status = :closed DESC, s.start_date DESC
             LIMIT 20',
            ['closed' => 'closed']
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function crops(): array
    {
        return $this->db->select(
            'SELECT c.id, c.crop_code, c.name, c.category, c.primary_unit, c.is_perishable
             FROM crops c
             WHERE c.status = :active
             ORDER BY c.name ASC',
            ['active' => 'active']
        );
    }

    /**
     * Stock items, so the spray and harvest forms can name a product without
     * asking the server mid-form.
     *
     * @return array<int,array<string,mixed>>
     */
    private function inputs(): array
    {
        return $this->db->select(
            'SELECT i.id, i.input_code, i.name, i.unit, i.active_ingredient, i.crop_id
             FROM inputs i
             WHERE i.status = :active
             ORDER BY i.name ASC
             LIMIT 200',
            ['active' => 'active']
        );
    }
}
