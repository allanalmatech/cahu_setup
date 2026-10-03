<?php
/**
 * Field portal: the small, touch friendly screen used on a phone in the field.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $today
 * @var array{farmers:int,acreage:float} $portfolio
 */
?>
<div class="cahu-pagehead">
    <div>
        <h1>Field portal</h1>
        <p class="cahu-pagehead__sub">Work due today, in the order it should be done.</p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('dashboard')) ?>">Full dashboard</a>
    </div>
</div>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Farmers in portfolio</div>
        <div class="cahu-stat__value"><?= e((int) $portfolio['farmers']) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Acres</div>
        <div class="cahu-stat__value"><?= e(format_number((float) $portfolio['acreage'], 1)) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Outstanding tasks</div>
        <div class="cahu-stat__value"><?= e(count($today)) ?></div>
    </div>
</div>

<?php if ($today === []): ?>
    <div class="cahu-card">
        <div class="cahu-card__body">
            <div class="cahu-empty">
                <div class="cahu-empty__icon">
                    <svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.4"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6L9 17l-5-5"/>
                    </svg>
                </div>
                <h3>Nothing outstanding</h3>
                <p>Every scheduled activity in your portfolio is up to date.</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($today as $item): ?>
        <div class="cahu-card mb-2">
            <div class="cahu-card__body">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <div class="fw-semibold">
                            <?= e(ucwords(str_replace('_', ' ', (string) $item['activity_type']))) ?>
                        </div>
                        <div class="text-muted small">
                            <?= e($item['full_name']) ?>
                            <span class="cahu-popup__code"><?= e($item['farmer_code']) ?></span>
                            <?php if (!empty($item['garden_code'])): ?> &middot; <?= e($item['garden_code']) ?><?php endif; ?>
                        </div>
                        <div class="text-muted small">
                            Due <?= e(format_date($item['due_date'])) ?>
                            <?= $item['priority'] === 'high' ? ' &middot; high priority' : '' ?>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?= status_badge((string) $item['status']) ?>
                        <a class="btn cahu-btn-primary btn-sm"
                           href="<?= e(url('portal/activity/' . $item['activity_code'])) ?>">Record</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
