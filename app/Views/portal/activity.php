<?php
/**
 * Field portal: record an activity completion from a phone.
 *
 * @var Core\View $view
 * @var array<string,mixed> $activity
 */
$code       = (string) $activity['activity_code'];
$alreadyDone = $activity['status'] === 'completed';
$late       = !$alreadyDone && (string) $activity['due_date'] < date('Y-m-d');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($activity['activity_name'] ?? ucwords(str_replace('_', ' ', (string) $activity['activity_type']))) ?></h1>
        <p class="cahu-pagehead__sub">
            <span class="cahu-table__code"><?= e($code) ?></span>
            &middot; <?= e($activity['full_name']) ?>
            &middot; <?= e($activity['crop_name'] ?: 'crop removed') ?>
            &middot; <?= e($activity['garden_code']) ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('portal')) ?>">Back to portal</a>
    </div>
</div>

<?php if ($alreadyDone): ?>
    <div class="cahu-card">
        <div class="cahu-card__body">
            <div class="cahu-empty">
                <div class="cahu-empty__icon cahu-empty__icon--ok">&#10003;</div>
                <h3>Already completed</h3>
                <p class="text-muted">
                    Recorded <?= e(format_datetime((string) $activity['completed_at'])) ?>.
                </p>
                <?php if (!empty($activity['completion_remarks'])): ?>
                    <p class="mb-0"><strong>Remarks:</strong> <?= e($activity['completion_remarks']) ?></p>
                <?php endif; ?>
                <?php if (!empty($activity['evidence'])): ?>
                    <p class="mt-2 mb-0">
                        <a href="<?= e($activity['evidence']) ?>" target="_blank" rel="noopener">View evidence photo</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php if ($late): ?>
        <div class="alert alert-warning">
            This task was due on <?= e(format_date((string) $activity['due_date'])) ?>, so it is being recorded late.
        </div>
    <?php endif; ?>

    <div class="cahu-card mb-3">
        <div class="cahu-card__head">Task</div>
        <div class="cahu-card__body">
            <dl class="row mb-0">
                <dt class="col-5 col-sm-3 text-muted fw-normal">Planned</dt>
                <dd class="col-7 col-sm-9"><?= e(format_date((string) $activity['planned_date'])) ?></dd>
                <dt class="col-5 col-sm-3 text-muted fw-normal">Due</dt>
                <dd class="col-7 col-sm-9"><?= e(format_date((string) $activity['due_date'])) ?></dd>
                <dt class="col-5 col-sm-3 text-muted fw-normal">Assigned to</dt>
                <dd class="col-7 col-sm-9">
                    <?= e($activity['assignee_type'] === 'farmer' ? 'Farmer' : 'Officer') ?>
                </dd>
                <?php if (!empty($activity['description'])): ?>
                    <dt class="col-5 col-sm-3 text-muted fw-normal">What to do</dt>
                    <dd class="col-7 col-sm-9"><?= e($activity['description']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <form method="post" action="<?= e(url('portal/activity/' . $code)) ?>" enctype="multipart/form-data" novalidate data-validate>
        <?= csrf_field() ?>

        <div class="cahu-card">
            <div class="cahu-card__head">Confirmation</div>
            <div class="cahu-card__body">
                <div class="row">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f_completion_date">Date done <span class="req">*</span></label>
                        <input class="form-control" type="date" id="f_completion_date" name="completion_date"
                               value="<?= e(old('completion_date', date('Y-m-d'))) ?>"
                               max="<?= e(date('Y-m-d')) ?>" required>
                        <div class="form-text">A completion cannot be dated in the future.</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="f_evidence">Photo evidence</label>
                        <input class="form-control" type="file" id="f_evidence" name="evidence" accept="image/*"
                               capture="environment">
                        <div class="form-text">Optional, unless this activity type requires it.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Where was this done?</label>
                        <div class="row g-2">
                            <div class="col-5">
                                <input class="form-control" type="number" step="0.0000001" id="f_latitude"
                                       name="latitude" placeholder="Latitude"
                                       value="<?= e(old('latitude')) ?>">
                            </div>
                            <div class="col-5">
                                <input class="form-control" type="number" step="0.0000001" id="f_longitude"
                                       name="longitude" placeholder="Longitude"
                                       value="<?= e(old('longitude')) ?>">
                            </div>
                            <div class="col-2 d-grid">
                                <button class="btn cahu-btn-outline" type="button"
                                        onclick="Cahu.locate('f_latitude','f_longitude','f_gps_status')">
                                    GPS
                                </button>
                            </div>
                        </div>
                        <div class="gps-status mt-1" id="f_gps_status">
                            Capturing where the work happened helps when a batch is later traced back to this plot.
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="f_completion_remarks">What was done</label>
                        <textarea class="form-control" id="f_completion_remarks" name="completion_remarks"
                                  rows="4" data-label="What was done"
                                  placeholder="Products used, quantities, anything the officer should know..."
                        ><?= e(old('completion_remarks')) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="cahu-card__foot cahu-form-actions">
                <a class="btn cahu-btn-outline" href="<?= e(url('portal')) ?>">Cancel</a>
                <button class="btn cahu-btn-primary" type="submit">Mark complete</button>
            </div>
        </div>
    </form>

    <p class="text-muted small mt-3">
        This record is kept permanently and appears on the farmer's digital record and on any batch later traced back
        to this plot.
    </p>
<?php endif; ?>