<?php
/**
 * Month view of the activity schedule.
 *
 * The grid is rendered server side so the month is readable without
 * JavaScript; the script then layers the status colouring and links on top.
 *
 * @var Core\View $view
 * @var string $month
 * @var string $label
 * @var string $previous
 * @var string $next
 */
$first = strtotime($month . '-01');
$start = strtotime('monday this week', (int) $first);
$weeks = (int) ceil((strtotime('last day of ' . date('Y-m', $first)) - $start) / (7 * 86400));
$today = date('Y-m-d');
?>
<div class="cahu-pagehead">
    <div>
        <h1>Activity calendar</h1>
        <p class="cahu-pagehead__sub">When work is due across the programme.</p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('activities/calendar?month=' . $previous)) ?>" aria-label="Previous month">&lsaquo;</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('activities/calendar')) ?>">This month</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('activities/calendar?month=' . $next)) ?>" aria-label="Next month">&rsaquo;</a>
        <a class="btn cahu-btn-primary" href="<?= e(url('activities')) ?>">List view</a>
    </div>
</div>

<div class="cahu-card" data-calendar data-month="<?= e($month) ?>">
    <div class="cahu-card__head d-flex justify-content-between align-items-center">
        <span><?= e($label) ?></span>
        <span class="cahu-cal__legend">
            <i class="cahu-cal__key cahu-cal__key--overdue"></i> overdue
            <i class="cahu-cal__key cahu-cal__key--due"></i> due
            <i class="cahu-cal__key cahu-cal__key--pending"></i> upcoming
            <i class="cahu-cal__key cahu-cal__key--completed"></i> done
        </span>
    </div>
    <div class="cahu-card__body cahu-cal" id="cahu-cal">
        <div class="cahu-cal__head">
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day): ?>
                <div><?= e($day) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="cahu-cal__grid" data-calendar-events
             data-url="<?= e(url('activities/calendar/data')) ?>?month=<?= e($month) ?>">
            <?php for ($cell = 0; $cell < $weeks * 7; $cell++): ?>
                <?php
                $day    = $start + $cell * 86400;
                $iso    = date('Y-m-d', $day);
                $inMonth = date('Y-m', $day) === $month;
                ?>
                <div class="cahu-cal__day<?= $inMonth ? '' : ' is-outside' ?><?= $iso === $today ? ' is-today' : '' ?>"
                     data-date="<?= e($iso) ?>">
                    <span class="cahu-cal__date"><?= e((string) date('j', $day)) ?></span>
                    <div class="cahu-cal__events" data-day-events>
                        <noscript>
                            <a class="small" href="<?= e(url('activities')) ?>">Switch on JavaScript, or use the list view.</a>
                        </noscript>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<p class="text-muted small mt-3">
    Dates are shown in your browser's local time. Overdue tasks appear against their original due date rather than
    moving forward, so the backlog stays visible.
</p>