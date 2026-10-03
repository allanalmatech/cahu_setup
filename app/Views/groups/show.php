<?php
/**
 * Group dashboard: membership, land, crops, production, activity, inputs,
 * problems and the value realised by the members.
 *
 * @var Core\View $view
 * @var array<string,mixed> $group
 * @var array<int,array<string,mixed>> $members
 * @var array<string,mixed> $stats
 * @var array<int,array<string,mixed>> $crops
 * @var array<int,array<string,mixed>> $openProblems
 * @var array<int,array{value:string,label:string}> $availableFarmers
 * @var array<int,array{value:string,label:string}> $roleOptions
 */
$activities = $stats['activities'];
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($group['name']) ?></h1>
        <p class="cahu-pagehead__sub">
            <span class="cahu-table__code"><?= e($group['group_code']) ?></span>
            &middot; <?= status_badge((string) $group['status']) ?>
            &middot; <?= e(ucwords(str_replace('_', ' ', (string) $group['group_type']))) ?>
            <?php if (!empty($group['registration_no'])): ?>
                &middot; <?= e($group['registration_no']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('farmers?group_id=' . $group['id'])) ?>">Members</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('groups')) ?>">Back to register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('groups/' . $group['group_code'] . '/edit')) ?>">Edit</a>
    </div>
