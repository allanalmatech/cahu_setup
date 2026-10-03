<?php
/**
 * Yield forecast register: expected production against what came off the field.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var array<int,array{value:string|int,label:string}> $seasons
 * @var array<int,array{value:string|int,label:string}> $crops
 * @var array<string,mixed> $summary
 */
ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th>Planting</th>
            <th>Crop &amp; farmer</th>
            <th class="text-end">Acreage</th>
            <th class="text-end">Expected</th>
            <th class="text-end">Revised</th>
            <th class="text-end">Actual</th>
            <th class="text-end">Variance</th>
            <th>Confidence</th>
            <th class="text-end">Revision</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns' => 9,
                'title'   => 'No forecasts yet',
                'message' => 'A forecast is written automatically when a planting is recorded. '
                    . 'Use Generate to backfill a season.',
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $target = $row['revised_production'] !== null
                    ? (float) $row['revised_production']
                    : (float) $row['expected_production'];
                $actual = (float) $row['actual_production'];
                $ahead  = $actual >= $target;
                ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('plantings/' . $row['planting_code'])) ?>"><?= e($row['planting_code']) ?></a>
                        <div class="text-muted small"><?= e(format_date($row['forecast_date'])) ?></div>
                    </td>
                    <td>
                        <span class="fw-semibold"><?= e($row['crop_name']) ?></span>
                        <div class="text-muted small">
                            <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                                <?= e($row['full_name']) ?>
                            </a>
                        </div>
                    </td>
                    <td class="text-end cahu-table__number"><?= e(format_number($row['acreage'], 2)) ?></td>
                    <td class="text-end cahu-table__number">
                        <?= e(format_number($row['expected_production'])) ?>
                        <span class="text-muted small">
                            <?= e(format_number($row['expected_yield_per_acre'], 2)) ?>/acre
                        </span>
                    </td>
                    <td class="text-end cahu-table__number">
                        <?php if ($row['revised_production'] !== null): ?>
                            <?= e(format_number($row['revised_production'])) ?>
                            <div class="text-muted small" title="<?= e($row['revision_reason'] ?? '') ?>">
                                revised
                            </div>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end cahu-table__number">
                        <?php if ($actual > 0): ?>
                            <?= e(format_number($actual)) ?>
                            <span class="text-muted small"><?= e(format_number($row['harvested'])) ?> recorded</span>
                        <?php else: ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end cahu-table__number<?= $actual > 0 ? ($ahead ? ' cahu-txt-success' : ' cahu-txt-danger') : '' ?>">
                        <?php if ($row['variance'] === null): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            <?= e(($row['variance'] >= 0 ? '+' : '') . format_number($row['variance'])) ?>
                            <span class="text-muted small"><?= e(format_number($row['variance_pct'], 1)) ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['confidence'] !== null): ?>
                            <?= status_badge(
                                (float) $row['confidence'] >= 75 ? 'on_track' : 'needs_attention',
                                format_number($row['confidence'], 0) . '%'
                            ) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($row['generated_by'] === 'manual'): ?>
                            <span class="text-muted small">manual</span>
                        <?php else: ?>
                            <button class="btn btn-sm cahu-btn-outline" type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#revise-<?= (int) $row['id'] ?>">
                                Revise
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($row['generated_by'] !== 'manual'): ?>
                    <tr class="collapse" id="revise-<?= (int) $row['id'] ?>">
                        <td colspan="9" class="bg-light">
                            <form method="post" action="<?= e(url('forecast/' . (int) $row['id'] . '/revise')) ?>"
                                  class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <div class="col-md-3">
                                    <label class="form-label" for="revised_yield_per_acre_<?= (int) $row['id'] ?>">
                                        Revised yield per acre
                                    </label>
                                    <input class="form-control" type="number" step="0.001" min="0.001"
                                           id="revised_yield_per_acre_<?= (int) $row['id'] ?>"
                                           name="revised_yield_per_acre"
                                           value="<?= e(format_number($row['expected_yield_per_acre'], 3)) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="revision_reason_<?= (int) $row['id'] ?>">
                                        Reason for the revision
                                    </label>
                                    <input class="form-control" type="text" maxlength="255"
                                           id="revision_reason_<?= (int) $row['id'] ?>"
                                           name="revision_reason"
                                           placeholder="e.g. drought reduced the expected yield"
                                           required>
                                </div>
                                <div class="col-md-3">
                                    <button class="btn cahu-btn-primary w-100" type="submit">Save revision</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$content = ob_get_clean();

$filterOptions = static fn (array $rows, string $placeholder): array => array_merge(
    [['value' => '', 'label' => $placeholder]],
    $rows
);

// Inline season picker for backfilling forecasts on a whole season at once.
ob_start();
?>
<select class="form-select" name="season_id" id="season_id" required>
    <option value="">Select a season...</option>
    <?php foreach ($seasons as $season): ?>
        <option value="<?= e((string) $season['value']) ?>"><?= e($season['label']) ?></option>
    <?php endforeach; ?>
</select>
<?php
$seasonPicker = (string) ob_get_clean();

$view->partial('partials/register', [
    'title'             => 'Yield forecast',
    'subtitle'          => sprintf(
        '%s forecast(s): %s expected, %s revised target, %s actually harvested.',
        number_format((int) ($summary['forecasts'] ?? 0)),
        format_number($summary['expected'] ?? 0),
        format_number($summary['target'] ?? 0),
        format_number($summary['actual'] ?? 0)
    ),
    'actions'           => [
        ['label' => 'Harvest register', 'url' => url('harvest'), 'icon' => '▤', 'style' => 'cahu-btn-outline'],
    ],
    'filters'           => [
        ['key' => 'season_id', 'label' => 'Season', 'options' => $filterOptions($seasons, 'All seasons')],
        ['key' => 'crop_id', 'label' => 'Crop', 'options' => $filterOptions($crops, 'All crops')],
    ],
    'searchPlaceholder' => 'Search by planting, crop or farmer...',
    'clearUrl'          => url('forecast'),
    'paginator'         => $paginator,
    'content'           => $content,
    'before'            => '<form method="get" action="' . e(url('forecast/generate')) . '" class="cahu-inlineform">'
        . '<label class="form-label mb-0" for="season_id">Backfill a season</label>'
        . $seasonPicker
        . '<button class="btn cahu-btn-primary" type="submit">Generate</button>'
        . '</form>',
]);