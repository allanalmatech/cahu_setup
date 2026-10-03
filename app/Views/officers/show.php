<?php
/**
 * Field officer dashboard: assigned farmers and gardens, work due this month,
 * and the problems the officer carries.
 *
 * @var Core\View $view
 * @var array<string,mixed> $officer
 * @var array<string,int> $metrics
 * @var array<int,array<string,mixed>> $farmers
 * @var array<int,array<string,mixed>> $gardens
 * @var array<int,array<string,mixed>> $dueActivities
 * @var array<int,array<string,mixed>> $assignments
 * @var array<int,array{value:string,label:string}> $entityTypes
 * @var array<string,array<int,array{value:string,label:string}>> $entityOptions
 */
$entityMap = json_encode(
    $entityOptions,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<div class="cahu-pagehead">
    <div class="d-flex align-items-center gap-3">
        <?php if (!empty($officer['photo'])): ?>
            <img class="cahu-avatar" style="width:56px;height:56px" src="<?= e($officer['photo']) ?>" alt="">
        <?php else: ?>
            <span class="cahu-avatar" style="width:56px;height:56px;font-size:1.5rem">
                <?= e(strtoupper(substr((string) $officer['full_name'], 0, 1))) ?>
            </span>
        <?php endif; ?>
        <div>
            <h1 class="mb-0"><?= e($officer['full_name']) ?></h1>
            <p class="cahu-pagehead__sub mb-0">
                <span class="cahu-table__code"><?= e($officer['officer_code']) ?></span>
                &middot; <?= status_badge((string) $officer['status']) ?>
                <?php if (!empty($officer['employee_no'])): ?>
                    &middot; <?= e($officer['employee_no']) ?>
                <?php endif; ?>
                <?php if (!empty($officer['district_name'])): ?>
                    &middot; <?= e($officer['district_name']) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('farmers?officer_id=' . $officer['id'])) ?>">Farmers</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('officers')) ?>">Back to register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('officers/' . $officer['officer_code'] . '/edit')) ?>">Edit</a>
    </div>
