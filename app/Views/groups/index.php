<?php
/**
 * Farmer group register.
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
            <th style="width:120px"><?= $view->partial('partials/sort', ['column' => 'group_code', 'label' => 'Group ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'name', 'label' => 'Group', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'group_type', 'label' => 'Type', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Location</th>
            <th>Contact</th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'member_count', 'label' => 'Members', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'total_acreage', 'label' => 'Acres', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 9,
                'title'       => 'No groups found',
                'message'     => 'Adjust the filters, or register the first farmer group for this area.',
                'actionLabel' => 'Register group',
                'actionUrl'   => url('groups/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= url('groups/' . e($row['group_code'])) ?>"><?= e($row['group_code']) ?></a>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= e($row['name']) ?></div>
                        <div class="text-muted small">
                            <?php if (!empty($row['registration_no'])): ?>
                                <?= e($row['registration_no']) ?>
                            <?php else: ?>
                                Not registered
                            <?php endif; ?>
                            <?php if (!empty($row['registration_date'])): ?>
                                &middot; <?= e(format_date($row['registration_date'])) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) $row['group_type']))) ?></td>
                    <td>
                        <div><?= e($row['district_name'] ?: '—') ?></div>
                        <div class="text-muted small"><?= e($row['sub_county_name'] ?: 'Sub-county not set') ?></div>
                    </td>
                    <td class="small">
                        <div><?= e($row['contact_person'] ?: '—') ?></div>
                        <div class="text-muted"><?= e($row['phone'] ?: 'No phone') ?></div>
                    </td>
                    <td class="text-end"><?= (int) $row['member_count'] ?></td>
                    <td class="text-end">
                        <div><?= e(format_number($row['total_acreage'])) ?></div>
                        <div class="text-muted small"><?= (int) $row['garden_count'] ?> garden(s)</div>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= url('groups/' . e($row['group_code'])) ?>">Open</a>
                        <a class="btn btn-sm btn-link text-decoration-none"
                           href="<?= url('groups/' . e($row['group_code']) . '/edit') ?>">Edit</a>
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
    'title'            => 'Farmer Groups',
    'subtitle'         => 'Cooperatives, producer groups and village groupings, with the members and land behind each one.',
    'actions'          => [
        ['label' => 'Register group', 'url' => url('groups/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'          => $filters,
    'searchPlaceholder' => 'Search by group name, ID or registration number...',
    'clearUrl'         => url('groups'),
    'paginator'        => $paginator,
    'content'          => $content,
]);