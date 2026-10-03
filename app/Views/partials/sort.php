<?php
/**
 * Sortable column header for register tables.
 *
 * @var string $column
 * @var string $label
 * @var string $currentSort
 * @var string $currentDirection
 */
$isActive = $currentSort === $column;
$next     = $isActive && $currentDirection === 'asc' ? 'desc' : 'asc';
?>
<a class="cahu-sortlink" href="?<?= e(query_string(['sort' => $column, 'direction' => $next, 'page' => null])) ?>">
    <?= e($label) ?>
    <?php if ($isActive): ?>
        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round" style="opacity:.7">
            <?php if ($currentDirection === 'asc'): ?>
                <path d="M18 15l-6-6-6 6"/>
            <?php else: ?>
                <path d="M6 9l6 6 6-6"/>
            <?php endif; ?>
        </svg>
    <?php endif; ?>
</a>
