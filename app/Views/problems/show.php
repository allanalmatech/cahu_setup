<?php
/**
 * Problem report detail: the concern, the response, and who is accountable.
 *
 * @var Core\View $view
 * @var array<string,mixed> $problem
 * @var array<int,array<string,mixed>> $photos
 * @var array<int,array<string,mixed>> $relatedInspections
 * @var array<int,string> $nextStatuses
 * @var bool $canManage
 */
$isOpen = !in_array((string) $problem['status'], ['resolved', 'closed'], true);
?>
<div class="cahu-pagehead">
    <div>
        <h1>Problem <?= e($problem['problem_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= status_badge((string) $problem['status']) ?>
            &middot; <?= status_badge((string) $problem['severity']) ?>
            &middot; <?= e($problem['problem_type_name'] ?: 'Unclassified') ?>
            &middot; reported <?= e(format_date($problem['report_date'])) ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('problems')) ?>">Back to register</a>
        <?php if ($canManage): ?>
            <a class="btn cahu-btn-outline" href="<?= e(url('problems/' . $problem['problem_code'] . '/edit')) ?>">Edit</a>
            <?php if ($nextStatuses !== []): ?>
                <a class="btn cahu-btn-primary" href="#advance">Move this report on</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ((int) $problem['is_critical'] === 1): ?>
    <div class="alert alert-danger">
        <strong>Critical problem type.</strong>
        This report is treated as an outbreak-level issue regardless of the severity recorded.
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100 mb-3">
            <div class="cahu-card__head">Report details</div>
            <div class="cahu-card__body">
                <dl class="row mb-0">
                    <?php
                    $details = [
                        'Farmer'          => $problem['farmer_name'] . ' (' . $problem['farmer_code'] . ')',
                        'Garden'          => $problem['garden_code'] !== null
                            ? $problem['garden_code'] . ' · ' . $problem['garden_name']
                            : null,
                        'Crop'            => $problem['crop_name'],
                        'Season'          => $problem['season_code'],
                        'Planting'        => $problem['planting_code'],
                        'Region'          => $problem['region_name'],
                        'District'        => $problem['district_name'],
                        'Area affected'   => $problem['affected_area'] !== null
                            ? format_number($problem['affected_area'], 3) . ' acres'
                            : null,
                        'Reported by'     => ucwords(str_replace('_', ' ', (string) $problem['reported_by_type'])),
                        'Assigned officer' => $problem['officer_name'] ?? 'Nobody yet',
                        'Officer phone'   => $problem['officer_phone'],
                        'GPS'             => ($problem['latitude'] !== null && $problem['longitude'] !== null)
                            ? round((float) $problem['latitude'], 5) . ', ' . round((float) $problem['longitude'], 5)
                            : null,
                        'Resolved'        => $problem['resolved_at'] !== null
                            ? format_datetime($problem['resolved_at'])
                            : null,
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
                        <dt class="col-5 col-sm-4 text-muted fw-normal"><?= e($label) ?></dt>
                        <dd class="col-7 col-sm-8"><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>

        <?php if ($photos !== []): ?>
            <div class="cahu-card">
                <div class="cahu-card__head">Evidence</div>
                <div class="cahu-card__body">
                    <div class="row g-3">
                        <?php foreach ($photos as $photo): ?>
                            <div class="col-6">
                                <a href="<?= e((string) $photo['path']) ?>" target="_blank" rel="noopener">
                                    <img class="cahu-evidence" src="<?= e((string) $photo['path']) ?>"
                                         alt="<?= e($photo['caption'] ?: 'Problem evidence') ?>">
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-8">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">What was reported</div>
            <div class="cahu-card__body">
                <?php /* The farmer's own words, kept verbatim. */ ?>
                <p class="mb-0" style="font-size:1rem"><?= nl2br(e($problem['description'])) ?></p>
            </div>
        </div>

        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Response</div>
            <div class="cahu-card__body">
                <?php if (!empty($problem['action_taken'])): ?>
                    <div class="form-section__title">Action taken</div>
                    <p><?= nl2br(e($problem['action_taken'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($problem['resolution'])): ?>
                    <div class="form-section__title">Resolution</div>
                    <p class="mb-0"><?= nl2br(e($problem['resolution'])) ?></p>
                <?php endif; ?>

                <?php if (empty($problem['action_taken']) && empty($problem['resolution'])): ?>
                    <?php if ($isOpen): ?>
                        <p class="text-muted mb-0">
                            Nothing has been done about this yet. Until action is recorded the report stays open,
                            and the farmer is not told it is resolved.
                        </p>
                    <?php else: ?>
                        <p class="text-muted mb-0">No response was recorded before this report was closed.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canManage && $nextStatuses !== []): ?>
            <div class="cahu-card mb-3" id="advance">
                <div class="cahu-card__head">Move this report on</div>
                <div class="cahu-card__body">
                    <p class="text-muted small">
                        Only the next valid steps are offered. A report cannot skip the person who has to act on
                        it, and it cannot go back to "reported" once someone has worked on it.
                    </p>

                    <form method="post" action="<?= e(url('problems/' . $problem['problem_code'] . '/status')) ?>">
                        <?= csrf_field() ?>

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="next_status">Next status</label>
                                <select class="form-select" id="next_status" name="status" required>
                                    <?php foreach ($nextStatuses as $status): ?>
                                        <option value="<?= e($status) ?>">
                                            <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label" for="assigned_officer_id">
                                    Assign an officer
                                    <?php if (in_array('assigned', $nextStatuses, true)): ?>
                                        <span class="req">*</span>
                                    <?php endif; ?>
                                </label>
                                <select class="form-select" id="assigned_officer_id" name="assigned_officer_id">
                                    <?php foreach ($officerOptions as $option): ?>
                                        <option value="<?= e($option['value']) ?>"
                                            <?= (string) $option['value'] === (string) $problem['assigned_officer_id'] ? 'selected' : '' ?>>
                                            <?= e($option['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="action_taken">Action taken</label>
                                <textarea class="form-control" id="action_taken" name="action_taken" rows="3"
                                          placeholder="What was actually done about this?"><?= e($problem['action_taken'] ?? '') ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="resolution">Resolution</label>
                                <textarea class="form-control" id="resolution" name="resolution" rows="2"
                                          placeholder="The outcome the farmer was told about."><?= e($problem['resolution'] ?? '') ?></textarea>
                                <div class="form-text">
                                    Required before a report can be marked resolved: a report that reads resolved
                                    but says nothing tells the farmer nothing.
                                </div>
                            </div>
                        </div>

                        <div class="cahu-form-actions">
                            <button class="btn cahu-btn-primary" type="submit">Save progress</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($canManage): ?>
            <div class="cahu-card mb-3">
                <div class="cahu-card__head">Move this report on</div>
                <div class="cahu-card__body">
                    <p class="text-muted mb-0">
                        This report is closed and has no further steps. Reports are kept as evidence that the
                        concern was raised, so it cannot be deleted.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($relatedInspections !== []): ?>
            <div class="cahu-card">
                <div class="cahu-card__head">Inspections of this plot around the report date</div>
                <div class="cahu-card__body cahu-card__body--flush">
                    <div class="table-responsive">
                        <table class="table cahu-table align-middle mb-0">
                            <thead>
                            <tr><th>Date</th><th>Condition</th><th>Pests</th><th>Disease</th><th class="text-end"></th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($relatedInspections as $related): ?>
                                <tr>
                                    <td><?= e(format_date($related['inspection_date'])) ?></td>
                                    <td><?= status_badge((string) ($related['crop_condition'] ?? 'not assessed')) ?></td>
                                    <td><?= status_badge((string) ($related['pest_status'] ?? 'none')) ?></td>
                                    <td><?= status_badge((string) ($related['disease_status'] ?? 'none')) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-link text-decoration-none"
                                           href="<?= e(url('inspections/' . $related['inspection_code'])) ?>">Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>