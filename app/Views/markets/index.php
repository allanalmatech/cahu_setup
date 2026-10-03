<?php
/**
 * Market register.
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
            <th style="width:130px"><?= $view->partial('partials/sort', ['column' => 'market_code', 'label' => 'Market code', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'name', 'label' => 'Market', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Contact</th>
            <th>Address</th>
            <th class="text-end">Open requirements</th>
            <th class="text-end">Volume demanded</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 8,
                'title'       => 'No markets found',
                'message'     => 'Markets are the venues produce is sold through. Register the first one to file demand against it.',
                'actionLabel' => 'Register market',
                'actionUrl'   => url('markets/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code"><?= e($row['market_code']) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($row['name']) ?></div>
                        <div class="text-muted small">
                            <?= e(ucwords(str_replace('_', ' ', (string) $row['market_type']))) ?>
                            <?php if (!empty($row['district_name'])): ?>
                                &middot; <?= e($row['district_name']) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div><?= e($row['contact_person'] ?: '—') ?></div>
                        <div class="text-muted small"><?= e($row['phone'] ?: '—') ?></div>
                    </td>
                    <td class="small"><?= e($row['address'] ?: '—') ?></td>
                    <td class="text-end">
                        <?php if ((int) $row['open_requirements'] > 0): ?>
                            <?= (int) $row['open_requirements'] ?> open
                        <?php else: ?>
                            <span class="text-muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= e(format_number($row['demanded_volume'])) ?></td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm btn-link text-decoration-none"
                           href="<?= e(url('markets/' . $row['market_code'] . '/edit')) ?>">Edit</a>
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
    'title'             => 'Markets',
    'subtitle'          => 'The venues produce is sold through, and the demand currently filed against each one.',
    'actions'           => [
        ['label' => 'Supply vs demand', 'url' => url('demand/balance'), 'icon' => '≡', 'style' => 'cahu-btn-outline'],
        ['label' => 'Register market', 'url' => url('markets/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by market name, code, contact or phone...',
    'clearUrl'          => url('markets'),
    'paginator'         => $paginator,
    'content'           => $content,
]);