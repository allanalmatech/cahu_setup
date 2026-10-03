<?php
/**
 * Print-optimised report. Rendered with no application layout, so the document
 * goes straight to a printer or a PDF driver.
 *
 * @var string $pageTitle
 * @var string $slug
 * @var array<string,mixed> $definition
 * @var array<int,string> $columns
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,mixed> $filters
 * @var array<string,string> $applied
 * @var bool $truncated
 */
$numberColumns = [
    'Gardens', 'Acreage', 'Acres', 'Activities completed', 'Activities total', 'Gardens inspected', 'Problems',
    'Harvest', 'Plantings', 'Farmers', 'Varieties', 'Yield / acre', 'Balance', 'Received', 'Issued', 'Used',
    'Demand', 'Available', 'Committed', 'Sold', 'Sales value', 'Buyers', 'Requirements', 'Deliveries',
    'Expected', 'Revised', 'Actual', 'Variance',
];

$percentageColumns = ['Completion %', 'Variance %'];
$dateColumns       = ['Registered', 'Planned', 'Due', 'Logged'];
$datetimeColumns   = ['Completed'];

$cell = static function (?string $column, mixed $value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    if (in_array($column, $dateColumns, true)) {
        return format_date((string) $value);
    }

    if (in_array($column, $datetimeColumns, true)) {
        return format_datetime((string) $value);
    }

    if (in_array($column, $percentageColumns, true) && is_numeric($value)) {
        return format_number($value, 1) . '%';
    }

    if (in_array($column, $numberColumns, true) && is_numeric($value)) {
        return format_number($value);
    }

    return (string) $value;
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> &middot; <?= e($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <style>
        body { background: #f4f6f5; }
        .report-sheet { background: #fff; }
        .report-table { font-size: .78rem; }
        .report-table th, .report-table td { padding: .35rem .5rem; white-space: nowrap; }
        .report-table thead th { background: #f4f7f5; font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; }
        .report-actions { position: sticky; top: 0; z-index: 10; }

        @media print {
            body { background: #fff; }
            .report-actions, .no-print { display: none !important; }
            .report-sheet { padding: 0 !important; box-shadow: none !important; }
            .report-table { font-size: .72rem; }
            .report-table thead { display: table-header-group; }
            .report-table tr { page-break-inside: avoid; }
            @page { margin: 14mm; }
        }
    </style>
</head>
<body>
<div class="report-actions no-print border-bottom bg-white py-2 mb-3">
    <div class="container d-flex flex-wrap align-items-center gap-2">
        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('reports/' . $slug)) ?>">Back to report</a>
        <button class="btn btn-sm cahu-btn-primary" type="button" onclick="window.print()">Print this report</button>
        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('reports/' . $slug . '/csv')) ?>">Export CSV</a>
        <span class="text-muted small ms-auto"><?= e(count($rows)) ?> row(s)</span>
    </div>
</div>

<div class="container report-sheet p-4 mb-4">
    <div class="cahu-print-head d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
        <div>
            <h1 class="h4 mb-1"><?= e($definition['title']) ?></h1>
            <div class="text-muted">
                <?= e($appName) ?> &middot; <?= e($definition['module']) ?> &middot; printed <?= e(format_date(date('Y-m-d'))) ?>
            </div>
        </div>
        <div class="text-end small">
            <?php if ($applied === []): ?>
                <div class="text-muted">No filters applied</div>
            <?php else: ?>
                <div class="text-muted">Filters applied</div>
                <?php foreach ($applied as $label => $value): ?>
                    <div><strong><?= e($label) ?>:</strong> <?= e($value) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($truncated): ?>
        <div class="alert alert-warning py-2 small">
            Only the first <?= e(count($rows)) ?> rows are shown. Narrow the filters to see the rest of the register.
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm report-table mb-0">
            <thead>
            <tr>
                <?php foreach ($columns as $column): ?>
                    <th><?= e($column) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= e(count($columns)) ?>" class="text-muted">No records match the filters applied to this report.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <td><?= e($cell($column, $row[$column] ?? null)) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p class="text-muted small mt-4 mb-0">
        Generated from the CAHU platform on <?= e(format_date(date('Y-m-d'))) ?>. Figures reflect the filters stated above and are read-only.
    </p>
</div>
</body>
</html>