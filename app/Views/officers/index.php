<?php
/**
 * Field officer register.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<int,array<string,mixed>> $filters
 */
ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th style="width:120px"><?= $view->partial('partials/sort', ['column' => 'officer_code', 'label' => 'Officer ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'full_name', 'label' => 'Officer', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Contact</th>
            <th>Area</th>
            <th><?= $view->partial('partials/sort', ['column' => 'employee_no', 'label' => 'Employee No.', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'farmer_count', 'label' => 'Farmers', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'garden_count', 'label' => 'Gardens', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'overdue_count', 'label' => 'Overdue', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Open problems</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 11,
                'title'       => 'No field officers found',
                'message'     => 'Adjust the filters, or add the first field officer to this area.',
                'actionLabel' => 'Add field officer',
                'actionUrl'   => url('officers/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= url('officers/' . e($row['officer_code'])) ?>"><?= e($row['officer_code']) ?></a>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($row['photo'])): ?>
                                <img class="cahu-avatar" src="<?= e($row['photo']) ?>" alt="" width="34" height="34">
                            <?php else: ?>
                                <span class="cahu-avatar"><?= e(strtoupper(substr((string) $row['full_name'], 0, 1))) ?></span>
                            <?php endif; ?>
                            <div>
                                <div class="fw-semibold"><?= e($row['full_name']) ?></div>
                                <div class="text-muted small">
                                    <?= e($row['username'] ?: 'No portal account') ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="small">
                        <div><?= e($row['phone'] ?: '—') ?></div>
                        <div class="text-muted"><?= e($row['email'] ?: '') ?></div>
                    </td>
                    <td class="small">
                        <div><?= e($row['district_name'] ?: '—') ?></div>
                        <div class="text-muted"><?= e($row['region_name'] ?: 'Region not set') ?></div>
                    </td>
                    <td><?= e($row['employee_no'] ?: '—') ?></td>
                    <td class="text-end"><?= (int) $row['farmer_count'] ?></td>
                    <td class="text-end"><?= (int) $row['garden_count'] ?></td>
                    <td class="text-end">
                        <?php if ((int) $row['overdue_count'] > 0): ?>
                            <span class="badge text-bg-danger"><?= (int) $row['overdue_count'] ?></span>
                        <?php else: ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= (int) $row['open_problem_count'] ?></td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= url('officers/' . e($row['officer_code'])) ?>">Open</a>
                        <a class="btn btn-sm btn-link text-decoration-none"
                           href="<?= url('officers/' . e($row['officer_code']) . '/edit') ?>">Edit</a>
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
    'title'            => 'Field Officers',
    'subtitle'         => 'Extension staff and the farmers, gardens and problems they carry.',
    'actions'          => [
        ['label' => 'Add field officer', 'url' => url('officers/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'          => $filters,
    'searchPlaceholder' => 'Search by name, officer ID, employee number, phone or email...',
    'clearUrl'         => url('officers'),
    'paginator'        => $paginator,
    'content'          => $content,
]);