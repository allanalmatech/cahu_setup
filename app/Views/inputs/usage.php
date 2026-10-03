<?php
/**
 * Input usage register: what farmers actually applied, and when.
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
            <th style="width:130px"><?= $view->partial('partials/sort', ['column' => 'usage_code', 'label' => 'Usage ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'input_name', 'label' => 'Input', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'farmer_name', 'label' => 'Farmer / plot', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'application_date', 'label' => 'Applied', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'quantity_used', 'label' => 'Quantity', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Issue</th>
            <th>Recorded by</th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 8,
                'title'       => 'No usage recorded',
                'message'     => 'Usage is what proves the input reached the field. Record an application against an issue so the farmer\'s record stays complete.',
                'actionLabel' => 'Record usage',
                'actionUrl'   => url('input-usage/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code"><?= e($row['usage_code']) ?></td>
                    <td>
                        <a class="text-decoration-none" href="<?= e(url('inputs/' . $row['input_code'])) ?>">
                            <?= e($row['input_name']) ?>
                        </a>
                        <div class="text-muted small"><?= e($row['purpose'] ?: 'No purpose recorded') ?></div>
                    </td>
                    <td>
                        <?php if (!empty($row['farmer_code'])): ?>
                            <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                                <?= e($row['farmer_name']) ?>
                            </a>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                        <div class="text-muted small"><?= e($row['garden_name'] ?: 'No plot') ?></div>
                    </td>
                    <td class="text-nowrap"><?= e(format_date($row['application_date'])) ?></td>
                    <td class="text-end text-nowrap"><?= e(format_quantity($row['quantity_used'], $row['unit'])) ?></td>
                    <td class="text-muted small">
                        <?php if (!empty($row['issue_code'])): ?>
                            <a class="text-decoration-none" href="<?= e(url('input-issues/' . $row['issue_code'])) ?>">
                                <?= e($row['issue_code']) ?>
                            </a>
                        <?php else: ?>
                            Standalone
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= e($row['recorded_by_name'] ?: 'System') ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline"
                           href="<?= e(url('input-usage/' . $row['usage_code'] . '/edit')) ?>">Edit</a>
                        <form method="post" class="d-inline"
                              action="<?= e(url('input-usage/' . $row['usage_code'] . '/delete')) ?>"
                              onsubmit="return confirm('Remove this usage record? Any parent issue will be recalculated.')">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Remove</button>
                        </form>
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
    'title'            => 'Input usage',
    'subtitle'         => 'What farmers actually applied, and the issue each application is counted against.',
    'actions'          => [
        ['label' => 'Issued inputs', 'url' => url('input-issues'), 'icon' => '↗', 'style' => 'cahu-btn-outline'],
        ['label' => 'Record usage', 'url' => url('input-usage/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'          => $filters,
    'searchPlaceholder' => 'Search by usage ID, input or farmer...',
    'clearUrl'         => url('input-usage'),
    'paginator'        => $paginator,
    'content'          => $content,
]);
