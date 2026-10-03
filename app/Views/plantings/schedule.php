<?php
/**
 * Activity schedule generated for a planting.
 *
 * @var Core\View $view
 * @var array<string,mixed> $planting
 * @var array<int,array<string,mixed>> $activities
 */
$completed = count(array_filter($activities, static fn (array $a): bool => $a['status'] === 'completed'));
$overdue   = count(array_filter($activities, static function (array $a): bool {
    return $a['status'] !== 'completed' && $a['due_date'] < date('Y-m-d');
}));
?>
<div class="cahu-pagehead">
    <div>
        <h1>Activity schedule</h1>
        <p class="cahu-pagehead__sub">
            <a href="<?= e(url('plantings/' . $planting['planting_code'])) ?>"><?= e($planting['planting_code']) ?></a>
            &middot; <?= e($planting['crop_name']) ?>
            <?php if (!empty($planting['variety_name'])): ?>
                (<?= e($planting['variety_name']) ?>)
            <?php endif; ?>
            &middot; planted <?= e(format_date($planting['planting_date'])) ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('plantings/' . $planting['planting_code'])) ?>">Back to planting</a>
    </div>
</div>

<?php if ($activities === []): ?>
    <div class="cahu-card">
        <div class="cahu-card__body">
            <div class="cahu-empty">
                <div class="cahu-empty__icon">!</div>
                <h3>No lifecycle template is configured</h3>
                <p class="text-muted mb-0">
                    This crop has no activity templates set up, so no tasks were generated. A programme manager can
                    add templates on the crop record and the schedule can then be regenerated from the planting.
                </p>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="cahu-stats mb-3">
        <div class="cahu-stat cahu-stat--green">
            <div class="cahu-stat__label">Tasks</div>
            <div class="cahu-stat__value"><?= e(count($activities)) ?></div>
            <div class="cahu-stat__meta">generated from the crop lifecycle</div>
        </div>
        <div class="cahu-stat cahu-stat--blue">
            <div class="cahu-stat__label">Completed</div>
            <div class="cahu-stat__value"><?= e($completed) ?></div>
            <div class="cahu-stat__meta">of <?= e(count($activities)) ?></div>
        </div>
        <div class="cahu-stat cahu-stat--amber">
            <div class="cahu-stat__label">Overdue</div>
            <div class="cahu-stat__value"><?= e($overdue) ?></div>
            <div class="cahu-stat__meta">past their due date</div>
        </div>
        <div class="cahu-stat cahu-stat--soil">
            <div class="cahu-stat__label">Expected harvest</div>
            <div class="cahu-stat__value" style="font-size:1.1rem">
                <?= e($planting['expected_harvest_date'] !== null ? format_date($planting['expected_harvest_date']) : 'Not set') ?>
            </div>
            <div class="cahu-stat__meta"><?= e($planting['full_name']) ?></div>
        </div>
    </div>

    <div class="cahu-card">
        <div class="cahu-card__body cahu-card__body--flush">
            <div class="table-responsive">
                <table class="table cahu-table align-middle mb-0">
                    <thead>
                    <tr><th>#</th><th>Activity</th><th>Planned</th><th>Due</th><th>Assigned to</th><th>Status</th><th>Completed</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activities as $index => $activity): ?>
                        <?php
                        $late = $activity['status'] !== 'completed' && $activity['due_date'] < date('Y-m-d');
                        ?>
                        <tr<?= $late ? ' class="cahu-row--late"' : '' ?>>
                            <td class="text-muted"><?= e($index + 1) ?></td>
                            <td>
                                <span class="fw-semibold">
                                    <?= e($activity['activity_name'] ?? ucwords(str_replace('_', ' ', (string) $activity['activity_type']))) ?>
                                </span>
                                <?php if (!empty($activity['description'])): ?>
                                    <div class="text-muted small"><?= e($activity['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e(format_date($activity['planned_date'])) ?></td>
                            <td>
                                <?= e(format_date($activity['due_date'])) ?>
                                <?php if ($late): ?>
                                    <div class="text-danger small">overdue</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($activity['assignee_type'] === 'farmer'
                                    ? $planting['full_name'] . ' (farmer)'
                                    : ($activity['officer_name'] ?: ucfirst((string) $activity['assignee_type']))) ?>
                            </td>
                            <td><?= status_badge((string) $activity['status']) ?></td>
                            <td class="small"><?= e($activity['completed_at'] !== null ? format_datetime($activity['completed_at']) : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>