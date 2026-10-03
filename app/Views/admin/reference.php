<?php
/**
 * Configurable reference data: the administrative hierarchy and the vocabularies
 * the rest of the platform picks from.
 *
 * Each set has an inline add form beside its current list, because these tables
 * fill in as the organisation works rather than being loaded once up front. The
 * four forms share this screen, so their field names are prefixed to keep a
 * validation error on the form that caused it.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $regions
 * @var array<int,array<string,mixed>> $districts
 * @var array<int,array<string,mixed>> $subCounties
 * @var array<int,array<string,mixed>> $parishes
 * @var array<int,array<string,mixed>> $villages
 * @var array<int,array<string,mixed>> $activityTypes
 * @var array<int,array<string,mixed>> $problemTypes
 * @var array<int,array<string,mixed>> $inputCategories
 * @var array<string,array<int,array<string,mixed>>> $parentOptions
 */
$levels = [
    'region'     => ['label' => 'Regions',      'rows' => $regions,     'parent_column' => null,              'parent_label' => '',            'name_max' => 80],
    'district'   => ['label' => 'Districts',    'rows' => $districts,   'parent_column' => 'region_name',    'parent_label' => 'Region',      'name_max' => 100],
    'sub_county' => ['label' => 'Sub-counties', 'rows' => $subCounties, 'parent_column' => 'district_name',  'parent_label' => 'District',    'name_max' => 100],
    'parish'     => ['label' => 'Parishes',     'rows' => $parishes,    'parent_column' => 'sub_county_name', 'parent_label' => 'Sub-county', 'name_max' => 100],
    'village'    => ['label' => 'Villages',     'rows' => $villages,    'parent_column' => 'parish_name',    'parent_label' => 'Parish',      'name_max' => 120],
];
?>
<div class="cahu-pagehead">
    <div>
        <h1>Reference data</h1>
        <p class="cahu-pagehead__sub">
            The lists every other screen selects from. Adding an entry makes it available immediately; existing records
            are never rewritten by a change to a lookup.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('admin')) ?>">Settings</a>
    </div>
</div>

<?php if ($errors = \Core\Flash::errors()): ?>
    <div class="alert alert-danger">
        <strong>Please correct the following:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php
