<?php
/**
 * Audit trail.
 *
 * The before and after of each entry are kept collapsed: the list is read for
 * who did what and when, and only opened when someone is checking a specific
 * change.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<int,array<string,mixed>> $filters
 * @var array{from:string,to:string} $range
 */
$flatten = static function (?string $raw): array {
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    // Older rows were written from a PHP array that had already been cast to a
    // string, so they hold the literal word "Array" rather than JSON. Falling
    // back to the raw text keeps those rows readable instead of blank.
    return is_array($decoded) ? $decoded : ['recorded' => $raw];
};

$rangeFilter = '<form class="cahu-filters mb-3" method="get" data-range-filter>'
    . '<span class="text-muted small">Between</span>'
    . '<input class="form-control" type="date" name="from" value="' . e($range['from']) . '" aria-label="From date">'
    . '<span class="text-muted small">and</span>'
    . '<input class="form-control" type="date" name="to" value="' . e($range['to']) . '" aria-label="To date">'
    . '<button class="btn cahu-btn-outline btn-sm" type="submit">Apply dates</button>'
    . '<a class="btn btn-link btn-sm text-decoration-none" href="' . e(url('admin/audit')) . '">Clear</a>'
    . '</form>';

ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th><?= $view->partial('partials/sort', ['column' => 'created_at', 'label' => 'When', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'user_name', 'label' => 'User', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'action', 'label' => 'Action', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'module', 'label' => 'Module', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Entity</th>
            <th>IP address</th>
            <th style="width:120px">Change</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 7,
                'title'       => 'No entries match',
                'message'     => 'Widen the date range or clear the filters. Every write in the platform is recorded here.',
                'actionLabel' => '',
                'actionUrl'   => '',
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $old = $flatten(isset($row['old_values']) ? (string) $row['old_values'] : null);
                $new = $flatten(isset($row['new_values']) ? (string) $row['new_values'] : null);
                $hasDiff = $old !== [] || $new !== [];
                ?>
                <tr>
                    <td class="small text-nowrap"><?= e(format_datetime($row['created_at'])) ?></td>
                    <td><?= e($row['user_name'] ?: 'system') ?></td>
                    <td><span class="cahu-chip"><?= e(str_replace('_', ' ', (string) $row['action'])) ?></span></td>
                    <td><?= e($row['module']) ?></td>
                    <td class="small">
                        <?php if (!empty($row['entity_label'])): ?>
                            <div class="fw-semibold"><?= e($row['entity_label']) ?></div>
                        <?php endif; ?>
                        <div class="text-muted">
                            <?= e($row['entity_type'] ?: 'record') ?><?php
                            echo $row['entity_id'] !== null && $row['entity_id'] !== ''
                                ? ' #' . e($row['entity_id'])
                                : '';
                            ?>
                        </div>
                    </td>
                    <td class="small text-muted"><?= e($row['ip_address'] ?: '—') ?></td>
                    <td>
                        <?php if (!$hasDiff): ?>
                            <span class="text-muted small">No values recorded</span>
                        <?php else: ?>
                            <details>
                                <summary class="text-decoration-none">Before / after</summary>
                                <div class="row g-2 mt-1" style="min-width:320px">
                                    <div class="col-6">
                                        <div class="text-muted small mb-1">Before</div>
                                        <?php if ($old === []): ?>
                                            <div class="text-muted small">&mdash;</div>
                                        <?php else: ?>
                                            <dl class="cahu-kv mb-0">
                                                <?php foreach ($old as $field => $value): ?>
                                                    <dt class="small"><?= e($field) ?></dt>
                                                    <dd class="small">
                                                        <?= e(is_scalar($value) || $value === null ? (string) $value : json_encode($value)) ?>
                                                    </dd>
                                                <?php endforeach; ?>
                                            </dl>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small mb-1">After</div>
                                        <?php if ($new === []): ?>
                                            <div class="text-muted small">&mdash;</div>
                                        <?php else: ?>
                                            <dl class="cahu-kv mb-0">
                                                <?php foreach ($new as $field => $value): ?>
                                                    <dt class="small"><?= e($field) ?></dt>
                                                    <dd class="small">
                                                        <?= e(is_scalar($value) || $value === null ? (string) $value : json_encode($value)) ?>
                                                    </dd>
                                                <?php endforeach; ?>
                                            </dl>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </details>
                        <?php endif; ?>
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
    'title'             => 'Audit log',
    'subtitle'          => 'Who changed what, when and from where. Entries are written once and never edited.',
    'actions'           => [
        ['label' => 'Export CSV', 'url' => url('admin/audit/export') . '?' . e(query_string(['page' => null, 'per_page' => null])), 'icon' => '↓', 'style' => 'cahu-btn-outline'],
    ],
    'before'            => $rangeFilter,
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by user, action, module, entity or IP address...',
    'clearUrl'          => url('admin/audit'),
    'paginator'         => $paginator,
    'content'           => $content,
]);