<?php
/**
 * Notification list.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $notifications
 * @var int $unread
 */
$tones = [
    'activity_assigned'   => 'blue',
    'activity_overdue'    => 'amber',
    'input_issued'        => 'green',
    'low_stock'           => 'amber',
    'harvest_recorded'    => 'green',
    'batch_created'       => 'green',
    'sale_recorded'       => 'green',
    'inspection_scheduled' => 'blue',
    'problem_reported'    => 'amber',
    'sync_conflict'       => 'soil',
];
?>
<div class="cahu-pagehead">
    <div>
        <h1>Notifications</h1>
        <p class="cahu-pagehead__sub">
            <?php if ($unread > 0): ?>
                <?= e($unread) ?> unread.
            <?php else: ?>
                You are all caught up.
            <?php endif; ?>
        </p>
    </div>
    <?php if ($unread > 0): ?>
        <div class="cahu-pagehead__actions">
            <form method="post" action="<?= e(url('notifications/read-all')) ?>">
                <?= csrf_field() ?>
                <button class="btn cahu-btn-outline" type="submit">Mark all as read</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="cahu-card">
    <div class="cahu-card__body cahu-card__body--flush">
        <?php if ($notifications === []): ?>
            <div class="cahu-empty">
                <div class="cahu-empty__icon">&#9993;</div>
                <h3>No notifications</h3>
                <p class="text-muted mb-0">
                    Activity assignments, low input stock and harvest updates appear here.
                </p>
            </div>
        <?php else: ?>
            <ul class="cahu-notifications list-unstyled mb-0">
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $isUnread = (int) $notification['is_read'] === 0;
                    $tone     = $tones[(string) $notification['type']] ?? 'soil';
                    $link     = $notification['link'] === null ? null : url((string) $notification['link']);
                    ?>
                    <li class="cahu-notification cahu-notification--<?= e($tone) ?><?= $isUnread ? ' is-unread' : '' ?>">
                        <div class="cahu-notification__body">
                            <div class="fw-semibold"><?= e($notification['title']) ?></div>
                            <div class="text-muted small"><?= e($notification['message']) ?></div>
                            <div class="cahu-notification__meta">
                                <span><?= e(format_datetime((string) $notification['created_at'])) ?></span>
                                <?php if (!empty($notification['entity_type'])): ?>
                                    <span class="badge text-bg-light text-dark">
                                        <?= e(ucfirst(str_replace('_', ' ', (string) $notification['entity_type']))) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="cahu-notification__actions">
                            <?php if ($link !== null): ?>
                                <a class="btn btn-sm cahu-btn-outline" href="<?= e($link) ?>">Open</a>
                            <?php endif; ?>
                            <?php if ($isUnread): ?>
                                <form method="post" action="<?= e(url('notifications/' . $notification['id'] . '/read')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-link text-decoration-none" type="submit">Mark read</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted small"><?= e($notification['read_at'] !== null ? 'Read' : '') ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>