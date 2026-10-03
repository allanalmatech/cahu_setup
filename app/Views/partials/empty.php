<?php
/**
 * Table body empty state.
 *
 * @var string $title
 * @var string $message
 * @var string $actionLabel
 * @var string $actionUrl
 * @var int $columns
 */
?>
<tr>
    <td colspan="<?= (int) ($columns ?? 6) ?>">
        <div class="cahu-empty">
            <div class="cahu-empty__icon">
                <svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.4"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 7h16M4 12h16M4 17h10"/>
                </svg>
            </div>
            <h3><?= e($title ?? 'Nothing to show yet') ?></h3>
            <p><?= e($message ?? 'No records match the current filters.') ?></p>
            <?php if (!empty($actionUrl)): ?>
                <a class="btn cahu-btn-primary" href="<?= e($actionUrl) ?>"><?= e($actionLabel ?? 'Create the first record') ?></a>
            <?php endif; ?>
        </div>
    </td>
</tr>
