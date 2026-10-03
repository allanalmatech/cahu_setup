<?php
/**
 * Activity record: the task, its evidence, and what came out of it.
 *
 * @var Core\View $view
 * @var array<string,mixed> $activity
 * @var array<string,array<int,array<string,mixed>>> $related
 */
$code        = (string) $activity['activity_code'];
$isCompleted = $activity['status'] === 'completed';
$isLate      = !$isCompleted && (string) $activity['due_date'] < date('Y-m-d');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($activity['activity_name'] ?? ucwords(str_replace('_', ' ', (string) $activity['activity_type']))) ?></h1>
        <p class="cahu-pagehead__sub">
            <span class="cahu-table__code"><?= e($code) ?></span>
            &middot; <?= status_badge((string) $activity['status']) ?>
            &middot; <?= priority_badge((string) $activity['priority']) ?>
            &middot; <?= e($activity['full_name']) ?>
            &middot; <?= e($activity['garden_code']) ?>
            <?= $activity['crop_name'] ? ' &middot; ' . e($activity['crop_name']) : '' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <?php if (!$isCompleted && $activity['status'] !== 'cancelled'): ?>
            <a class="btn cahu-btn-primary" href="<?= e(url('activities/' . $code . '/edit')) ?>">Record work</a>
        <?php endif; ?>
        <a class="btn cahu-btn-outline" href="<?= e(url('activities/' . $code . '/edit')) ?>">Edit</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('activities')) ?>">Back to register</a>
    </div>
</div>

<?php if ($isLate): ?>
    <div class="alert alert-warning">
        This activity was due <?= e(format_date((string) $activity['due_date'])) ?> and is still outstanding
        (<?= e((int) ((strtotime(date('Y-m-d')) - strtotime((string) $activity['due_date'])) / 86400)) ?> day(s) late).
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Task</div>
            <div class="cahu-card__body">
                <dl class="row mb-0">
                    <?php
                    $details = [
                        'Activity type' => $activity['activity_name'] ?? ucwords(str_replace('_', ' ', (string) $activity['activity_type'])),
                        'What to do'    => $activity['description'],
                        'Planned'       => format_date((string) $activity['planned_date']),
                        'Due'           => format_date((string) $activity['due_date']),
                        'Assigned to'   => $activity['assignee_type'] === 'farmer'
                            ? 'Farmer'
                            : ($activity['officer_name'] ?: ucfirst((string) $activity['assignee_type'])),
                        'Officer'       => $activity['officer_name'],
                        'Garden'        => $activity['garden_code'] ? $activity['garden_code'] . ' — ' . $activity['garden_name'] : null,
                        'Crop'          => $activity['crop_name'] ? $activity['crop_name'] . ($activity['variety_name'] ? ' (' . $activity['variety_name'] . ')' : '') : null,
                        'Planting'      => $activity['planting_code'],
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
                        <dt class="col-5 col-sm-4 text-muted fw-normal"><?= e($label) ?></dt>
                        <dd class="col-7 col-sm-8">
                            <?php if ($label === 'Planting'): ?>
                                <a href="<?= e(url('plantings/' . $value)) ?>"><?= e($value) ?></a>
                            <?php else: ?>
                                <?= e($value) ?>
                            <?php endif; ?>
                        </dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Completion</div>
            <div class="cahu-card__body">
                <?php if ($isCompleted): ?>
                    <dl class="row mb-0">
                        <dt class="col-5 col-sm-3 text-muted fw-normal">Completed</dt>
                        <dd class="col-7 col-sm-9"><?= e(format_datetime((string) $activity['completed_at'])) ?></dd>
                        <dt class="col-5 col-sm-3 text-muted fw-normal">Recorded by</dt>
                        <dd class="col-7 col-sm-9"><?= e($activity['completed_by_name'] ?: 'System') ?></dd>
                        <?php if (!empty($activity['completion_remarks'])): ?>
                            <dt class="col-5 col-sm-3 text-muted fw-normal">Remarks</dt>
                            <dd class="col-7 col-sm-9"><?= nl2br(e($activity['completion_remarks'])) ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php if (!empty($activity['evidence'])): ?>
                        <div class="mt-3">
                            <div class="text-muted small mb-1">Evidence</div>
                            <a href="<?= e($activity['evidence']) ?>" target="_blank" rel="noopener">
                                <img class="cahu-evidence" src="<?= e($activity['evidence']) ?>" alt="Completion evidence">
                            </a>
                        </div>
                    <?php endif; ?>
                <?php elseif ($activity['status'] === 'cancelled'): ?>
                    <p class="text-muted mb-0">This activity was cancelled.</p>
                <?php else: ?>
                    <p class="mb-3">This task has not been recorded as done yet.</p>
                    <div class="row g-2">
                        <div class="col-12 col-sm-auto">
                            <a class="btn cahu-btn-primary" href="<?= e(url('portal/activity/' . $code)) ?>">Record completion</a>
                        </div>
                        <div class="col-12 col-sm-auto">
                            <form method="post" action="<?= e(url('activities/' . $code . '/cancel')) ?>"
                                  onsubmit="return confirm('Cancel this activity?')">
                                <?= csrf_field() ?>
                                <button class="btn cahu-btn-outline" type="submit">Cancel task</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$blocks = [
    ['title' => 'Inputs applied', 'rows' => $related['usage'], 'columns' => ['application_date', 'input_name', 'purpose', 'quantity_used', 'unit']],
    ['title' => 'Spraying', 'rows' => $related['sprays'], 'columns' => ['application_date', 'product_name', 'quantity_used', 'unit', 'pest_disease_targeted']],
    ['title' => 'Inspections', 'rows' => $related['inspections'], 'columns' => ['inspection_date', 'crop_condition', 'pest_status', 'disease_status', 'recommendations']],
    ['title' => 'Harvests', 'rows' => $related['harvests'], 'columns' => ['harvest_date', 'quantity_harvested', 'unit', 'quality', 'grade']],
];
$hasAny = array_filter($blocks, static fn (array $block): bool => $block['rows'] !== []);
?>

<?php if ($hasAny !== []): ?>
    <div class="cahu-card mt-3">
        <div class="cahu-card__head">What came out of this task</div>
        <div class="cahu-card__body">
            <?php foreach ($hasAny as $block): ?>
                <div class="mb-4">
                    <div class="form-section__title"><?= e($block['title']) ?></div>
                    <div class="table-responsive">
                        <table class="table table-sm cahu-table align-middle mb-0">
                            <thead>
                            <tr>
                                <?php foreach ($block['columns'] as $column): ?>
                                    <th><?= e(ucwords(str_replace('_', ' ', $column))) ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($block['rows'] as $row): ?>
                                <tr>
                                    <?php foreach ($block['columns'] as $column): ?>
                                        <td><?= e($row[$column] === null ? '—' : $row[$column]) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>