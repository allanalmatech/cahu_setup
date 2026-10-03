<?php
/**
 * Crop register.
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
            <th style="width:110px"><?= $view->partial('partials/sort', ['column' => 'crop_code', 'label' => 'Code', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'name', 'label' => 'Crop', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'category', 'label' => 'Category', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'lifecycle_days', 'label' => 'Lifecycle', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Expected yield</th>
            <th>Varieties</th>
            <th class="text-end">Planted</th>
            <th>Lifecycle steps</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 10,
                'title'       => 'No crops found',
                'message'     => 'Crops are the reference data every planting, activity and harvest is filed against. Add the first one to get started.',
                'actionLabel' => 'Add crop',
                'actionUrl'   => url('crops/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php $varieties = (int) $row['variety_count']; $templates = (int) $row['template_count']; ?>
                <tr>
                    <td class="cahu-table__code"><?= e($row['crop_code']) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($row['name']) ?></div>
                        <?php if (!empty($row['description'])): ?>
                            <div class="text-muted small"><?= e(mb_strimwidth((string) $row['description'], 0, 70, '…')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) $row['category']))) ?></td>
                    <td>
                        <?php if ($row['lifecycle_days'] !== null): ?>
                            <?= (int) $row['lifecycle_days'] ?> days
                        <?php else: ?>
                            <span class="text-muted">Not set</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($row['expected_yield_per_acre'] !== null): ?>
                            <div><?= e(format_number($row['expected_yield_per_acre'], 3)) ?></div>
                            <div class="text-muted small">per acre<?= $row['yield_unit'] ? ' (' . e($row['yield_unit']) . ')' : '' ?></div>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($varieties > 0): ?>
                            <a href="<?= e(url('varieties?crop_id=' . $row['id'])) ?>"><?= e($varieties) ?> variety/varieties</a>
                        <?php else: ?>
                            <a class="text-decoration-none" href="<?= e(url('varieties/create?crop_id=' . $row['id'])) ?>">Add variety</a>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= e(format_number($row['planted_acreage'])) ?> ac</td>
                    <td>
                        <?php if ($templates > 0): ?>
                            <a href="<?= e(url('crops/' . $row['id'] . '/lifecycle')) ?>"><?= e($templates) ?> active</a>
                        <?php else: ?>
                            <a class="text-decoration-none" href="<?= e(url('crops/' . $row['id'] . '/lifecycle')) ?>">None configured</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= status_badge((string) $row['status']) ?>
                        <?php if ((int) $row['is_perishable'] === 1): ?>
                            <div class="text-muted small">Perishable</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end cahu-table__actions">
                        <div class="d-flex justify-content-end align-items-center gap-2">
                            <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('crops/' . $row['id'] . '/lifecycle')) ?>">Lifecycle</a>
                            <a class="btn btn-sm btn-link text-decoration-none"
                               href="<?= e(url('crops/' . $row['id'] . '/edit')) ?>">Edit</a>
                            <form method="post" action="<?= e(url('crops/' . $row['id'] . '/delete')) ?>"
                                  onsubmit="return confirm('Delete this crop? If it carries any production history it will be marked inactive instead.');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Delete</button>
                            </form>
                        </div>
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
    'title'             => 'Crops',
    'subtitle'          => 'The crop catalogue, its varieties, and the lifecycle that drives every planting of it.',
    'actions'           => [
        ['label' => 'Varieties', 'url' => url('varieties'), 'icon' => '≡', 'style' => 'cahu-btn-outline'],
        ['label' => 'Add crop', 'url' => url('crops/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by crop code, name or description...',
    'clearUrl'          => url('crops'),
    'paginator'         => $paginator,
    'content'           => $content,
]);
