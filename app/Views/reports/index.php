<?php
/**
 * Report catalogue, grouped by the module each extract comes from.
 *
 * @var string $pageTitle
 * @var array<string,array<string,array<string,mixed>>> $catalogue
 * @var string[] $filterLabels
 */
$filterLabels = [
    'from'        => 'Date range (from)',
    'to'          => 'Date range (to)',
    'season_id'   => 'Season',
    'district_id' => 'District',
    'crop_id'     => 'Crop',
    'group_id'    => 'Farmer group',
];
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            Every extract supports a date range, a season, a district, a crop and a farmer group. Export to CSV or print for a paper copy.
        </p>
    </div>
</div>

<?php if ($catalogue === []): ?>
    <div class="cahu-card">
        <div class="cahu-card__body">
            <p class="text-muted mb-0">No reports have been defined.</p>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($catalogue as $module => $reports): ?>
    <div class="cahu-card mb-3">
        <div class="cahu-card__head"><?= e($module) ?></div>
        <div class="cahu-card__body cahu-card__body--flush">
            <div class="table-responsive">
                <table class="table cahu-table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Report</th>
                        <th>What it covers</th>
                        <th>Filters</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($reports as $slug => $report): ?>
                        <tr>
                            <td>
                                <a href="<?= e($report['url']) ?>"><?= e($report['title']) ?></a>
                                <div class="text-muted small"><?= e($slug) ?></div>
                            </td>
                            <td class="small"><?= e($report['description']) ?></td>
                            <td class="small text-muted">
                                <?php
                                $names = array_map(
                                    static fn (string $key): string => $filterLabels[$key] ?? $key,
                                    $report['filters']
                                );
                                ?>
                                <?= $names === [] ? 'None' : e(implode(', ', $names)) ?>
                            </td>
                            <td class="text-end">
                                <div class="cahu-table__actions justify-content-end">
                                    <a class="btn btn-sm cahu-btn-outline" href="<?= e($report['csv']) ?>">CSV</a>
                                    <a class="btn btn-sm cahu-btn-outline" href="<?= e($report['print']) ?>">Print</a>
                                    <a class="btn btn-sm cahu-btn-primary" href="<?= e($report['url']) ?>">Open</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>