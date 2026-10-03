<?php
/**
 * Field visit register.
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

// The date range is not a dropdown, so it is rendered as its own small form
// above the register rather than being forced into the shared filter bar.
$rangeForm = sprintf(
    '<form class="cahu-filters mb-3" method="get" action="%s">
        <input class="form-control" type="date" name="from" value="%s" aria-label="Visits from">
        <input class="form-control" type="date" name="to" value="%s" aria-label="Visits to">
        <button class="btn cahu-btn-outline btn-sm" type="submit">Apply dates</button>
        %s
    </form>',
    e(url('visits')),
    e($from),
    e($to),
    ($from !== '' || $to !== '')
        ? '<a class="btn btn-link btn-sm text-decoration-none" href="' . e(url('visits')) . '">Clear</a>'
        : ''
);

ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th>Visit ID</th>
            <th><?= $view->partial('partials/sort', ['column' => 'visit_date', 'label' => 'Date', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Officer</th>
            <th>Farmer / plot</th>
            <th>Purpose</th>
            <th>GPS</th>
            <th>Met</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Outcome', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 9,
                'title'       => 'No field visits found',
                'message'     => 'A visit records that an officer actually reached a farmer, with the advice given. Recording one is the evidence behind every outreach figure.',
                'actionLabel' => 'Record field visit',
                'actionUrl'   => url('visits/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('visits/' . $row['visit_code'])) ?>"><?= e($row['visit_code']) ?></a>
                        <?php if ((int) $row['inspection_count'] > 0): ?>
                            <div class="text-muted small"><?= (int) $row['inspection_count'] ?> inspection(s)</div>
                        <?php endif; ?>
                    </td>
                    <td><?= e(format_date($row['visit_date'])) ?></td>
                    <td><?= e($row['officer_name'] ?: 'Unassigned') ?></td>
                    <td>
                        <?php if (!empty($row['farmer_code'])): ?>
                            <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                                <?= e($row['full_name']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">No farmer recorded</span>
                        <?php endif; ?>
                        <div class="text-muted small">
                            <?= e($row['garden_code'] ?: 'Whole farm') ?>
                            <?php if (!empty($row['garden_name'])): ?>
                                &middot; <?= e($row['garden_name']) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="small"><?= e($row['visit_purpose'] ?: '—') ?></td>
                    <td class="small">
                        <?php if ($row['latitude'] !== null && $row['longitude'] !== null): ?>
                            <span class="text-success">Captured</span>
                            <div class="text-muted">
                                <?= e(round((float) $row['latitude'], 5)) ?>,
                                <?= e(round((float) $row['longitude'], 5)) ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted">Not captured</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= e($row['people_met'] !== null ? (string) (int) $row['people_met'] : '—') ?></td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('visits/' . $row['visit_code'])) ?>">Open</a>
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
    'title'             => 'Field visits',
    'subtitle'          => 'Every contact between an officer and a farmer, with the advice that was given.',
    'actions'           => [
        ['label' => 'Record field visit', 'url' => url('visits/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by visit ID, purpose, farmer or officer...',
    'clearUrl'          => url('visits'),
    'paginator'         => $paginator,
    'content'           => $content,
    'before'            => $rangeForm,
]);