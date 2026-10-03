<?php
/**
 * Tab bar for profile and record pages.
 *
 * @var array<int,array{label:string,url:string,match:string,count?:int}> $tabs
 * @var string $currentPath
 */
?>
<nav class="cahu-tabs mb-3">
    <?php foreach ($tabs as $tab): ?>
        <a class="<?= $currentPath === $tab['match'] ? 'is-active' : '' ?>" href="<?= e($tab['url']) ?>">
            <?= e($tab['label']) ?>
            <?php if (isset($tab['count'])): ?>
                <span class="tab-count"><?= e($tab['count']) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
