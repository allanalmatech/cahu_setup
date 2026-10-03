<?php
/**
 * Market demand register.
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
            <th>Requirement</th>
            <th>Buyer</th>
            <th>Crop</th>
            <th class="text-end">Quantity</th>
            <th>Quality required</th>
            <th>Delivery location</th>
            <th><?= $view->partial('partials/sort', ['column' => 'required_date', 'label' => 'Required by', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Offered price</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 10,
                'title'       => 'No market demand recorded',
                'message'     => 'Record what buyers are asking for and the platform can tell you, crop by crop, whether supply covers it.',
                'actionLabel' => 'Record demand',
                'actionUrl'   => url('demand/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code"><?= e($row['requirement_code']) ?></td>
                    <td>
                        <a class="text-decoration-none" href="<?= e(url('buyers/' . $row['buyer_code'])) ?>">
                            <?= e($row['buyer_name']) ?>
                        </a>
                        <?php if (!empty($row['market_name'])): ?>
                            <div class="text-muted small"><?= e($row['market_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fw-semibold"><?= e($row['crop_name']) ?></span>
                        <?php if (!empty($row['variety_name'])): ?>
                            <div class="text-muted small"><?= e($row['variety_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= e(format_quantity($row['quantity_required'], $row['unit'])) ?></td>
                    <td class="small"><?= e($row['quality_required'] ?: '—') ?></td>
                    <td class="small"><?= e($row['delivery_location'] ?: '—') ?></td>
                    <td><?= e(format_date($row['required_date'])) ?></td>
                    <td class="text-end">
                        <?php if ($row['offered_price'] !== null && $row['offered_price'] !== ''): ?>
                            <?= e(format_money($row['offered_price'])) ?>
                            <div class="text-muted small"><?= e($row['price_unit'] ?: '') ?></div>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <div class="d-flex justify-content-end align-items-center gap-2">
                            <a class="btn btn-sm btn-link text-decoration-none"
                               href="<?= e(url('demand/' . $row['requirement_code'] . '/edit')) ?>">Edit</a>
                            <form method="post" action="<?= e(url('demand/' . $row['requirement_code'] . '/delete')) ?>"
                                  onsubmit="return confirm('Withdraw this demand? Anything already committed against it is cancelled rather than deleted.');">
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
    'title'             => 'Market demand',
    'subtitle'          => 'What buyers have asked for, and whether current supply still covers it.',
    'actions'           => [
        ['label' => 'Supply vs demand', 'url' => url('demand/balance'), 'icon' => '≡', 'style' => 'cahu-btn-outline'],
        ['label' => 'Record demand', 'url' => url('demand/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by buyer, crop, delivery location or requirement ID...',
    'clearUrl'          => url('demand'),
    'paginator'         => $paginator,
    'content'           => $content,
]);