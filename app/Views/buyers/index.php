<?php
/**
 * Buyer register.
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
            <th style="width:130px"><?= $view->partial('partials/sort', ['column' => 'buyer_code', 'label' => 'Buyer ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'name', 'label' => 'Buyer', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Contact</th>
            <th>Country</th>
            <th>Quality requirements</th>
            <th class="text-end">Open demand</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 8,
                'title'       => 'No buyers found',
                'message'     => 'Buyers are the counterparties the cooperative sells to. Register the first one to start placing demand against them.',
                'actionLabel' => 'Register buyer',
                'actionUrl'   => url('buyers/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('buyers/' . $row['buyer_code'])) ?>"><?= e($row['buyer_code']) ?></a>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="cahu-avatar"><?= e(strtoupper(substr((string) $row['name'], 0, 1))) ?></span>
                            <div>
                                <div class="fw-semibold"><?= e($row['name']) ?></div>
                                <div class="text-muted small">
                                    <?= e(ucwords(str_replace('_', ' ', (string) $row['buyer_type']))) ?>
                                    <?php if (!empty($row['district_name'])): ?>
                                        &middot; <?= e($row['district_name']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div><?= e($row['contact_person'] ?: '—') ?></div>
                        <div class="text-muted small"><?= e($row['phone'] ?: '—') ?></div>
                    </td>
                    <td><?= e($row['country'] ?: '—') ?></td>
                    <td class="small"><?= e($row['quality_requirements'] ?: '—') ?></td>
                    <td class="text-end">
                        <?php if ((int) $row['open_demand'] > 0): ?>
                            <a href="<?= e(url('demand?buyer_id=' . $row['id'])) ?>">
                                <?= (int) $row['open_demand'] ?> open
                            </a>
                        <?php else: ?>
                            <span class="text-muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('buyers/' . $row['buyer_code'])) ?>">Open</a>
                        <a class="btn btn-sm btn-link text-decoration-none"
                           href="<?= e(url('buyers/' . $row['buyer_code'] . '/edit')) ?>">Edit</a>
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
    'title'             => 'Buyers',
    'subtitle'          => 'Every buyer the cooperative trades with, the terms they buy on, and the demand they have open.',
    'actions'           => [
        ['label' => 'Market demand', 'url' => url('demand'), 'icon' => '≡', 'style' => 'cahu-btn-outline'],
        ['label' => 'Register buyer', 'url' => url('buyers/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by name, buyer ID, contact, phone, email or contract...',
    'clearUrl'          => url('buyers'),
    'paginator'         => $paginator,
    'content'           => $content,
]);