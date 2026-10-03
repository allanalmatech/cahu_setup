<?php
/**
 * Printable farmer record (no application chrome, print-optimised).
 *
 * @var array<string,mixed> $farmer
 * @var array<int,array<string,mixed>> $gardens
 * @var array<int,array<string,mixed>> $crops
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Farmer record <?= e($farmer['farmer_code']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="bg-white">
<div class="container py-4" style="max-width:900px">
    <div class="cahu-print-head d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Farmer record</h1>
            <div class="text-muted"><?= e($appName) ?> &middot; printed <?= e(format_date(date('Y-m-d'))) ?></div>
        </div>
        <div class="text-end">
            <div class="h5 mb-0"><?= e($farmer['farmer_code']) ?></div>
            <div class="text-muted small"><?= e(ucwords(str_replace('_', ' ', (string) $farmer['status']))) ?></div>
        </div>
    </div>

    <h2 class="h6 text-uppercase text-muted">Farmer</h2>
    <table class="table table-sm">
        <tbody>
        <?php
        $rows = [
            'Full name'   => $farmer['full_name'],
            'Phone'       => $farmer['phone'],
            'Email'       => $farmer['email'],
            'National ID' => $farmer['national_id'],
            'Gender'      => $farmer['gender'],
            'District'    => $farmer['district_name'],
            'Sub-county'  => $farmer['sub_county_name'],
            'Parish'      => $farmer['parish_name'],
            'Village'     => $farmer['registered_village_name'] ?: $farmer['village_name'],
            'GPS'         => ($farmer['latitude'] !== null && $farmer['longitude'] !== null)
                ? round((float) $farmer['latitude'], 5) . ', ' . round((float) $farmer['longitude'], 5)
                : null,
            'Group'       => $farmer['group_name'],
            'Officer'     => $farmer['officer_name'],
            'Registered'  => format_date($farmer['registration_date']),
        ];
        ?>
        <?php foreach ($rows as $label => $value): ?>
            <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
            <tr>
                <th class="w-25 text-muted fw-normal"><?= e($label) ?></th>
                <td><?= e($value) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 class="h6 text-uppercase text-muted mt-4">Gardens</h2>
    <table class="table table-sm">
        <thead>
        <tr><th>Garden</th><th>Name</th><th>Acres</th><th>Soil</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if ($gardens === []): ?>
            <tr><td colspan="5" class="text-muted">No gardens recorded.</td></tr>
        <?php else: ?>
            <?php foreach ($gardens as $garden): ?>
                <tr>
                    <td><?= e($garden['garden_code']) ?></td>
                    <td><?= e($garden['name']) ?></td>
                    <td><?= e(format_number($garden['acreage'])) ?></td>
                    <td><?= e($garden['soil_type']) ?></td>
                    <td><?= e($garden['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <h2 class="h6 text-uppercase text-muted mt-4">Crops and plantings</h2>
    <table class="table table-sm">
        <thead>
        <tr><th>Planting</th><th>Crop</th><th>Garden</th><th>Season</th><th>Date</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if ($crops === []): ?>
            <tr><td colspan="6" class="text-muted">No plantings recorded.</td></tr>
        <?php else: ?>
            <?php foreach ($crops as $crop): ?>
                <tr>
                    <td><?= e($crop['planting_code']) ?></td>
                    <td><?= e($crop['crop_name']) ?><?= !empty($crop['variety_name']) ? ' (' . e($crop['variety_name']) . ')' : '' ?></td>
                    <td><?= e($crop['garden_code']) ?></td>
                    <td><?= e($crop['season_code']) ?></td>
                    <td><?= e(format_date($crop['planting_date'])) ?></td>
                    <td><?= e($crop['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <p class="text-muted small mt-4 mb-0">
        This record is generated from the CAHU platform. The farmer ID is permanent and must not be reused.
    </p>
</div>
</body>
</html>
