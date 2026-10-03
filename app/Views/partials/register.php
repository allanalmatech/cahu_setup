<?php
/**
 * Standard register screen toolbar: title, actions, filter bar and pager.
 *
 * @var Core\View $view
 * @var string $title
 * @var string $subtitle
 * @var array<int,array{label:string,url:string,icon?:string,style?:string}> $actions
 * @var array<int,array{key:string,label:string,options:array<int,array{value:string,label:string}>}> $filters
 * @var Core\Paginator|null $paginator
 * @var string $searchPlaceholder
 */
$paginator       = $paginator ?? null;
$filters         = $filters ?? [];
$actions         = $actions ?? [];
$title           = $title ?? '';
$subtitle        = $subtitle ?? '';
$searchPlaceholder = $searchPlaceholder ?? '';
$searchTerm      = $_GET['search'] ?? '';
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($title) ?></h1>
        <?php if (!empty($subtitle)): ?>
            <p class="cahu-pagehead__sub"><?= e($subtitle) ?></p>
        <?php endif; ?>
    </div>
    <?php if ($actions !== []): ?>
        <div class="cahu-pagehead__actions">
            <?php foreach ($actions as $action): ?>
                <?php $style = $action['style'] ?? 'cahu-btn-outline'; ?>
                <a class="btn <?= e($style) ?>" href="<?= e($action['url']) ?>">
                    <?php if (!empty($action['icon'])): ?><?= e($action['icon']) ?><?php endif; ?>
                    <?= e($action['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($before)): ?>
    <?= $before ?>
<?php endif; ?>

<div class="cahu-card">
    <?php if ($searchPlaceholder !== '' || $filters !== []): ?>
        <form class="cahu-filters" method="get">
            <?php if ($searchPlaceholder !== ''): ?>
                <div class="cahu-filters__search">
                    <span>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                    </span>
                    <input class="form-control" type="search" name="search" value="<?= e($searchTerm) ?>"
                           placeholder="<?= e($searchPlaceholder) ?>" data-search-submit>
                </div>
            <?php endif; ?>

            <?php foreach ($filters as $filter): ?>
                <select class="form-select" name="<?= e($filter['key']) ?>" data-autosubmit
                        aria-label="<?= e($filter['label']) ?>">
                    <?php foreach ($filter['options'] as $option): ?>
                        <option value="<?= e($option['value']) ?>"
                            <?= (string) ($_GET[$filter['key']] ?? '') === (string) $option['value'] ? 'selected' : '' ?>>
                            <?= e($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endforeach; ?>

            <noscript><button class="btn cahu-btn-outline btn-sm" type="submit">Apply</button></noscript>

            <?php if ($searchTerm !== '' || array_filter($_GET, static fn ($v, $k) => $k !== 'page' && $k !== 'per_page' && $k !== 'sort' && $k !== 'direction' && $v !== '', ARRAY_FILTER_USE_BOTH) !== []): ?>
                <a class="btn btn-link btn-sm text-decoration-none" href="<?= e($clearUrl ?? '?') ?>">Clear</a>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?= $content ?? '' ?>

    <?php if ($paginator !== null && $paginator->total > 0): ?>
        <div class="cahu-card__foot d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="text-muted small"><?= e($paginator->summary()) ?></span>
            <?php if ($paginator->hasPages()): ?>
                <nav class="cahu-pager" aria-label="Pagination">
                    <?php if ($paginator->page > 1): ?>
                        <a href="?<?= e(query_string(['page' => $paginator->page - 1])) ?>" aria-label="Previous page">&lsaquo;</a>
                    <?php else: ?>
                        <span class="is-disabled">&lsaquo;</span>
                    <?php endif; ?>

                    <?php if ($paginator->window()[0] > 1): ?>
                        <a href="?<?= e(query_string(['page' => 1])) ?>">1</a>
                        <?php if ($paginator->window()[0] > 2): ?><span class="is-disabled">&hellip;</span><?php endif; ?>
                    <?php endif; ?>

                    <?php foreach ($paginator->window() as $page): ?>
                        <?php if ($page === $paginator->page): ?>
                            <span class="is-current"><?= e($page) ?></span>
                        <?php else: ?>
                            <a href="?<?= e(query_string(['page' => $page])) ?>"><?= e($page) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if (end($paginator->window()) < $paginator->lastPage): ?>
                        <?php if (end($paginator->window()) < $paginator->lastPage - 1): ?><span class="is-disabled">&hellip;</span><?php endif; ?>
                        <a href="?<?= e(query_string(['page' => $paginator->lastPage])) ?>"><?= e($paginator->lastPage) ?></a>
                    <?php endif; ?>

                    <?php if ($paginator->page < $paginator->lastPage): ?>
                        <a href="?<?= e(query_string(['page' => $paginator->page + 1])) ?>" aria-label="Next page">&rsaquo;</a>
                    <?php else: ?>
                        <span class="is-disabled">&rsaquo;</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
