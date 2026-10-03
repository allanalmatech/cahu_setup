<?php
/**
 * Global search results, grouped by record type.
 *
 * @var Core\View $view
 * @var string $term
 * @var array<int,array{group:string,title:string,meta:string,url:string}> $results
 */
$grouped = [];

foreach ($results as $result) {
    $grouped[$result['group']][] = $result;
}
?>
<div class="cahu-pagehead">
    <div>
        <h1>Search results</h1>
        <p class="cahu-pagehead__sub">Matches for &ldquo;<?= e($term) ?>&rdquo;</p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('dashboard')) ?>">Back to dashboard</a>
    </div>
</div>

<?php if ($results === []): ?>
    <div class="cahu-card">
        <div class="cahu-card__body">
            <div class="cahu-empty">
                <div class="cahu-empty__icon">
                    <svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.4"
                         stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                </div>
                <h3>Nothing matched</h3>
                <p>
                    Search by farmer ID, name, phone number, garden code, planting code, batch code or sale code.
                    A farmer ID looks like <span class="cahu-table__code">F-0001</span>.
                </p>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($grouped as $group => $items): ?>
        <div class="cahu-card mb-3">
            <div class="cahu-card__head"><?= e($group) ?> <span class="text-muted"><?= e(count($items)) ?></span></div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td class="fw-semibold">
                                    <a class="text-decoration-none" href="<?= e($item['url']) ?>"><?= e($item['title']) ?></a>
                                </td>
                                <td class="text-muted small"><?= e($item['meta']) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm cahu-btn-outline" href="<?= e($item['url']) ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
