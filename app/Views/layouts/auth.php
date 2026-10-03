<?php
/**
 * @var string $content
 * @var string $appName
 * @var string $appShort
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= e($pageTitle ?? 'Sign in') ?> &middot; <?= e($appName) ?></title>
    <link rel="icon" href="<?= asset('assets/img/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>
<div class="cahu-auth">
    <aside class="cahu-auth__aside">
        <div class="cahu-auth__brand">
            <div class="cahu-brandmark">CA</div>
            <div>
                <strong style="display:block;font-size:1.02rem"><?= e($appShort) ?></strong>
                <span style="font-size:.72rem;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.08em">
                    Cooperative Agribusiness Hub Uganda
                </span>
            </div>
        </div>

        <div class="cahu-auth__pitch">
            <h2>Every farmer, every garden, every harvest &mdash; on one record.</h2>
            <p>
                The platform links registration, GPS mapping, crop cycles, field activities, input
                distribution, inspections, production forecasts and market traceability into a single
                connected chain of evidence.
            </p>
            <div class="cahu-auth__chain">
                <span>Farmer</span><i>&rarr;</i>
                <span>Garden</span><i>&rarr;</i>
                <span>Crop</span><i>&rarr;</i>
                <span>Activity</span><i>&rarr;</i>
                <span>Input</span><i>&rarr;</i>
                <span>Inspection</span><i>&rarr;</i>
                <span>Harvest</span><i>&rarr;</i>
                <span>Market</span><i>&rarr;</i>
                <span>Sale</span>
            </div>
        </div>

        <div style="position:relative;z-index:1;font-size:.72rem;color:rgba(255,255,255,.5)">
            &copy; <?= date('Y') ?> <?= e($appName) ?>. Authorised personnel only.
        </div>
    </aside>

    <main class="cahu-auth__main">
        <div class="cahu-auth__form">
            <?php foreach (\Core\Flash::pull() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> py-2 small"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>

            <?= $content ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
