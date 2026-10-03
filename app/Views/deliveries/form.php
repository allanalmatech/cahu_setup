<?php
/**
 * Delivery update form.
 *
 * @var Core\View $view
 * @var array<string,mixed> $delivery
 * @var string $pageTitle
 */
$fields = [
    ['name' => 'dispatch_date', 'label' => 'Dispatch date', 'type' => 'date', 'colspan' => 4],
    ['name' => 'expected_date', 'label' => 'Expected date', 'type' => 'date', 'colspan' => 4,
     'help' => 'Cannot be earlier than the dispatch date.'],
    ['name' => 'delivered_date', 'label' => 'Delivered date', 'type' => 'date', 'colspan' => 4,
     'help' => 'Required once the status is delivered.'],
    ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'colspan' => 4,
     'options' => \Core\Form::enumOptions("'pending','in_transit','delivered','partially_delivered','cancelled'", '')],
    ['name' => 'delivery_location', 'label' => 'Delivery location', 'type' => 'text', 'colspan' => 6],
    ['name' => 'transporter_name', 'label' => 'Transporter', 'type' => 'text', 'colspan' => 6],
    ['name' => 'vehicle_number', 'label' => 'Vehicle number', 'type' => 'text', 'colspan' => 6],
    ['name' => 'tracking_number', 'label' => 'Tracking number', 'type' => 'text', 'colspan' => 6],
    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'colspan' => 12],
];
?>
<div class="cahu-pagehead">
    <div>
        <h1>Delivery <?= e($delivery['delivery_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <a href="<?= e(url('sales/' . $delivery['sale_code'])) ?>"><?= e($delivery['sale_code']) ?></a>
            · <?= e($delivery['buyer_name']) ?>
            · <?= e(format_money($delivery['total_value'], (string) $delivery['currency'])) ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('deliveries')) ?>">Back to register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('sales/' . $delivery['sale_code'])) ?>">Open the sale</a>
    </div>
</div>

<?php if ($errors = \Core\Flash::errors()): ?>
    <div class="alert alert-danger">
        <strong>Please correct the following:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">Current position</div>
    <div class="cahu-card__body">
        <dl class="cahu-kv">
            <dt>Status</dt>
            <dd><?= status_badge((string) $delivery['status']) ?></dd>

            <dt>Dispatched</dt>
            <dd><?= e(format_date($delivery['dispatch_date'])) ?></dd>

            <dt>Expected</dt>
            <dd><?= e(format_date($delivery['expected_date'])) ?></dd>

            <dt>Delivered</dt>
            <dd><?= e(format_date($delivery['delivered_date'])) ?></dd>

            <dt>Location</dt>
            <dd><?= e($delivery['delivery_location'] ?: '—') ?></dd>

            <dt>Transporter</dt>
            <dd><?= e($delivery['transporter_name'] ?: '—') ?></dd>

            <dt>Vehicle</dt>
            <dd><?= e($delivery['vehicle_number'] ?: '—') ?></dd>

            <dt>Tracking</dt>
            <dd><?= e($delivery['tracking_number'] ?: '—') ?></dd>

            <dt>Sale status</dt>
            <dd><?= status_badge((string) $delivery['sale_status']) ?></dd>
        </dl>
    </div>
</div>

<form method="post" action="<?= e(url('deliveries/' . $delivery['delivery_code'])) ?>" novalidate data-validate>
    <?= csrf_field() ?>

    <div class="cahu-card">
        <div class="cahu-card__body">
            <div class="form-section">
                <div class="form-section__title">Movement</div>
                <div class="row">
                    <?php foreach ($fields as $field): ?>
                        <?php $view->partial('partials/field', ['field' => $field, 'record' => $delivery, 'gpsLng' => '']); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e(url('deliveries')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit">Save delivery</button>
        </div>
    </div>
</form>