</div>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Members</div>
        <div class="cahu-stat__value"><?= e($stats['members']) ?></div>
        <div class="cahu-stat__meta"><?= e($stats['officers']) ?> field officer(s) involved</div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Land under cultivation</div>
        <div class="cahu-stat__value"><?= e(format_number($stats['acreage'])) ?></div>
        <div class="cahu-stat__meta">acres across <?= e($stats['gardens']) ?> garden(s)</div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Harvest vs forecast</div>
        <div class="cahu-stat__value"><?= e(format_quantity($stats['actual'])) ?></div>
        <div class="cahu-stat__meta">of <?= e(format_quantity($stats['expected'])) ?> expected</div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Activities completed</div>
        <div class="cahu-stat__value"><?= e($activities['completed']) ?></div>
        <div class="cahu-stat__meta">
            <?= (int) $activities['pending'] ?> pending &middot;
            <?= (int) $activities['due'] ?> due &middot;
            <?= (int) $activities['overdue'] ?> overdue
        </div>
    </div>
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Inputs issued</div>
        <div class="cahu-stat__value"><?= e(format_quantity($stats['inputs'], $stats['input_unit'])) ?></div>
        <div class="cahu-stat__meta">received by member farmers</div>
    </div>
    <div class="cahu-stat">
        <div class="cahu-stat__label">Open problems</div>
        <div class="cahu-stat__value"><?= e($stats['problems']) ?></div>
        <div class="cahu-stat__meta">awaiting a resolution</div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Sales value</div>
        <div class="cahu-stat__value"><?= e(format_money($stats['sales_value'])) ?></div>
        <div class="cahu-stat__meta">from member produce</div>
    </div>
    <div class="cahu-stat">
        <div class="cahu-stat__label">Crops grown</div>
        <div class="cahu-stat__value"><?= e(count($crops)) ?></div>
        <div class="cahu-stat__meta">distinct crops on member farms</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100 mb-3">
            <div class="cahu-card__head">Group details</div>
            <div class="cahu-card__body">
                <?php
                $details = [
                    'Registration number' => $group['registration_no'],
                    'Registration date'   => !empty($group['registration_date']) ? format_date($group['registration_date']) : null,
                    'Region'              => $group['region_name'],
                    'District'            => $group['district_name'],
                    'Sub-county'          => $group['sub_county_name'],
                    'Parish'              => $group['parish_name'],
                    'Village'             => $group['registered_village_name'],
                    'Address'             => $group['address'],
                    'Contact person'      => $group['contact_person'],
                    'Phone'               => $group['phone'],
                    'Email'               => $group['email'],
                    'GPS'                 => ($group['latitude'] !== null && $group['longitude'] !== null)
                        ? round((float) $group['latitude'], 5) . ', ' . round((float) $group['longitude'], 5)
                        : null,
                ];
                ?>
                <dl class="cahu-kv mb-0">
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
                        <dt><?= e($label) ?></dt>
                        <dd><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
                <?php if (!empty($group['description'])): ?>
                    <hr>
                    <div class="text-muted small">Description</div>
                    <p class="mb-0"><?= nl2br(e($group['description'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">Add member</div>
            <div class="cahu-card__body">
                <form method="post" action="<?= e(url('groups/' . $group['group_code'] . '/members')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="g_farmer">Farmer <span class="req">*</span></label>
                        <select class="form-select" id="g_farmer" name="farmer_id" required>
                            <?php if ($availableFarmers === []): ?>
                                <option value="">No unassigned farmers available</option>
                            <?php else: ?>
                                <?php foreach ($availableFarmers as $option): ?>
                                    <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <div class="form-text">
                            Only farmers who are not yet in a group can be added, because membership is single valued.
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="g_role">Role</label>
                            <select class="form-select" id="g_role" name="role_in_group">
                                <?php foreach ($roleOptions as $option): ?>
                                    <option value="<?= e($option['value']) ?>" <?= $option['value'] === 'member' ? 'selected' : '' ?>>
                                        <?= e($option['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="g_joined">Joined date</label>
                            <input class="form-control" type="date" id="g_joined" name="joined_date"
                                   value="<?= e(date('Y-m-d')) ?>">
                        </div>
                    </div>
                    <button class="btn cahu-btn-primary w-100 mt-3" type="submit"
                            <?= $availableFarmers === [] ? 'disabled' : '' ?>>
                        Add member
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Member register</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Farmer</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <th>Location</th>
                            <th class="text-end">Gardens</th>
                            <th class="text-end">Acres</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($members === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 8,
                                'title'       => 'No members yet',
                                'message'     => 'Add the founding members so the group dashboard can report their land, crops and production.',
                                'actionLabel' => null,
                                'actionUrl'   => null,
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($members as $member): ?>
                                <tr>
                                    <td>
                                        <a class="cahu-table__code" href="<?= e(url('farmers/' . $member['farmer_code'])) ?>">
                                            <?= e($member['farmer_code']) ?>
                                        </a>
                                        <div class="fw-semibold"><?= e($member['full_name']) ?></div>
                                        <div class="text-muted small"><?= e($member['phone']) ?></div>
                                    </td>
                                    <td><?= e(ucwords(str_replace('_', ' ', (string) $member['role_in_group']))) ?></td>
                                    <td><?= e(format_date($member['joined_date'])) ?></td>
                                    <td class="small">
                                        <?= e($member['district_name'] ?: '—') ?>
                                        <?php if (!empty($member['village_name'])): ?>
                                            <div class="text-muted"><?= e($member['village_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= (int) $member['garden_count'] ?></td>
                                    <td class="text-end"><?= e(format_number($member['total_acreage'])) ?></td>
                                    <td><?= status_badge((string) $member['farmer_status']) ?></td>
                                    <td class="text-end cahu-table__actions">
                                        <form method="post" class="d-inline"
                                              action="<?= e(url('groups/' . $group['group_code'] . '/members/remove')) ?>"
                                              data-confirm="Remove <?= e($member['full_name']) ?> from this group?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="farmer_id" value="<?= (int) $member['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
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

        <div class="cahu-card">
            <div class="cahu-card__head">Crops on member farms</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Crop</th>
                            <th>Category</th>
                            <th class="text-end">Farmers</th>
                            <th class="text-end">Plantings</th>
                            <th class="text-end">Acres</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($crops === []): ?>
                            <tr><td colspan="5" class="text-muted p-3">
                                No plantings recorded for member farmers yet.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($crops as $crop): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($crop['crop_name']) ?></td>
                                    <td><?= e($crop['category'] ? ucwords(str_replace('_', ' ', (string) $crop['category'])) : '—') ?></td>
                                    <td class="text-end"><?= (int) $crop['farmer_count'] ?></td>
                                    <td class="text-end"><?= (int) $crop['planting_count'] ?></td>
                                    <td class="text-end"><?= e(format_number($crop['acreage'])) ?></td>
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
    <div class="cahu-card__head">Open problems</div>
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr>
                    <th>Problem</th>
                    <th>Type</th>
                    <th>Farmer</th>
                    <th>Reported</th>
                    <th>Severity</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($openProblems === []): ?>
                    <?= $view->partial('partials/empty', [
                        'columns'     => 6,
                        'title'       => 'No open problems',
                        'message'     => 'Nothing is currently blocking this group\'s members. Resolved problems are kept on each farmer record.',
                        'actionLabel' => null,
                        'actionUrl'   => null,
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($openProblems as $problem): ?>
                        <tr>
                            <td>
                                <a class="cahu-table__code" href="<?= e(url('problems/' . $problem['problem_code'])) ?>">
                                    <?= e($problem['problem_code']) ?>
                                </a>
                                <div class="small"><?= e($problem['description']) ?></div>
                            </td>
                            <td><?= e($problem['problem_type'] ?: '—') ?></td>
                            <td>
                                <a href="<?= e(url('farmers/' . $problem['farmer_code'])) ?>"><?= e($problem['full_name']) ?></a>
                            </td>
                            <td><?= e(format_date($problem['report_date'])) ?></td>
                            <td><?= priority_badge((string) $problem['severity']) ?></td>
                            <td><?= status_badge((string) $problem['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<form method="post" class="mt-3"
      action="<?= e(url('groups/' . $group['group_code'] . '/delete')) ?>"
      data-confirm="Deactivate this group? The identifier is retained and will not be reused.">
    <?= csrf_field() ?>
    <button class="btn btn-outline-danger" type="submit">Deactivate group</button>
</form>