</div>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Assigned farmers</div>
        <div class="cahu-stat__value"><?= e($metrics['farmers']) ?></div>
        <div class="cahu-stat__meta"><?= e($metrics['gardens']) ?> garden(s) in their care</div>
    </div>
    <div class="cahu-stat">
        <div class="cahu-stat__label">Visits this month</div>
        <div class="cahu-stat__value"><?= e($metrics['visits_month']) ?></div>
        <div class="cahu-stat__meta"><?= e($metrics['visits_due']) ?> visit(s) still due</div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Inspections this month</div>
        <div class="cahu-stat__value"><?= e($metrics['inspections_month']) ?></div>
        <div class="cahu-stat__meta"><?= e($metrics['gardens_inspected']) ?> garden(s) inspected</div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Activities due</div>
        <div class="cahu-stat__value"><?= e($metrics['activities_due']) ?></div>
        <div class="cahu-stat__meta">not yet past the due date</div>
    </div>
    <div class="cahu-stat cahu-stat--red">
        <div class="cahu-stat__label">Overdue activities</div>
        <div class="cahu-stat__value"><?= e($metrics['activities_overdue']) ?></div>
        <div class="cahu-stat__meta">past due and not completed</div>
    </div>
    <div class="cahu-stat">
        <div class="cahu-stat__label">Problems reported</div>
        <div class="cahu-stat__value"><?= e($metrics['problems_reported']) ?></div>
        <div class="cahu-stat__meta"><?= e($metrics['problems_open']) ?> still open</div>
    </div>
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Problems resolved</div>
        <div class="cahu-stat__value"><?= e($metrics['problems_resolved']) ?></div>
        <div class="cahu-stat__meta">closed or resolved</div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Farmers visited</div>
        <div class="cahu-stat__value"><?= e($metrics['farmers_visited']) ?></div>
        <div class="cahu-stat__meta">in the current month</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100 mb-3">
            <div class="cahu-card__head">Officer details</div>
            <div class="cahu-card__body">
                <?php
                $details = [
                    'Phone'          => $officer['phone'],
                    'Alternative phone' => $officer['alt_phone'],
                    'Email'          => $officer['email'],
                    'Employee number' => $officer['employee_no'],
                    'Region'         => $officer['region_name'],
                    'District'       => $officer['district_name'],
                    'Added on'       => format_date($officer['created_at'], 'd M Y'),
                ];
                ?>
                <dl class="cahu-kv mb-0">
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
                        <dt><?= e($label) ?></dt>
                        <dd><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
                <?php if (!empty($officer['notes'])): ?>
                    <hr>
                    <div class="text-muted small">Notes</div>
                    <p class="mb-0"><?= nl2br(e($officer['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Portal account</div>
            <div class="cahu-card__body">
                <?php if ($officer['user_id'] !== null): ?>
                    <dl class="cahu-kv mb-3">
                        <dt>Username</dt>
                        <dd><?= e($officer['username'] ?: 'Account removed') ?></dd>
                        <dt>Account email</dt>
                        <dd><?= e($officer['user_email'] ?: '—') ?></dd>
                        <dt>Account status</dt>
                        <dd><?= status_badge($officer['user_status'] !== null ? (string) $officer['user_status'] : null) ?></dd>
                    </dl>
                <?php else: ?>
                    <p class="text-muted small mb-3">
                        No portal account is linked, so this officer cannot sign in to file records or advisories.
                    </p>
                <?php endif; ?>

                <a class="btn cahu-btn-outline w-100"
                   href="<?= e(url('officers/' . $officer['officer_code'] . '/edit')) ?>">
                    <?= $officer['user_id'] === null ? 'Link a portal account' : 'Change portal account' ?>
                </a>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">Assign an area or record</div>
            <div class="cahu-card__body">
                <form method="post" action="<?= e(url('officers/' . $officer['officer_code'] . '/assign')) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="o_entity_type">Assignment type <span class="req">*</span></label>
                        <select class="form-select" id="o_entity_type" name="entity_type" required data-entity-type>
                            <?php foreach ($entityTypes as $option): ?>
                                <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="o_entity_id">Record <span class="req">*</span></label>
                        <select class="form-select" id="o_entity_id" name="entity_id" required data-entity-target>
                            <?php foreach ($entityOptions['farmer'] as $option): ?>
                                <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Assigning a district or sub-county also puts every farmer registered there under this officer.
                        </div>
                    </div>
                    <button class="btn cahu-btn-primary w-100" type="submit">Assign</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Current assignments</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Type</th>
                            <th>Assigned record</th>
                            <th>Assigned on</th>
                            <th>By</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($assignments === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 5,
                                'title'       => 'Nothing assigned yet',
                                'message'     => 'Assign a farmer, garden, group or whole district to start building this officer\'s caseload.',
                                'actionLabel' => null,
                                'actionUrl'   => null,
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td><?= e(ucfirst(str_replace('_', ' ', (string) $assignment['entity_type']))) ?></td>
                                    <td class="fw-semibold"><?= e($assignment['entity_label']) ?></td>
                                    <td><?= e(format_date($assignment['assigned_at'])) ?></td>
                                    <td class="small"><?= e($assignment['assigned_by_name'] ?: '—') ?></td>
                                    <td class="text-end cahu-table__actions">
                                        <form method="post" class="d-inline"
                                              action="<?= e(url('officers/' . $officer['officer_code'] . '/unassign')) ?>"
                                              data-confirm="Remove this assignment?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="entity_type" value="<?= e($assignment['entity_type']) ?>">
                                            <input type="hidden" name="entity_id" value="<?= (int) $assignment['entity_id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Unassign</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Assigned farmers</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Farmer</th>
                            <th>Location</th>
                            <th>Group</th>
                            <th class="text-end">Gardens</th>
                            <th class="text-end">Acres</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($farmers === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 7,
                                'title'       => 'No farmers assigned',
                                'message'     => 'Assign farmers or a whole district from the panel on the left.',
                                'actionLabel' => null,
                                'actionUrl'   => null,
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($farmers as $farmer): ?>
                                <tr>
                                    <td>
                                        <a class="cahu-table__code" href="<?= e(url('farmers/' . $farmer['farmer_code'])) ?>">
                                            <?= e($farmer['farmer_code']) ?>
                                        </a>
                                        <div class="fw-semibold"><?= e($farmer['full_name']) ?></div>
                                        <div class="text-muted small"><?= e($farmer['phone']) ?></div>
                                    </td>
                                    <td><?= e($farmer['district_name'] ?: '—') ?></td>
                                    <td class="small"><?= e($farmer['group_name'] ?: 'No group') ?></td>
                                    <td class="text-end"><?= (int) $farmer['garden_count'] ?></td>
                                    <td class="text-end"><?= e(format_number($farmer['total_acreage'])) ?></td>
                                    <td><?= status_badge((string) $farmer['status']) ?></td>
                                    <td class="text-end cahu-table__actions">
                                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('farmers/' . $farmer['farmer_code'])) ?>">Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">Activities needing attention</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Activity</th>
                            <th>Crop</th>
                            <th>Farmer</th>
                            <th>Due</th>
                            <th>Priority</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($dueActivities === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 6,
                                'title'       => 'Nothing outstanding',
                                'message'     => 'Every activity assigned to this officer is completed and within its due date.',
                                'actionLabel' => null,
                                'actionUrl'   => null,
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($dueActivities as $activity): ?>
                                <tr>
                                    <td>
                                        <a class="cahu-table__code" href="<?= e(url('activities/' . $activity['activity_code'])) ?>">
                                            <?= e($activity['activity_code']) ?>
                                        </a>
                                        <div class="small"><?= e($activity['activity_name']) ?></div>
                                    </td>
                                    <td><?= e($activity['crop_name'] ?: '—') ?></td>
                                    <td>
                                        <a href="<?= e(url('farmers/' . $activity['farmer_code'])) ?>"><?= e($activity['full_name']) ?></a>
                                    </td>
                                    <td><?= e(format_date($activity['due_date'])) ?></td>
                                    <td><?= priority_badge((string) $activity['priority']) ?></td>
                                    <td><?= status_badge((string) $activity['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="cahu-card mt-3">
    <div class="cahu-card__head">Assigned gardens</div>
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr>
                    <th>Garden</th>
                    <th>Farmer</th>
                    <th class="text-end">Acres</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($gardens === []): ?>
                    <?= $view->partial('partials/empty', [
                        'columns'     => 5,
                        'title'       => 'No gardens in the caseload',
                        'message'     => 'Gardens appear here once a farmer or a garden is assigned to this officer.',
                        'actionLabel' => null,
                        'actionUrl'   => null,
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($gardens as $garden): ?>
                        <tr>
                            <td>
                                <a class="cahu-table__code" href="<?= e(url('gardens/' . $garden['garden_code'])) ?>">
                                    <?= e($garden['garden_code']) ?>
                                </a>
                                <div class="text-muted small"><?= e($garden['name']) ?></div>
                            </td>
                            <td>
                                <a href="<?= e(url('farmers/' . $garden['farmer_code'])) ?>"><?= e($garden['full_name']) ?></a>
                            </td>
                            <td class="text-end"><?= e(format_number($garden['acreage'])) ?></td>
                            <td><?= status_badge((string) $garden['status']) ?></td>
                            <td class="text-end cahu-table__actions">
                                <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('gardens/' . $garden['garden_code'])) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<form method="post" class="mt-3"
      action="<?= e(url('officers/' . $officer['officer_code'] . '/delete')) ?>"
      data-confirm="Deactivate this officer? The identifier is retained and will not be reused.">
    <?= csrf_field() ?>
    <button class="btn btn-outline-danger" type="submit">Deactivate officer</button>
</form>

<?php
$pageScripts = [<<<JS
(function () {
    var map = {$entityMap};
    var typeSelect = document.querySelector('[data-entity-type]');
    var target = document.querySelector('[data-entity-target]');
    if (!typeSelect || !target) return;

    function repopulate() {
        var options = map[typeSelect.value] || [];
        target.innerHTML = '';
        options.forEach(function (option) {
            var node = document.createElement('option');
            node.value = option.value;
            node.textContent = option.label;
            target.appendChild(node);
        });
        if (options.length === 0) {
            var none = document.createElement('option');
            none.value = '';
            none.textContent = 'Nothing available to assign';
            target.appendChild(none);
        }
    }

    typeSelect.addEventListener('change', repopulate);
})();
JS];
?>