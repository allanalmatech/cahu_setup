<?php
/**
 * Garden change history.
 *
 * @var Core\View $view
 * @var array<string,mixed> $garden
 * @var array<int,array<string,mixed>> $history
 */
$code = (string) $garden['garden_code'];

$eventLabels = [
    'acreage_change'   => 'Acreage changed',
    'status_change'    => 'Status changed',
    'boundary_mapped'  => 'Boundary mapped',
    'transfer'         => 'Transferred to another farmer',
    'corrected'        => 'Record corrected',
];
?>
<div class="cahu-pagehead">
    <div>
        <h1>Garden history</h1>
        <p class="cahu-pagehead__sub">
            <?= e($garden['name']) ?> &middot;
            <a href="<?= e(url('gardens/' . $code)) ?>"><?= e($code) ?></a>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('gardens/' . $code)) ?>">Back to garden</a>
    </div>
</div>

<div class="cahu-card">
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr><th>Date</th><th>Event</th><th>From</th><th>To</th><th>Reason</th><th>Recorded by</th></tr>
                </thead>
                <tbody>
                <?php if ($history === []): ?>
                    <tr><td colspan="6" class="text-muted p-3">No changes have been recorded for this garden.</td></tr>
                <?php else: ?>
                    <?php foreach ($history as $entry): ?>
                        <tr>
                            <td><?= e(format_datetime($entry['created_at'])) ?></td>
                            <td class="fw-semibold">
                                <?= e($eventLabels[$entry['event_type']] ?? ucwords(str_replace('_', ' ', (string) $entry['event_type']))) ?>
                            </td>
                            <td class="text-muted"><?= e($entry['from_value'] ?? '—') ?></td>
                            <td><?= e($entry['to_value'] ?? '—') ?></td>
                            <td class="small"><?= e($entry['reason'] ?: '—') ?></td>
                            <td class="small"><?= e($entry['username'] ?? 'System') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<p class="text-muted small mt-3">
    This log is append-only. Entries are never edited or deleted, because a buyer or regulator may rely on them
    when tracing produce back to its origin.
</p>
