<?php
/**
 * Farmer register.
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
            <th style="width:120px"><?= $view->partial('partials/sort', ['column' => 'farmer_code', 'label' => 'Farmer ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'full_name', 'label' => 'Farmer', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Location</th>
            <th>Group / officer</th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'total_acreage', 'label' => 'Acres', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 7,
                'title'       => 'No farmers found',
                'message'     => 'Adjust the filters, or register the first farmer for this area.',
                'actionLabel' => 'Register farmer',
                'actionUrl'   => url('farmers/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= url('farmers/' . e($row['farmer_code'])) ?>"><?= e($row['farmer_code']) ?></a>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($row['profile_photo'])): ?>
                                <img class="cahu-avatar" src="<?= e($row['profile_photo']) ?>" alt="" width="34" height="34">
                            <?php else: ?>
                                <span class="cahu-avatar"><?= e(strtoupper(substr((string) $row['full_name'], 0, 1))) ?></span>
                            <?php endif; ?>
                            <div>
                                <div class="fw-semibold"><?= e($row['full_name']) ?></div>
                                <div class="text-muted small">
                                    <?= e($row['phone']) ?>
                                    <?php if (!empty($row['farmer_type'])): ?>
                                        &middot; <?= e(ucwords(str_replace('_', ' ', (string) $row['farmer_type']))) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div><?= e($row['village_name'] ?: '—') ?></div>
                        <div class="text-muted small">
                            <?= e($row['district_name'] ?: 'District not set') ?>
                        </div>
                    </td>
                    <td>
                        <div><?= e($row['group_name'] ?: 'No group') ?></div>
                        <div class="text-muted small"><?= e($row['officer_name'] ?: 'Unassigned') ?></div>
                    </td>
                    <td class="text-end">
                        <div><?= e(format_number($row['total_acreage'])) ?></div>
                        <div class="text-muted small"><?= (int) $row['garden_count'] ?> garden(s)</div>
                    </td>
                    <td>
                        <?= status_badge((string) $row['status']) ?>
                        <?php if ((int) $row['overdue_count'] > 0): ?>
                            <div class="small text-danger mt-1"><?= (int) $row['overdue_count'] ?> overdue</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= url('farmers/' . e($row['farmer_code'])) ?>">Open</a>
                        <a class="btn btn-sm btn-link text-decoration-none"
                           href="<?= url('farmers/' . e($row['farmer_code']) . '/edit') ?>">Edit</a>
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
    'title'            => 'Farmers',
    'subtitle'         => 'Every registered farmer and the digital record behind their farm.',
    'actions'          => [
        ['label' => 'Export CSV', 'url' => url('farmers/export/csv'), 'icon' => '↓', 'style' => 'cahu-btn-outline'],
        ['label' => 'Register farmer', 'url' => url('farmers/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'          => $filters,
    'searchPlaceholder' => 'Search by ID, name, phone, national ID or email...',
    'clearUrl'         => url('farmers'),
    'paginator'        => $paginator,
    'content'          => $content,
]);
