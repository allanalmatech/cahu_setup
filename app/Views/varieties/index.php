<?php
/**
 * Variety register, grouped by crop.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<int,array<string,mixed>> $filters
 */
$currentCrop = null;
ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th style="width:110px"><?= $view->partial('partials/sort', ['column' => 'variety_code', 'label' => 'Code', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'name', 'label' => 'Variety', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'maturity_days', 'label' => 'Maturity', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'expected_yield_per_acre', 'label' => 'Yield / acre', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Recommended areas</th>
            <th class="text-end">Plantings</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 8,
                'title'       => 'No varieties found',
                'message'     => 'Varieties carry the maturity, yield and recommended areas that differ within a crop. Add one to get started.',
                'actionLabel' => 'Add variety',
                'actionUrl'   => url('varieties/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php if ($currentCrop !== (int) $row['crop_id']): ?>
                    <?php $currentCrop = (int) $row['crop_id']; ?>
                    <tr class="table-light">
                        <td colspan="8">
                            <a class="fw-semibold text-decoration-none" href="<?= e(url('crops/' . $row['crop_id'] . '/lifecycle')) ?>">
                                <?= e($row['crop_name']) ?>
                            </a>
                            <span class="text-muted small">&middot; <?= e($row['crop_code']) ?></span>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="cahu-table__code"><?= e($row['variety_code']) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($row['name']) ?></div>
                        <?php if (!empty($row['description'])): ?>
                            <div class="text-muted small"><?= e(mb_strimwidth((string) $row['description'], 0, 70, '…')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($row['maturity_days'] !== null): ?>
                            <?= (int) $row['maturity_days'] ?> days
                        <?php else: ?>
                            <span class="text-muted">Crop default</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($row['expected_yield_per_acre'] !== null): ?>
                            <?= e(format_number($row['expected_yield_per_acre'], 3)) ?>
                            <?php if ($row['yield_unit']): ?>
                                <div class="text-muted small"><?= e($row['yield_unit']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e($row['recommended_areas'] ?: '—') ?></td>
                    <td class="text-end">
                        <?php if ((int) $row['planting_count'] > 0): ?>
                            <a href="<?= e(url('plantings?variety_id=' . $row['id'])) ?>"><?= (int) $row['planting_count'] ?></a>
                        <?php else: ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <div class="d-flex justify-content-end align-items-center gap-2">
                            <a class="btn btn-sm btn-link text-decoration-none"
                               href="<?= e(url('varieties/' . $row['id'] . '/edit')) ?>">Edit</a>
                            <form method="post" action="<?= e(url('varieties/' . $row['id'] . '/delete')) ?>"
                                  onsubmit="return confirm('Delete this variety? If it has been planted it will be marked inactive instead.');">
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
    'title'             => 'Crop varieties',
    'subtitle'          => 'The cultivars available under each crop, and the plantings that have used them.',
    'actions'           => [
        ['label' => 'Crops', 'url' => url('crops'), 'icon' => '≡', 'style' => 'cahu-btn-outline'],
        ['label' => 'Add variety', 'url' => url('varieties/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by variety code, variety or crop...',
    'clearUrl'          => url('varieties'),
    'paginator'         => $paginator,
    'content'           => $content,
]);
