<?php
declare(strict_types=1);

namespace Services;

use Core\Database;

/**
 * End-to-end traceability.
 *
 * Walks the chain required by the specification in reverse from the point of
 * sale or export back to the individual farmers:
 *
 *   Export -> Sale -> Batch -> Harvest -> Garden -> Crop -> Farmer
 *
 * Each hop keeps the farmer, garden and crop attached so a buyer, auditor or
 * regulator can be given the full origin of a consignment in one query.
 */
final class Traceability
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Full chain for a sale, including every contributing farmer and the
     * officers and inputs that touched each harvest.
     */
    public function forSale(int $saleId): ?array
    {
        $sale = $this->db->selectOne(
            'SELECT s.*, b.name AS buyer_name, b.buyer_type, b.country AS buyer_country,
                    m.name AS market_name
             FROM sales s
             INNER JOIN buyers b ON b.id = s.buyer_id
             LEFT JOIN markets m ON m.id = s.market_id
             WHERE s.id = :id',
            ['id' => $saleId]
        );

        if ($sale === null) {
            return null;
        }

        $items = $this->db->select(
            'SELECT si.*, c.name AS crop_name, v.name AS variety_name, bt.batch_code, bt.grade, bt.status AS batch_status
             FROM sale_items si
             INNER JOIN crops c ON c.id = si.crop_id
             LEFT JOIN crop_varieties v ON v.id = si.variety_id
             LEFT JOIN harvest_batches bt ON bt.id = si.batch_id
             WHERE si.sale_id = :id',
            ['id' => $saleId]
        );

        $deliveries = $this->db->select('SELECT * FROM deliveries WHERE sale_id = :id', ['id' => $saleId]);
        $exports    = $this->db->select('SELECT * FROM exports WHERE sale_id = :id', ['id' => $saleId]);

        foreach ($items as &$item) {
            $item['origin'] = $item['batch_id'] === null ? [] : $this->forBatch((int) $item['batch_id']);
        }
        unset($item);

        return [
            'sale'       => $sale,
            'items'      => $items,
            'deliveries' => $deliveries,
            'exports'    => $exports,
        ];
    }

    /**
     * Full origin of a batch: which farmers, gardens, crops and plots the
     * produce came from.
     *
     * @return array{batch:array<string,mixed>,contributors:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function forBatch(int $batchId): array
    {
        $batch = $this->db->selectOne(
            'SELECT b.*, c.name AS crop_name, v.name AS variety_name, s.season_code
             FROM harvest_batches b
             INNER JOIN crops c ON c.id = b.crop_id
             LEFT JOIN crop_varieties v ON v.id = b.variety_id
             LEFT JOIN seasons s ON s.id = b.season_id
             WHERE b.id = :id',
            ['id' => $batchId]
        );

        if ($batch === null) {
            return ['batch' => [], 'contributors' => [], 'summary' => []];
        }

        $contributors = $this->db->select(
            'SELECT h.id AS harvest_id, h.harvest_code, h.harvest_date, h.quantity_harvested, h.unit,
                    h.quality, h.grade, h.moisture_content, h.losses,
                    g.id AS garden_id, g.garden_code, g.name AS garden_name, g.acreage,
                    g.latitude, g.longitude, g.land_ownership, g.irrigation_status,
                    p.id AS planting_id, p.planting_code, p.planting_date, p.growth_stage,
                    f.id AS farmer_id, f.farmer_code, f.full_name, f.phone,
                    d.name AS district_name, sc.name AS sub_county_name, v.village_name,
                    o.officer_code, o.full_name AS officer_name,
                    fc.expected_yield_per_acre,
                    (SELECT COALESCE(SUM(hu.quantity_harvested), 0) FROM harvest_records hu WHERE hu.planting_id = p.id) AS planting_total
             FROM harvest_batch_items hbi
             INNER JOIN harvest_records h ON h.id = hbi.harvest_id
             INNER JOIN gardens g ON g.id = h.garden_id
             INNER JOIN farmers f ON f.id = h.farmer_id
             LEFT JOIN plantings p ON p.id = h.planting_id
             LEFT JOIN crops fc ON fc.id = h.crop_id
             LEFT JOIN districts d ON d.id = g.district_id
             LEFT JOIN sub_counties sc ON sc.id = g.sub_county_id
             LEFT JOIN field_officers o ON o.id = h.officer_id
             WHERE hbi.batch_id = :batch
             ORDER BY f.farmer_code ASC',
            ['batch' => $batchId]
        );

        foreach ($contributors as &$contributor) {
            $contributor['inputs'] = $this->db->select(
                'SELECT iu.usage_code, iu.quantity_used, iu.unit, iu.application_date,
                        i.name AS input_name, ic.name AS category_name
                 FROM input_usage iu
                 INNER JOIN inputs i ON i.id = iu.input_id
                 INNER JOIN input_categories ic ON ic.id = i.category_id
                 WHERE iu.farmer_id = :farmer AND iu.garden_id = :garden
                 ORDER BY iu.application_date DESC
                 LIMIT 10',
                ['farmer' => $contributor['farmer_id'], 'garden' => $contributor['garden_id']]
            );

            $contributor['inspections'] = $this->db->select(
                'SELECT i.inspection_code, i.inspection_date, i.crop_condition, i.pest_status,
                        i.disease_status, i.weed_status, i.recommendations
                 FROM inspections i
                 WHERE i.farmer_id = :farmer AND i.garden_id = :garden
                 ORDER BY i.inspection_date DESC
                 LIMIT 5',
                ['farmer' => $contributor['farmer_id'], 'garden' => $contributor['garden_id']]
            );
        }
        unset($contributor);

        $summary = [
            'farmer_count'     => count(array_unique(array_column($contributors, 'farmer_id'))),
            'garden_count'     => count(array_unique(array_column($contributors, 'garden_id'))),
            'total_quantity'   => array_sum(array_map('floatval', array_column($contributors, 'quantity_harvested'))),
            'unit'             => $batch['unit'],
            'total_acreage'    => array_sum(array_map('floatval', array_column($contributors, 'acreage'))),
            'first_harvest'    => $contributors === [] ? null : min(array_column($contributors, 'harvest_date')),
            'last_harvest'     => $contributors === [] ? null : max(array_column($contributors, 'harvest_date')),
        ];

        return ['batch' => $batch, 'contributors' => $contributors, 'summary' => $summary];
    }

    /**
     * Full origin of an export consignment.
     */
    public function forExport(int $exportId): ?array
    {
        $export = $this->db->selectOne(
            'SELECT e.*, b.name AS buyer_name, b.buyer_type, s.sale_code
             FROM exports e
             LEFT JOIN buyers b ON b.id = e.buyer_id
             LEFT JOIN sales s ON s.id = e.sale_id
             WHERE e.id = :id',
            ['id' => $exportId]
        );

        if ($export === null) {
            return null;
        }

        // An export may name its batch directly, or inherit it from the sale it
        // was raised against.
        $batchId = $export['batch_id'] !== null
            ? (int) $export['batch_id']
            : $this->db->scalar(
                'SELECT si.batch_id FROM sale_items si
                 WHERE si.sale_id = :sale AND si.batch_id IS NOT NULL
                 ORDER BY si.id ASC LIMIT 1',
                ['sale' => $export['sale_id'] ?? 0]
            );

        $batchId = $batchId === null ? null : (int) $batchId;

        return [
            'export' => $export,
            'chain'  => $batchId === null ? null : $this->forBatch($batchId),
        ];
    }

    /**
     * Flat rows suitable for CSV export of a batch's origin.
     *
     * @return array<int,array<string,mixed>>
     */
    public function batchOriginRows(int $batchId): array
    {
        $chain = $this->forBatch($batchId);

        $rows = [];
        foreach ($chain['contributors'] as $contributor) {
            $rows[] = [
                'batch_code'     => $chain['batch']['batch_code'] ?? '',
                'crop'           => $chain['batch']['crop_name'] ?? '',
                'farmer_code'    => $contributor['farmer_code'],
                'farmer_name'    => $contributor['full_name'],
                'phone'          => $contributor['phone'],
                'garden_code'    => $contributor['garden_code'],
                'garden_name'    => $contributor['garden_name'],
                'acreage'        => $contributor['acreage'],
                'latitude'       => $contributor['latitude'],
                'longitude'      => $contributor['longitude'],
                'district'       => $contributor['district_name'],
                'planting_date'  => $contributor['planting_date'],
                'harvest_date'   => $contributor['harvest_date'],
                'quantity'       => $contributor['quantity_harvested'],
                'unit'           => $contributor['unit'],
                'quality'        => $contributor['quality'],
                'officer'        => $contributor['officer_name'],
            ];
        }

        return $rows;
    }
}
