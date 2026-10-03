<?php
/**
 * A single report extract: filters, the filters actually in force, and the rows.
 *
 * @var string $pageTitle
 * @var string $slug
 * @var array<string,mixed> $definition
 * @var array<int,string> $columns
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,mixed> $filters
 * @var array<string,string> $applied
 * @var array<string,array<int,array{value:string,label:string}>> $options
 * @var bool $truncated
 * @var string $sort
 */
$numberColumns = [
    'Gardens', 'Acreage', 'Acres', 'Activities completed', 'Activities total', 'Gardens inspected', 'Problems',
    'Harvest', 'Plantings', 'Farmers', 'Varieties', 'Yield / acre', 'Balance', 'Received', 'Issued', 'Used',
    'Demand', 'Available', 'Committed', 'Sold', 'Sales value', 'Buyers', 'Requirements', 'Deliveries',
    'Expected', 'Revised', 'Actual', 'Variance',
];

$percentageColumns = ['Completion %', 'Variance %'];

$dateColumns = ['Registered', 'Planned', 'Due', 'Logged'];

$datetimeColumns = ['Completed'];

$accepted = in_array('from', $definition['filters'], true) || in_array('to', $definition['filters'], true);
$scoped  = array_values(array_intersect($definition['filters'], ['season_id', 'district_id', 'crop_id', 'group_id']));

// Only the user's own filters travel with the export and print links; the
// internal row ceiling is not part of the address.
$exportQuery = http_build_query(array_filter(
    [
        'from'        => $filters['from'] ?? null,
        'to'          => $filters['to'] ?? null,
        'season_id'   => $filters['season_id'] ?? null,
        'district_id' => $filters['district_id'] ?? null,
        'crop_id'     => $filters['crop_id'] ?? null,
        'group_id'    => $filters['group_id'] ?? null,
    ],
    static fn ($value): bool => $value !== null && $value !== ''
));

/**
 * Renders one cell in the shape its column calls for: dates read as dates,
 * counts with a thousands separator, percentages marked as such.
 */
$cell = static function (?string $column, mixed $value): string {
    if ($value === null || $value === '') {
        return '&mdash;';
    }

    if (in_array($column, $dateColumns, true)) {
        return e(format_date((string) $value));
    }

    if (in_array($column, $datetimeColumns, true)) {
        return e(format_datetime((string) $value));
    }

    if (in_array($column, $percentageColumns, true) && is_numeric($value)) {
        return e(format_number($value, 1) . '%');
    }

    if (in_array($column, $numberColumns, true) && is_numeric($value)) {
        return e(format_number($value));
    }

    return e((string) $value);
};
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub"><?= e($definition['description']) ?></p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('reports')) ?>">All reports</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('reports/' . $slug . '/csv') . ($exportQuery === '' ? '' : '?' . $exportQuery)) ?>">Export CSV</a>
        <a class="btn cahu-btn-primary" href="<?= e(url('reports/' . $slug . '/print') . ($exportQuery === '' ? '' : '?' . $exportQuery)) ?>">Print</a>
    </div>
</div>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">Filters</div>
    <div class="cahu-card__body">
        <form class="cahu-filters" method="get" action="<?= e(url('reports/' . $slug)) ?>">
            <?php if ($accepted): ?>
                <div>
                    <label class="form-label" for="filter-from">From</label>
                    <input class="form-control" type="date" name="from" id="filter-from" value="<?= e($filters['from'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label" for="filter-to">To</label>
                    <input class="form-control" type="date" name="to" id="filter-to" value="<?= e($filters['to'] ?? '') ?>">
                </div>
            <?php endif; ?>

            <?php foreach ($scoped as $key): ?>
                <?php if (!isset($options[$key])) { continue; } ?>
                <div>
                    <label class="form-label" for="filter-<?= e($key) ?>">
                        <?= e(ucwords(str_replace('_id', '', $key))) ?>
                    </label>
                    <select class="form-select" name="<?= e($key) ?>" id="filter-<?= e($key) ?>" data-autosubmit>
                        <?php foreach ($options[$key] as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= (string) ($filters[$key] ?? '') === (string) $option['value'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>

            <noscript><button class="btn cahu-btn-outline btn-sm" type="submit">Apply</button></noscript>

            <?php if ($applied !== []): ?>
                <a class="btn btn-link btn-sm text-decoration-none" href="<?= e(url('reports/' . $slug)) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <div class="text-muted small mt-2">
            <?php if ($applied === []): ?>
                No filters applied &mdash; every record in the register is included.
            <?php else: ?>
                Filters applied:
                <?php foreach ($applied as $label => $value): ?>
                    <span class="badge text-bg-light text-dark"><?= e($label) ?>: <?= e($value) ?></span>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($truncated): ?>
                <span class="badge text-bg-warning">
                    Showing the first <?= e(count($rows)) ?> rows
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="cahu-card">
    <div class="cahu-card__head"><?= e($definition['title']) ?> &middot; <?= e(count($rows)) ?> row(s)</div>
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <?php if (!isset($definition['sortable'][$column])): ?>
                            <th><?= e($column) ?></th>
                        <?php else: ?>
                            <?php
                            $ascending  = $definition['sortable'][$column] === 'asc';
                            $nextSort   = $sort === $column && $ascending ? '' : $column;
                            $linkQuery  = $nextSort === '' ? query_string(['sort' => null]) : query_string(['sort' => $nextSort]);
                            ?>
                            <th><a class="cahu-sortlink" href="?<?= e($linkQuery) ?>"><?= e($column) ?><?php if ($sort === $column): ?><?= $ascending ? ' &uarr;' : ' &darr;' ?><?php endif; ?></a></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <?= $view->partial('partials/empty', [
                        'columns' => count($columns),
                        'title'   => 'Nothing to report',
                        'message' => 'No records match the filters applied to this report.',
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <td><?= $cell($column, $row[$column] ?? null) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($applied !== [] || $truncated): ?>
        <div class="cahu-card__foot">
            <div class="text-muted small">
                This extract reflects the filters shown above. Printing or exporting carries the same filters with it.
            </div>
        </div>
    <?php endif; ?>
</div>