// Shared rather than assigned locally: the layout is captured with the data the
// controller passed, so a variable set here would never reach it.
$view->share('pageScripts', [
    <<<'JS'
    (function () {
        var levelSelect = document.getElementById('ref_level');
        var needed = { region: null, district: 'region', sub_county: 'district', parish: 'sub_county', village: 'parish' };

        function sync() {
            var wanted = needed[levelSelect.value];
            var chosen = levelSelect.options[levelSelect.selectedIndex];
            var nameField = document.getElementById('ref_name');

            // Each level's column is a different width, so the client limit has
            // to follow the level. The server re-checks it either way.
            if (nameField && chosen) {
                nameField.setAttribute('maxlength', chosen.getAttribute('data-max'));
            }

            document.querySelectorAll('[data-parent-level]').forEach(function (wrapper) {
                var show = wrapper.getAttribute('data-parent-level') === wanted;
                wrapper.classList.toggle('d-none', !show);
                wrapper.querySelector('select').disabled = !show;
            });
        }

        levelSelect.addEventListener('change', sync);
        sync();
    })();
    JS,
]);
?>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">Add a place</div>
    <div class="cahu-card__body">
        <p class="text-muted small">
            The hierarchy runs region, district, sub-county, parish, village. Every level below a region needs its
            parent, and a place only becomes selectable once its parent exists.
        </p>

        <form method="post" action="<?= e(url('admin/reference/locations')) ?>" novalidate data-validate>
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-12 col-md-3 mb-3">
                    <label class="form-label" for="ref_level">Level <span class="req">*</span></label>
                    <select class="<?= invalid_class('loc_type') ?> form-select" id="ref_level" name="loc_type" required>
                        <?php foreach ($levels as $value => $meta): ?>
                            <option value="<?= e($value) ?>" data-max="<?= (int) $meta['name_max'] ?>"
                                    <?= $value === 'region' ? 'selected' : '' ?>>
                                <?= e($meta['label']) ?> (<?= e(count($meta['rows'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (has_error('loc_type')): ?>
                        <div class="invalid-feedback d-block"><?= e(error_for('loc_type')) ?></div>
                    <?php endif; ?>
                </div>

                <?php foreach ($parentOptions as $level => $options): ?>
                    <div class="col-12 col-md-3 mb-3" data-parent-level="<?= e($level) ?>">
                        <label class="form-label" for="ref_parent_<?= e($level) ?>">
                            <?= e(ucwords(str_replace('_', ' ', (string) $level))) ?>
                        </label>
                        <select class="<?= invalid_class('loc_parent_id') ?> form-select"
                                id="ref_parent_<?= e($level) ?>" name="loc_parent_id">
                            <?php foreach ($options as $option): ?>
                                <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (has_error('loc_parent_id')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('loc_parent_id')) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="col-12 col-md-3 mb-3">
                    <label class="form-label" for="ref_name">Name <span class="req">*</span></label>
                    <input class="<?= invalid_class('loc_name') ?> form-control" type="text" id="ref_name" name="loc_name"
                           value="<?= e(old('loc_name')) ?>" required maxlength="80" data-label="Name">
                    <?php if (has_error('loc_name')): ?>
                        <div class="invalid-feedback d-block"><?= e(error_for('loc_name')) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-12 col-md-3 mb-3">
                    <label class="form-label" for="ref_code">Code</label>
                    <input class="<?= invalid_class('loc_code') ?> form-control" type="text" id="ref_code" name="loc_code"
                           value="<?= e(old('loc_code')) ?>" maxlength="20" data-label="Code">
                    <?php if (has_error('loc_code')): ?>
                        <div class="invalid-feedback d-block"><?= e(error_for('loc_code')) ?></div>
                    <?php else: ?>
                        <div class="form-text">Left blank it is taken from the name. Used by regions and districts.</div>
                    <?php endif; ?>
                </div>
            </div>

            <button class="btn cahu-btn-primary" type="submit">Add place</button>
        </form>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($levels as $key => $level): ?>
        <div class="col-12 col-xl-6">
            <div class="cahu-card h-100">
                <div class="cahu-card__head">
                    <?= e($level['label']) ?>
                    <span class="tab-count"><?= e(count($level['rows'])) ?></span>
                </div>
                <div class="cahu-card__body cahu-card__body--flush">
                    <?php if ($level['rows'] === []): ?>
                        <div class="cahu-empty">
                            <h3>None yet</h3>
                            <p>Add the first <?= e(strtolower($level['label'])) ?> with the form above.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table cahu-table align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <?php if ($level['parent_label'] !== ''): ?>
                                        <th><?= e($level['parent_label']) ?></th>
                                    <?php endif; ?>
                                    <?php if ($key === 'region' || $key === 'district'): ?>
                                        <th>Code</th>
                                    <?php endif; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($level['rows'] as $row): ?>
                                    <tr>
                                        <td><?= e($row['name']) ?></td>
                                        <?php if ($level['parent_label'] !== ''): ?>
                                            <td class="small text-muted"><?= e($row[$level['parent_column']] ?: '—') ?></td>
                                        <?php endif; ?>
                                        <?php if ($key === 'region' || $key === 'district'): ?>
                                            <td class="cahu-table__code"><?= e($row['code'] ?: '—') ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Activity types</div>
            <div class="cahu-card__body">
                <p class="text-muted small">
                    The vocabulary of work on a crop. These fill the activity schedule whenever a planting is recorded.
                </p>

                <form method="post" action="<?= e(url('admin/reference/activity-types')) ?>" novalidate data-validate>
                    <?= csrf_field() ?>

                    <div class="row">
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label" for="act_name">Activity name <span class="req">*</span></label>
                            <input class="<?= invalid_class('act_name') ?> form-control" type="text" id="act_name"
                                   name="act_name" required maxlength="80" data-label="Activity name">
                            <?php if (has_error('act_name')): ?>
                                <div class="invalid-feedback d-block"><?= e(error_for('act_name')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label" for="act_code">Code</label>
                            <input class="<?= invalid_class('act_code') ?> form-control" type="text" id="act_code"
                                   name="act_code" maxlength="50" data-label="Code">
                            <?php if (has_error('act_code')): ?>
                                <div class="invalid-feedback d-block"><?= e(error_for('act_code')) ?></div>
                            <?php else: ?>
                                <div class="form-text">Left blank it is taken from the name.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label" for="act_category">Category</label>
                            <input class="form-control" type="text" id="act_category" name="act_category"
                                   maxlength="60" placeholder="e.g. land preparation, crop care, post harvest">
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="act_photo"
                                       name="act_photo" value="1">
                                <label class="form-check-label" for="act_photo">A photo is required as evidence</label>
                            </div>
                        </div>
                    </div>

                    <button class="btn cahu-btn-primary" type="submit">Add activity type</button>
                </form>
            </div>

            <div class="cahu-card__body cahu-card__body--flush">
                <?php if ($activityTypes === []): ?>
                    <div class="cahu-empty">
                        <h3>No activity types</h3>
                        <p>Add the first one above so a planting can generate a schedule.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table cahu-table align-middle mb-0">
                            <thead>
                            <tr><th>Name</th><th>Code</th><th>Category</th><th>Photo</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activityTypes as $type): ?>
                                <tr>
                                    <td><?= e($type['name']) ?></td>
                                    <td class="cahu-table__code"><?= e($type['code']) ?></td>
                                    <td class="small text-muted"><?= e($type['category'] ?: '—') ?></td>
                                    <td class="small"><?= (int) $type['requires_photo'] === 1 ? 'Required' : '&mdash;' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Problem types</div>
            <div class="cahu-card__body">
                <p class="text-muted small">
                    What a farmer or field officer can report. A type marked critical is escalated as soon as it is
                    submitted.
                </p>

                <form method="post" action="<?= e(url('admin/reference/problem-types')) ?>" novalidate data-validate>
                    <?= csrf_field() ?>

                    <div class="row">
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label" for="prob_name">Problem name <span class="req">*</span></label>
                            <input class="<?= invalid_class('prob_name') ?> form-control" type="text" id="prob_name"
                                   name="prob_name" required maxlength="120" data-label="Problem name">
                            <?php if (has_error('prob_name')): ?>
                                <div class="invalid-feedback d-block"><?= e(error_for('prob_name')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label" for="prob_code">Code</label>
                            <input class="<?= invalid_class('prob_code') ?> form-control" type="text" id="prob_code"
                                   name="prob_code" maxlength="50" data-label="Code">
                            <?php if (has_error('prob_code')): ?>
                                <div class="invalid-feedback d-block"><?= e(error_for('prob_code')) ?></div>
                            <?php else: ?>
                                <div class="form-text">Left blank it is taken from the name.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label" for="prob_category">Category <span class="req">*</span></label>
                            <select class="<?= invalid_class('prob_category') ?> form-select" id="prob_category"
                                    name="prob_category" required>
                                <?php
                                foreach (\Core\Form::enumOptions(
                                    "'pest','disease','weather','input','soil','theft','other'",
                                    'Select category...'
                                ) as $option):
                                    ?>
                                    <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (has_error('prob_category')): ?>
                                <div class="invalid-feedback d-block"><?= e(error_for('prob_category')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="prob_critical"
                                       name="prob_critical" value="1">
                                <label class="form-check-label" for="prob_critical">Escalate immediately when reported</label>
                            </div>
                        </div>
                    </div>

                    <button class="btn cahu-btn-primary" type="submit">Add problem type</button>
                </form>
            </div>

            <div class="cahu-card__body cahu-card__body--flush">
                <?php if ($problemTypes === []): ?>
                    <div class="cahu-empty">
                        <h3>No problem types</h3>
                        <p>Add the first one above so a problem can be reported against a garden.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table cahu-table align-middle mb-0">
                            <thead>
                            <tr><th>Name</th><th>Code</th><th>Category</th><th>Critical</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($problemTypes as $type): ?>
                                <tr>
                                    <td><?= e($type['name']) ?></td>
                                    <td class="cahu-table__code"><?= e($type['code']) ?></td>
                                    <td><?= status_badge((string) $type['category']) ?></td>
                                    <td class="small">
                                        <?= (int) $type['is_critical'] === 1
                                            ? '<span class="badge text-bg-danger">Critical</span>'
                                            : '&mdash;' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="cahu-card">
    <div class="cahu-card__head">Input categories</div>
    <div class="cahu-card__body">
        <p class="text-muted small">
            The top level of the inventory: every input item belongs to exactly one of these.
        </p>

        <form method="post" action="<?= e(url('admin/reference/input-categories')) ?>" novalidate data-validate>
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-12 col-md-4 mb-3">
                    <label class="form-label" for="cat_name">Category name <span class="req">*</span></label>
                    <input class="<?= invalid_class('cat_name') ?> form-control" type="text" id="cat_name"
                           name="cat_name" required maxlength="80" data-label="Category name">
                    <?php if (has_error('cat_name')): ?>
                        <div class="invalid-feedback d-block"><?= e(error_for('cat_name')) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-12 col-md-3 mb-3">
                    <label class="form-label" for="cat_code">Code</label>
                    <input class="<?= invalid_class('cat_code') ?> form-control" type="text" id="cat_code"
                           name="cat_code" maxlength="50" data-label="Code">
                    <?php if (has_error('cat_code')): ?>
                        <div class="invalid-feedback d-block"><?= e(error_for('cat_code')) ?></div>
                    <?php else: ?>
                        <div class="form-text">Left blank it is taken from the name.</div>
                    <?php endif; ?>
                </div>

                <div class="col-12 col-md-5 mb-3">
                    <label class="form-label" for="cat_description">Description</label>
                    <input class="form-control" type="text" id="cat_description" name="cat_description"
                           maxlength="255">
                </div>
            </div>

            <button class="btn cahu-btn-primary" type="submit">Add input category</button>
        </form>
    </div>

    <div class="cahu-card__body cahu-card__body--flush">
        <?php if ($inputCategories === []): ?>
            <div class="cahu-empty">
                <h3>No input categories</h3>
                <p>Add the first one above so input items can be catalogued against it.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table cahu-table align-middle mb-0">
                    <thead>
                    <tr><th>Name</th><th>Code</th><th>Description</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($inputCategories as $category): ?>
                        <tr>
                            <td><?= e($category['name']) ?></td>
                            <td class="cahu-table__code"><?= e($category['code']) ?></td>
                            <td class="small text-muted"><?= e($category['description'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>