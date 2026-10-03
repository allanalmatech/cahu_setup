<?php
/**
 * Advisory library.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<int,array{id:string,name:string,total:int,children:array<int,array<string,mixed>>}> $categories
 * @var array<int,array<string,mixed>> $filters
 */
$activeCategory = (string) ($_GET['category_id'] ?? '');
$pageLink       = static fn (string $id): string => $id === $activeCategory ? url('knowledge') : url('knowledge?category_id=' . $id);
ob_start();
?>
<?php if ($categories !== []): ?>
    <div class="cahu-filters">
        <span class="text-muted small fw-semibold">Browse by topic</span>
        <?php foreach ($categories as $branch): ?>
            <a class="btn btn-sm <?= $activeCategory === $branch['id'] ? 'cahu-btn-primary' : 'cahu-btn-outline' ?>"
               href="<?= e($pageLink($branch['id'])) ?>">
                <?= e($branch['name']) ?> <span class="text-muted">(<?= e($branch['total']) ?>)</span>
            </a>
            <?php foreach ($branch['children'] as $child): ?>
                <a class="btn btn-sm <?= $activeCategory === $child['id'] ? 'cahu-btn-primary' : 'cahu-btn-outline' ?>"
                   href="<?= e($pageLink($child['id'])) ?>">
                    &nbsp;&nbsp;<?= e($child['name']) ?> <span class="text-muted">(<?= e($child['total']) ?>)</span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <div class="cahu-sortlink">
        Sort:
        <?= $view->partial('partials/sort', ['column' => 'title', 'label' => 'Title', 'currentSort' => $sort, 'currentDirection' => $direction]) ?>
        <?= $view->partial('partials/sort', ['column' => 'created_at', 'label' => 'Newest', 'currentSort' => $sort, 'currentDirection' => $direction]) ?>
        <?= $view->partial('partials/sort', ['column' => 'view_count', 'label' => 'Most read', 'currentSort' => $sort, 'currentDirection' => $direction]) ?>
    </div>
    <span class="text-muted small"><?= e($paginator->summary()) ?></span>
</div>

<?php if ($rows === []): ?>
    <div class="cahu-empty">
        <div class="cahu-empty__icon">
            <svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.4"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 7h16M4 12h16M4 17h10"/>
            </svg>
        </div>
        <h3>Nothing in the library matches</h3>
        <p>
            Try a different search word, or clear the filters. If the guidance a farmer needs is not here yet,
            add it so it is the first thing they find next time.
        </p>
        <a class="btn cahu-btn-primary" href="<?= e(url('knowledge/create')) ?>">Add the first advisory</a>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($rows as $row): ?>
            <?php $published = (int) $row['is_published'] === 1; ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="cahu-card h-100">
                    <div class="cahu-card__body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="badge text-bg-secondary">
                                <?= e(ucwords(str_replace('_', ' ', (string) $row['content_type']))) ?>
                            </span>
                            <?php if ($published): ?>
                                <?= status_badge('active', 'Published') ?>
                            <?php else: ?>
                                <?= status_badge('inactive', 'Draft') ?>
                            <?php endif; ?>
                        </div>

                        <h3 class="h6 fw-semibold mb-1">
                            <a class="text-decoration-none" href="<?= e(url('knowledge/' . $row['slug'])) ?>">
                                <?= e($row['title']) ?>
                            </a>
                        </h3>

                        <?php if (!empty($row['summary'])): ?>
                            <p class="small text-muted mb-2">
                                <?= e(mb_strimwidth((string) $row['summary'], 0, 150, '…')) ?>
                            </p>
                        <?php else: ?>
                            <p class="small text-muted mb-2">No summary has been written for this material yet.</p>
                        <?php endif; ?>

                        <dl class="cahu-kv small mb-3">
                            <dt>Topic</dt>
                            <dd><?= e($row['category_name'] ?: 'Uncategorised') ?></dd>
                            <?php if (!empty($row['crop_name'])): ?>
                                <dt>Crop</dt>
                                <dd><?= e($row['crop_name']) ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($row['farming_stage'])): ?>
                                <dt>Stage</dt>
                                <dd><?= e($row['farming_stage']) ?></dd>
                            <?php endif; ?>
                        </dl>

                        <div class="mt-auto d-flex justify-content-between align-items-center gap-2">
                            <span class="text-muted small">
                                <?= e((int) $row['view_count']) ?> view(s)
                                <?php if (!empty($row['published_at'])): ?>
                                    &middot; <?= e(format_date($row['published_at'])) ?>
                                <?php endif; ?>
                            </span>
                            <span class="d-flex gap-2">
                                <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('knowledge/' . $row['slug'])) ?>">Open</a>
                                <a class="btn btn-sm btn-link text-decoration-none"
                                   href="<?= e(url('knowledge/' . $row['slug'] . '/edit')) ?>">Edit</a>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($paginator->hasPages()): ?>
    <nav class="cahu-pager mt-3 justify-content-center" aria-label="Pagination">
        <?php if ($paginator->page > 1): ?>
            <a href="?<?= e(query_string(['page' => $paginator->page - 1])) ?>" aria-label="Previous page">&lsaquo;</a>
        <?php else: ?>
            <span class="is-disabled">&lsaquo;</span>
        <?php endif; ?>

        <?php foreach ($paginator->window() as $page): ?>
            <?php if ($page === $paginator->page): ?>
                <span class="is-current"><?= e($page) ?></span>
            <?php else: ?>
                <a href="?<?= e(query_string(['page' => $page])) ?>"><?= e($page) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($paginator->page < $paginator->lastPage): ?>
            <a href="?<?= e(query_string(['page' => $paginator->page + 1])) ?>" aria-label="Next page">&rsaquo;</a>
        <?php else: ?>
            <span class="is-disabled">&rsaquo;</span>
        <?php endif; ?>
    </nav>
<?php endif; ?>
<?php
$content = ob_get_clean();

$view->partial('partials/register', [
    'title'             => 'Knowledge Centre',
    'subtitle'          => 'Advice written for farmers: what to do, when to do it and what to watch for.',
    'actions'           => [
        ['label' => 'Add content', 'url' => url('knowledge/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search the title, summary and guidance text...',
    'clearUrl'          => url('knowledge'),
    'paginator'         => $paginator,
    'content'           => $content,
]);
