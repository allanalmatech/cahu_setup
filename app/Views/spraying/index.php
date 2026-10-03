<?php
/**
 * Spraying register.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<int,array<string,mixed>> $filters
 * @var string $from
 * @var string $to
 */

// A follow-up date that has already passed means the spray schedule slipped,
// which is the thing an officer most needs to see first in this list.
$today = date('Y-m-d');

$rangeForm = sprintf(
    '<form class="cahu-filters mb-3" method="get" action="%s">
        <input class="form-control" type="date" name="from" value="%s" aria-label="Sprayed from">
        <input class="form-control" type="date" name="to" value="%s" aria-label="Sprayed to">
        <button class="btn cahu-btn-outline btn-sm" type="submit">Apply dates</button>
        %s
    </form>',
    e(url('spraying')),
    e($from),
    e($to),
    ($from !== '' || $to !== '')
        ? '<a class="btn btn-link btn-sm text-decoration-none" href="' . e(url('spraying')) . '">Clear</a>'
        : ''
);

ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th>Spray ID</th>
            <th><?= $view->partial('partials/sort', ['column' => 'application_date', 'label' => 'Applied', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Farmer / garden</th>
            <th>Crop</th>
            <th>Product</th>
            <th>Target</th>
            <th class="text-end">Quantity</th>
            <th>Next application</th>
            <th>Officer</th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 10,
                'title'       => 'No spraying records found',
                'message'     => 'Spray records are the evidence of what was applied to a plot and when it must be applied again.',
                'actionLabel' => 'Record spraying',
                'actionUrl'   => url('spraying/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $next = $row['next_application_date'];
                $overdue = $next !== null && $next !== '' && $next < $today;
                ?>
                <tr<?= $overdue ? ' class="cahu-row--late"' : '' ?>>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('spraying/' . $row['spray_code'])) ?>"><?= e($row['spray_code']) ?></a>
                        <?php if (!empty($row['input_id'])): ?>
                            <div class="text-muted small">From stock</div>
                        <?php endif; ?>
                    </td>
                    <td><?= e(format_date($row['application_date'])) ?></td>
                    <td>
                        <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                            <?= e($row['full_name']) ?>
                        </a>
                        <div class="text-muted small">
                            <?= e($row['garden_code']) ?><?= $row['garden_name'] ? ' · ' . e($row['garden_name']) : '' ?>
                        </div>
                    </td>
                    <td><?= e($row['crop_name'] ?: '—') ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($row['product_name']) ?></div>
                        <div class="text-muted small"><?= e($row['active_ingredient'] ?: '—') ?></div>
                    </td>
                    <td class="small"><?= e($row['pest_disease_targeted'] ?: '—') ?></td>
                    <td class="text-end">
                        <?= e(format_number($row['quantity_used'], 2)) ?>
                        <div class="text-muted small"><?= e($row['unit']) ?></div>
                    </td>
                    <td>
                        <?php if ($next === null || $next === ''): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            <span class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= e(format_date($next)) ?></span>
                            <?php if ($overdue): ?>
                                <div class="small text-danger">Follow-up overdue</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['officer_name'] ?: '—') ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('spraying/' . $row['spray_code'])) ?>">Open</a>
                        <a class="btn btn-sm btn-link text-decoration-none"
                           href="<?= e(url('spraying/' . $row['spray_code'] . '/edit')) ?>">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$content = ob_get_clean();

$view->partial('partials/register', [
    'title'             => 'Spraying',
    'subtitle'          => 'What was applied to each plot, and when the next application falls due.',
    'actions'           => [
        ['label' => 'Record spraying', 'url' => url('spraying/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by spray ID, product, active ingredient, target or farmer...',
    'clearUrl'          => url('spraying'),
    'paginator'         => $paginator,
    'content'           => $content,
    'before'            => $rangeForm,
]);